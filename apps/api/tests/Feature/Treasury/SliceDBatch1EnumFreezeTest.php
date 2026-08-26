<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Domain\Enums\JournalCode;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Treasury\Domain\Enums\InstrumentEventType;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Voucher\Domain\Enums\RedemptionMode;
use App\Modules\Voucher\Domain\Enums\VoucherKind;
use App\Modules\Voucher\Domain\Enums\VoucherSource;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Slice D batch 1 — THE FREEZE PIN. The guard the batch's own docblocks point at.
 *
 * WHY IT EXISTS (r1 treasury C1 / fiscal F-2, both proven live).
 * `EnumCheckParityTest` cannot enforce the "a new enum case needs its own widening
 * migration" obligation, and the r1 gates proved it rather than argued it: the
 * parity gate runs under `RefreshDatabase`, against a schema the batch-1
 * migrations just built, and those migrations re-derive their value list from
 * `Enum::cases()` AT MIGRATE TIME. On a fresh schema the CHECK and the enum
 * therefore agree by construction, forever. A reviewer added a 6th `VoucherStatus`
 * case with NO widening migration and both gates stayed green
 * (`EnumCheckParityTest` 11/790, `SliceDBatch1CheckConstraintsTest` 19/70) — while
 * every tenant already migrated kept the five-value CHECK and would raise SQLSTATE
 * 23514 on the first `escheated` voucher. A per-tenant, runtime, money-table write
 * bomb with zero CI signal.
 *
 * WHAT THIS FILE DOES. It pins the EXACT case list, in order, that the five
 * batch-1 migrations materialised into the 13 `chk_{table}_{column}_enum`
 * constraints on 2026-08-25 — as STRING LITERALS committed here, not derived from
 * the enums. That is the whole point: the frozen copy has to be independent of the
 * thing it is freezing, exactly like `enum_cases_at_acknowledgement` in
 * `enum-check-parity-acknowledgements.json`. Move an enum and this file goes red,
 * in both directions:
 *
 *   ADDED case    → already-migrated tenants reject it (23514). Ship a WIDENING
 *                   migration (`DROP CONSTRAINT IF EXISTS` + `ADD CONSTRAINT`),
 *                   then re-pin here.
 *   REMOVED case  → the DB goes WIDER than the enum. The parity gate reports a NEW
 *                   failure and the O-31 anti-growth ceiling refuses to baseline it
 *                   away, so this costs a NARROWING migration PLUS a per-tenant
 *                   census (any surviving row with the removed value aborts that
 *                   tenant). Live candidates: `JournalEntryStatus::Reversed`
 *                   (spec §R-D3) and the dead `PaymentOrigin` cases (§R-D8);
 *                   LEDGER C-40.
 *   REORDERED     → no migration needed (the admitted SET is unchanged), but the
 *                   materialised SQL text moves, so re-pin here deliberately.
 *
 * DRIVER-FREE BY DESIGN. No database, no `RefreshDatabase`, no `pgsql` guard: it
 * compares PHP constants to PHP constants, so unlike
 * `SliceDBatch1CheckConstraintsTest` it does NOT self-skip on the default SQLite
 * suite and runs everywhere `tests/Feature/Treasury` runs.
 *
 * SCOPE. 13 constraints over 12 distinct enums (`InstrumentStatus` governs both
 * `instrument_events.from_status` and `.to_status`). This is the template for
 * Slice D batches 2..N: every batch ships its own freeze pin next to its
 * migrations.
 */
