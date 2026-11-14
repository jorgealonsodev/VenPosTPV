<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Install;

use VenPosTPV\Service\Fiscal\Journal\FiscalJournalResetResult;

/**
 * The installer page shown when the shop already holds fiscal documents this new
 * database lacks (WebInstaller::shopJournalAhead), and its note on the last page.
 *
 * Two exits, each a form that carries every installer field again (hidden), so the
 * choice is one more submit of the same install:
 * - «Voy a restaurar una copia de seguridad» (RESTORE): the install goes on without
 *   starting a journal; the provisioning step stays pending and the till blocked
 *   until the backup is restored (docs/diario-fiscal.md).
 * - «Empezar de cero» (FRESH): a required checkbox and reason; the install then runs
 *   FiscalJournalFreshStart (tpv:fiscal:journal:reset --accept-shop-ahead).
 * When the shop cannot be asked at the moment of that submit, unreachable() stops the
 * install with a retry form that keeps the choice: it is never dropped.
 *
 * Only counts and document numbers from the shop are shown, never customer data;
 * every value from the shop or the person is escaped.
 */
final class JournalDecisionPage
{
    public const RESTORE = 'restore';
    public const FRESH = 'fresh';
    public const TITLE = 'La tienda ya tiene documentos fiscales';
    public const MAX_REASON = 500;

    /**
     * @param array<string, mixed> $post the submitted form
     *
     * @return array{choice: ?string, reason: string, confirmed: bool, errors: list<string>}
     */
    public static function read(array $post): array
    {
        $choice = $post['fiscal_journal_choice'] ?? null;
        $choice = \in_array($choice, [self::RESTORE, self::FRESH], true) ? $choice : null;
        $reason = \is_string($post['fiscal_journal_reason'] ?? null) ? trim($post['fiscal_journal_reason']) : '';
        $confirmed = ($post['fiscal_journal_confirm'] ?? null) === '1';
        $errors = [];
        if ($choice === self::FRESH) {
            if (!$confirmed) {
                $errors[] = 'Para empezar de cero, marca la casilla que confirma que la numeración empieza otra vez en 1 y que no estás restaurando una copia de seguridad.';
            }
            if ($reason === '') {
                $errors[] = 'Para empezar de cero, escribe el motivo: queda anotado en el registro.';
            } elseif (mb_strlen($reason) > self::MAX_REASON) {
                $errors[] = sprintf('El motivo puede tener como mucho %d caracteres.', self::MAX_REASON);
            }
        }

        return ['choice' => $choice, 'reason' => $reason, 'confirmed' => $confirmed, 'errors' => $errors];
    }

    /**
     * @param array{missing: string, documents: int, journals: int, series: array<string, string>} $ahead
     * @param array<string, string>                                                                   $hidden the installer fields to send again
     * @param array{choice: ?string, reason: string, confirmed: bool, errors: list<string>}           $decision read() of this submit
     */
    public static function html(array $ahead, string $shopUrl, array $hidden, array $decision): string
    {
        $fields = '';
        foreach ($hidden as $name => $value) {
            $fields .= '<input type="hidden" name="' . self::h((string) $name) . '" value="' . self::h((string) $value) . '">';
        }
        $rows = '';
        foreach ($ahead['series'] as $series => $number) {
            $rows .= '<tr><td>' . self::h((string) $series) . '</td><td>' . self::h($number) . '</td></tr>';
        }
        $errors = $decision['choice'] !== null && $decision['errors'] !== []
            ? '<div class="err"><strong>Revisa esto:</strong><ul><li>' . implode('</li><li>', array_map(self::h(...), $decision['errors'])) . '</li></ul></div>'
            : '';

        return $errors
            . '<p>La tienda <code>' . self::h($shopUrl) . '</code> guarda ' . (int) $ahead['documents'] . ' documentos fiscales (tickets, abonos y facturas) de una instalación anterior del TPV que esta base de datos no tiene. '
            . 'El TPV no puede saber si estás restaurando una copia de seguridad o empezando de cero, así que no sigue hasta que lo elijas. Todavía no se ha guardado la configuración.</p>'
            . ($rows !== '' ? '<div class="table"><table><tr><th>Serie</th><th>Último número en la tienda</th></tr>' . $rows . '</table></div>' : '')
            . '<h2>Voy a restaurar una copia de seguridad</h2>'
            . '<p>Elige esto si vas a traer la base de datos de la instalación anterior. La instalación termina, pero <strong>no empieza el diario fiscal</strong> y la caja no deja vender hasta que restaures la copia: así no se repite ningún número. '
            . 'Después restaura la copia de la base de datos y, si las tienes, las carpetas <code>backups/fiscal-journal/</code> y <code>var/fiscal-journal/</code>, siguiendo la guía <code>docs/diario-fiscal.md</code> («Restaurar una copia de seguridad»).</p>'
            . '<form method="post" autocomplete="off">' . $fields
            . '<input type="hidden" name="fiscal_journal_choice" value="' . self::RESTORE . '">'
            . '<button type="submit">Voy a restaurar una copia de seguridad</button></form>'
            . '<h2>Empezar de cero</h2>'
            . '<p class="err">Solo si de verdad empiezas de cero, y <strong>nunca después de restaurar una copia de seguridad</strong>. '
            . 'La numeración de tickets, abonos y facturas de una instalación nueva empieza otra vez en 1 y repetirá números que ya emitió la instalación anterior (los de la tabla). '
            . 'Para no repetirlos, antes de la primera venta ajusta en <strong>Ajustes → Cajas</strong> el próximo número de cada serie o usa una letra de caja distinta (<code>docs/despliegue-plesk.md</code>, «Continuar la numeración a mano»). '
            . 'Los diarios que la tienda guarda ahora quedan retirados (no vuelven a bloquear la caja) y el cambio queda anotado con el motivo.</p>'
            . '<form method="post" autocomplete="off">' . $fields
            . '<input type="hidden" name="fiscal_journal_choice" value="' . self::FRESH . '">'
            . '<label style="display:flex;gap:10px;align-items:flex-start;font-weight:400"><input type="checkbox" name="fiscal_journal_confirm" value="1" required style="width:auto;min-height:0;margin-top:4px"'
            . ($decision['confirmed'] ? ' checked' : '') . '> <span>Entiendo que la numeración empieza otra vez en 1 y repetirá números, y no estoy restaurando una copia de seguridad.</span></label>'
            . '<label for="fiscal_journal_reason">Motivo <small>— queda anotado en el registro</small></label>'
            . '<input id="fiscal_journal_reason" name="fiscal_journal_reason" value="' . self::h($decision['reason']) . '" maxlength="' . self::MAX_REASON . '" placeholder="Reinstalación desde cero" required>'
            . '<button type="submit">Empezar de cero</button></form>';
    }

