<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use VenPosTPV\Command\DatabaseBackupCommand;
use VenPosTPV\Service\Backup\BackupResult;
use VenPosTPV\Service\Backup\DatabaseBackup;
use VenPosTPV\Service\Backup\HealthReport;
use VenPosTPV\Service\Mail\BackupAlertMailer;
use VenPosTPV\Service\Mail\TicketMailException;

final class DatabaseBackupCommandTest extends TestCase
{
    public function testPrintsOneLineWithTheFileSizeAndTableCount(): void
    {
        $backup = $this->createMock(DatabaseBackup::class);
        $backup->expects($this->once())->method('run')->with(7)
            ->willReturn(new BackupResult('/srv/tpv/backups/venpostpv-20261105-040000.sql.gz', 1_572_864, 42, new HealthReport(42, 120, [])));
        $tester = new CommandTester(new DatabaseBackupCommand($backup));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame("Database checks passed: 42 tables, fiscal chain OK (120 records)\nBackup venpostpv-20261105-040000.sql.gz: 1.5 MB, 42 tables\n", $tester->getDisplay());
    }

    public function testSmallFilesAreShownInKilobytes(): void
    {
        $backup = $this->createMock(DatabaseBackup::class);
        $backup->method('run')->willReturn(new BackupResult('/b/venpostpv-20261105-040000.sql.gz', 2048, 1, new HealthReport(1, null, [])));
        $tester = new CommandTester(new DatabaseBackupCommand($backup));

        $tester->execute([]);
        $this->assertSame("Database checks passed: 1 tables, no fiscal records table\nBackup venpostpv-20261105-040000.sql.gz: 2.0 KB, 1 tables\n", $tester->getDisplay());
    }

    public function testKeepOption(): void
    {
        $backup = $this->createMock(DatabaseBackup::class);
        $backup->expects($this->once())->method('run')->with(3)->willReturn(new BackupResult('/b/venpostpv-20261105-040000.sql.gz', 10, 1, new HealthReport(1, null, [])));

        $this->assertSame(Command::SUCCESS, (new CommandTester(new DatabaseBackupCommand($backup)))->execute(['--keep' => '3']));
    }

    public function testInvalidKeepRunsNothing(): void
    {
        $backup = $this->createMock(DatabaseBackup::class);
        $backup->expects($this->never())->method('run');
        $tester = new CommandTester(new DatabaseBackupCommand($backup));

        $this->assertSame(Command::FAILURE, $tester->execute(['--keep' => '0']));
        $this->assertSame("--keep must be a positive integer\n", $tester->getDisplay());
    }

