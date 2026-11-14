<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use VenPosTPV\Controller\Api\FiscalJournalController;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalReconciler;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalState;

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

    public function testOnlyAnAdminSeesTheJournalState(): void
    {
        $reconciler = $this->createMock(FiscalJournalReconciler::class);
        $reconciler->expects($this->never())->method('current');
        $reconciler->expects($this->never())->method('run');
        $controller = new FiscalJournalController($reconciler);

        $response = $controller->status($this->request('cashier'));
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['error' => 'Acceso denegado — solo administradores'], json_decode((string) $response->getContent(), true));
        $this->assertSame(403, $controller->status($this->request(null))->getStatusCode());
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

        $response = (new FiscalJournalController($reconciler))->status($this->request('admin'));

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

        $response = (new FiscalJournalController($reconciler))->status($this->request('admin'));

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(['error' => 'No se pudo leer el estado del diario fiscal'], json_decode((string) $response->getContent(), true));
    }
}
