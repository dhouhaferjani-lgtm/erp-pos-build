<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Presentation\Requests\CreateJournalEntryRequest;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Phase 4.5 — Accounting module ingress precision ceiling tests.
 *
 * The journal debit/credit tests bind to the REAL production rules from
 * CreateJournalEntryRequest (CompanyContext id set — no DB), re-keyed from
 * `lines.*.<field>` to flat `<field>`, so they FAIL if a production scale
 * changes. The opening-balance tests MIRROR (do NOT bind to) the inline
 * validator in OpeningBalanceBatchController (see comments + callsite lines);
 * that endpoint requires a fully-built batch context to reach validation.
 */
final class JournalEntryIngressTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Production journal-line rules from CreateJournalEntryRequest, re-keyed from
     * `lines.*.<field>` to flat `<field>`. rules() reads $this->user()->tenant_id
     * and app(CompanyContext::class)->requireCompanyId(), so a tenant + company +
     * user are seeded and bound.
     *
     * @return array<string, mixed>
     */
    private function journalLineRules(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        app(CompanyContext::class)->setCompanyId($company->id);

        $request = new CreateJournalEntryRequest;
        $request->setUserResolver(fn () => $user);

        $rules = $request->rules();

        $lineRules = [];
        foreach (['debit', 'credit'] as $field) {
            $key = "lines.*.{$field}";
            $this->assertArrayHasKey(
                $key,
                $rules,
                "CreateJournalEntryRequest no longer exposes {$key} — the test no longer binds to production."
            );
            $lineRules[$field] = $rules[$key];
        }

        return $lineRules;
    }

    // ── Journal line debit/credit (money, scale 3, SIGNED) ────────────────────

    /**
     * @dataProvider overPreciseProvider
     */
    public function test_journal_debit_rejects_over_precise(string $amount): void
    {
        $rules = $this->journalLineRules();
        $v = Validator::make(['debit' => $amount], ['debit' => $rules['debit']]);

        $this->assertTrue($v->fails(), "Expected debit={$amount} to fail");
        $this->assertArrayHasKey('debit', $v->errors()->toArray());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function overPreciseProvider(): array
    {
        return [
            '4-decimal' => ['100.1234'],
            '5-decimal' => ['0.00001'],
        ];
    }

    public function test_journal_credit_accepts_3_decimal(): void
    {
        $rules = $this->journalLineRules();
        $v = Validator::make(['credit' => '100.123'], ['credit' => $rules['credit']]);

        $this->assertEmpty(
            $v->errors()->get('credit'),
            'Expected 3-decimal credit to pass: '.$v->errors()->first('credit')
        );
    }

    /**
     * Journal lines legitimately carry signed amounts (reversing/correcting
     * entries), so the regex must allow a leading minus. Binds to the production
     * regex (extracted from the rule, with min:0 removed to isolate the signed
     * regex behaviour).
     */
    public function test_journal_debit_accepts_negative_3_decimal(): void
    {
        $rules = $this->journalLineRules();
        $regexOnly = array_values(array_filter(
            $rules['debit'],
            fn ($rule) => ! is_string($rule) || ! str_starts_with($rule, 'min:')
        ));
        $v = Validator::make(['debit' => '-42.500'], ['debit' => $regexOnly]);

        $this->assertEmpty(
            $v->errors()->get('debit'),
            'Expected signed 3-decimal debit to pass the production regex ceiling'
        );
    }

    // ── Opening-balance inventory quantity (scale 4) ──────────────────────────
    //
    // The opening-balance tests below MIRROR (do NOT bind to) the inline
    // validator in OpeningBalanceBatchController::store
    // (app/Modules/Accounting/Presentation/Controllers/OpeningBalanceBatchController.php
    // :406 quantity, :407 unit_cost, :413 total, :414 open_amount). That endpoint
    // needs a fully-built batch context to reach validation.

    public function test_opening_quantity_rejects_5_decimal(): void
    {
        // Mirrors OpeningBalanceBatchController.php:406 — NOT bound to production.
        $rules = ['quantity' => ['required', 'numeric', 'gt:0', 'regex:/^-?\d+(\.\d{1,4})?$/']];
        $v = Validator::make(['quantity' => '2.12345'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('quantity', $v->errors()->toArray());
    }

    public function test_opening_quantity_accepts_4_decimal(): void
    {
        $rules = ['quantity' => ['required', 'numeric', 'gt:0', 'regex:/^-?\d+(\.\d{1,4})?$/']];
        $v = Validator::make(['quantity' => '2.1234'], $rules);

        $this->assertEmpty($v->errors()->get('quantity'));
    }

    // ── Opening-balance unit_cost / total / open_amount (money, scale 3) ──────

    public function test_opening_unit_cost_rejects_4_decimal(): void
    {
        $rules = ['unit_cost' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['unit_cost' => '9.9999'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('unit_cost', $v->errors()->toArray());
    }

    public function test_opening_open_amount_accepts_3_decimal(): void
    {
        $rules = ['open_amount' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['open_amount' => '500.000'], $rules);

        $this->assertEmpty($v->errors()->get('open_amount'));
    }
}
