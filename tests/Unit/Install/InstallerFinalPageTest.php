<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;

/**
 * The «TPV instalado» page is only reachable after a real install, so it is
 * checked on the installer source: the hosting panel gets ONE scheduled task,
 * `tpv:cron`, every minute, and the page ends with the first steps of a new shop.
 */
final class InstallerFinalPageTest extends TestCase
{
    private const INSTALLER = __DIR__ . '/../../../public/install.php';

    private string $source;

    protected function setUp(): void
    {
        $this->source = (string) file_get_contents(self::INSTALLER);
    }

    public function testThePageAsksForOneScheduledTaskRunningTpvCron(): void
    {
        self::assertSame(1, substr_count($this->source, 'tpv:cron --env=prod'), 'tpv:cron is named once, as the task');
        self::assertStringContainsString('<h2>1. Tareas programadas</h2>', $this->source);
        self::assertStringContainsString('<code>* * * * *</code>', $this->source);
        self::assertStringContainsString('Ajustes → Acerca de', $this->source);
        self::assertStringContainsString('<code>backups/</code>', $this->source);
    }

    public function testTheFiveOldCommandsAreNoLongerSeparateTasks(): void
    {
        foreach ([
            'tpv:sync:outbox --env=prod',
            'tpv:catalog:sync --stock-only --env=prod',
            'tpv:catalog:sync --full --env=prod',
            'tpv:customers:sync --env=prod',
            'tpv:backup:database --env=prod',
        ] as $command) {
            self::assertStringNotContainsString($command, $this->source);
        }
        self::assertStringNotContainsString('cinco tareas', $this->source);
        self::assertStringNotContainsString('una vez a mano', $this->source, 'the catalog loads by itself now');
    }

    public function testThePageEndsWithTheFirstSteps(): void
    {
        $steps = strpos($this->source, '<h2>5. Primeros pasos</h2>');
        self::assertNotFalse($steps);
        self::assertGreaterThan((int) strpos($this->source, '<h2>4. Entrar</h2>'), $steps);
        self::assertStringContainsString('Crear caja y usarla en este equipo', $this->source);
        self::assertStringContainsString('Agente de impresión', $this->source);
    }
}
