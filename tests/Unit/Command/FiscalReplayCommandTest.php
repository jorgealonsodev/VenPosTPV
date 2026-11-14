<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use VenPosTPV\Command\FiscalReplayCommand;
use VenPosTPV\Service\Fiscal\Journal\FiscalReplay;
use VenPosTPV\Service\Fiscal\Journal\FiscalReplayResult;

/** tpv:fiscal:replay, the guided replay of the fiscal journal (fiscal-journal D8.5 exit codes). */
final class FiscalReplayCommandTest extends TestCase
{
    private const DOCUMENTS = [
        ['seq' => 11, 'kind' => 'F2', 'number' => 'A26000011', 'generated_at' => '2026-11-14T18:02:10+01:00'],
        ['seq' => 12, 'kind' => 'R5', 'number' => 'R2026-000001', 'generated_at' => '2026-11-14T18:03:00+01:00'],
    ];

    private function tester(FiscalReplayResult $result, bool $apply): CommandTester
    {
        $replay = $this->createMock(FiscalReplay::class);
        $replay->expects($this->once())->method('run')->with($apply)->willReturn($result);
        $application = new Application();
        $application->add(new FiscalReplayCommand($replay));

        return new CommandTester($application->find('tpv:fiscal:replay'));
    }

    private static function display(CommandTester $tester): string
    {
        return str_replace(\PHP_EOL, "\n", $tester->getDisplay());
    }

    public function testByDefaultItIsADryRunThatListsTheDocumentsAndExitsTwo(): void
    {
        $tester = $this->tester(new FiscalReplayResult(FiscalReplayResult::PENDING, self::DOCUMENTS), false);

        $this->assertSame(2, $tester->execute([]));
        $display = self::display($tester);
        $this->assertStringContainsString('Documentos del diario fiscal que faltan en la base de datos: 2', $display);
        $this->assertStringContainsString('11  F2  A26000011  2026-11-14T18:02:10+01:00', $display);
        $this->assertStringContainsString('12  R5  R2026-000001  2026-11-14T18:03:00+01:00', $display);
        $this->assertStringContainsString('No se ha escrito nada. Para recuperarlos: tpv:fiscal:replay --apply', $display);
    }

    public function testDryRunCanBeAskedExplicitly(): void
    {
        $tester = $this->tester(new FiscalReplayResult(FiscalReplayResult::PENDING, self::DOCUMENTS), false);

        $this->assertSame(2, $tester->execute(['--dry-run' => true]));
    }

    public function testApplyRestoresAndExitsZero(): void
    {
        $tester = $this->tester(new FiscalReplayResult(FiscalReplayResult::DONE, self::DOCUMENTS), true);

        $this->assertSame(0, $tester->execute(['--apply' => true]));
        $display = self::display($tester);
        $this->assertStringContainsString('Recuperados 2 documentos (A26000011 … R2026-000001).', $display);
        $this->assertStringContainsString('La caja puede volver a emitir documentos.', $display);
        $this->assertStringContainsString('No se recuperan', $display, 'what the replay leaves out (D8.4)');
    }

    public function testNothingToDoExitsZero(): void
    {
        $tester = $this->tester(new FiscalReplayResult(FiscalReplayResult::NOTHING), false);

        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('Nada que recuperar', self::display($tester));
    }

    public function testABackupOlderThanTheJournalExitsThreeWithItsMessage(): void
    {
        $message = 'La copia restaurada es anterior al diario conservado (desde 14/11/2026). Restaura una copia más reciente.';
        $tester = $this->tester(new FiscalReplayResult(FiscalReplayResult::TOO_OLD, [], $message), true);

        $this->assertSame(3, $tester->execute(['--apply' => true], ['capture_stderr_separately' => true]));
        $this->assertSame($message . "\n", str_replace(\PHP_EOL, "\n", $tester->getErrorOutput()));
    }

    public function testAConflictExitsFour(): void
    {
        $tester = $this->tester(new FiscalReplayResult(FiscalReplayResult::CONFLICT, [], 'Conflicto: order_meta id 15 ya existe con otro contenido.'), true);

        $this->assertSame(4, $tester->execute(['--apply' => true], ['capture_stderr_separately' => true]));
        $this->assertStringContainsString('order_meta id 15', $tester->getErrorOutput());
    }

    public function testAnErrorAndAGuardStillActiveExitOne(): void
    {
        $tester = $this->tester(new FiscalReplayResult(FiscalReplayResult::ERROR, [], 'El diario fiscal está dañado.'), false);
        $this->assertSame(1, $tester->execute([], ['capture_stderr_separately' => true]));
        $this->assertStringContainsString('El diario fiscal está dañado.', $tester->getErrorOutput());

        $tester = $this->tester(new FiscalReplayResult(FiscalReplayResult::STILL_BLOCKED, self::DOCUMENTS, 'La caja sigue bloqueada: falta A26000013 en la base de datos.'), true);
        $this->assertSame(1, $tester->execute(['--apply' => true], ['capture_stderr_separately' => true]));
        $this->assertStringContainsString('La caja sigue bloqueada', $tester->getErrorOutput());
    }

    public function testApplyAndDryRunTogetherAreRefusedWithoutRunning(): void
    {
        $replay = $this->createMock(FiscalReplay::class);
        $replay->expects($this->never())->method('run');
        $application = new Application();
        $application->add(new FiscalReplayCommand($replay));
        $tester = new CommandTester($application->find('tpv:fiscal:replay'));

        $this->assertSame(1, $tester->execute(['--apply' => true, '--dry-run' => true], ['capture_stderr_separately' => true]));
    }

    public function testTheHelpListsEveryExitCode(): void
    {
        $application = new Application();
        $application->add(new FiscalReplayCommand($this->createMock(FiscalReplay::class)));
        $help = $application->find('tpv:fiscal:replay')->getHelp();

        $this->assertStringContainsString('0 hecho, o nada que recuperar', $help);
        $this->assertStringContainsString('1 error', $help);
        $this->assertStringContainsString('2 (sin --apply) hay documentos que recuperar', $help);
        $this->assertStringContainsString('3 la copia restaurada es anterior al diario conservado', $help);
        $this->assertStringContainsString('4 conflicto', $help);
    }

    /** The exit code of every status (D8.5). */
    public function testExitCodes(): void
    {
        $this->assertSame(0, (new FiscalReplayResult(FiscalReplayResult::DONE))->exitCode());
        $this->assertSame(0, (new FiscalReplayResult(FiscalReplayResult::NOTHING))->exitCode());
        $this->assertSame(1, (new FiscalReplayResult(FiscalReplayResult::ERROR))->exitCode());
        $this->assertSame(1, (new FiscalReplayResult(FiscalReplayResult::STILL_BLOCKED))->exitCode());
        $this->assertSame(2, (new FiscalReplayResult(FiscalReplayResult::PENDING))->exitCode());
        $this->assertSame(3, (new FiscalReplayResult(FiscalReplayResult::TOO_OLD))->exitCode());
        $this->assertSame(4, (new FiscalReplayResult(FiscalReplayResult::CONFLICT))->exitCode());
    }
}
