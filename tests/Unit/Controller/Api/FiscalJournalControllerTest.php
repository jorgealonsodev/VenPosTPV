<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use VenPosTPV\Controller\Api\FiscalJournalController;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuard;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalReconciler;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalState;
use VenPosTPV\Service\Fiscal\Journal\FiscalReplay;
use VenPosTPV\Service\Fiscal\Journal\FiscalReplayResult;

/** GET /api/fiscal/journal: Ajustes → VERI*FACTU → «Diario fiscal» (admins only, read-only). */
final class FiscalJournalControllerTest extends TestCase
{
    private function request(?string $role): Request
    {
        $request = Request::create('/api/fiscal/journal', 'GET');
        $session = new Session(new MockArraySessionStorage());
        if ($role !== null) {
            $session->set('user_id', 1);
            $session->set('role', $role);
        }
        $request->setSession($session);

        return $request;
    }

    private function guard(?array $report = null): FiscalJournalGuard
    {
        $guard = $this->createMock(FiscalJournalGuard::class);
        $guard->method('report')->willReturn($report ?? ['state' => FiscalJournalGuard::OK, 'missing' => null]);

        return $guard;
    }

    /**
     * Only an admin sees the journal state; any other logged-in user gets only whether
     * the till is blocked (the guard state), so a reloaded till shows «Caja bloqueada»
     * at once (final verification gap 6). Without a user: nothing.
     */
    public function testOnlyAnAdminSeesTheJournalStateACashierOnlyTheGuardState(): void
    {
        $reconciler = $this->createMock(FiscalJournalReconciler::class);
        $reconciler->expects($this->never())->method('current');
        $reconciler->expects($this->never())->method('run');
        $guard = $this->createMock(FiscalJournalGuard::class);
        $guard->expects($this->never())->method('report');
        $guard->method('check')->willReturn(['state' => FiscalJournalGuard::BEHIND_JOURNAL, 'missing' => ['number' => 'A26000124', 'invoice_type' => 'F2']]);
        $controller = new FiscalJournalController($reconciler, $guard, $this->createMock(FiscalReplay::class));

        $response = $controller->status($this->request('cashier'));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['data' => ['guard' => ['state' => 'behind_journal']]], json_decode((string) $response->getContent(), true), 'no document number, no journal detail');