    /**
     * A choice was sent but the shop cannot be asked now (WebInstaller::shopJournalCheck
     * says SHOP_UNREACHABLE): the install stops here, writing nothing, and one form
     * sends the same install and the same choice again.
     *
     * @param array<string, string>                                                         $hidden   the installer fields to send again
     * @param array{choice: ?string, reason: string, confirmed: bool, errors: list<string>} $decision read() of this submit
     */
    public static function unreachable(string $shopUrl, array $hidden, array $decision): string
    {
        $again = $hidden + ['fiscal_journal_choice' => (string) $decision['choice']];
        if ($decision['choice'] === self::FRESH) {
            $again += ['fiscal_journal_reason' => $decision['reason']] + ($decision['confirmed'] ? ['fiscal_journal_confirm' => '1'] : []);
        }
        $fields = '';
        foreach ($again as $name => $value) {
            $fields .= '<input type="hidden" name="' . self::h((string) $name) . '" value="' . self::h((string) $value) . '">';
        }
        $chosen = $decision['choice'] === self::FRESH ? 'Empezar de cero' : 'Voy a restaurar una copia de seguridad';

        return '<p class="err">No se ha podido preguntar a la tienda <code>' . self::h($shopUrl) . '</code> por sus documentos fiscales. '
            . 'Sin su respuesta el TPV no puede seguir con lo que elegiste («' . $chosen . '»). No se ha guardado nada: ni la configuración ni el diario fiscal.</p>'
            . '<p>Comprueba que la tienda está en marcha y que el módulo <strong>TPV Connect</strong> sigue activo (versión 0.9.0 o posterior) y vuelve a intentarlo.</p>'
            . '<form method="post" autocomplete="off">' . $fields
            . '<button type="submit">Volver a intentarlo</button></form>';
    }

    /** The note on the installer's last page after the choice. */
    public static function outcome(string $choice, ?FiscalJournalResetResult $result): string
    {
        $html = '<h2>Diario fiscal</h2>';
        if ($choice === self::RESTORE) {
            return $html . '<p class="err">El diario fiscal no se ha empezado: la tienda tiene documentos que esta base de datos no tiene y la caja no deja vender hasta que restaures la copia de seguridad.</p><ol>'
                . '<li>Restaura la copia de la base de datos (<code>docs/despliegue-plesk.md</code>, «Copias de seguridad y restauración») y, si las tienes, las carpetas <code>backups/fiscal-journal/</code> y <code>var/fiscal-journal/</code>.</li>'
                . '<li>Entra en el TPV como administrador, abre <strong>Ajustes → VERI*FACTU → Diario fiscal</strong> y, si lo pide, pulsa <strong>Recuperar documentos</strong>.</li>'
                . '<li>Todos los pasos están en <code>docs/diario-fiscal.md</code> («Restaurar una copia de seguridad»).</li></ol>';
        }
        if ($result !== null && $result->status === FiscalJournalResetResult::DONE) {
            return $html . '<p class="ok">Diario fiscal empezado de cero, con el motivo anotado en el registro. Los diarios que tenía la tienda quedan retirados. '
                . 'La numeración de tickets, abonos y facturas empieza otra vez en 1: ajusta en Ajustes → Cajas el próximo número de cada serie antes de la primera venta si no quieres repetir números.</p>';
        }

        return $html . '<p class="err">No se ha podido empezar el diario fiscal de cero: ' . self::h(rtrim($result?->message ?? 'motivo desconocido', '. ')) . '. '
            . 'Mientras no se haga, la caja se bloquea en cuanto el TPV pueda preguntar a la tienda. Con acceso por consola: <code>php bin/console tpv:fiscal:journal:reset --confirm "&lt;motivo&gt;" --accept-shop-ahead --env=prod</code> (<code>docs/diario-fiscal.md</code>).</p>';
    }

    private static function h(string $s): string
    {
        return htmlspecialchars($s, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
