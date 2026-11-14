<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use VenPosTPV\Controller\Api\ProvisioningController;
use VenPosTPV\Service\Cron\CronRunner;
use VenPosTPV\Service\Provisioning\ProvisioningReport;
use VenPosTPV\Service\Provisioning\ProvisioningRunner;
use VenPosTPV\Service\Provisioning\StepStatus;

/** Ajustes → Acerca de → «Estado de la instalación» (admins only, read-only). */
final class ProvisioningControllerTest extends TestCase
{
    private function request(?string $role): Request
    {
        $request = Request::create('/api/settings/provisioning', 'GET');
        $session = new Session(new MockArraySessionStorage());
        if ($role !== null) {
            $session->set('user_id', 1);
            $session->set('role', $role);
        }
        $request->setSession($session);

        return $request;
    }

    public function testOnlyAnAdminSeesTheInstallationStatus(): void
    {
        $runner = $this->createMock(ProvisioningRunner::class);
        $runner->expects($this->never())->method('status');
        $runner->expects($this->never())->method('run');
        $controller = new ProvisioningController($runner);

        $response = $controller->status($this->request('cashier'));
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Acceso denegado — solo administradores', json_decode((string) $response->getContent(), true)['error']);
        $this->assertSame(403, $controller->status($this->request(null))->getStatusCode());
    }

    public function testAnAdminGetsEveryStepWithItsReasonAndNothingRuns(): void
    {
        $runner = $this->createMock(ProvisioningRunner::class);
        $runner->expects($this->never())->method('run');
        $runner->expects($this->once())->method('status')->willReturn(new ProvisioningReport([
            new StepStatus('migrations', 'Actualizar la base de datos (migraciones)', StepStatus::DONE),
            new StepStatus('free-item-products', 'Productos genéricos del artículo libre en PrestaShop', StepStatus::FAILED, 'Sin conexión con PrestaShop.', new \DateTimeImmutable('2026-11-09T10:00:00+01:00'), false, new \DateTimeImmutable('2026-11-09T10:15:00+01:00')),
        ]));

        $response = (new ProvisioningController($runner))->status($this->request('admin'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['data' => [
            'complete' => false,
            'steps' => [
                ['id' => 'migrations', 'description' => 'Actualizar la base de datos (migraciones)', 'status' => 'done', 'reason' => null, 'attempted_at' => null, 'next_attempt_at' => null],
                ['id' => 'free-item-products', 'description' => 'Productos genéricos del artículo libre en PrestaShop', 'status' => 'failed', 'reason' => 'Sin conexión con PrestaShop.', 'attempted_at' => '2026-11-09T10:00:00+01:00', 'next_attempt_at' => '2026-11-09T10:15:00+01:00'],
            ],
        ]], json_decode((string) $response->getContent(), true));
    }

    public function testAnAdminAlsoGetsTheStateOfTheScheduledTask(): void
    {
        $runner = $this->createMock(ProvisioningRunner::class);
        $runner->method('status')->willReturn(new ProvisioningReport([
            new StepStatus('migrations', 'Actualizar la base de datos (migraciones)', StepStatus::DONE),
        ]));
        $cronStatus = [
            'last_run_at' => '2026-11-14T12:00:10+01:00',
            'running' => true,
            'jobs' => [
                ['id' => 'catalog', 'label' => 'Catálogo completo', 'daily_at' => '03:00', 'last_attempt_at' => '2026-11-14T03:00:10+01:00', 'last_success_at' => null, 'last_error' => 'Sync failed: shop unreachable'],
            ],
        ];
        $cron = $this->createMock(CronRunner::class);
        $cron->expects($this->never())->method('run');
        $cron->expects($this->once())->method('status')->willReturn($cronStatus);

        $data = json_decode((string) (new ProvisioningController($runner, $cron))->status($this->request('admin'))->getContent(), true)['data'];

        $this->assertTrue($data['complete'], 'the existing fields stay');
        $this->assertCount(1, $data['steps']);
        $this->assertSame($cronStatus, $data['cron']);
    }
}
