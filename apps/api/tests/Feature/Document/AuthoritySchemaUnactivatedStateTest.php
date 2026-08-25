<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Document\Domain\CountryDocumentSettings;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DTOs\FiscalAuthorityTypes;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalAuthorityMode;
use App\Modules\Document\Domain\Enums\FiscalAuthorityStatus;
use App\Modules\Document\Domain\Enums\PolicyExpertiseStatus;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use Database\Seeders\CountryDocumentSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;
use Tests\Traits\BuildsDeliveryPolicyFixtures;

/**
 * C-QR0a — the fiscal-authority dimension EXISTS in storage in an UNACTIVATED
 * state (SPEC §1 fiscal row, §2.3; program r11 condition 1 makes this lane
 * schema-only).
 *
 * The whole point of splitting the authority work into QR0a (schema) and QR0b
 * (activation) is that the migration can ship, per tenant, days before anything
 * reads it — `RollingTenantMigrationCommand` migrates one tenant at a time, so
 * for a whole window the fleet is MIXED. This test pins the property that makes
 * that window safe: between this migration and C-QR0b the new columns are NULL
 * everywhere, no code path writes them, and posting behaves exactly as before.
 *
 * If a later lane makes any of these go red WITHOUT also shipping the initialiser,
 * the backfill and the guard together, the staged deployment is no longer safe.
 */
final class AuthoritySchemaUnactivatedStateTest extends TestCase
{
    use BuildsDeliveryPolicyFixtures;
    use RefreshDatabase;

    /** @var list<string> */
    private const SETTINGS_COLUMNS = [
        'fiscal_authority_mode',
        'fiscal_authority_types',
        'policy_expertise_status',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryPolicyFixtures();
        (new CountryDocumentSettingsSeeder)->run();
    }

    private function postingService(): DocumentPostingService
    {
        return app(DocumentPostingService::class);
    }

    // ── the columns exist ────────────────────────────────────────────────────

    public function test_the_new_columns_exist_on_both_tables(): void
    {
        foreach (self::SETTINGS_COLUMNS as $column) {
            self::assertTrue(
                Schema::hasColumn('country_document_settings', $column),
                "country_document_settings.{$column} must exist after this lane's migration.",
            );
        }

        self::assertTrue(Schema::hasColumn('documents', 'fiscal_authority_status'));
    }

    // ── nothing is seeded, nothing is backfilled ─────────────────────────────

    public function test_every_seeded_country_settings_row_has_null_in_the_new_columns(): void
    {
        $rows = DB::table('country_document_settings')->get();

        self::assertGreaterThan(0, $rows->count(), 'The seeder must have produced rows, or this proves nothing.');

        foreach ($rows as $row) {
            foreach (self::SETTINGS_COLUMNS as $column) {
                self::assertNull(
                    $row->{$column},
                    "country_document_settings.{$column} must be NULL until C-QR0b seeds it ({$row->country_code}).",
                );
            }
        }
    }

    public function test_every_document_row_has_a_null_authority_status(): void
    {
        $this->dpConfirmedInvoice([$this->dpServiceLine()]);
        $this->dpConfirmedCreditNote([$this->dpPhysicalLine()]);
        $this->dpDraftDeliveryNote([$this->dpPhysicalLine()]);

        $rows = DB::table('documents')->get();

        self::assertGreaterThan(0, $rows->count());

        foreach ($rows as $row) {
            self::assertNull(
                $row->fiscal_authority_status,
                'No writer exists in this lane, so no document may carry an authority status.',
            );
        }
    }

    // ── the application still posts ──────────────────────────────────────────

