<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Label\LabelCatalog;
use VenPosTPV\Service\Label\LabelPreviewRenderer;
use VenPosTPV\Service\Label\LabelPrintException;
use VenPosTPV\Service\Label\LabelPrintService;
use VenPosTPV\Service\Label\LabelSettings;
use VenPosTPV\Service\Label\LabelSettingsStore;
use VenPosTPV\Service\PrintAgent\AgentRegistry;
use VenPosTPV\Service\PrintAgent\TerminalNotResolvedException;

/**
 * Labels from the POS (docs/prd/agente-impresion.md L1-L9): preview, print, the
 * scanned-code lookup of the Etiquetas screen, the held labels of a till whose agent
 * was away ("Hay N etiquetas pendientes. ¿Imprimirlas?") and the test label of
 * Ajustes → Cajas. Any logged user prints labels; writes accept JSON only (415),
 * so a cross-site form cannot trigger them. The till is the session's; it is never
 * assumed (T9).
 */
#[AsController]
final class LabelController
{
    private const MAX_PREVIEWS = 60;

    public function __construct(
        private readonly LabelPrintService $labels,
        private readonly LabelPreviewRenderer $previews,
        private readonly LabelSettingsStore $settings,
        private readonly LabelCatalog $catalog,
        private readonly AgentRegistry $agents,
        private readonly Connection $connection,
    ) {
    }

    /** {items:[{product_id, id_product_attribute, quantity}]} → {previews, errors, labels} */
    #[Route('/labels/preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $denied = self::loggedJsonOnly($request);
        if ($denied !== null) {
            return $denied;
        }

        $terminalId = self::positiveInt($request->getSession()->get('terminal_id'));
        $settings = $terminalId !== null ? $this->settings->forTerminal($terminalId) : LabelSettings::defaults();
        try {
            $lines = $this->labels->lineLabels(self::body($request)['items'] ?? null);
        } catch (LabelPrintException $e) {
            return self::refused($e);
        }

        $previews = [];
        $errors = [];
        $count = 0;
        foreach ($lines as $line) {
            $label = $line['label'];
            if ($label === null) {
                $errors[] = ['product_id' => $line['product_id'], 'id_product_attribute' => $line['id_product_attribute'], 'reason' => (string) $line['reason']];
                continue;
            }
            $count += $line['quantity'];
            if (\count($previews) >= self::MAX_PREVIEWS) {
                continue;
            }
            $previews[] = [
                'product_id' => $line['product_id'],
                'id_product_attribute' => $line['id_product_attribute'],
                'quantity' => $line['quantity'],
                'name' => $label->name,
                'combination' => $label->combination,
                'price' => $label->price,
                'price_text' => $label->priceText(),
                'barcode' => ['type' => $label->barcode->type, 'text' => $label->barcode->text],
                'image' => $this->previews->dataUri($label, $settings),
            ];
        }

