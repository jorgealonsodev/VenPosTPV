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
        $reset->expects($this->once())->method('run')->with('Servidor nuevo', null, false, false)->willReturn(
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

    public function testShopUnavailableIsPassedToTheServiceOnlyWhenGiven(): void
    {
        $reset = $this->createMock(FiscalJournalReset::class);
        $reset->expects($this->once())->method('run')->with('Tienda sin el módulo 0.9.0', null, true, false)->willReturn(
            new FiscalJournalResetResult(FiscalJournalResetResult::DONE, 'Diario fiscal nuevo 1234: 3 documentos anotados.', '1234', 3)
        );

        $this->assertSame(0, $this->tester($reset)->execute(['--confirm' => 'Tienda sin el módulo 0.9.0', '--shop-unavailable' => true]));
    }

    public function testTheShopUnavailableOptionWarnsThatNumbersMayRepeat(): void
    {
        $command = new FiscalJournalResetCommand($this->createMock(FiscalJournalReset::class));
        $option = $command->getDefinition()->getOption('shop-unavailable');

        $this->assertFalse($option->acceptValue());
        $this->assertStringContainsString('se pueden repetir números', $option->getDescription());
        $this->assertStringContainsString('copia anterior', $option->getDescription());
        $this->assertStringContainsString('--shop-unavailable', $command->getHelp());
    }

    public function testAcceptShopAheadIsPassedOnlyWhenGivenAndItsHelpWarnsNumbersMayBeIssuedAgain(): void
    {
        $reset = $this->createMock(FiscalJournalReset::class);
        $reset->expects($this->once())->method('run')->with('Reinstalación desde cero', null, false, true)->willReturn(
            new FiscalJournalResetResult(FiscalJournalResetResult::DONE, 'Diario fiscal nuevo 1234: 0 documentos anotados.', '1234', 0)
        );
        $this->assertSame(0, $this->tester($reset)->execute(['--confirm' => 'Reinstalación desde cero', '--accept-shop-ahead' => true]));

        $command = new FiscalJournalResetCommand($this->createMock(FiscalJournalReset::class));
        $description = $command->getDefinition()->getOption('accept-shop-ahead')->getDescription();
        $this->assertStringContainsString('la tienda tenga documentos que esta base de datos no tiene', $description);
        $this->assertStringContainsString('se pueden volver a emitir', $description);
        $this->assertStringContainsString('nunca después de restaurar una copia de seguridad', $description);
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
