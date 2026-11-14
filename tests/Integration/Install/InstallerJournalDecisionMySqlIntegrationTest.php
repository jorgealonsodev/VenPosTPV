<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Install;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use VenPosTPV\Kernel;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalFreshStart;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuard;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuardCache;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIdentity;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalLocations;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalReset;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalResetResult;
use VenPosTPV\Service\Fiscal\Journal\FiscalReplay;
use VenPosTPV\Service\Install\WebInstaller;
use VenPosTPV\Service\PrestaShop\PsJournalClient;
use VenPosTPV\Service\Provisioning\ProvisioningReport;
use VenPosTPV\Service\Provisioning\ProvisioningRunner;
use VenPosTPV\Service\Provisioning\StepStatus;
use VenPosTPV\Service\Sale\TicketNumberer;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * A new install with an empty MySQL database against a shop that already holds an
 * earlier installation's fiscal journal. The shop is a local `php -S` running the
 * module's own JournalStore (fixtures/fake_shop_router.php), never a real shop.
 *
 * - The real installer page (in a PHP subprocess, on a copy of public/install.php)
 *   stops on the decision page, with nothing written but the migrations; a refused
 *   «Empezar de cero» keeps every field; pairing is not asked for again.
 * - What each exit does after .env is written, with the installed app (a test kernel
 *   whose journal folders and lock files live in the test folder): «restaurar»
 *   starts nothing, «empezar de cero» retires the shop's journals, records the reason
 *   and the installer, starts a journal and the first ticket is a new install's first.
 * - A shop with no journal lines, one that is down and an older module change nothing.
 */
final class InstallerJournalDecisionMySqlIntegrationTest extends TestCase
{
    private const SECRET = 'installer-journal-test-secret-0123456789';
    private const API_KEY = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ012345';
    private const PREVIOUS = '00000000-0000-4000-8000-0000000000f1';

    private ?Connection $server = null;
    private ?Connection $conn = null;
    private string $database = '';
    private string $project = '';
    private string $shopDir = '';
    private string $shopUrl = '';
    /** @var resource|null */
    private $shopProcess = null;
    private ?Kernel $kernel = null;
    /** @var array<string, string|null> */
    private array $saved = [];
    /** @var array{host: string, port: int, name: string, user: string, password: string} */
    private array $settings;

