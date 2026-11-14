<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIntents;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalLocations;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalWriter;
use VenPosTPV\Service\Fiscal\Journal\JournalLocation;

/**
 * A fiscal journal in a temporary project folder (backups/fiscal-journal and
 * var/fiscal-journal), with the intents and the writer of one "process".
 */
final class JournalHarness
{
    public readonly string $root;
    public readonly FiscalJournalLocations $locations;
    public readonly FiscalJournalIntents $intents;
    public readonly FiscalJournalWriter $writer;

    public function __construct(private readonly Connection $connection, ?string $root = null)
    {
        $this->root = $root ?? sys_get_temp_dir() . '/venpos-fiscal-journal-' . bin2hex(random_bytes(5));
        $this->locations = new FiscalJournalLocations($this->root . '/backups/fiscal-journal', $this->root . '/var/fiscal-journal');
        $this->intents = new FiscalJournalIntents($this->locations);
        $this->writer = new FiscalJournalWriter($connection, $this->locations, $this->intents);
    }

    /** Another process on the same folders (nothing of this one's memory). */
    public function anotherProcess(): self
    {
        return new self($this->connection, $this->root);
    }

    public function primary(): JournalLocation
    {
        return $this->locations->primary();
    }

    public function secondary(): JournalLocation
    {
        return $this->locations->secondary();
    }

    /** @return list<array<string, mixed>> */
    public function lines(?JournalLocation $location = null): array
    {
        return array_values(iterator_to_array(($location ?? $this->primary())->lines(), false));
    }

    /** @return list<string> intent file names in both locations */
    public function intentFiles(): array
    {
        $files = [];
        foreach (['backups', 'var'] as $base) {
            foreach (glob($this->root . '/' . $base . '/fiscal-journal/intents/*.json') ?: [] as $file) {
                $files[] = $base . '/' . basename($file);
            }
        }

        return $files;
    }

    public function remove(): void
    {
        self::removePath($this->root);
    }

    private static function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        @chmod($path, 0700);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removePath($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
