<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Psr\Log\LoggerInterface;

/**
 * Refuses to issue a fiscal document while the database is behind the fiscal
 * journal (fiscal-journal S5, D5, D7 `behind_journal`): after a restore of an older
 * backup the next ticket, credit note or invoice would take a number already issued.
 *
 * The evidence is what the journal knows was committed, read from both folders:
 * the last valid line, `head.json` (kept by the writer when lines are removed, and
 * never rewritten while the journal is damaged) and the intents marked
 * `confirmed`. Each document must be in `fiscal_record` with the same number,
 * type and hash. Only committed documents ever reach that evidence, so a journal
 * behind the database (normal lag) is never a block.
 *
 * A journal that cannot be read and a database error never block either (S2): the
 * check logs and lets the till issue.
 *
 * The shop's half (D6, D7, S8), checked after the journal's: `shop-head.json`, the
 * last document of every series of every journal the shop holds, stored by the
 * every-minute task, the reset and the provisioning step (FiscalJournalShopSync),
 * whatever journal id this database knows or none, but the retired journals
 * (FiscalJournalIdentity::retired). A document there that the database lacks is
 * `behind_shop`: the shop only ever receives committed documents, so this is never
 * normal lag. The database naming a journal that no folder holds, until the shop
 * has answered for every journal it holds, is `journal_missing`. A shop that cannot
 * be reached, a module too old and a missing or unreadable shop head are never a
 * block: only positive evidence is.
 *
 * Cost per check: a few small files and one indexed lookup per distinct document
 * (usually one). Called early by FiscalJournalGuardListener, before the issuing
 * controller runs, and again by FiscalRecorder::append under the chain lock (D5),
 * where the exception rolls the issue back; inside a transaction the
 * lookup is a locking read on MySQL, so a REPEATABLE READ snapshot taken earlier in
 * that transaction cannot hide a document another till committed and journaled.
 */
class FiscalJournalGuard
{
    public const OK = 'ok';
    public const BEHIND_JOURNAL = 'behind_journal';
    public const BEHIND_SHOP = 'behind_shop';
    public const JOURNAL_MISSING = 'journal_missing';

