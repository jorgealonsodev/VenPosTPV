<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Install;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalResetResult;
use VenPosTPV\Service\Install\JournalDecisionPage;

/**
 * The installer page shown when the shop already holds fiscal documents this new
 * database lacks: two exits («Voy a restaurar una copia de seguridad», «Empezar de
 * cero»), what the shop holds (counts and the last number of each series, no
 * customer data), and every value from the shop or the person escaped.
 */
final class JournalDecisionPageTest extends TestCase
{
    private const AHEAD = ['missing' => 'A26000007', 'documents' => 12, 'journals' => 2, 'series' => ['A26' => 'A26000007', 'F2026' => 'F2026-000003', 'R2026' => 'R2026-000002']];

    public function testWithoutAChoiceNothingIsDecidedAndNothingIsWrong(): void
    {
        $decision = JournalDecisionPage::read(['db_name' => 'tpv']);

        $this->assertNull($decision['choice']);
        $this->assertSame([], $decision['errors']);
    }

    public function testRestoreNeedsNothingElse(): void
    {
        $decision = JournalDecisionPage::read(['fiscal_journal_choice' => 'restore']);

        $this->assertSame(JournalDecisionPage::RESTORE, $decision['choice']);
        $this->assertSame([], $decision['errors']);
    }

    public function testAFreshStartWithoutTheCheckboxOrTheReasonIsRefused(): void
    {
        $neither = JournalDecisionPage::read(['fiscal_journal_choice' => 'fresh', 'fiscal_journal_reason' => '   ']);
        $this->assertSame(JournalDecisionPage::FRESH, $neither['choice']);
        $this->assertCount(2, $neither['errors']);

        $noBox = JournalDecisionPage::read(['fiscal_journal_choice' => 'fresh', 'fiscal_journal_reason' => 'Reinstalación']);
        $this->assertCount(1, $noBox['errors']);
        $this->assertStringContainsString('casilla', $noBox['errors'][0]);
        $this->assertSame('Reinstalación', $noBox['reason']);

        $noReason = JournalDecisionPage::read(['fiscal_journal_choice' => 'fresh', 'fiscal_journal_confirm' => '1']);
        $this->assertCount(1, $noReason['errors']);
        $this->assertStringContainsString('motivo', $noReason['errors'][0]);
        $this->assertTrue($noReason['confirmed']);

        $tooLong = JournalDecisionPage::read(['fiscal_journal_choice' => 'fresh', 'fiscal_journal_confirm' => '1', 'fiscal_journal_reason' => str_repeat('x', 501)]);
        $this->assertCount(1, $tooLong['errors']);
    }

    public function testAFreshStartWithTheCheckboxAndAReasonIsAccepted(): void
    {
        $decision = JournalDecisionPage::read(['fiscal_journal_choice' => 'fresh', 'fiscal_journal_confirm' => '1', 'fiscal_journal_reason' => "  Reinstalación desde cero\n "]);

        $this->assertSame(JournalDecisionPage::FRESH, $decision['choice']);
        $this->assertSame([], $decision['errors']);
        $this->assertSame('Reinstalación desde cero', $decision['reason']);
    }

    public function testAnUnknownChoiceIsNoChoice(): void
    {
        $this->assertNull(JournalDecisionPage::read(['fiscal_journal_choice' => 'delete'])['choice']);
        $this->assertNull(JournalDecisionPage::read(['fiscal_journal_choice' => ['fresh']])['choice']);
    }

    public function testThePageSaysWhatTheShopHoldsAndWhatEachExitDoes(): void
    {
        $html = JournalDecisionPage::html(self::AHEAD, 'https://tutienda.com', ['db_name' => 'tpv'], JournalDecisionPage::read([]));

        $this->assertStringContainsString('12 documentos fiscales', $html);
        foreach (self::AHEAD['series'] as $series => $number) {
            $this->assertStringContainsString('<td>' . $series . '</td><td>' . $number . '</td>', $html);
        }
        $this->assertStringContainsString('Voy a restaurar una copia de seguridad', $html);
        $this->assertStringContainsString('Empezar de cero', $html);
        $this->assertStringContainsString('docs/diario-fiscal.md', $html);
        $this->assertStringContainsString('empieza otra vez en 1', $html);
        $this->assertStringContainsString('repetirá números', $html);
        $this->assertStringContainsString('nunca después de restaurar una copia de seguridad', $html);
        // Two forms: restore and fresh start, each carrying the installer's fields.
        $this->assertSame(2, substr_count($html, '<form method="post"'));
        $this->assertSame(2, substr_count($html, '<input type="hidden" name="db_name" value="tpv">'));
        $this->assertSame(1, preg_match('/<input[^>]*name="fiscal_journal_confirm"[^>]*>/', $html, $box));
        $this->assertStringContainsString('required', $box[0]);
        $this->assertSame(1, preg_match('/<input[^>]*name="fiscal_journal_reason"[^>]*>/', $html, $reason));
        $this->assertStringContainsString('required', $reason[0]);
    }

