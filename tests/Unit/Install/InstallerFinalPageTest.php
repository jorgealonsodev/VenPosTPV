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

    public function testTheTaskIsExplainedFieldByFieldAsInPlesk(): void
    {
        $section = $this->cronSection();

        self::assertStringContainsString('<tr><th>Campo</th><th>Qué poner</th></tr>', $section);
        foreach ([
            'Activa',
            'Tipo de tarea',
            'Ruta de acceso del script',
            'con argumentos (a la derecha de la ruta)',
            'Usar la versión PHP',
            'Ejecutar',
            'Descripción',
            'Notificar',
        ] as $field) {
            self::assertSame(1, substr_count($section, '<tr><td>' . $field . '</td>'), $field);
        }
        self::assertStringContainsString("\$cronArgs = 'tpv:cron --env=prod';", $this->source);
        self::assertStringContainsString('<td><code>\' . h($cronArgs) . \'</code></td>', $section);
        self::assertStringContainsString('<td><code>\' . h($script) . \'</code></td>', $section);
        self::assertStringContainsString('<td><strong>\' . h($phpVersion) . \'</strong></td>', $section);
        self::assertStringContainsString('<strong>Solo errores</strong>', $section);
        self::assertStringContainsString('Ejecutar ahora', $section);
        self::assertStringNotContainsString('Con argumentos', $this->source, 'the old one-row table is gone');
    }

    private function cronSection(): string
    {
        $start = strpos($this->source, '<h2>1. Tareas programadas</h2>');
        $end = strpos($this->source, '<h2>2. PHP del subdominio</h2>');
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        return substr($this->source, $start, $end - $start);
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

    public function testTheMigrationNotesWarnWhoKeepsMoreThanSevenBackups(): void
    {
        foreach (['despliegue-plesk.md', 'despliegue-hosting.md'] as $doc) {
            $source = (string) file_get_contents(__DIR__ . '/../../../docs/' . $doc);
            $start = strpos($source, '**Si tu instalación tiene las cinco tareas de antes**');
            self::assertNotFalse($start, $doc);
            $end = strpos($source, "\n\n", $start);
            $note = substr($source, $start, $end === false ? null : $end - $start);

            self::assertStringContainsString('tpv:backup:database --keep=N', $note, $doc);
            self::assertStringContainsString('más de 7', $note, $doc);
            self::assertStringNotContainsString('no se rompe nada', $note, $doc);
        }
    }
}
