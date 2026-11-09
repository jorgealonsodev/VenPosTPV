<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Install;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Install\ProvisioningSummary;
use VenPosTPV\Service\Install\WebInstaller;
use VenPosTPV\Service\Provisioning\ProvisioningException;
use VenPosTPV\Service\Provisioning\ProvisioningReport;
use VenPosTPV\Service\Provisioning\ProvisioningRunner;
use VenPosTPV\Service\Provisioning\ProvisioningStateStore;
use VenPosTPV\Service\Provisioning\ProvisioningStep;
use VenPosTPV\Service\Provisioning\StepStatus;

/**
 * The web installer runs the provisioning steps right after the migrations. A step
 * that needs the shop may fail there (the module is not installed yet): the install
 * still finishes and its last page says what is still pending and why.
 */
final class WebInstallerProvisioningTest extends TestCase
{
    public function testTheInstallFinishesWithAShopStepPendingAndSaysWhy(): void
    {
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $conn->executeStatement('CREATE TABLE app_setting (name VARCHAR(100) PRIMARY KEY, value TEXT)');
        $shop = new class implements ProvisioningStep {
            public function id(): string
            {
                return 'free-item-products';
            }

            public function description(): string
            {
                return 'Productos genéricos del artículo libre en PrestaShop';
            }

            public function isDone(): bool
            {
                return false;
            }

            public function run(): void
            {
                throw new ProvisioningException('PrestaShop no ha preparado los productos del artículo libre: <HTTP 404>.');
            }
        };
        $db = new class implements ProvisioningStep {
            public function id(): string
            {
                return 'migrations';
            }

            public function description(): string
            {
                return 'Actualizar la base de datos (migraciones)';
            }

            public function isDone(): bool
            {
                return true;
            }

            public function run(): void
            {
            }
        };
        $runner = new ProvisioningRunner([$db, $shop], new ProvisioningStateStore($conn));

        $report = (new WebInstaller(sys_get_temp_dir()))->provision(static fn (): ProvisioningRunner => $runner);

        $this->assertInstanceOf(ProvisioningReport::class, $report);
        $this->assertSame(['done', 'failed'], array_map(static fn (StepStatus $s) => $s->status, $report->steps));

        $html = ProvisioningSummary::html($report);
        $this->assertStringContainsString('<h2>Estado de la instalación</h2>', $html);
        $this->assertStringContainsString('<tr><td>Actualizar la base de datos (migraciones)</td><td>Hecho</td></tr>', $html);
        $this->assertStringContainsString(
            '<tr><td>Productos genéricos del artículo libre en PrestaShop</td><td>Pendiente: PrestaShop no ha preparado los productos del artículo libre: &lt;HTTP 404&gt;.</td></tr>',
            $html
        );
        $this->assertStringContainsString('se reintentan solos', $html);
    }

    public function testWhenTheStepsCannotEvenStartTheInstallStillFinishes(): void
    {
        $log = ini_set('error_log', '/dev/null');
        try {
            $report = (new WebInstaller(sys_get_temp_dir()))->provision(static function (): ProvisioningRunner {
                throw new \RuntimeException('Unable to write in the "var/cache/prod" directory.');
            });
        } finally {
            ini_set('error_log', (string) $log);
        }

        $this->assertNull($report);
        $html = ProvisioningSummary::html(null);
        $this->assertStringContainsString('<h2>Estado de la instalación</h2>', $html);
        $this->assertStringContainsString('<code>php bin/console tpv:provision --env=prod</code>', $html);
    }

    public function testEverythingDoneSaysSo(): void
    {
        $html = ProvisioningSummary::html(new ProvisioningReport([new StepStatus('migrations', 'Actualizar la base de datos (migraciones)', StepStatus::DONE)]));

        $this->assertStringContainsString('<p class="ok">Todos los pasos de la instalación están hechos.</p>', $html);
        $this->assertStringNotContainsString('se reintentan solos', $html);
    }
}
