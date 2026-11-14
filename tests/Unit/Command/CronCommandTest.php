<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Command;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use VenPosTPV\Command\CronCommand;
use VenPosTPV\Service\Cron\CronLock;
use VenPosTPV\Service\Cron\CronRunner;
use VenPosTPV\Service\Cron\CronStateStore;

/** tpv:cron runs the existing commands that are due (odd/tasks/single-cron-task.md). */
final class CronCommandTest extends TestCase
{
    private string $lockDir;

    /** @var list<string> command name and its options, per run */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->lockDir = sys_get_temp_dir() . '/venpos-cron-cmd-' . bin2hex(random_bytes(6));
        mkdir($this->lockDir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->lockDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->lockDir);
    }

    /**
     * @param array<string, int> $exitCodes per command name; 0 when absent
     * @param list<string>       $missing   commands the application does not have
     */
    private function tester(array $exitCodes = [], array $missing = []): CommandTester
    {
        // A file, not ':memory:': the runner closes the connection between jobs.
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->lockDir . '/state-' . bin2hex(random_bytes(4)) . '.sqlite']);
        $connection->executeStatement('CREATE TABLE app_setting (name VARCHAR(64) PRIMARY KEY, value TEXT)');
        $runner = new CronRunner(new CronStateStore($connection), new CronLock($this->lockDir), 'Europe/Madrid');

        $application = new Application();
        foreach (['tpv:sync:outbox', 'tpv:catalog:sync', 'tpv:customers:sync', 'tpv:backup:database'] as $name) {
            if (!\in_array($name, $missing, true)) {
                $application->add($this->fakeCommand($name, $exitCodes[$name] ?? 0));
            }
        }
        $application->add(new CronCommand($runner));

        return new CommandTester($application->find('tpv:cron'));
    }

    private function fakeCommand(string $name, int $exitCode): Command
    {
        $command = new Command($name);
        $command->addOption('full', null, InputOption::VALUE_NONE);
        $command->addOption('stock-only', null, InputOption::VALUE_NONE);
        $command->setCode(function (InputInterface $input, OutputInterface $output) use ($name, $exitCode): int {
            $options = array_keys(array_filter(['--full' => $input->getOption('full'), '--stock-only' => $input->getOption('stock-only')]));
            $this->calls[] = trim($name . ' ' . implode(' ', $options));
            $output->writeln("{$name} says hello");
            if ($exitCode !== 0) {
                $output->writeln("{$name} failed: shop unreachable");
            }

            return $exitCode;
        });

        return $command;
    }

    public function testAFirstRunLaunchesEveryCommandWithItsOptionsAndShowsTheirOutput(): void
    {
        $tester = $this->tester();

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([
            'tpv:sync:outbox',
            'tpv:catalog:sync --stock-only',
            'tpv:catalog:sync --full',
            'tpv:customers:sync',
            'tpv:backup:database',
        ], $this->calls);
        $display = $tester->getDisplay();
        foreach (['tpv:sync:outbox', 'tpv:catalog:sync', 'tpv:customers:sync', 'tpv:backup:database'] as $name) {
            self::assertStringContainsString("{$name} says hello", $display);
        }
    }

    public function testAFailingJobGivesExitCodeOneAndTheOthersStillRun(): void
    {
        $tester = $this->tester(['tpv:sync:outbox' => 1]);

        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertCount(5, $this->calls);
        self::assertStringContainsString('tpv:sync:outbox failed: shop unreachable', $tester->getDisplay());
    }

    public function testASkippedJobIsReportedOnlyInVerboseMode(): void
    {
        $held = fopen($this->lockDir . '/cron-stock.lock', 'c');
        self::assertTrue(flock($held, LOCK_EX | LOCK_NB));

        try {
            $quiet = $this->tester();
            $quiet->execute([]);
            self::assertStringNotContainsString('Skipped', $quiet->getDisplay());

            $verbose = $this->tester();
            $verbose->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
            self::assertSame(Command::SUCCESS, $verbose->getStatusCode());
            self::assertStringContainsString('Skipped stock', $verbose->getDisplay());
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }
        self::assertNotContains('tpv:catalog:sync --stock-only', $this->calls);
    }

    public function testEachFailedJobIsWrittenToTheErrorOutputForThePanelsErrorMail(): void
    {
        $tester = $this->tester(['tpv:sync:outbox' => 1, 'tpv:catalog:sync' => 1]);

        $tester->execute([], ['capture_stderr_separately' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(
            "tpv:cron: outbox (tpv:sync:outbox) failed: tpv:sync:outbox failed: shop unreachable\n"
            . "tpv:cron: stock (tpv:catalog:sync --stock-only) failed: tpv:catalog:sync failed: shop unreachable\n"
            . "tpv:cron: catalog (tpv:catalog:sync --full) failed: tpv:catalog:sync failed: shop unreachable\n",
            str_replace(\PHP_EOL, "\n", $tester->getErrorOutput())
        );
        self::assertStringContainsString('tpv:sync:outbox failed: shop unreachable', $tester->getDisplay(), 'the command\'s own output still shows');
    }

    public function testWhenNothingFailsNothingIsWrittenToTheErrorOutput(): void
    {
        $tester = $this->tester();

        $tester->execute([], ['capture_stderr_separately' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('', $tester->getErrorOutput());
    }

    public function testAMissingCommandIsThatJobsFailureAndTheOthersStillRun(): void
    {
        $tester = $this->tester([], ['tpv:customers:sync']);

        $tester->execute([], ['capture_stderr_separately' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(['tpv:sync:outbox', 'tpv:catalog:sync --stock-only', 'tpv:catalog:sync --full', 'tpv:backup:database'], $this->calls);
        self::assertStringContainsString('tpv:cron: customers (tpv:customers:sync) failed: ', $tester->getErrorOutput());
        self::assertStringContainsString('tpv:customers:sync', $tester->getDisplay());
    }
}