    /** Whether this process refused an issue since the last takeRefusal() (one request per process). */
    private bool $refused = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly FiscalJournalLocations $locations,
        private readonly ?LoggerInterface $logger = null,
        private ?FiscalJournalIdentity $identity = null,
    ) {
    }

    /**
     * @throws FiscalJournalBehindException when the database is behind the journal
     */
    public function assertCanIssue(): void
    {
        $check = $this->check();
        if ($check['state'] !== self::OK) {
            $this->refused = true;

            throw new FiscalJournalBehindException($check['state'], $check['missing']['number'] ?? null);
        }
    }

    /**
     * Whether an issue was refused since the last call, and forgets it: the services
     * and controllers catch the exception thrown under the chain lock and build their
     * own error answer, which FiscalJournalGuardListener then turns into the 423.
     */
    public function takeRefusal(): bool
    {
        $refused = $this->refused;
        $this->refused = false;

        return $refused;
    }

    /**
     * @return array{state: string, missing: ?array{number: string, invoice_type: string}}
     */
    public function check(): array
    {
        try {
            foreach ($this->evidence() as $document) {
                if (!$this->inDatabase($document)) {
                    return ['state' => self::BEHIND_JOURNAL, 'missing' => ['number' => $document['number'], 'invoice_type' => $document['invoice_type']]];
                }
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('Fiscal journal guard could not check the database: {message}', ['message' => $e->getMessage()]);
        }

        try {
            return $this->checkShop();
        } catch (\Throwable $e) {
            $this->logger?->warning('Fiscal journal guard could not check the shop copy: {message}', ['message' => $e->getMessage()]);
        }

        return ['state' => self::OK, 'missing' => null];
    }

    /**
     * @return array{state: string, missing: ?array{number: string, invoice_type: string}}
     */
    private function checkShop(): array
    {
        $inFolders = [];
        foreach ([$this->locations->primary(), $this->locations->secondary()] as $location) {
            $id = $location->journalId();
            if ($id !== null) {
                $inFolders[strtolower($id)] = true;
            }
        }
        $identity = $this->identity ??= new FiscalJournalIdentity($this->connection);
        $named = $identity->journalId();
        [$heads, $answeredForAll] = $this->shopHeads($identity->retired());
        $missing = $this->missingFromShop($heads);
        if ($missing !== null) {
            return ['state' => self::BEHIND_SHOP, 'missing' => $missing];
        }
        // Only an answer for every journal confirms: "nothing under that id" alone never does.
        if ($named !== null && !isset($inFolders[$named]) && !$answeredForAll) {
            return ['state' => self::JOURNAL_MISSING, 'missing' => null];
        }

        return ['state' => self::OK, 'missing' => null];
    }

    /**
     * The first document of these shop heads (each with its `series`, as
     * PsJournalClient::head() answers or shop-head.json stores them) that the
     * database lacks, by number + hash; null when the database holds them all.
     * Also used by tpv:fiscal:journal:reset on the head it asks for itself.
     *
     * @param iterable<array{series: iterable<mixed>}> $heads
     *
     * @return array{number: string, invoice_type: string}|null
     */
    public function missingFromShop(iterable $heads): ?array
    {
        foreach ($heads as $head) {
            foreach ($head['series'] as $entry) {
                if (!\is_array($entry) || !\is_string($entry['number'] ?? null) || $entry['number'] === '' || !\is_string($entry['hash'] ?? null) || $entry['hash'] === '') {
                    continue;
                }
                if (!$this->inDatabase(['number' => $entry['number'], 'invoice_type' => '', 'hash' => $entry['hash']])) {
                    return ['number' => $entry['number'], 'invoice_type' => ''];
                }
            }
        }

        return null;
    }

    /**
     * The newest stored shop head of every journal the shop holds, found in either
     * folder, but the retired ones (FiscalJournalIdentity::retired), and whether a
     * stored answer covered every journal of the shop (`all`).
     *
     * @param list<string> $retired lowercase
     *
     * @return array{0: array<string, array{fetched_at: string, series: list<array{number: string, hash: string}>}>, 1: bool}
     */
    private function shopHeads(array $retired): array
    {
        $heads = [];
        $answeredForAll = false;
        foreach ([$this->locations->primary(), $this->locations->secondary()] as $location) {
            $stored = $location->readShopHead();
            if (\is_string($stored['all']['fetched_at'] ?? null)) {
                $answeredForAll = true;
            }
            if (!\is_array($stored['heads'] ?? null)) {
                continue;
            }
            foreach ($stored['heads'] as $id => $head) {
                $id = strtolower((string) $id);
                if (\in_array($id, $retired, true) || !\is_array($head) || !\is_string($head['fetched_at'] ?? null) || !\is_array($head['series'] ?? null)) {
                    continue;
                }
                if (isset($heads[$id]) && strcmp($heads[$id]['fetched_at'], $head['fetched_at']) >= 0) {
                    continue;
                }
                $series = [];
                foreach ($head['series'] as $entry) {
                    if (\is_array($entry) && \is_string($entry['number'] ?? null) && $entry['number'] !== '' && \is_string($entry['hash'] ?? null) && $entry['hash'] !== '') {
                        $series[] = ['number' => $entry['number'], 'hash' => $entry['hash']];
                    }
                }
                $heads[$id] = ['fetched_at' => $head['fetched_at'], 'series' => $series];
            }
        }

        return [$heads, $answeredForAll];
    }

    /**
     * For the administrator (Ajustes): the state and, when behind, the first document
     * missing from the database and how many journal lines follow it (null when the
     * database's chain head is not in the journal, so they cannot be counted).
     *
     * @return array{state: string, missing: ?array{number: string, invoice_type: string, after: ?int}}
     */
    public function report(): array
    {
        $check = $this->check();
        if ($check['state'] === self::OK || $check['missing'] === null) {
            return ['state' => $check['state'], 'missing' => null];
        }
        $missing = $check['missing'] + ['after' => null];
        if ($check['state'] !== self::BEHIND_JOURNAL) {
            return ['state' => $check['state'], 'missing' => $missing];
        }
        try {
            $counted = $this->missingLines();
            if ($counted !== null) {
                $missing = $counted;
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('Fiscal journal guard could not count the missing documents: {message}', ['message' => $e->getMessage()]);
        }

        return ['state' => $check['state'], 'missing' => $missing];
    }

    /**
     * The committed documents the journal knows of, latest first, one per hash.
     *
     * @return list<array{number: string, invoice_type: string, hash: string}>
     */
    private function evidence(): array
    {
        $documents = [];
        foreach ([$this->locations->primary(), $this->locations->secondary()] as $location) {
            try {
                $found = [];
                $line = $location->lastValidLine();
                if (\is_array($line) && \is_array($line['fiscal_record'] ?? null)) {
                    $found[] = $line['fiscal_record'];
                }
                $head = $location->readHead();
                if ($head !== null) {
                    $found[] = $head;
                }
                foreach ($location->intents() as $intent) {
                    if (($intent['state'] ?? null) === FiscalJournalIntents::STATE_CONFIRMED && \is_array($intent['fiscal_record'] ?? null)) {
                        $found[] = $intent['fiscal_record'];
                    }
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('Fiscal journal guard could not read {directory}: {message}', ['directory' => $location->directory(), 'message' => $e->getMessage()]);

                continue;
            }
            foreach ($found as $record) {
                $number = $record['number'] ?? null;
                $hash = $record['hash'] ?? null;
                if (!\is_string($number) || $number === '' || !\is_string($hash) || $hash === '' || isset($documents[$hash])) {
                    continue;
                }
                $documents[$hash] = ['number' => $number, 'invoice_type' => \is_string($record['invoice_type'] ?? null) ? $record['invoice_type'] : '', 'hash' => $hash];
            }
        }

        return array_values($documents);
    }

    /** @param array{number: string, invoice_type: string, hash: string} $document */
    private function inDatabase(array $document): bool
    {
        $sql = 'SELECT 1 FROM fiscal_record WHERE number = ? AND hash = ?';
        $params = [$document['number'], $document['hash']];
        if ($document['invoice_type'] !== '') {
            $sql .= ' AND invoice_type = ?';
            $params[] = $document['invoice_type'];
        }
        $sql .= ' LIMIT 1';
        if ($this->connection->isTransactionActive() && $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $sql .= ' LOCK IN SHARE MODE';
        }

        return $this->connection->fetchOne($sql, $params) !== false;
    }

    /**
     * The journal lines after the one holding the database's chain head (every line
     * when the database has no fiscal record): the first of them and how many follow.
     *
     * @return array{number: string, invoice_type: string, after: int}|null null when they cannot be told
     */
    private function missingLines(): ?array
    {
        $head = $this->connection->fetchOne('SELECT last_hash FROM fiscal_chain WHERE id = 1');
        $head = \is_string($head) && $head !== '' ? $head : null;
        $found = $head === null;
        $foundSeq = 0;
        /** @var array<int, array{number: string, invoice_type: string}> $after */
        $after = [];
        foreach ([$this->locations->primary(), $this->locations->secondary()] as $location) {
            foreach ($location->lines() as $line) {
                if (!\is_array($line) || !\is_int($line['seq'] ?? null) || !\is_array($line['fiscal_record'] ?? null)) {
                    continue;
                }
                $record = $line['fiscal_record'];
                if ($head !== null && ($record['hash'] ?? null) === $head) {
                    $found = true;
                    $foundSeq = $line['seq'];
                    $after = array_filter($after, static fn (int $seq): bool => $seq > $foundSeq, \ARRAY_FILTER_USE_KEY);

                    continue;
                }
                if ($found && $line['seq'] > $foundSeq) {
                    $after[$line['seq']] = ['number' => (string) ($record['number'] ?? ''), 'invoice_type' => (string) ($record['invoice_type'] ?? '')];
                }
            }
        }
        if (!$found || $after === []) {
            return null;
        }
        ksort($after);

        return reset($after) + ['after' => \count($after) - 1];
    }
}
