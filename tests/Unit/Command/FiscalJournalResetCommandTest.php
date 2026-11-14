<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use VenPosTPV\Command\FiscalJournalResetCommand;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalReset;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalResetResult;

/** tpv:fiscal:journal:reset --confirm "<motivo>" (fiscal-journal A6). */
final class FiscalJournalResetCommandTest extends TestCase
{
    private function tester(FiscalJournalReset $reset): CommandTester
    {
        $application = new Application();
        $application->add(new FiscalJournalResetCommand($reset));

        return new CommandTester($application->find('tpv:fiscal:journal:reset'));
    }

    private static function display(CommandTester $tester): string
    {
        return str_replace(\PHP_EOL, "\n", $tester->getDisplay());
    }

    public function testWithoutConfirmItExplainsAndDoesNothing(): void
    {
        $reset = $this->createMock(FiscalJournalReset::class);
        $reset->expects($this->never())->method('run');
        $tester = $this->tester($reset);

        $this->assertSame(2, $tester->execute([]));
        $this->assertStringContainsString('--confirm "<motivo>"', self::display($tester));
    }

    public function testAnEmptyReasonIsRejectedBeforeTheService(): void
    {
        $reset = $this->createMock(FiscalJournalReset::class);
        $reset->expects($this->never())->method('run');
        $tester = $this->tester($reset);

        $this->assertSame(2, $tester->execute(['--confirm' => '   ']));
        $this->assertStringContainsString('--confirm "<motivo>"', self::display($tester));
    }

    public function testItRunsWithTheReasonAndPrintsTheOutcome(): void
    {
        $reset = $this->createMock(FiscalJournalReset::class);
        $reset->expects($this->once())->method('run')->with('Servidor nuevo')->willReturn(
            new FiscalJournalResetResult(FiscalJournalResetResult::DONE, 'Diario fiscal nuevo 1234: 3 documentos anotados.', '1234', 3, ['backups/fiscal-journal/archive/x'])
        );
        $tester = $this->tester($reset);

        $this->assertSame(0, $tester->execute(['--confirm' => 'Servidor nuevo']));
        $display = self::display($tester);
        $this->assertStringContainsString('Diario fiscal nuevo 1234: 3 documentos anotados.', $display);
        $this->assertStringContainsString('backups/fiscal-journal/archive/x', $display);
    }

    public function testARefusalExitsThree(): void
    {
        $reset = $this->createMock(FiscalJournalReset::class);
        $reset->method('run')->willReturn(new FiscalJournalResetResult(FiscalJournalResetResult::REFUSED, 'La base de datos está por detrás del diario fiscal.'));
        $tester = $this->tester($reset);

        $this->assertSame(3, $tester->execute(['--confirm' => 'Motivo']));
        $this->assertStringContainsString('La base de datos está por detrás del diario fiscal.', self::display($tester));
    }

    public function testTheHelpListsTheExitCodes(): void
    {
        $help = (new FiscalJournalResetCommand($this->createMock(FiscalJournalReset::class)))->getHelp();

        $this->assertStringContainsString('archive/', $help);
        foreach (['0 ', '1 ', '2 ', '3 '] as $code) {
            $this->assertStringContainsString('  ' . $code, $help);
        }
    }
}