        $response = $controller->status($this->request(null));
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['error' => 'Acceso denegado — solo administradores'], json_decode((string) $response->getContent(), true));
    }

    public function testAGuardThatCannotBeReadForACashierIsAnErrorNotACrash(): void
    {
        $guard = $this->createMock(FiscalJournalGuard::class);
        $guard->method('check')->willThrowException(new \RuntimeException('connection refused'));

        $response = (new FiscalJournalController($this->createMock(FiscalJournalReconciler::class), $guard, $this->createMock(FiscalReplay::class)))->status($this->request('cashier'));

        $this->assertSame(503, $response->getStatusCode());
    }

    public function testAnAdminGetsTheStateWithoutItsInternalCheckpointsAndNothingRuns(): void
    {
        $state = FiscalJournalState::notStarted(new \DateTimeImmutable('2026-11-14T18:03:00+01:00'))->data;
        $state['status'] = FiscalJournalState::OK;
        $state['journal_id'] = '7f0c1f4e-1d2b-4c4f-9a8e-2b1d3c4e5f60';
        $state['last_document'] = ['number' => 'A26000124', 'invoice_type' => 'F2', 'generated_at' => '2026-11-14T18:02:10+01:00'];
        $state['verified'] = ['primary' => ['file' => 'journal-2026-11.jsonl', 'offset' => 10, 'line' => 1, 'seq' => 1, 'line_hash' => 'ab']];
        $reconciler = $this->createMock(FiscalJournalReconciler::class);
        $reconciler->expects($this->never())->method('run');
        $reconciler->expects($this->once())->method('current')->willReturn(new FiscalJournalState($state));

        $response = (new FiscalJournalController($reconciler, $this->guard(), $this->createMock(FiscalReplay::class)))->status($this->request('admin'));

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true)['data'];
        $this->assertSame('ok', $data['status']);
        $this->assertSame('A26000124', $data['last_document']['number']);
        $this->assertSame(0, $data['pending']['count']);
        $this->assertArrayNotHasKey('verified', $data);
    }

    public function testAStateThatCannotBeReadIsAnErrorNotACrash(): void
    {
        $reconciler = $this->createMock(FiscalJournalReconciler::class);
        $reconciler->method('current')->willThrowException(new \RuntimeException('connection refused'));

        $response = (new FiscalJournalController($reconciler, $this->guard(), $this->createMock(FiscalReplay::class)))->status($this->request('admin'));

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(['error' => 'No se pudo leer el estado del diario fiscal'], json_decode((string) $response->getContent(), true));
    }

    public function testAnAdminSeesWhetherTheTillIsBlockedAndWhatIsMissing(): void
    {
        $state = FiscalJournalState::notStarted(new \DateTimeImmutable('2026-11-14T18:03:00+01:00'))->data;
        $state['status'] = FiscalJournalState::HEAD_MISSING;
        $reconciler = $this->createMock(FiscalJournalReconciler::class);
        $reconciler->method('current')->willReturn(new FiscalJournalState($state));
        $report = ['state' => FiscalJournalGuard::BEHIND_JOURNAL, 'missing' => ['number' => 'A26000003', 'invoice_type' => 'F2', 'after' => 2]];

        $response = (new FiscalJournalController($reconciler, $this->guard($report), $this->createMock(FiscalReplay::class)))->status($this->request('admin'));

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true)['data'];
        $this->assertSame('head_missing', $data['status']);
        $this->assertSame($report, $data['guard']);
    }

    public function testAnUpToDateTillReportsTheGuardOk(): void
    {
        $state = FiscalJournalState::notStarted(new \DateTimeImmutable('2026-11-14T18:03:00+01:00'))->data;
        $reconciler = $this->createMock(FiscalJournalReconciler::class);
        $reconciler->method('current')->willReturn(new FiscalJournalState($state));

        $data = json_decode((string) (new FiscalJournalController($reconciler, $this->guard(), $this->createMock(FiscalReplay::class)))->status($this->request('admin'))->getContent(), true)['data'];

        $this->assertSame(['state' => 'ok', 'missing' => null], $data['guard']);
    }

    // POST /api/fiscal/journal/replay: the Ajustes button «Recuperar documentos» (fiscal-journal S7, D8.5).

    private function replayRequest(?string $role, mixed $body): Request
    {
        $request = Request::create('/api/fiscal/journal/replay', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], \is_string($body) ? $body : json_encode($body));
        $session = new Session(new MockArraySessionStorage());
        if ($role !== null) {
            $session->set('user_id', 1);
            $session->set('role', $role);
        }
        $request->setSession($session);

        return $request;
    }

    private function behind(): FiscalJournalGuard
    {
        return $this->guard(['state' => FiscalJournalGuard::BEHIND_JOURNAL, 'missing' => ['number' => 'A26000011', 'invoice_type' => 'F2', 'after' => 1]]);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private static function answer(\Symfony\Component\HttpFoundation\JsonResponse $response): array
    {
        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }

    private const DOCUMENTS = [
        ['seq' => 11, 'kind' => 'F2', 'number' => 'A26000011', 'generated_at' => '2026-11-14T18:02:10+01:00'],
        ['seq' => 12, 'kind' => 'R5', 'number' => 'R2026000001', 'generated_at' => '2026-11-14T18:03:10+01:00'],
    ];

    public function testOnlyAnAdminCanReplayAndNothingRunsOtherwise(): void
    {
        $replay = $this->createMock(FiscalReplay::class);
        $replay->expects($this->never())->method('run');
        $guard = $this->behind();
        $controller = new FiscalJournalController($this->createMock(FiscalJournalReconciler::class), $guard, $replay);

        foreach (['cashier', null] as $role) {
            [$status, $body] = self::answer($controller->replay($this->replayRequest($role, ['apply' => true])));
            $this->assertSame(403, $status);
            $this->assertSame(['error' => 'Acceso denegado — solo administradores'], $body);
        }
    }

    public function testTheReplayDoesNotRunWhenTheTillIsNotBlocked(): void
    {
        $replay = $this->createMock(FiscalReplay::class);
        $replay->expects($this->never())->method('run');
        $controller = new FiscalJournalController($this->createMock(FiscalJournalReconciler::class), $this->guard(), $replay);

        foreach ([['apply' => false], ['apply' => true]] as $payload) {
            [$status, $body] = self::answer($controller->replay($this->replayRequest('admin', $payload)));
            $this->assertSame(409, $status);
            $this->assertSame(['error' => 'Nada que recuperar: la base de datos tiene todos los documentos del diario fiscal.', 'code' => 'nothing_to_replay'], $body);
        }
    }

    public function testWithoutApplyItOnlyListsWhatWouldBeRecovered(): void
    {
        $replay = $this->createMock(FiscalReplay::class);
        $replay->expects($this->exactly(3))->method('run')->with(false)->willReturn(new FiscalReplayResult(FiscalReplayResult::PENDING, self::DOCUMENTS));
        $controller = new FiscalJournalController($this->createMock(FiscalJournalReconciler::class), $this->behind(), $replay);

        // No body, apply false, or anything but a literal true is a dry run.
        foreach (['', ['apply' => false], ['apply' => 'yes']] as $payload) {
            [$status, $body] = self::answer($controller->replay($this->replayRequest('admin', $payload)));
            $this->assertSame(200, $status);
            $this->assertSame(['data' => ['status' => 'pending', 'applied' => false, 'documents' => self::DOCUMENTS, 'message' => null]], $body);
        }
    }

    public function testApplyRecoversTheDocumentsThroughTheSameServiceAsTheCommand(): void
    {
        $replay = $this->createMock(FiscalReplay::class);
        $replay->expects($this->once())->method('run')->with(true)->willReturn(new FiscalReplayResult(FiscalReplayResult::DONE, self::DOCUMENTS));
        $controller = new FiscalJournalController($this->createMock(FiscalJournalReconciler::class), $this->behind(), $replay);

        [$status, $body] = self::answer($controller->replay($this->replayRequest('admin', ['apply' => true])));

        $this->assertSame(200, $status);
        $this->assertSame(['data' => ['status' => 'done', 'applied' => true, 'documents' => self::DOCUMENTS, 'message' => null]], $body);
    }

    public function testARefusedReplayAnswersItsMessageAndStatus(): void
    {
        $cases = [
            [FiscalReplayResult::TOO_OLD, 'La copia restaurada es anterior al diario conservado (desde 01/11/2026). Restaura una copia más reciente.', 409],
            [FiscalReplayResult::CONFLICT, 'Conflicto: order_meta 1501 ya existe con otro contenido. No se ha escrito nada.', 409],
            [FiscalReplayResult::STILL_BLOCKED, 'No hay documentos del diario que recuperar, pero la caja sigue bloqueada: falta A26000011 en la base de datos.', 409],
            [FiscalReplayResult::ERROR, 'El diario fiscal está ocupado; vuelve a intentarlo en un minuto.', 500],
        ];
        foreach ($cases as [$result, $message, $expected]) {
            $replay = $this->createMock(FiscalReplay::class);
            $replay->method('run')->willReturn(new FiscalReplayResult($result, [], $message));
            $controller = new FiscalJournalController($this->createMock(FiscalJournalReconciler::class), $this->behind(), $replay);

            [$status, $body] = self::answer($controller->replay($this->replayRequest('admin', ['apply' => true])));

            $this->assertSame($expected, $status, $result);
            $this->assertSame(['error' => $message, 'code' => 'replay_' . $result, 'data' => ['status' => $result, 'applied' => false, 'documents' => [], 'message' => $message]], $body);
        }
    }

    public function testAGuardThatCannotBeReadIsAnErrorAndNothingRuns(): void
    {
        $guard = $this->createMock(FiscalJournalGuard::class);
        $guard->method('report')->willThrowException(new \RuntimeException('connection refused'));
        $replay = $this->createMock(FiscalReplay::class);
        $replay->expects($this->never())->method('run');

        [$status, $body] = self::answer((new FiscalJournalController($this->createMock(FiscalJournalReconciler::class), $guard, $replay))->replay($this->replayRequest('admin', ['apply' => true])));

        $this->assertSame(503, $status);
        $this->assertSame(['error' => 'No se pudo leer el estado del diario fiscal'], $body);
    }

    public function testTheReplayIsAPostRouteSoTheOriginCheckOfStateChangingRequestsApplies(): void
    {
        $routes = (new \ReflectionMethod(FiscalJournalController::class, 'replay'))->getAttributes(\Symfony\Component\Routing\Attribute\Route::class);
        $this->assertCount(1, $routes);
        $route = $routes[0]->newInstance();
        $this->assertSame('/fiscal/journal/replay', $route->getPath());
        $this->assertSame(['POST'], $route->getMethods());
    }
}