    public function test_a_document_still_confirms_and_posts_with_the_new_columns_untouched(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpServiceLine()]);

        self::assertSame(DocumentStatus::Confirmed, $invoice->status);

        $posted = $this->postingService()->post($invoice);

        self::assertSame(DocumentStatus::Posted, $posted->status);
        self::assertNull(
            DB::table('documents')->where('id', $invoice->id)->value('fiscal_authority_status'),
            'Posting must not invent an authority status while the dimension is unactivated.',
        );
    }

    public function test_a_credit_note_still_posts(): void
    {
        $creditNote = $this->dpConfirmedCreditNote([$this->dpPhysicalLine()]);

        self::assertSame(DocumentStatus::Posted, $this->postingService()->post($creditNote)->status);
        self::assertNull(DB::table('documents')->where('id', $creditNote->id)->value('fiscal_authority_status'));
    }

    // ── the settings row is still writable without the new fields ────────────

    public function test_the_settings_row_saves_without_the_new_fields(): void
    {
        $row = CountryDocumentSettings::query()->where('country_code', 'TN')->firstOrFail();

        $row->touch();
        (new CountryDocumentSettingsSeeder)->run();

        $reloaded = DB::table('country_document_settings')->where('country_code', 'TN')->first();
        self::assertNotNull($reloaded);

        foreach (self::SETTINGS_COLUMNS as $column) {
            self::assertNull($reloaded->{$column});
        }
    }

    // ── the casts, and nothing but the casts ─────────────────────────────────

    public function test_the_settings_model_casts_the_new_columns(): void
    {
        $row = CountryDocumentSettings::query()->where('country_code', 'TN')->firstOrFail();

        $row->forceFill([
            'fiscal_authority_mode' => FiscalAuthorityMode::Required,
            'fiscal_authority_types' => FiscalAuthorityTypes::of(DocumentType::Invoice, DocumentType::CreditNote),
            'policy_expertise_status' => PolicyExpertiseStatus::Approved,
        ])->save();

        $fresh = CountryDocumentSettings::query()->where('country_code', 'TN')->firstOrFail();

        self::assertSame(FiscalAuthorityMode::Required, $fresh->fiscal_authority_mode);
        self::assertInstanceOf(FiscalAuthorityTypes::class, $fresh->fiscal_authority_types);
        self::assertSame(['invoice', 'credit_note'], $fresh->fiscal_authority_types->toArray());
        self::assertSame(PolicyExpertiseStatus::Approved, $fresh->policy_expertise_status);

        self::assertSame(
            ['invoice', 'credit_note'],
            json_decode(
                (string) DB::table('country_document_settings')->where('country_code', 'TN')->value('fiscal_authority_types'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
            'The JSON column stores the bare list of DocumentType values.',
        );
    }

    public function test_the_document_model_casts_the_authority_status(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpServiceLine()]);

        $invoice->forceFill(['fiscal_authority_status' => FiscalAuthorityStatus::Pending])->save();

        self::assertSame(FiscalAuthorityStatus::Pending, Document::query()->findOrFail($invoice->id)->fiscal_authority_status);
        self::assertSame(
            'pending',
            DB::table('documents')->where('id', $invoice->id)->value('fiscal_authority_status'),
        );
    }

    /**
     * Gate r1 F-2 — the docblock claim on {@see FiscalAuthorityTypes}, executed.
     *
     * The value object canonicalises the set so that "two rows that mean the same
     * thing are equal byte-for-byte in the database", which is what lets C-QR0b's
     * country-parity check be a COMPARISON rather than a set intersection. On
     * PostgreSQL that sentence is only true if the column is `jsonb`: the `json`
     * type has NO equality operator, so `WHERE fiscal_authority_types = ?` raises
     * 42883 and any Eloquent `where()` on the column 500s. SQLite cannot falsify
     * this — there `json` is TEXT and `=` works — which is exactly why the proof
     * has to run on PostgreSQL.
     */
    public function test_the_canonical_form_is_comparable_with_sql_equality(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped(
                'SQL equality on the JSON column is a PostgreSQL type property: SQLite stores json/jsonb '
                .'as TEXT, where `=` always works, so it cannot falsify the jsonb requirement. '
                .'Run with -c phpunit-pgsql.xml.'
            );
        }

        $row = CountryDocumentSettings::query()->where('country_code', 'TN')->firstOrFail();

        // Written in the WRONG order on purpose: canonicalisation is what makes the
        // stored bytes predictable, and jsonb arrays are order-significant.
        $row->forceFill([
            'fiscal_authority_types' => FiscalAuthorityTypes::fromArray(['credit_note', 'invoice']),
        ])->save();

        $matches = DB::table('country_document_settings')
            ->where('country_code', 'TN')
            ->whereRaw('fiscal_authority_types = ?::jsonb', [json_encode(['invoice', 'credit_note'])])
            ->count();

        self::assertSame(1, $matches, 'The canonical form must be findable by SQL equality on jsonb.');

        self::assertSame(
            0,
            DB::table('country_document_settings')
                ->where('country_code', 'TN')
                ->whereRaw('fiscal_authority_types = ?::jsonb', [json_encode(['credit_note', 'invoice'])])
                ->count(),
            'jsonb arrays are order-significant — which is precisely why the DTO canonicalises.',
        );
    }

    /**
     * Gate r1 F-7. A scalar is the plausible mistake — one type written where the SET
     * was meant — and it used to reach `fromArray(iterable)` and die with a raw
     * TypeError, skipping the typed refusal the rest of this design promises.
     */
    public function test_the_cast_refuses_a_scalar_with_the_typed_exception(): void
    {
        $row = CountryDocumentSettings::query()->where('country_code', 'TN')->firstOrFail();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be assigned a');

        $row->forceFill(['fiscal_authority_types' => 'invoice'])->save();
    }

    /**
     * Fail-safe (rule 4 / SPEC §2.3 F-112): the dimension must not become
     * mass-assignable before the lane that owns its writer. A request payload
     * carrying `fiscal_authority_status` must be dropped, not honoured.
     */
    public function test_the_new_columns_are_not_mass_assignable_yet(): void
    {
        self::assertTrue(
            Schema::hasColumn('documents', 'fiscal_authority_status'),
            'This guard is only meaningful once the column exists.',
        );
        self::assertNotContains('fiscal_authority_status', (new Document)->getFillable());

        foreach (self::SETTINGS_COLUMNS as $column) {
            self::assertNotContains($column, (new CountryDocumentSettings)->getFillable());
        }
    }
}