    public function testAFailureExitsWithOneAndOnlyTheMessage(): void
    {
        $backup = $this->createMock(DatabaseBackup::class);
        $backup->method('run')->willThrowException(new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused'));
        $tester = new CommandTester(new DatabaseBackupCommand($backup));

        $this->assertSame(1, $tester->execute([]));
        $this->assertSame("Backup failed: SQLSTATE[HY000] [2002] Connection refused\n", $tester->getDisplay());
    }

    public function testProblemsFoundByTheChecksArePrintedAndExitWithOne(): void
    {
        $backup = $this->createMock(DatabaseBackup::class);
        $backup->method('run')->willReturn(new BackupResult('/b/venpostpv-20261105-040000-REVISAR.sql.gz', 2048, 42, new HealthReport(42, 7, [
            'Table order_meta: Corrupt',
            'Fiscal chain broken at record 5: La huella no coincide con los datos del registro (registro modificado)',
        ])));
        $tester = new CommandTester(new DatabaseBackupCommand($backup));

        $this->assertSame(1, $tester->execute([]));
        $this->assertSame(
            "Database checks found problems (old backups were not deleted):\n"
            . "- Table order_meta: Corrupt\n"
            . "- Fiscal chain broken at record 5: La huella no coincide con los datos del registro (registro modificado)\n"
            . "Backup venpostpv-20261105-040000-REVISAR.sql.gz: 2.0 KB, 42 tables\n",
            $tester->getDisplay()
        );
    }

    private function reviewResult(): BackupResult
    {
        return new BackupResult('/b/venpostpv-20261105-040000-REVISAR.sql.gz', 2048, 42, new HealthReport(42, null, ['Table order_meta: Corrupt']));
    }

    public function testAFailedBackupSendsTheFailureAlert(): void
    {
        $backup = $this->createMock(DatabaseBackup::class);
        $backup->method('run')->willThrowException(new \RuntimeException('Cannot write the backup file (is the disk full?).'));
        $alerts = $this->createMock(BackupAlertMailer::class);
        $alerts->expects($this->once())->method('send')->with(
            'VenPosTPV: copia de seguridad fallida',
            $this->callback(function (string $body): bool {
                $this->assertMatchesRegularExpression('/\AFecha: \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\n/', $body);
                $this->assertStringContainsString("Archivo: no se ha escrito ninguna copia.\n", $body);
                $this->assertStringContainsString("- Cannot write the backup file (is the disk full?).\n", $body);

                return true;
            })
        )->willReturn(true);
        $tester = new CommandTester(new DatabaseBackupCommand($backup, $alerts));

        $this->assertSame(1, $tester->execute([]));
        $this->assertSame("Backup failed: Cannot write the backup file (is the disk full?).\n", $tester->getDisplay());
    }

    public function testABackupMarkedForReviewSendsTheReviewAlert(): void
    {
        $backup = $this->createMock(DatabaseBackup::class);
        $backup->method('run')->willReturn($this->reviewResult());
        $alerts = $this->createMock(BackupAlertMailer::class);
        $alerts->expects($this->once())->method('send')->with(
            'VenPosTPV: revisar copia de seguridad',
            $this->callback(function (string $body): bool {
                $this->assertStringContainsString("Archivo: venpostpv-20261105-040000-REVISAR.sql.gz (2.0 KB, 42 tablas)\n", $body);
                $this->assertStringContainsString('No se han borrado las copias anteriores.', $body);
                $this->assertStringContainsString("- Table order_meta: Corrupt\n", $body);

                return true;
            })
        )->willReturn(true);

        $this->assertSame(1, (new CommandTester(new DatabaseBackupCommand($backup, $alerts)))->execute([]));
    }

    public function testAHealthyBackupSendsNoAlert(): void
    {
        $backup = $this->createMock(DatabaseBackup::class);
        $backup->method('run')->willReturn(new BackupResult('/b/venpostpv-20261105-040000.sql.gz', 10, 1, new HealthReport(1, null, [])));
        $alerts = $this->createMock(BackupAlertMailer::class);
        $alerts->expects($this->never())->method('send');

        $this->assertSame(Command::SUCCESS, (new CommandTester(new DatabaseBackupCommand($backup, $alerts)))->execute([]));
    }

    public function testAnAlertThatCannotBeSentIsOneMoreLineAndChangesNothingElse(): void
    {
        $backup = $this->createMock(DatabaseBackup::class);
        $backup->method('run')->willReturn($this->reviewResult());
        $alerts = $this->createMock(BackupAlertMailer::class);
        $alerts->method('send')->willThrowException(new TicketMailException('El servidor de correo no aceptó el envío: auth', 502));
        $tester = new CommandTester(new DatabaseBackupCommand($backup, $alerts));

        $this->assertSame(1, $tester->execute([]));
        $this->assertSame(
            "Database checks found problems (old backups were not deleted):\n"
            . "- Table order_meta: Corrupt\n"
            . "Backup venpostpv-20261105-040000-REVISAR.sql.gz: 2.0 KB, 42 tables\n"
            . "Backup alert email not sent: El servidor de correo no aceptó el envío: auth\n",
            $tester->getDisplay()
        );
    }
}