final class SliceDBatch1EnumFreezeTest extends TestCase
{
    /**
     * The 13 value sets EXACTLY as the five batch-1 migrations wrote them into
     * PostgreSQL on 2026-08-25, transcribed from those migrations' census SQL.
     * Order is `Enum::cases()` declaration order, which is the order the migration
     * materialises.
     *
     * DO NOT regenerate this from the enums. A pin derived from the thing it pins
     * is not a pin.
     *
     * @return array<string, array{0: string, 1: class-string<\BackedEnum>, 2: list<string>}>
     */
    public static function frozenValueSets(): array
    {
        return [
            'chk_vouchers_status_enum' => [
                'chk_vouchers_status_enum',
                VoucherStatus::class,
                ['issued', 'partially_redeemed', 'fully_redeemed', 'expired', 'voided'],
            ],
            'chk_vouchers_source_enum' => [
                'chk_vouchers_source_enum',
                VoucherSource::class,
                ['refund', 'exchange_surplus', 'goodwill', 'loyalty_credit', 'gift_card_purchase', 'promotional'],
            ],
            'chk_vouchers_voucher_kind_enum' => [
                'chk_vouchers_voucher_kind_enum',
                VoucherKind::class,
                ['MPV', 'SPV'],
            ],
            'chk_vouchers_redemption_mode_enum' => [
                'chk_vouchers_redemption_mode_enum',
                RedemptionMode::class,
                ['bearer', 'customer_bound'],
            ],
            'chk_journal_entries_status_enum' => [
                'chk_journal_entries_status_enum',
                JournalEntryStatus::class,
                ['draft', 'posted', 'reversed'],
            ],
            'chk_journal_entries_journal_code_enum' => [
                'chk_journal_entries_journal_code_enum',
                JournalCode::class,
                ['VT', 'AC', 'BQ', 'CA', 'EF', 'OD'],
            ],
            'chk_payments_status_enum' => [
                'chk_payments_status_enum',
                PaymentStatus::class,
                ['pending', 'completed', 'failed', 'reversed'],
            ],
            'chk_payments_payment_type_enum' => [
                'chk_payments_payment_type_enum',
                PaymentType::class,
                // W4R2-2: `pos_refund` joined the enum, so the pinned list moves
                // WITH its widening migration
                // (2026_08_25_150000_widen_payments_payment_type_check_for_pos_refund).
                // This test going red on an enum change with no migration is the
                // whole point — see the …130300 docblock.
                ['document_payment', 'advance', 'refund', 'credit_application', 'supplier_payment', 'pos', 'pos_refund', 'reversal'],
            ],
            'chk_payments_origin_enum' => [
                'chk_payments_origin_enum',
                PaymentOrigin::class,
                ['pos', 'web_admin', 'mobile', 'api', 'unknown_legacy', 'back_office'],
            ],
            'chk_documents_type_enum' => [
                'chk_documents_type_enum',
                DocumentType::class,
                [
                    'quote', 'sales_order', 'purchase_order', 'invoice', 'credit_note', 'delivery_note',
                    'return_note', 'expense', 'supplier_invoice', 'supplier_credit_note', 'income',
                    'purchase_rfq', 'correcting_entry',
                ],
            ],
            'chk_instrument_events_event_type_enum' => [
                'chk_instrument_events_event_type_enum',
                InstrumentEventType::class,
                [
                    'created', 'issued', 'details_updated', 'custody_transferred', 'remitted',
                    'cleared', 'bounced', 're_presented', 'cancelled',
                ],
            ],
            'chk_instrument_events_from_status_enum' => [
                'chk_instrument_events_from_status_enum',
                InstrumentStatus::class,
                [
                    'received', 'in_transit', 'deposited', 'clearing', 'cleared',
                    'bounced', 'expired', 'cancelled', 'collected',
                ],
            ],
            'chk_instrument_events_to_status_enum' => [
                'chk_instrument_events_to_status_enum',
                InstrumentStatus::class,
                [
                    'received', 'in_transit', 'deposited', 'clearing', 'cleared',
                    'bounced', 'expired', 'cancelled', 'collected',
                ],
            ],
        ];
    }