    protected function setUp(): void
    {
        $this->settings = TestDatabase::settings();
        try {
            $this->server = DriverManager::getConnection([
                'driver' => 'pdo_mysql', 'host' => $this->settings['host'], 'port' => $this->settings['port'],
                'user' => $this->settings['user'], 'password' => $this->settings['password'], 'charset' => 'utf8mb4',
            ]);
            $this->server->executeQuery('SELECT 1');
        } catch (\Throwable) {
            self::markTestSkipped('MySQL is not available.');
        }
        $this->database = $this->settings['name'] . '_install_jd_' . bin2hex(random_bytes(3));
        $this->server->executeStatement(sprintf('CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $this->database));

        $this->project = sys_get_temp_dir() . '/venpos-installer-journal-' . bin2hex(random_bytes(4));
        mkdir($this->project . '/public', 0700, true);
        $root = \dirname(__DIR__, 3);
        copy(getenv('VENPOS_INSTALLER_SOURCE') ?: $root . '/public/install.php', $this->project . '/public/install.php');
        copy($root . '/public/install-logo.php', $this->project . '/public/install-logo.php');
        symlink($root . '/vendor', $this->project . '/vendor');
        symlink($root . '/migrations', $this->project . '/migrations');

        $this->startShop();
        // The previous installation's journal: 5 tickets and 2 credit notes.
        $year = (int) date('Y');
        $rows = [];
        for ($seq = 1; $seq <= 7; ++$seq) {
            $credit = $seq > 5;
            $number = $credit ? sprintf('R%d-%06d', $year, $seq - 5) : TicketNumberer::format('A', $year, $seq);
            $rows[] = ['journal_id' => self::PREVIOUS, 'seq' => $seq, 'hash' => strtoupper(hash('sha256', $number)), 'number' => $number, 'series' => $credit ? 'R' . $year : TicketNumberer::series('A', $year)];
        }
        $this->shopRows($rows);

        $this->conn = DriverManager::getConnection([
            'driver' => 'pdo_mysql', 'host' => $this->settings['host'], 'port' => $this->settings['port'], 'dbname' => $this->database,
            'user' => $this->settings['user'], 'password' => $this->settings['password'], 'charset' => 'utf8mb4',
        ]);
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        foreach ($this->saved as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }
        if (\is_resource($this->shopProcess)) {
            proc_terminate($this->shopProcess);
            proc_close($this->shopProcess);
        }
        $this->conn?->close();
        if ($this->server !== null && $this->database !== '') {
            $this->server->executeStatement(sprintf('DROP DATABASE IF EXISTS `%s`', $this->database));
            $this->server->close();
        }
        foreach ([$this->project, $this->shopDir] as $dir) {
            if ($dir !== '') {
                (new Filesystem())->remove($dir);
            }
        }
    }

    private function startShop(): void
    {
        $this->shopDir = sys_get_temp_dir() . '/venpos-installer-shop-' . bin2hex(random_bytes(4));
        mkdir($this->shopDir, 0700);
        file_put_contents($this->shopDir . '/secret', self::SECRET);
        file_put_contents($this->shopDir . '/requests.log', '');
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        \assert($probe !== false);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $this->shopUrl = 'http://127.0.0.1:' . $port;
        $this->shopProcess = proc_open(
            [\PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fixtures/fake_shop_router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['FAKE_SHOP_DIR' => $this->shopDir] + getenv(),
        );
        for ($i = 0; $i < 100; ++$i) {
            $socket = @fsockopen('127.0.0.1', $port, $code, $message, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50000);
        }
        self::fail('The fake shop did not start.');
    }

    /** @param list<array<string, mixed>> $rows */
    private function shopRows(array $rows): void
    {
        file_put_contents($this->shopDir . '/store.json', json_encode($rows, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }

    /** @return list<string> the journal requests the shop received (their action) */
    private function journalRequests(): array
    {
        $lines = array_filter(explode("\n", (string) file_get_contents($this->shopDir . '/requests.log')));

        return array_values(array_map(static fn (string $l): string => trim(substr($l, \strlen('/module/tpvconnect/journal'))), array_filter($lines, static fn (string $l): bool => str_starts_with($l, '/module/tpvconnect/journal'))));
    }

    /** @return array<string, string> the installer form, manual keys */
    private function form(): array
    {
        return [
            'install_key' => 'KEY-1234',
            'db_host' => $this->settings['host'], 'db_port' => (string) $this->settings['port'], 'db_name' => $this->database,
            'db_user' => $this->settings['user'], 'db_password' => $this->settings['password'],
            'tpv_url' => 'http://localhost', 'admin_user' => 'admin', 'admin_password' => "admin's <long> password",
            'store_name' => 'Tienda & Cía', 'store_address' => 'Calle Mayor 1',
            'ps_mode' => 'manual', 'ps_url' => $this->shopUrl, 'ps_api_key' => self::API_KEY, 'sso_secret' => self::SECRET,
            'ps_currency' => '1', 'ps_shop' => '1', 'timezone' => 'Europe/Madrid',
        ];
    }

    /** The real page, in its own PHP process, as InstallerFormRefillTest runs it. */
    private function request(array $post): string
    {
        file_put_contents($this->project . '/INSTALL_KEY', "KEY-1234\n");
        $public = $this->project . '/public';
        file_put_contents($public . '/run.php', '<?php'
            . ' $_SERVER["REQUEST_METHOD"] = "POST";'
            . ' $_SERVER["HTTP_HOST"] = "localhost"; $_SERVER["REMOTE_ADDR"] = "127.0.0.1";'
            . ' $_SERVER["DOCUMENT_ROOT"] = ' . var_export($public, true) . ';'
            . ' $_POST = ' . var_export($post, true) . ';'
            . ' require __DIR__ . "/install.php";');
        $output = [];
        exec(escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg($public . '/run.php') . ' 2>&1', $output);

        return implode("\n", $output);
    }

    private static function fieldValue(string $html, string $name): string
    {
        self::assertSame(1, preg_match('/<input\b[^>]*\bname="' . preg_quote($name, '/') . '"[^>]*>/', $html, $tag), "field {$name} is in the page");

        return preg_match('/\bvalue="([^"]*)"/', $tag[0], $value) === 1 ? html_entity_decode($value[1], \ENT_QUOTES | \ENT_HTML5, 'UTF-8') : '';
    }

    private function assertNothingStarted(): void
    {
        $this->assertFileDoesNotExist($this->project . '/.env');
        $this->assertFileDoesNotExist($this->project . '/var/install.lock');
        $this->assertDirectoryDoesNotExist($this->project . '/backups/fiscal-journal');
        $this->assertDirectoryDoesNotExist($this->project . '/var/fiscal-journal');
        $this->assertFalse($this->conn->fetchOne("SELECT value FROM app_setting WHERE name = 'fiscal_journal.id'"));
        $this->assertFalse($this->conn->fetchOne("SELECT value FROM app_setting WHERE name = 'fiscal_journal.retired'"));
        $this->assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM audit_log WHERE action = ?', [FiscalJournalReset::AUDIT_ACTION]));
    }

    public function testTheInstallStopsOnTheDecisionPageWithWhatTheShopHoldsAndNothingStarted(): void
    {
        $html = $this->request($this->form());

        $this->assertStringContainsString('La tienda ya tiene documentos fiscales', $html);
        $this->assertStringContainsString('7 documentos fiscales', $html);
        $year = (int) date('Y');
        $this->assertStringContainsString('<td>A' . sprintf('%02d', $year % 100) . '</td><td>' . TicketNumberer::format('A', $year, 5) . '</td>', $html);
        $this->assertStringContainsString('<td>R' . $year . '</td><td>' . sprintf('R%d-%06d', $year, 2) . '</td>', $html);
        $this->assertStringContainsString('Voy a restaurar una copia de seguridad', $html);
        $this->assertStringContainsString('Empezar de cero', $html);
        $this->assertStringContainsString('empieza otra vez en 1', $html);
        // Every field goes back (escaped), the keys of the shop in manual mode.
        $this->assertSame("admin's <long> password", self::fieldValue($html, 'admin_password'));
        $this->assertSame($this->settings['password'], self::fieldValue($html, 'db_password'));
        $this->assertSame('KEY-1234', self::fieldValue($html, 'install_key'));
        $this->assertSame('Tienda & Cía', self::fieldValue($html, 'store_name'));
        $this->assertSame('manual', self::fieldValue($html, 'ps_mode'));
        $this->assertSame(self::SECRET, self::fieldValue($html, 'sso_secret'));
        $this->assertStringNotContainsString('<long>', $html);
        $this->assertSame(['heads'], $this->journalRequests());
        $this->assertGreaterThan(0, (int) self::fieldValue($html, 'migrated'), 'the migrations ran and are counted for the last page');
        $this->assertNothingStarted();
    }

    public function testAFreshStartWithoutTheCheckboxOrTheReasonIsRefusedAndKeepsEveryField(): void
    {
        $first = $this->request($this->form());
        $post = $this->form() + ['migrated' => self::fieldValue($first, 'migrated'), 'fiscal_journal_choice' => 'fresh', 'fiscal_journal_reason' => '<b>Reinstalación</b> "desde cero"'];

        $html = $this->request($post);

        $this->assertStringContainsString('La tienda ya tiene documentos fiscales', $html);
        $this->assertStringContainsString('marca la casilla', $html);
        $this->assertSame('<b>Reinstalación</b> "desde cero"', self::fieldValue($html, 'fiscal_journal_reason'));
        $this->assertStringNotContainsString('<b>Reinstalación', $html);
        $this->assertSame("admin's <long> password", self::fieldValue($html, 'admin_password'));
        $this->assertSame($this->database, self::fieldValue($html, 'db_name'));

        $html = $this->request(['fiscal_journal_confirm' => '1', 'fiscal_journal_reason' => '  '] + $post);
        $this->assertStringContainsString('escribe el motivo', $html);
        $this->assertStringContainsString('checked', (string) preg_replace('/.*(<input[^>]*name="fiscal_journal_confirm"[^>]*>).*/s', '$1', $html));
        $this->assertNothingStarted();
    }

    public function testAPairedInstallIsNotPairedAgainOnTheDecisionPage(): void
    {
        file_put_contents($this->shopDir . '/pair.json', json_encode(['api_key' => self::API_KEY, 'sso_secret' => self::SECRET, 'shop_id' => 2, 'currency_id' => 3, 'timezone' => 'Atlantic/Canary', 'module_version' => '0.9.0'], \JSON_THROW_ON_ERROR));
        $form = ['ps_mode' => 'pair', 'pair_code' => '123456', 'ps_api_key' => '', 'sso_secret' => ''] + $this->form();

        $html = $this->request($form);

        $this->assertStringContainsString('La tienda ya tiene documentos fiscales', $html);
        $this->assertSame('manual', self::fieldValue($html, 'ps_mode'));
        $this->assertSame(self::API_KEY, self::fieldValue($html, 'ps_api_key'));
        $this->assertSame(self::SECRET, self::fieldValue($html, 'sso_secret'));
        $this->assertSame('2', self::fieldValue($html, 'ps_shop'));
        $this->assertSame('3', self::fieldValue($html, 'ps_currency'));
        $this->assertSame('Atlantic/Canary', self::fieldValue($html, 'timezone'));
        $this->assertSame('1', self::fieldValue($html, 'paired'));

        // The decision page's own submit (here refused) sends the keys, never the code again.
        $again = [];
        preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)">/', $html, $m, \PREG_SET_ORDER);
        foreach ($m as [, $name, $value]) {
            $again[$name] = html_entity_decode($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        }
        $this->request(['fiscal_journal_choice' => 'fresh'] + $again);
        $pairs = array_filter(explode("\n", (string) file_get_contents($this->shopDir . '/requests.log')), static fn (string $l): bool => str_starts_with($l, '/module/tpvconnect/pair'));
        $this->assertCount(1, $pairs);
        $this->assertNothingStarted();
    }

    private function stopShop(): void
    {
        if (\is_resource($this->shopProcess)) {
            proc_terminate($this->shopProcess);
            proc_close($this->shopProcess);
        }
        $this->shopProcess = null;
    }

    /**
     * `.env` as a folder: the page's finish() cannot create the file and says so, so a
     * page that goes past the shop check stops there, before any journal or the
     * provisioning (which would boot the real app on the real project folder).
     */
    private function blockEnvFile(): void
    {
        mkdir($this->project . '/.env');
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function submittedChoices(): iterable
    {
        yield 'restore' => [['fiscal_journal_choice' => 'restore']];
        yield 'fresh' => [['fiscal_journal_choice' => 'fresh', 'fiscal_journal_confirm' => '1', 'fiscal_journal_reason' => 'Reinstalación desde cero']];
    }

    /**
     * The choice is made on the decision page, then the shop cannot be asked at the
     * second submit: the installer never goes on as if the shop held nothing (after
     * «Empezar de cero» that would start a journal without retiring the old one).
     *
     * @param array<string, string> $choice
     */
    #[DataProvider('submittedChoices')]
    public function testASubmittedChoiceIsNeverDroppedWhenTheShopCannotBeAskedThen(array $choice): void
    {
        $first = $this->request($this->form());
        $this->assertStringContainsString('La tienda ya tiene documentos fiscales', $first);
        $this->stopShop();
        $this->blockEnvFile();

        $html = $this->request($choice + ['migrated' => self::fieldValue($first, 'migrated')] + $this->form());

        $this->assertStringContainsString('No se ha podido preguntar a la tienda', $html);
        $this->assertStringNotContainsString('No se pudo crear el archivo .env', $html, 'the install did not go on');
        $this->assertStringNotContainsString('TPV instalado', $html);
        $this->assertSame($choice['fiscal_journal_choice'], self::fieldValue($html, 'fiscal_journal_choice'));
        $this->assertSame("admin's <long> password", self::fieldValue($html, 'admin_password'));
        $this->assertSame(self::fieldValue($first, 'migrated'), self::fieldValue($html, 'migrated'));
        $this->assertDirectoryExists($this->project . '/.env');
        $this->assertFileDoesNotExist($this->project . '/var/install.lock');
        $this->assertDirectoryDoesNotExist($this->project . '/backups/fiscal-journal');
        $this->assertDirectoryDoesNotExist($this->project . '/var/fiscal-journal');
        $this->assertFalse($this->conn->fetchOne("SELECT value FROM app_setting WHERE name = 'fiscal_journal.id'"));
        $this->assertFalse($this->conn->fetchOne("SELECT value FROM app_setting WHERE name = 'fiscal_journal.retired'"));
        $this->assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM audit_log WHERE action = ?', [FiscalJournalReset::AUDIT_ACTION]));
    }

    /** Without a choice, a shop that cannot be asked leaves the page as before: it goes on to write .env. */
    public function testWithoutAChoiceAShopThatCannotBeAskedLetsThePageGoOnAsBefore(): void
    {
        $this->stopShop();
        $this->blockEnvFile();

        $html = $this->request($this->form());

        $this->assertStringContainsString('No se pudo crear el archivo .env', $html);
        $this->assertStringNotContainsString('La tienda ya tiene documentos fiscales', $html);
        $this->assertStringNotContainsString('No se ha podido preguntar a la tienda', $html);
    }

    /** The test kernel of the installed app: journal folders and lock files in the test project. */
    private function bootInstalled(?string $shopUrl = null): Kernel
    {
        $installer = new WebInstaller($this->project);
        $values = $installer->validate(['ps_url' => $shopUrl ?? $this->shopUrl] + $this->form())['values'];
        $values['MESSENGER_TRANSPORT_DSN'] = 'sync://';
        $values['APP_ENV'] = 'test';
        foreach ($values as $name => $value) {
            if (!\array_key_exists($name, $this->saved)) {
                $this->saved[$name] = isset($_ENV[$name]) ? (string) $_ENV[$name] : null;
            }
            $_ENV[$name] = $value;
        }
        $installer->migrate($this->conn);

        $dir = $this->project;
        $this->kernel = new class ('test', false, $dir) extends Kernel {
            public function __construct(string $environment, bool $debug, private readonly string $dir)
            {
                parent::__construct($environment, $debug);
            }

            public function getCacheDir(): string
            {
                return $this->dir . '/var/cache';
            }

            public function getLogDir(): string
            {
                return $this->dir . '/var/log';
            }

            protected function build(ContainerBuilder $container): void
            {
                parent::build($container);
                $container->addCompilerPass(new class ($this->dir) implements CompilerPassInterface {
                    public function __construct(private readonly string $dir)
                    {
                    }

                    public function process(ContainerBuilder $container): void
                    {
                        $container->getDefinition(FiscalJournalLocations::class)
                            ->setArgument('$primary', $this->dir . '/backups/fiscal-journal')
                            ->setArgument('$secondary', $this->dir . '/var/fiscal-journal');
                        $container->getDefinition(FiscalJournalReset::class)->setArgument('$replayLockPath', $this->dir . '/var/fiscal-replay.lock');
                        $container->getDefinition(FiscalReplay::class)->setArgument('$lockPath', $this->dir . '/var/fiscal-replay.lock');
                        $container->getDefinition(FiscalJournalGuardCache::class)->setArgument('$file', $this->dir . '/var/fiscal-journal-guard.json');
                    }
                }, PassConfig::TYPE_BEFORE_OPTIMIZATION, 1000);
            }
        };
        $this->kernel->boot();

        return $this->kernel;
    }

    private function provision(WebInstaller $installer, Kernel $kernel): ProvisioningReport
    {
        $report = $installer->provision(static function () use ($kernel): ProvisioningRunner {
            $runner = $kernel->getContainer()->get(ProvisioningRunner::class);
            \assert($runner instanceof ProvisioningRunner);

            return $runner;
        });
        $this->assertNotNull($report);

        return $report;
    }

    private static function step(ProvisioningReport $report, string $id): object
    {
        foreach ($report->steps as $step) {
            if ($step->id === $id) {
                return $step;
            }
        }
        self::fail('no step ' . $id);
    }

    private function guard(): FiscalJournalGuard
    {
        return new FiscalJournalGuard($this->conn, new FiscalJournalLocations($this->project . '/backups/fiscal-journal', $this->project . '/var/fiscal-journal'), null, new FiscalJournalIdentity($this->conn));
    }

    private function freshStart(WebInstaller $installer, Kernel $kernel, string $reason): FiscalJournalResetResult
    {
        return $installer->startJournalAfresh($reason, static function () use ($kernel): FiscalJournalFreshStart {
            $freshStart = $kernel->getContainer()->get(FiscalJournalFreshStart::class);
            \assert($freshStart instanceof FiscalJournalFreshStart);

            return $freshStart;
        });
    }

    public function testRestoreStartsNoJournalAndTheTillStaysBlocked(): void
    {
        $kernel = $this->bootInstalled();
        $installer = new WebInstaller($this->project);

        $step = self::step($this->provision($installer, $kernel), 'fiscal-journal');

        $this->assertSame(StepStatus::FAILED, $step->status);
        $this->assertStringContainsString('docs/diario-fiscal.md', (string) $step->reason);
        $this->assertFileDoesNotExist($this->project . '/backups/fiscal-journal/journal.id');
        $this->assertFalse($this->conn->fetchOne("SELECT value FROM app_setting WHERE name = 'fiscal_journal.id'"));
        $this->assertFalse($this->conn->fetchOne("SELECT value FROM app_setting WHERE name = 'fiscal_journal.retired'"));
        $this->assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM audit_log WHERE action = ?', [FiscalJournalReset::AUDIT_ACTION]));
        $this->assertSame(FiscalJournalGuard::BEHIND_SHOP, $this->guard()->check()['state']);
    }

    public function testAFreshStartRetiresTheShopsJournalsRecordsItAndANewInstallIssuesItsFirstTicket(): void
    {
        $kernel = $this->bootInstalled();
        $installer = new WebInstaller($this->project);

        $result = $this->freshStart($installer, $kernel, 'Reinstalación desde cero: servidor nuevo');
        $this->assertSame(FiscalJournalResetResult::DONE, $result->status, $result->message);
        $step = self::step($this->provision($installer, $kernel), 'fiscal-journal');

        $this->assertSame(StepStatus::DONE, $step->status, (string) $step->reason);
        $identity = new FiscalJournalIdentity($this->conn);
        $this->assertSame([self::PREVIOUS], $identity->retired());
        $this->assertSame($result->journalId, $identity->journalId());
        $journal = json_decode((string) file_get_contents($this->project . '/backups/fiscal-journal/journal.id'), true);
        $this->assertSame($result->journalId, $journal['journal_id']);
        $audit = $this->conn->fetchAllAssociative('SELECT payload FROM audit_log WHERE action = ?', [FiscalJournalReset::AUDIT_ACTION]);
        $this->assertCount(1, $audit);
        $payload = json_decode((string) $audit[0]['payload'], true);
        $this->assertSame('Reinstalación desde cero: servidor nuevo', $payload['reason']);
        $this->assertSame('installer', $payload['origin']);
        $this->assertSame([self::PREVIOUS], $payload['accept_shop_ahead']['retired_journals']);

        // The till may sell, and its first ticket is a new installation's first (the shop's A..000001 again, by design).
        $guard = $this->guard();
        $this->assertSame(FiscalJournalGuard::OK, $guard->check()['state']);
        $guard->assertCanIssue();
        $this->conn->insert('terminal', ['id' => 1, 'ps_shop_id' => 1, 'name' => 'Caja 1', 'ticket_letter' => 'A', 'active' => 1]);
        $numberer = new TicketNumberer($this->conn);
        $now = new \DateTimeImmutable();
        $numberer->prepareYear(1, $now);
        $number = $this->conn->transactional(static fn (): string => $numberer->next(1, $now));
        $this->assertSame(TicketNumberer::format('A', (int) $now->format('Y'), 1), $number);
    }

    public function testASecondSubmitOrALaterInstallerRunNeverRetiresOrRestartsTheJournal(): void
    {
        $kernel = $this->bootInstalled();
        $installer = new WebInstaller($this->project);
        $first = $this->freshStart($installer, $kernel, 'Reinstalación');
        $this->assertSame(FiscalJournalResetResult::DONE, $first->status, $first->message);

        $second = $this->freshStart($installer, $kernel, 'Reinstalación');

        $this->assertSame(FiscalJournalResetResult::REFUSED, $second->status);
        $this->assertSame($first->journalId, (new FiscalJournalIdentity($this->conn))->journalId());
        $this->assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM audit_log WHERE action = ?', [FiscalJournalReset::AUDIT_ACTION]));
        $this->assertDirectoryDoesNotExist($this->project . '/backups/fiscal-journal/archive');
        // The installer run again later never shows the decision page for this database.
        $this->assertNull($installer->shopJournalAhead($this->conn, new PsJournalClient($this->shopUrl, self::SECRET, HttpClient::create())));
    }

    /** @return iterable<string, array{string}> */
    public static function shopsThatChangeNothing(): iterable
    {
        yield 'no journal lines' => ['empty'];
        yield 'older module' => ['old'];
        yield 'shop down' => ['down'];
    }

    #[DataProvider('shopsThatChangeNothing')]
    public function testAShopWithoutLinesDownOrWithAnOlderModuleLeavesTheInstallAsBefore(string $shop): void
    {
        if ($shop === 'empty') {
            $this->shopRows([]);
        }
        if ($shop === 'old') {
            file_put_contents($this->shopDir . '/mode', 'old');
        }
        $url = $shop === 'down' ? 'http://127.0.0.1:1' : $this->shopUrl;
        $installer = new WebInstaller($this->project);
        $installer->migrate($this->conn);

        $this->assertNull($installer->shopJournalAhead($this->conn, new PsJournalClient($url, self::SECRET, HttpClient::create())));

        // And the journal step starts the journal, as today.
        $kernel = $this->bootInstalled($url);
        $step = self::step($this->provision($installer, $kernel), 'fiscal-journal');
        $this->assertSame(StepStatus::DONE, $step->status, (string) $step->reason);
        $this->assertNotFalse($this->conn->fetchOne("SELECT value FROM app_setting WHERE name = 'fiscal_journal.id'"));
        $this->assertSame([], (new FiscalJournalIdentity($this->conn))->retired());
    }
}