        return self::noStore(new JsonResponse([
            'previews' => $previews,
            'errors' => $errors,
            'labels' => $count,
            'height_mm' => $settings->heightMm,
        ]));
    }

    /** {items} → 201 {jobs, print_status: queued|held|printed|failed, labels, errors} */
    #[Route('/labels/print', methods: ['POST'])]
    public function print(Request $request): JsonResponse
    {
        $denied = self::loggedJsonOnly($request);
        if ($denied !== null) {
            return $denied;
        }
        $terminalId = self::positiveInt($request->getSession()->get('terminal_id'));
        if ($terminalId === null) {
            return self::terminalNotResolved();
        }

        try {
            $result = $this->labels->print($terminalId, self::body($request)['items'] ?? null, self::positiveInt($request->getSession()->get('user_id')));
        } catch (LabelPrintException $e) {
            return self::refused($e);
        } catch (TerminalNotResolvedException) {
            return self::terminalNotResolved();
        } catch (TableNotFoundException) {
            return self::unavailable();
        }

        return self::noStore(new JsonResponse($result, $result['print_status'] === 'failed' ? Response::HTTP_BAD_GATEWAY : Response::HTTP_CREATED));
    }

    /** ?code= → {product_id, id_product_attribute, name, combination, stock, image_url} (Etiquetas: scanning) */
    #[Route('/labels/lookup', methods: ['GET'])]
    public function lookup(Request $request): JsonResponse
    {
        if ($request->getSession()->get('user_id') === null) {
            return self::noStore(new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED));
        }
        $code = $request->query->get('code');
        $found = \is_string($code) ? $this->catalog->findByCode(mb_substr($code, 0, 64)) : null;
        $product = $found !== null ? $this->catalog->productData($found['product_id']) : null;
        if ($found === null || $product === null) {
            return self::noStore(new JsonResponse(['error' => 'Código no encontrado en el catálogo'], Response::HTTP_NOT_FOUND));
        }

        $combination = null;
        $stock = (int) ($product['stock'] ?? 0);
        foreach ((array) ($product['combinations'] ?? []) as $candidate) {
            if (\is_array($candidate) && (int) $candidate['id'] === $found['id_product_attribute']) {
                $combination = LabelCatalog::attributes((array) ($candidate['attributes'] ?? []));
                $stock = (int) ($candidate['stock'] ?? 0);
            }
        }

        return self::noStore(new JsonResponse($found + [
            'name' => (string) $product['name'],
            'combination' => $combination,
            'stock' => $stock,
            'image_url' => isset($product['image_url']) && \is_string($product['image_url']) && $product['image_url'] !== '' ? $product['image_url'] : null,
        ]));
    }

    /** ?terminal_id → {jobs, labels, connected, ask, message} (L9) */
    #[Route('/labels/pending', methods: ['GET'])]
    public function pending(Request $request): JsonResponse
    {
        $terminalId = $this->pendingTerminal($request, $request->query->get('terminal_id'));
        if ($terminalId instanceof JsonResponse) {
            return $terminalId;
        }
        if (!$this->labels->printerConfig($terminalId)->usesAgent()) {
            return self::noStore(new JsonResponse(['jobs' => 0, 'labels' => 0, 'connected' => false, 'ask' => false, 'message' => null]));
        }

        try {
            $pending = $this->labels->pending($terminalId);
            $connected = $this->agents->statusOf($terminalId)['connected'];
        } catch (TableNotFoundException) {
            return self::unavailable();
        }
        $ask = $connected && $pending['labels'] > 0;

        return self::noStore(new JsonResponse($pending + [
            'connected' => $connected,
            'ask' => $ask,
            'message' => $ask ? sprintf('Hay %d %s. ¿Imprimirlas?', $pending['labels'], $pending['labels'] === 1 ? 'etiqueta pendiente' : 'etiquetas pendientes') : null,
        ]));
    }

    /** {terminal_id?} → {labels} */
    #[Route('/labels/pending/print', methods: ['POST'])]
    public function printPending(Request $request): JsonResponse
    {
        return $this->onPending($request, fn (int $terminalId): int => $this->labels->releasePending($terminalId));
    }

    /** {terminal_id?} → {labels} */
    #[Route('/labels/pending/discard', methods: ['POST'])]
    public function discardPending(Request $request): JsonResponse
    {
        return $this->onPending($request, fn (int $terminalId): int => $this->labels->discardPending($terminalId));
    }

    /** "Imprimir etiqueta de prueba" (Ajustes → Cajas, L8). */
    #[Route('/terminals/{id}/labels/test', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function testLabel(Request $request, int $id): JsonResponse
    {
        $denied = self::loggedJsonOnly($request);
        if ($denied !== null) {
            return $denied;
        }
        if ($request->getSession()->get('role') !== 'admin') {
            return self::noStore(new JsonResponse(['error' => 'Acceso denegado — solo administradores'], Response::HTTP_FORBIDDEN));
        }
        if ($this->connection->fetchOne('SELECT id FROM terminal WHERE id = ?', [$id]) === false) {
            return self::noStore(new JsonResponse(['error' => 'Caja no encontrada'], Response::HTTP_NOT_FOUND));
        }

        try {
            $result = $this->labels->printTest($id, self::positiveInt($request->getSession()->get('user_id')));
        } catch (LabelPrintException $e) {
            return self::refused($e);
        } catch (TableNotFoundException) {
            return self::unavailable();
        }

        return self::noStore(new JsonResponse($result, $result['print_status'] === 'failed' ? Response::HTTP_BAD_GATEWAY : Response::HTTP_CREATED));
    }

    /** @param \Closure(int): int $action */
    private function onPending(Request $request, \Closure $action): JsonResponse
    {
        $denied = self::loggedJsonOnly($request);
        if ($denied !== null) {
            return $denied;
        }
        $terminalId = $this->pendingTerminal($request, self::body($request)['terminal_id'] ?? null);
        if ($terminalId instanceof JsonResponse) {
            return $terminalId;
        }

        try {
            return self::noStore(new JsonResponse(['labels' => $action($terminalId)]));
        } catch (TableNotFoundException) {
            return self::unavailable();
        }
    }

    /** The till asked for: the session's; another till only for an administrator. */
    private function pendingTerminal(Request $request, mixed $asked): int|JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return self::noStore(new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED));
        }
        $own = self::positiveInt($session->get('terminal_id'));
        $asked = self::positiveInt($asked);
        if ($asked !== null && $asked !== $own && $session->get('role') !== 'admin') {
            return self::noStore(new JsonResponse(['error' => 'Acceso denegado'], Response::HTTP_FORBIDDEN));
        }
        $terminalId = $asked ?? $own;

        return $terminalId ?? self::terminalNotResolved();
    }

    private static function loggedJsonOnly(Request $request): ?JsonResponse
    {
        if ($request->getSession()->get('user_id') === null) {
            return self::noStore(new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED));
        }
        if ($request->getContentTypeFormat() !== 'json') {
            return self::noStore(new JsonResponse(['error' => 'Se espera un cuerpo JSON'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE));
        }

        return null;
    }

    /** @return array<mixed> */
    private static function body(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);

        return \is_array($body) ? $body : [];
    }

    private static function positiveInt(mixed $value): ?int
    {
        $int = filter_var($value, \FILTER_VALIDATE_INT);

        return \is_int($int) && $int > 0 ? $int : null;
    }

    private static function refused(LabelPrintException $e): JsonResponse
    {
        return self::noStore(new JsonResponse(['error' => $e->getMessage(), 'code' => $e->errorCode], $e->status));
    }

    private static function terminalNotResolved(): JsonResponse
    {
        return self::noStore(new JsonResponse(['error' => (new TerminalNotResolvedException())->getMessage(), 'code' => TerminalNotResolvedException::CODE], Response::HTTP_BAD_REQUEST));
    }

    private static function unavailable(): JsonResponse
    {
        return self::noStore(new JsonResponse(['error' => 'El TPV aún no admite el agente de impresión', 'code' => 'print_agent_unavailable'], Response::HTTP_SERVICE_UNAVAILABLE));
    }

    private static function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
