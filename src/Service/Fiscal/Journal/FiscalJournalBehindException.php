<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

/**
 * The database is behind the fiscal journal (a restore of an older backup): the
 * till must not issue any document, or it would reuse a number already issued
 * (fiscal-journal S5, D7). Answered with HTTP 423 and the message below
 * (FiscalJournalGuardListener).
 */
final class FiscalJournalBehindException extends \RuntimeException
{
    /** The POS message of every issuing endpoint (D7). */
    public const MESSAGE = 'Caja bloqueada: la base de datos se ha restaurado desde una copia anterior a los últimos documentos. Avisa al administrador (Ajustes → VERI*FACTU → Diario fiscal).';

    /** The `code` of the 423 body, so the front end can tell it apart from other errors. */
    public const CODE = 'fiscal_journal_behind';

    public function __construct(
        public readonly string $state,
        public readonly ?string $missingNumber,
    ) {
        parent::__construct(self::MESSAGE);
    }
}