    /**
     * The PostgreSQL column DEFAULTS the batch-1 migrations left in place on the
     * four NOT NULL columns that carry one (r1 treasury M-2).
     *
     * A default OUTSIDE its CHECK's value set is a write bomb on every INSERT that
     * omits the column — PostgreSQL applies the default and the CHECK then rejects
     * it — and NOTHING else sees it: the in-migration census scans existing rows
     * only, and the parity gate compares the CHECK to the enum, never to the
     * default. So it is asserted here, against the same frozen set.
     *
     * @return array<string, array{0: string, 1: string}> label => [constraint, default value]
     */
    public static function columnDefaults(): array
    {
        return [
            'journal_entries.status' => ['chk_journal_entries_status_enum', 'draft'],
            'payments.payment_type' => ['chk_payments_payment_type_enum', 'document_payment'],
            'payments.status' => ['chk_payments_status_enum', 'pending'],
            'vouchers.voucher_kind' => ['chk_vouchers_voucher_kind_enum', 'MPV'],
        ];
    }

    /**
     * @param  class-string<\BackedEnum>  $enum
     * @param  list<string>  $frozen
     */
    #[DataProvider('frozenValueSets')]
    public function test_enum_still_matches_the_value_set_frozen_into_its_check(string $constraint, string $enum, array $frozen): void
    {
        $live = array_map(
            static fn (\BackedEnum $case): string => (string) $case->value,
            $enum::cases(),
        );

        $this->assertSame(
            $frozen,
            $live,
            "{$enum} changed after Slice D batch 1 froze it into `{$constraint}`.\n"
            ."The CHECK on every already-migrated tenant still admits exactly the frozen list, so:\n"
            ."  - a case you ADDED will be rejected there with SQLSTATE 23514 → ship a WIDENING migration;\n"
            ."  - a case you REMOVED leaves the DB WIDER than the enum → ship a NARROWING migration plus a\n"
            ."    per-tenant census (the O-31 ceiling will not let you baseline it away);\n"
            ."  - a pure REORDER needs no migration, only a deliberate re-pin.\n"
            .'Then re-pin the list in '.self::class.'::frozenValueSets(). '
            ."Do NOT 'fix' this by regenerating the pin from the enum — that is the failure this test exists to catch."
        );
    }

    /**
     * @param  list<string>  $frozen
     */
    #[DataProvider('frozenValueSets')]
    public function test_frozen_set_has_no_duplicate_values(string $constraint, string $enum, array $frozen): void
    {
        $this->assertSame(
            $frozen,
            array_values(array_unique($frozen)),
            "The frozen list for {$enum} ({$constraint}) contains a duplicate — a transcription error in the pin itself."
        );
    }

    #[DataProvider('columnDefaults')]
    public function test_column_default_is_inside_the_frozen_value_set(string $constraint, string $default): void
    {
        $sets = self::frozenValueSets();
        $this->assertArrayHasKey($constraint, $sets, "Unknown constraint {$constraint} in the defaults pin.");

        [, , $frozen] = $sets[$constraint];

        $this->assertContains(
            $default,
            $frozen,
            "The PostgreSQL column default '{$default}' is NOT in the value set frozen into {$constraint}.\n"
            .'Every INSERT that omits the column would apply the default and then be rejected with SQLSTATE 23514. '
            ."Neither the in-migration census (existing rows only) nor the parity gate (CHECK vs enum) can see this.\n"
            .'Fix the default with an ALTER TABLE … SET DEFAULT migration, or fix the enum — do not relax this assertion.'
        );
    }

    public function test_the_pin_covers_every_constraint_the_batch_created(): void
    {
        $this->assertCount(
            13,
            self::frozenValueSets(),
            'Slice D batch 1 created 13 CHECK constraints; the freeze pin must carry all 13.'
        );

        $expected = [
            'chk_documents_type_enum',
            'chk_instrument_events_event_type_enum',
            'chk_instrument_events_from_status_enum',
            'chk_instrument_events_to_status_enum',
            'chk_journal_entries_journal_code_enum',
            'chk_journal_entries_status_enum',
            'chk_payments_origin_enum',
            'chk_payments_payment_type_enum',
            'chk_payments_status_enum',
            'chk_vouchers_redemption_mode_enum',
            'chk_vouchers_source_enum',
            'chk_vouchers_status_enum',
            'chk_vouchers_voucher_kind_enum',
        ];

        $actual = array_keys(self::frozenValueSets());
        sort($actual);

        $this->assertSame($expected, $actual);
    }
}