    public function testEverythingFromTheShopOrThePersonIsEscaped(): void
    {
        $ahead = ['missing' => '<b>x</b>', 'documents' => 1, 'journals' => 1, 'series' => ['<script>s</script>' => '<img src=x onerror=alert(1)>']];
        $decision = JournalDecisionPage::read(['fiscal_journal_choice' => 'fresh', 'fiscal_journal_reason' => '"><script>alert(1)</script>']);

        $html = JournalDecisionPage::html($ahead, 'https://tutienda.com/"><script>', ['admin_password' => '"><script>p</script>'], $decision);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>x', $html);
        $this->assertStringContainsString('&lt;script&gt;s&lt;/script&gt;', $html);
        $this->assertSame(1, preg_match('/<input[^>]*name="fiscal_journal_reason"[^>]*value="([^"]*)"/', $html, $m));
        $this->assertSame('"><script>alert(1)</script>', html_entity_decode($m[1], \ENT_QUOTES | \ENT_HTML5, 'UTF-8'), 'the reason is kept after a failed submit');
        $this->assertStringContainsString('<div class="err">', $html, 'the errors of the failed submit are shown');
    }

    public function testAFailedFreshStartKeepsTheCheckboxTicked(): void
    {
        $html = JournalDecisionPage::html(self::AHEAD, 'https://tutienda.com', [], JournalDecisionPage::read(['fiscal_journal_choice' => 'fresh', 'fiscal_journal_confirm' => '1']));

        $this->assertSame(1, preg_match('/<input[^>]*name="fiscal_journal_confirm"[^>]*>/', $html, $box));
        $this->assertStringContainsString('checked', $box[0]);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function submittedChoices(): iterable
    {
        yield 'restore' => [['fiscal_journal_choice' => 'restore']];
        yield 'fresh' => [['fiscal_journal_choice' => 'fresh', 'fiscal_journal_confirm' => '1', 'fiscal_journal_reason' => '"><script>r</script>']];
    }

    /**
     * A choice was made, but the shop cannot be asked now: the page says so and its one
     * form sends the same install and the same choice again, every value escaped.
     *
     * @param array<string, string> $post
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('submittedChoices')]
    public function testWhenTheShopCannotBeAskedThePageKeepsTheChoiceForARetry(array $post): void
    {
        $html = JournalDecisionPage::unreachable('https://tpv.tutienda.com/"><script>', ['admin_password' => '"><script>p</script>', 'db_name' => 'tpv'], JournalDecisionPage::read($post));

        $this->assertStringContainsString('<p class="err">', $html);
        $this->assertStringContainsString('No se ha podido preguntar a la tienda', $html);
        $this->assertStringContainsString('Volver a intentarlo', $html);
        $this->assertStringContainsString('No se ha guardado nada', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertSame(1, substr_count($html, '<form '));
        preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)">/', $html, $m, \PREG_SET_ORDER);
        $sent = [];
        foreach ($m as [, $name, $value]) {
            $sent[$name] = html_entity_decode($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        }
        $this->assertSame('"><script>p</script>', $sent['admin_password']);
        $this->assertSame('tpv', $sent['db_name']);
        $this->assertSame($post['fiscal_journal_choice'], $sent['fiscal_journal_choice']);
        if ($post['fiscal_journal_choice'] === 'fresh') {
            $this->assertSame('1', $sent['fiscal_journal_confirm']);
            $this->assertSame('"><script>r</script>', $sent['fiscal_journal_reason']);
        } else {
            $this->assertArrayNotHasKey('fiscal_journal_confirm', $sent);
            $this->assertArrayNotHasKey('fiscal_journal_reason', $sent);
        }
    }

    public function testTheLastPageSaysWhatToDoAfterEachExit(): void
    {
        $restore = JournalDecisionPage::outcome(JournalDecisionPage::RESTORE, null);
        $this->assertStringContainsString('docs/diario-fiscal.md', $restore);
        $this->assertStringContainsString('Recuperar documentos', $restore);

        $done = JournalDecisionPage::outcome(JournalDecisionPage::FRESH, new FiscalJournalResetResult(FiscalJournalResetResult::DONE, 'Diario fiscal nuevo x', 'x'));
        $this->assertStringContainsString('class="ok"', $done);
        $this->assertStringContainsString('empieza otra vez en 1', $done);

        $failed = JournalDecisionPage::outcome(JournalDecisionPage::FRESH, new FiscalJournalResetResult(FiscalJournalResetResult::REFUSED, 'La tienda <no> contesta'));
        $this->assertStringContainsString('class="err"', $failed);
        $this->assertStringContainsString('La tienda &lt;no&gt; contesta', $failed);
        $this->assertStringContainsString('--accept-shop-ahead', $failed);
    }
}
