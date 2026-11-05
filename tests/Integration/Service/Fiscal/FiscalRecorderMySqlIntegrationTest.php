<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Fiscal;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Fiscal\FiscalChainVerifier;
use VenPosTPV\Service\Fiscal\FiscalHash;
use VenPosTPV\Service\Fiscal\FiscalRecorder;
use VenPosTPV\Service\Fiscal\FiscalRecordTriggers;
use VenPosTPV\Tests\Support\TestDatabase;

final class FiscalRecorderMySqlIntegrationTest extends TestCase
{
    private const TERMINAL = 94101;
    private const ORDERS = [94111, 94112];
    private const REFUND = 94121;

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->reset();
        $this->connection->insert('terminal', ['id' => self::TERMINAL, 'ps_shop_id' => 1, 'name' => 'Fiscal', 'tax_id' => ' g12345678 ', 'active' => 1]);
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->reset();
            $this->connection->close();
        }
    }

    /**
     * The tables are emptied with DDL because the records refuse DELETE when the
     * triggers exist. Only the test database is touched.
     */
    private function reset(): void
    {
        \assert($this->connection !== null);
        $this->connection->executeStatement('DROP TRIGGER IF EXISTS fiscal_record_no_update');
        $this->connection->executeStatement('DROP TRIGGER IF EXISTS fiscal_record_no_delete');
        $this->connection->executeStatement('TRUNCATE TABLE fiscal_record');
        $this->connection->executeStatement('UPDATE fiscal_chain SET last_record_id = NULL, last_hash = NULL WHERE id = 1');
        $this->connection->executeStatement('DELETE FROM refund WHERE id = ?', [self::REFUND]);
        $this->connection->executeStatement('DELETE FROM order_meta WHERE id IN (?, ?)', self::ORDERS);
        $this->connection->executeStatement('DELETE FROM terminal WHERE id = ?', [self::TERMINAL]);
    }

    private function recorder(): FiscalRecorder
    {
        \assert($this->connection !== null);

        return new FiscalRecorder($this->connection);
    }

    private function sale(int $orderId, string $reference, float $total, float $tax): void
    {
        \assert($this->connection !== null);
        $this->connection->insert('order_meta', [
            'id' => $orderId,
            'reference' => $reference,
            'terminal_id' => self::TERMINAL,
            'session_id' => 0,
            'user_id' => 1,
            'payment_breakdown' => json_encode(['reference' => $reference, 'total' => $total, 'tax' => $tax], JSON_THROW_ON_ERROR),
            'printed' => 0,
            'synced' => 0,
            'created_at' => '2026-11-05 10:00:00',
        ]);
        $this->connection->transactional(fn () => $this->recorder()->recordSale($orderId, self::TERMINAL, $reference, $total, $tax, new \DateTimeImmutable('2026-11-05 10:00:00')));
    }

    /** @return list<array<string, mixed>> */
    private function records(): array
    {
        \assert($this->connection !== null);

        return $this->connection->fetchAllAssociative('SELECT * FROM fiscal_record ORDER BY id');
    }

    public function testEachTicketAndCreditNoteIsChainedToThePreviousRecord(): void
    {
        \assert($this->connection !== null);
        $this->sale(self::ORDERS[0], 'Z26000001', 12.10, 2.10);
        $this->sale(self::ORDERS[1], 'Z26000002', 24.20, 4.20);
        $this->connection->insert('refund', [
            'id' => self::REFUND,
            'request_id' => 'fiscal-test-refund',
            'status' => 'done',
            'order_id' => self::ORDERS[1],
            'terminal_id' => self::TERMINAL,
            'session_id' => 0,
            'user_id' => 1,
            'reason_code' => 'other',
            'total' => 12.10,
            'cash_amount' => 12.10,
            'card_amount' => 0,
            'credit_note_number' => 'R2026-000001',
            'created_at' => '2026-11-05 11:00:00',
        ]);
        $this->connection->transactional(fn () => $this->recorder()->recordCreditNote(self::REFUND, new \DateTimeImmutable('2026-11-05 11:00:00')));

        $records = $this->records();
        self::assertCount(3, $records);
        self::assertSame(['F2', 'F2', 'R5'], array_column($records, 'invoice_type'));
        self::assertSame(['Z26000001', 'Z26000002', 'R2026-000001'], array_column($records, 'number'));
        self::assertSame(['12.10', '24.20', '-12.10'], array_column($records, 'total'));
        self::assertSame(['2.10', '4.20', '-2.10'], array_column($records, 'tax_total'));
        self::assertSame('G12345678', $records[0]['issuer_nif']);
        self::assertSame('05-11-2026', $records[0]['issue_date']);
        self::assertSame('Z26000002', $records[2]['rectified_number']);

        self::assertNull($records[0]['previous_hash']);
        self::assertSame($records[0]['hash'], $records[1]['previous_hash']);
        self::assertSame($records[1]['hash'], $records[2]['previous_hash']);
        self::assertSame(
            FiscalHash::alta('G12345678', 'Z26000001', '05-11-2026', 'F2', '2.10', '12.10', '', (string) $records[0]['generated_at']),
            $records[0]['hash']
        );
        self::assertSame($records[2]['hash'], $this->connection->fetchOne('SELECT last_hash FROM fiscal_chain WHERE id = 1'));

        $result = (new FiscalChainVerifier($this->connection))->verify();
        self::assertTrue($result['ok']);
        self::assertSame(3, $result['records']);
    }

    public function testARolledBackSaleLeavesNoRecordAndTheChainUnchanged(): void
    {
        \assert($this->connection !== null);
        $this->sale(self::ORDERS[0], 'Z26000001', 12.10, 2.10);
        $head = $this->connection->fetchOne('SELECT last_hash FROM fiscal_chain WHERE id = 1');

        try {
            $this->connection->transactional(function (): void {
                $this->recorder()->recordSale(self::ORDERS[1], self::TERMINAL, 'Z26000002', 5.0, 0.87, new \DateTimeImmutable());
                throw new \RuntimeException('sale failed');
            });
        } catch (\RuntimeException) {
        }

        self::assertCount(1, $this->records());
        self::assertSame($head, $this->connection->fetchOne('SELECT last_hash FROM fiscal_chain WHERE id = 1'));
    }

    public function testRecordingOutsideATransactionIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->recorder()->recordSale(self::ORDERS[0], self::TERMINAL, 'Z26000001', 1.0, 0.17, new \DateTimeImmutable());
    }

    public function testTheVerifierFindsAnEditedARemovedAndATruncatedRecord(): void
    {
        \assert($this->connection !== null);
        $this->sale(self::ORDERS[0], 'Z26000001', 12.10, 2.10);
        $this->sale(self::ORDERS[1], 'Z26000002', 24.20, 4.20);
        [$first, $second] = $this->records();
        $verifier = new FiscalChainVerifier($this->connection);

        $this->connection->update('fiscal_record', ['total' => '1.00'], ['id' => $first['id']]);
        $edited = $verifier->verify();
        self::assertFalse($edited['ok']);
        self::assertSame((int) $first['id'], $edited['broken_id']);
        $this->connection->update('fiscal_record', ['total' => $first['total']], ['id' => $first['id']]);
        self::assertTrue($verifier->verify()['ok']);

        $this->connection->delete('fiscal_record', ['id' => $first['id']]);
        $removed = $verifier->verify();
        self::assertFalse($removed['ok']);
        self::assertSame((int) $second['id'], $removed['broken_id']);

        $this->connection->insert('fiscal_record', $first);
        $this->connection->delete('fiscal_record', ['id' => $second['id']]);
        $truncated = $verifier->verify();
        self::assertFalse($truncated['ok']);
        self::assertNull($truncated['broken_id']);
    }

    public function testTheTriggersRefuseUpdateAndDeleteWhenTheHostAllowsThem(): void
    {
        \assert($this->connection !== null);
        $this->sale(self::ORDERS[0], 'Z26000001', 12.10, 2.10);
        if (!FiscalRecordTriggers::create($this->connection)) {
            self::assertFalse(FiscalRecordTriggers::exist($this->connection));
            self::assertFalse((new FiscalChainVerifier($this->connection))->verify()['protected']);
            $this->markTestSkipped('This database user cannot create triggers (best effort, as on shared hosting)');
        }
        self::assertTrue(FiscalRecordTriggers::create($this->connection), 'creating them again is harmless');
        self::assertTrue((new FiscalChainVerifier($this->connection))->verify()['protected']);

        $id = (int) $this->records()[0]['id'];
        foreach ([
            fn () => $this->connection?->update('fiscal_record', ['total' => '1.00'], ['id' => $id]),
            fn () => $this->connection?->delete('fiscal_record', ['id' => $id]),
        ] as $change) {
            try {
                $change();
                self::fail('The change was accepted');
            } catch (\Doctrine\DBAL\Exception $e) {
                self::assertStringContainsString('append-only', $e->getMessage());
            }
        }
        self::assertCount(1, $this->records());
    }
}
