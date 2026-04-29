# POS Refund Flow Implementation Plan (Phase 1 — Standard Retail, Single-Terminal, NF525-aware)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a standard-retail POS refund flow — receipt scan, partial/full refund, exchange (one-screen-two-fiscal-docs), voucher tender (issue + redeem on this terminal), refund destination policy, manager override, fiscal-grade hash chain v3 — for offline-first single-terminal small shops, without breaking Phase 2 (automotive) compatibility.

**Architecture:** Hexagonal Laravel modules. Voucher is a new module (`apps/api/app/Modules/Voucher/`) that becomes the **canonical redeemable-instrument layer** across all sources (refund-issued, exchange-surplus, goodwill, loyalty-credit redemption, future gift cards / promotional) — unified entity with a `source` discriminator, single redemption pipeline, source-filterable back-office UX. Audit confirmed no voucher primitive exists today; loyalty's `RewardType::Credit` calculates a value but has no payment-row counterpart, and Phase 1.5+ wires it through `VoucherIssuanceService::issueFromLoyaltyCredit`. Receipts move from "seal at create" to "draft → finalize" via a new `ReceiptFinalizationService` and a `pending_seal` lifecycle. Hash payload v3 (RFC 8785 canonical JSON) commits payments, voucher ledger entries, exchange linkage, and audit fields. Voucher is a non-taxable liability (EU Directive 2016/1065) with a tender at redemption — no VAT logic at the voucher layer. Phase 1 voucher domain + customer-history search is **single-terminal** (offline-first: local SQLite is the default lookup path; sync runs asynchronously in the background; lookups never hit the API). Refund/exchange UX is the validated industry pattern: dedicated entry button + scan-from-anywhere with confirmation sheet, cart-style β with "Returning"/"Buying new" sections, manager PIN at confirm gated on cash destination + threshold (not at flow start). Plan extends the existing `Nf525DataProvider` (H3 contract on `dev`); does not modify the Compliance contract.

**Tech Stack:** Laravel 12, PHP 8.4 strict, PostgreSQL 16 (PostgreSQL triggers for receipt immutability), Redis (rate limits), React 19 + Vite + TanStack Query (back-office), Tauri 2 + React 19 + SQLite (POS desktop), Vitest + PHPUnit, PHPStan level 8, Pint, ESLint.

**Spec:** `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md`
**Coordination:** `docs/sessions/2026-04-28-refund-vs-deferred-coordination.md`
**Codex review 1 (spec, round 1):** (in user transcript)
**Codex review 2 (spec, round 2):** `docs/superpowers/reviews/2026-04-28-pos-refund-flow-codex-review-2.md`
**Codex review 3 (plan):** `docs/superpowers/reviews/2026-04-29-pos-refund-flow-plan-codex-review.md`

**Decisions locked from Codex review 3 (2026-04-29):**

1. **Lifecycle column = `fiscal_status`** (existing column, already on `dev`). New values: `pending_seal | fiscalized | voided`. No new `status` column.
2. **Schema version lives on `pos_terminals.fiscal_schema_version`** (default 2). Receipts inherit the terminal's version at finalize time. No `fiscal_schema_version` column on `pos_receipts`.
3. **Offline pre-cutover policy: force-drain.** A terminal refuses cutover to v3 while its local-sync queue has unsynced receipts. Operator must drain before flipping.
4. **Exchange partial state is not allowed.** Single DB transaction creates both pending receipts, finalizes both, persists the idempotency triple atomically. Recovery via `pos_exchange_requests` row.
5. **Endpoints stay two** (`POST /pos/receipts` + `POST /pos/receipts/{id}/payments`). The controller internally drafts (`fiscal_status = pending_seal`) on create; `pay` records payments and calls `ReceiptFinalizationService::finalize()` when the receipt is fully tendered. Backward-compatible API surface.
6. **Local-SQLite mirror task moves before scan dispatcher** (was Task 46 → now Task 38a). Receipt-token scans require local metadata; no API hit during cashier interaction.
7. **PaymentRefundService idempotency = unique partial index on `(company_id, original_payment_id, refund_request_id)`** for refund payment rows. App-layer + DB-layer.

---

## Phasing

The plan is decomposed into **eight phases** with checkpoints. Each phase produces working tested software on its own; phases must land in order. Re-numbered after Codex review 3 to fix sequencing issues; total now 53 tasks.

- **Phase A — Foundations** (Tasks 1-9): hash v3 canonical payload + golden test vectors PHP+TS, fiscal_status `pending_seal` lifecycle, terminal `fiscal_schema_version` migration, ReceiptFinalizationService + integration into ReceiptCreationService / ReceiptPaymentService / ReceiptController / ReceiptSyncService / OrderToReceiptService, eco-tax columns (Phase 2 forward compatibility).
- **Phase B — Voucher core** (Tasks 10-16): Voucher entity + ledger + GL entries (non-taxable model), code generator, single-terminal redemption, expiry/void, layered rate limit lookup, cascade.
- **Phase C — Refund destination + proration** (Tasks 17-22): policy settings extension on `Company.ValueObjects.ReservationSettings` (correct path), RefundDestinationResolver, PaymentRefundService proration with `original_payment_id` + `refund_request_id` columns + DB-level unique partial index + payment_type bug fix, permissions seeder, instrument_serial rename + discriminator with restaurant_voucher rejection guard.
- **Phase D — Receipt lookup** (Tasks 23-26): tenant signing keys, ReceiptLookupService with QR `v:kid:receipt_uuid:mac` + full verifier matrix (tampered MAC, retired kid, expired token, replay, cross-tenant, cross-company), CustomerHistorySearchService (this-terminal-only), receipt printer QR embed.
- **Phase E — ReceiptReturnService refactor** (Tasks 27-33): decomposed into return-draft builder, RefundDestinationResolver integration, voucher issuance pre-finalization, payment refund + cash-drawer side effects, finalization with voucher ledger payload, DB-level idempotency on `refund_request_id`, chain/inventory rollback tests.
- **Phase F — Exchange flow** (Tasks 34-38): `pos_exchange_requests` idempotency table BEFORE ExchangeService, ExchangeService skeleton with idempotency triple persistence, both halves committed via `exchange_group_id` in v3 hash, stock locking through both halves, surplus → voucher.
- **Phase G — Z-report v3 + Nf525DataProvider population + cutover** (Tasks 39-43): sale-vs-return split, voucher counters, **Nf525DataProvider population (was missing)**, per-terminal cutover gate (refuses while local-sync queue non-empty), golden v2→v3 chain test matrix (empty-shift / just-after-Z / pending-pre-cutover-sync / mixed-version-refusal), legacy hash test audit pass.
- **Phase H — Frontend (web + Tauri)** (Tasks 44-53): **local-SQLite voucher/receipt mirror first (was Task 46, now Task 44)**, then back-office voucher list + goodwill modal + POS Refund Policies page + customer-history audit page + scan dispatcher with confirmation sheet → cart-with-sections wiring + manager-PIN-at-confirm + voucher tender at payment + receipt printer.

**Pre-flight at the end of each phase:** `./scripts/preflight.sh` clean, targeted tests green, manual smoke at the affected screen.

**Branch:** `feat/refund-flow` (created from `origin/dev`).

**Coordination dependency:** H3 (`Nf525DataProviderContract` + DTOs) is on `origin/dev`. Refund-flow extends `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php` (the POS-side implementation) only; does not modify the contract or DTOs.

---

## Task 1: Create the refund-flow worktree + branch

**Files:**
- New: `feat/refund-flow` branch off `origin/dev`

- [ ] **Step 1: Verify clean working tree on `dev`**

```bash
git fetch origin
git status
```

Expected: working tree matches `origin/dev` HEAD. If not, stop — don't pollute the new branch with stray changes.

- [ ] **Step 2: Create the worktree**

Use the `superpowers:using-git-worktrees` skill. Branch name: `feat/refund-flow`. Base: `origin/dev`.

- [ ] **Step 3: Verify the H3 contract is present in the worktree**

```bash
ls apps/api/app/Shared/Contracts/Compliance/Nf525DataProviderContract.php
ls apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php
ls apps/api/app/Shared/Contracts/Compliance/DTOs/Nf525VoucherLedgerEntryData.php
```

Expected: all three files exist. If any are missing, `dev` was not as advertised — stop and re-confirm with the orchestrator.

- [ ] **Step 4: Verify preflight passes on a clean `dev` baseline**

```bash
./scripts/preflight.sh
```

Expected: clean. If not, investigate before any new code lands.

- [ ] **Step 5: Commit a `.refund-flow-baseline` marker**

Create an empty file `docs/.refund-flow-baseline-2026-04-28` to anchor the branch and verify the commit pipeline works.

```bash
touch docs/.refund-flow-baseline-2026-04-28
git add docs/.refund-flow-baseline-2026-04-28
git commit -m "chore(refund-flow): branch baseline marker"
```

---

## Task 2: Define the v3 canonical receipt-hash format — PHP

**Files:**
- Create: `apps/api/app/Modules/POS/Domain/Services/Fiscal/V3/CanonicalPayloadBuilder.php`
- Create: `apps/api/app/Modules/POS/Domain/Services/Fiscal/V3/CanonicalJsonEncoder.php`
- Test: `apps/api/tests/Unit/POS/Fiscal/V3/CanonicalJsonEncoderTest.php`
- Fixtures: `apps/api/tests/Fixtures/Fiscal/v3-golden-hashes/01-cash-only-eur.json`, `02-mixed-tender-tnd.json`, `03-voucher-tender-eur.json`, `04-stacked-vouchers-eur.json`, `05-return-with-voucher-issuance-eur.json`, `06-exchange-pair-eur.json`, `07-tnd-residual.json`

- [ ] **Step 1: Write the failing test for canonical JSON encoding**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\POS\Fiscal\V3;

use App\Modules\POS\Domain\Services\Fiscal\V3\CanonicalJsonEncoder;
use Tests\TestCase;

final class CanonicalJsonEncoderTest extends TestCase
{
    public function test_encodes_empty_object_as_RFC_8785(): void
    {
        $encoder = new CanonicalJsonEncoder();
        $this->assertSame('{}', $encoder->encode([]));
    }

    public function test_sorts_keys_lexicographically(): void
    {
        $encoder = new CanonicalJsonEncoder();
        $this->assertSame('{"a":1,"b":2}', $encoder->encode(['b' => 2, 'a' => 1]));
    }

    public function test_serializes_null_as_null_token(): void
    {
        $encoder = new CanonicalJsonEncoder();
        $this->assertSame('{"x":null}', $encoder->encode(['x' => null]));
    }

    public function test_decimal_strings_passthrough_without_quoting(): void
    {
        // RFC 8785 says strings stay strings; numeric strings stay strings.
        $encoder = new CanonicalJsonEncoder();
        $this->assertSame('{"amount":"12.345"}', $encoder->encode(['amount' => '12.345']));
    }

    public function test_escapes_unicode_per_RFC_8785(): void
    {
        $encoder = new CanonicalJsonEncoder();
        $this->assertSame('{"name":"café"}', $encoder->encode(['name' => 'café']));
    }

    public function test_arrays_preserve_order(): void
    {
        $encoder = new CanonicalJsonEncoder();
        $this->assertSame('{"x":[1,2,3]}', $encoder->encode(['x' => [1, 2, 3]]));
    }
}
```

- [ ] **Step 2: Run the test, watch it fail**

```bash
cd apps/api
./vendor/bin/phpunit --filter CanonicalJsonEncoderTest
```

Expected: FAIL with "class not found".

- [ ] **Step 3: Write the canonical JSON encoder**

```php
<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Services\Fiscal\V3;

/**
 * RFC 8785 / JSON Canonicalization Scheme (JCS) encoder.
 *
 * Produces byte-identical output for byte-identical input across PHP and
 * TypeScript implementations. Used for v3 receipt-hash payloads.
 */
final class CanonicalJsonEncoder
{
    /**
     * @param  array<string, mixed>|list<mixed>  $value
     */
    public function encode(array $value): string
    {
        return $this->encodeValue($value);
    }

    private function encodeValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return $this->encodeString($value);
        }
        if (is_array($value)) {
            return array_is_list($value)
                ? $this->encodeArray($value)
                : $this->encodeObject($value);
        }
        throw new \InvalidArgumentException('Unsupported value type: '.get_debug_type($value));
    }

    /** @param  array<string, mixed>  $value */
    private function encodeObject(array $value): string
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        $parts = [];
        foreach ($keys as $key) {
            $parts[] = $this->encodeString((string) $key).':'.$this->encodeValue($value[$key]);
        }
        return '{'.implode(',', $parts).'}';
    }

    /** @param  list<mixed>  $value */
    private function encodeArray(array $value): string
    {
        return '['.implode(',', array_map(fn ($v) => $this->encodeValue($v), $value)).']';
    }

    private function encodeString(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
```

- [ ] **Step 4: Run the test, watch it pass**

```bash
./vendor/bin/phpunit --filter CanonicalJsonEncoderTest
```

Expected: PASS.

- [ ] **Step 5: Write a failing test for the canonical payload builder (cash-only EUR)**

Add to `CanonicalPayloadBuilderTest.php`:

```php
public function test_builds_v3_payload_for_cash_only_eur_receipt(): void
{
    $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/Fiscal/v3-golden-hashes/01-cash-only-eur.json')), true);

    $builder = new CanonicalPayloadBuilder($this->scaleResolver);
    $actual = $builder->build($fixture['input']);

    $this->assertSame($fixture['expected_canonical'], $actual);
    $this->assertSame($fixture['expected_hash'], hash('sha256', $actual));
}
```

The fixture file is the source of truth — a JSON document with `{input, expected_canonical, expected_hash}` keys.

- [ ] **Step 6: Build the fixture file `01-cash-only-eur.json`**

```json
{
  "input": {
    "receipt_number": "R-2026-000001",
    "posted_at": "2026-04-28T10:15:30Z",
    "previous_hash": "abc123",
    "total": "12.50",
    "currency": "EUR",
    "vat_breakdown": [{"rate": "0.20", "amount": "2.08"}],
    "payments": [{"method_code": "cash", "payment_type": "pos", "amount": "12.50", "instrument_type": null, "instrument_serial": null}],
    "voucher_ledger_entries": [],
    "exchange_group_id": null,
    "audit": null
  },
  "expected_canonical": "<filled by encoder during fixture-bootstrap step>",
  "expected_hash": "<filled by encoder during fixture-bootstrap step>"
}
```

The first time this fixture is built, run the encoder to produce `expected_canonical` + `expected_hash` and commit them. After that, the fixture is read-only — any changes to the encoder must keep the fixture's `expected_hash` stable.

- [ ] **Step 7: Implement `CanonicalPayloadBuilder`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Services\Fiscal\V3;

use App\Shared\Contracts\CurrencyScaleResolverInterface;

/**
 * Builds the v3 canonical-JSON payload for a receipt hash.
 *
 * Top-level keys (lexicographic): audit_hash, currency, exchange_group_id,
 * payment_methods_hash, posted_at, previous_hash, receipt_number,
 * schema_version (3), total, vat_breakdown_hash, voucher_ledger_hash.
 */
final class CanonicalPayloadBuilder
{
    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * @param  array{
     *   receipt_number: string,
     *   posted_at: string,
     *   previous_hash: ?string,
     *   total: string,
     *   currency: string,
     *   vat_breakdown: list<array{rate: string, amount: string}>,
     *   payments: list<array{method_code: string, payment_type: string, amount: string, instrument_type: ?string, instrument_serial: ?string}>,
     *   voucher_ledger_entries: list<array{voucher_id: string, voucher_code: string, event: string, amount: string, gl_journal_entry_id: ?string}>,
     *   exchange_group_id: ?string,
     *   audit: ?array{authorized_by_user_id: ?string, override_reason: ?string, policy_trigger: ?string, out_of_window: ?bool, refund_request_id: ?string}
     * }  $input
     */
    public function build(array $input): string
    {
        $encoder = new CanonicalJsonEncoder();

        $payments = $input['payments'];
        usort($payments, function ($a, $b) {
            return [$a['method_code'], $a['instrument_type'] ?? '', $a['instrument_serial'] ?? '', $a['amount']]
                <=> [$b['method_code'], $b['instrument_type'] ?? '', $b['instrument_serial'] ?? '', $b['amount']];
        });

        $vat = $input['vat_breakdown'];
        usort($vat, fn ($a, $b) => $a['rate'] <=> $b['rate']);

        $voucher = $input['voucher_ledger_entries'];
        usort($voucher, fn ($a, $b) => $a['voucher_code'] <=> $b['voucher_code']);

        $payload = [
            'audit_hash' => hash('sha256', $encoder->encode($input['audit'] ?? [])),
            'currency' => strtoupper($input['currency']),
            'exchange_group_id' => $input['exchange_group_id'],
            'payment_methods_hash' => hash('sha256', $encoder->encode($payments)),
            'posted_at' => $input['posted_at'],
            'previous_hash' => $input['previous_hash'],
            'receipt_number' => $input['receipt_number'],
            'schema_version' => 3,
            'total' => $input['total'],
            'vat_breakdown_hash' => hash('sha256', $encoder->encode($vat)),
            'voucher_ledger_hash' => hash('sha256', $encoder->encode($voucher)),
        ];

        return $encoder->encode($payload);
    }
}
```

- [ ] **Step 8: Run the cash-only fixture, watch it pass**

```bash
./vendor/bin/phpunit --filter CanonicalPayloadBuilderTest::test_builds_v3_payload_for_cash_only_eur_receipt
```

Expected: PASS. If `expected_canonical`/`expected_hash` were placeholders, the test will fail once and you commit the encoder output. From then on, the test is the regression guard.

- [ ] **Step 9: Add the remaining 6 fixtures with goldens**

Repeat the pattern for fixtures `02-` through `07-`. Each tests a different shape (mixed tender, voucher tender, stacked vouchers, return with voucher issuance, exchange pair, TND residual). Write the failing test first, build the fixture file (placeholder hashes), run the encoder once to populate, commit.

- [ ] **Step 10: Verify all 7 fixtures pass and PHPStan is clean**

```bash
./vendor/bin/phpunit --filter Canonical
./vendor/bin/phpstan analyse --level=8 app/Modules/POS/Domain/Services/Fiscal
./vendor/bin/pint app/Modules/POS/Domain/Services/Fiscal --test
```

Expected: all green.

- [ ] **Step 11: Commit**

```bash
git add apps/api/app/Modules/POS/Domain/Services/Fiscal/V3/ apps/api/tests/Unit/POS/Fiscal/V3/ apps/api/tests/Fixtures/Fiscal/v3-golden-hashes/
git commit -m "feat(pos): add v3 canonical receipt-hash payload builder + 7 golden fixtures"
```

---

## Task 3: Mirror the v3 canonical encoder in TypeScript (Tauri client) — byte-identical to PHP

**Files:**
- Create: `apps/pos/src/lib/fiscal/v3/canonicalJson.ts`
- Create: `apps/pos/src/lib/fiscal/v3/canonicalPayload.ts`
- Test: `apps/pos/src/lib/fiscal/v3/__tests__/canonicalJson.test.ts`
- Fixtures: `apps/pos/src/lib/fiscal/v3/__fixtures__/v3-golden-hashes/01-cash-only-eur.json` ... `07-tnd-residual.json` (copies of the PHP fixtures, identical bytes)

- [ ] **Step 1: Write a failing test that round-trips fixture 01 through the TS encoder**

```typescript
import { describe, it, expect } from 'vitest';
import { canonicalJson } from '../canonicalJson';
import { buildCanonicalPayload } from '../canonicalPayload';
import fixture01 from '../__fixtures__/v3-golden-hashes/01-cash-only-eur.json';

describe('v3 canonical payload — TS', () => {
  it('produces byte-identical canonical bytes for cash-only EUR fixture', async () => {
    const actual = await buildCanonicalPayload(fixture01.input);
    expect(actual).toBe(fixture01.expected_canonical);
  });

  it('produces byte-identical SHA-256 hash for cash-only EUR fixture', async () => {
    const actual = await buildCanonicalPayload(fixture01.input);
    const enc = new TextEncoder().encode(actual);
    const buf = await crypto.subtle.digest('SHA-256', enc);
    const hex = Array.from(new Uint8Array(buf))
      .map((b) => b.toString(16).padStart(2, '0'))
      .join('');
    expect(hex).toBe(fixture01.expected_hash);
  });
});
```

- [ ] **Step 2: Run the test, watch it fail**

```bash
cd apps/pos
pnpm test --run canonical
```

Expected: FAIL with "module not found".

- [ ] **Step 3: Implement `canonicalJson.ts` (RFC 8785 in TS)**

```typescript
export function canonicalJson(value: unknown): string {
  if (value === null) return 'null';
  if (typeof value === 'boolean') return value ? 'true' : 'false';
  if (typeof value === 'number') {
    if (!Number.isInteger(value)) {
      throw new Error('canonicalJson: only integers supported; serialize decimals as strings');
    }
    return String(value);
  }
  if (typeof value === 'string') return JSON.stringify(value);
  if (Array.isArray(value)) {
    return '[' + value.map(canonicalJson).join(',') + ']';
  }
  if (typeof value === 'object') {
    const keys = Object.keys(value as Record<string, unknown>).sort();
    return (
      '{' +
      keys
        .map((k) => JSON.stringify(k) + ':' + canonicalJson((value as Record<string, unknown>)[k]))
        .join(',') +
      '}'
    );
  }
  throw new Error(`canonicalJson: unsupported type ${typeof value}`);
}
```

- [ ] **Step 4: Implement `canonicalPayload.ts` (mirror of PHP `CanonicalPayloadBuilder`)**

Same field order, same sort rules. Use Web Crypto for SHA-256 of subobjects.

- [ ] **Step 5: Copy the 7 PHP fixture files into `apps/pos/src/lib/fiscal/v3/__fixtures__/v3-golden-hashes/`**

```bash
cp apps/api/tests/Fixtures/Fiscal/v3-golden-hashes/*.json apps/pos/src/lib/fiscal/v3/__fixtures__/v3-golden-hashes/
```

These must remain bit-identical to the PHP copies. Add a CI lint that fails if they diverge.

- [ ] **Step 6: Run all 7 fixture tests**

```bash
cd apps/pos
pnpm test --run canonical
```

Expected: 14 assertions pass (canonical bytes + hash for each of 7 fixtures).

- [ ] **Step 7: Add a CI lint asserting fixture parity**

Create `apps/pos/scripts/check-fiscal-fixture-parity.sh`:

```bash
#!/bin/bash
set -e
PHP_FIXTURES=$(realpath apps/api/tests/Fixtures/Fiscal/v3-golden-hashes)
TS_FIXTURES=$(realpath apps/pos/src/lib/fiscal/v3/__fixtures__/v3-golden-hashes)
diff -r "$PHP_FIXTURES" "$TS_FIXTURES"
echo "Fiscal fixture parity OK"
```

Wire it into `scripts/preflight.sh`. Failing this lint blocks any PR.

- [ ] **Step 8: Commit**

```bash
git add apps/pos/src/lib/fiscal/v3/ apps/pos/scripts/check-fiscal-fixture-parity.sh scripts/preflight.sh
git commit -m "feat(pos-tauri): mirror v3 canonical hash in TS with PHP fixture parity check"
```

---

## Task 4: Migration — `pos_receipts.fiscal_hash`/`chain_sequence` nullable + `pos_terminals.fiscal_schema_version` + `fiscal_status` lifecycle values

**Files:**
- Create: `apps/api/database/migrations/2026_05_01_000001_prepare_pos_receipts_for_pending_seal.php`
- Create: `apps/api/database/migrations/2026_05_01_000002_add_fiscal_schema_version_to_pos_terminals.php`
- Create: `apps/api/database/migrations/2026_05_01_000003_update_pos_receipts_immutability_trigger_for_pending_seal.php`
- Test: `apps/api/tests/Feature/POS/Migrations/PendingSealMigrationTest.php`

**Decision (Codex review 3 Finding A): use the existing `fiscal_status` column** (already on `dev` from the offline-sync migration `2026_04_18_211150`). New values join the existing set: `pending_seal | fiscalized | voided` (and any pre-existing values like `synced` remain). No new `status` column. **Schema version on terminal only**, not receipt.

- [ ] **Step 1: Write the failing migration test**

```php
public function test_pos_receipts_fiscal_hash_is_nullable_after_migration(): void
{
    Artisan::call('migrate');
    $columnInfo = DB::selectOne("SELECT is_nullable FROM information_schema.columns WHERE table_name = 'pos_receipts' AND column_name = 'fiscal_hash'");
    $this->assertSame('YES', $columnInfo->is_nullable);
}

public function test_pos_terminals_has_fiscal_schema_version_default_2(): void
{
    Artisan::call('migrate');
    $columnInfo = DB::selectOne("SELECT column_default FROM information_schema.columns WHERE table_name = 'pos_terminals' AND column_name = 'fiscal_schema_version'");
    $this->assertSame('2', trim((string) $columnInfo->column_default, "'"));
}

public function test_pending_seal_fiscal_status_can_transition_to_fiscalized(): void
{
    // Insert a receipt with fiscal_status = 'pending_seal', update to 'fiscalized', assert no trigger raise.
}

public function test_fiscalized_receipt_cannot_revert_to_pending_seal(): void
{
    // Trigger should reject backward transitions.
}
```

- [ ] **Step 2: Watch it fail**

- [ ] **Step 3: Write the migration**

```php
public function up(): void
{
    Schema::table('pos_receipts', function (Blueprint $table) {
        $table->string('fiscal_hash', 64)->nullable()->change();
        $table->integer('chain_sequence')->nullable()->change();
    });

    // Extend fiscal_status check constraint to include 'pending_seal'
    DB::statement("ALTER TABLE pos_receipts DROP CONSTRAINT IF EXISTS pos_receipts_fiscal_status_check");
    DB::statement("ALTER TABLE pos_receipts ADD CONSTRAINT pos_receipts_fiscal_status_check CHECK (fiscal_status IN ('pending_seal', 'fiscalized', 'voided', 'synced'))");
    // Note: 'synced' kept for backward-compat with offline-sync flow; pre-existing values audited and left in place.
}
```

- [ ] **Step 4: Add `pos_terminals.fiscal_schema_version`**

```php
public function up(): void
{
    Schema::table('pos_terminals', function (Blueprint $table) {
        $table->smallInteger('fiscal_schema_version')->default(2);
    });
}
```

- [ ] **Step 5: Update the immutability trigger to allow `pending_seal → fiscalized`**

```php
public function up(): void
{
    DB::statement("
        CREATE OR REPLACE FUNCTION pos_receipts_immutability_trigger() RETURNS trigger AS $$
        BEGIN
            -- Allow fiscal_status transition pending_seal -> fiscalized (single-direction)
            IF OLD.fiscal_status = 'pending_seal' AND NEW.fiscal_status = 'fiscalized' THEN
                RETURN NEW;
            END IF;
            -- Allow void status flip from fiscalized -> voided (existing behavior)
            IF OLD.fiscal_status = 'fiscalized' AND NEW.fiscal_status = 'voided' THEN
                RETURN NEW;
            END IF;
            -- Reject any other update on a fiscalized receipt
            IF OLD.fiscal_status = 'fiscalized' THEN
                RAISE EXCEPTION 'Receipt is immutable after fiscalization';
            END IF;
            -- Reject pending_seal -> anything-but-fiscalized
            IF OLD.fiscal_status = 'pending_seal' AND NEW.fiscal_status NOT IN ('pending_seal', 'fiscalized') THEN
                RAISE EXCEPTION 'pending_seal can only transition to fiscalized';
            END IF;
            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;
    ");
}
```

- [ ] **Step 6: Update `Receipt` and `Terminal` model fillable + casts**

`Receipt`: `fiscal_status` already in fillable; verify the FiscalStatus enum (or string cast) accepts the new values.
`Terminal`: add `fiscal_schema_version` to fillable, cast to integer.

- [ ] **Step 7: Run migrations and tests**

```bash
cd apps/api
php artisan migrate:fresh --env=testing
./vendor/bin/phpunit --filter PendingSealMigrationTest
```

Expected: PASS.

- [ ] **Step 8: Commit**

---

## Task 5: `ReceiptFinalizationService` — finalize a v2 cash sale with legacy hash parity

**Files:**
- Create: `apps/api/app/Modules/POS/Application/Services/ReceiptFinalizationService.php`
- Test: `apps/api/tests/Feature/POS/ReceiptFinalizationServiceTest.php`

- [ ] **Step 1: Write the failing test — finalize a v2 cash receipt produces the legacy hash byte-for-byte**

- [ ] **Step 2: Watch it fail**

- [ ] **Step 3: Implement `ReceiptFinalizationService::finalize()` with version dispatch**

```php
public function finalize(Receipt $receipt): Receipt
{
    return DB::transaction(function () use ($receipt) {
        if ($receipt->fiscal_status !== FiscalStatus::PendingSeal) {
            return $receipt; // idempotent: re-call returns the existing record
        }

        $terminal = $receipt->terminal()->lockForUpdate()->first();
        $version = $terminal->fiscal_schema_version;

        $receipt->fiscal_hash = match ($version) {
            2 => $this->legacyHashService->compute($receipt),
            3 => $this->v3HashService->compute($receipt),
            default => throw new \LogicException("Unknown fiscal_schema_version: {$version}"),
        };
        $receipt->previous_hash = $terminal->last_hash;
        $receipt->chain_sequence = $terminal->current_sequence;
        $receipt->fiscal_status = FiscalStatus::Fiscalized;
        $receipt->save();

        $terminal->last_hash = $receipt->fiscal_hash;
        $terminal->current_sequence++;
        $terminal->save();

        return $receipt;
    });
}
```

- [ ] **Step 4: Run the test, watch v2 parity pass**

- [ ] **Step 5: Add a v3 finalize test** using fixture 01 (cash-only EUR), terminal at version=3. Assert finalized hash equals fixture's `expected_hash`.

- [ ] **Step 6: Commit**

---

## Task 6: Migrate `ReceiptCreationService` to draft-first (no chain advancement on create)

**Files:**
- Modify: `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php`
- Test: `apps/api/tests/Feature/POS/ReceiptCreationServicePendingSealTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_create_writes_pending_seal_receipt_without_fiscal_hash(): void
{
    $receipt = $this->service->createReceipt($this->saleData, $this->terminal, $this->cashier);
    $this->assertSame(FiscalStatus::PendingSeal, $receipt->fiscal_status);
    $this->assertNull($receipt->fiscal_hash);
    $this->assertNull($receipt->chain_sequence);
}

public function test_create_does_not_advance_terminal_sequence(): void
{
    $sequenceBefore = $this->terminal->current_sequence;
    $this->service->createReceipt(...);
    $this->terminal->refresh();
    $this->assertSame($sequenceBefore, $this->terminal->current_sequence);
}

public function test_create_does_not_set_terminal_last_hash(): void { ... }
```

- [ ] **Step 2: Watch it fail**

- [ ] **Step 3: Refactor `ReceiptCreationService::createReceipt()`** — remove inline hash computation (lines 478, 480, 549, 563, 589 on `dev`); set `fiscal_status = pending_seal`; do NOT advance terminal `last_hash` or `current_sequence`. Returns a draft.

- [ ] **Step 4: Existing tests asserting fiscalized-at-create now fail** — audit them, update to either (a) call `finalize()` after create, or (b) assert pending_seal directly. Document each change in the commit message.

- [ ] **Step 5: Commit**

---

## Task 7: Migrate `ReceiptPaymentService` to finalize-when-fully-tendered

**Files:**
- Modify: `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php`
- Modify: `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php` (controller wiring)
- Test: `apps/api/tests/Feature/POS/ReceiptPaymentServiceFinalizationTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_pay_finalizes_pending_receipt_when_fully_tendered(): void
{
    $draft = $this->createService->createReceipt(...); // pending_seal
    $finalized = $this->payService->recordPayments($draft, $this->fullCashTender, $this->cashier);
    $this->assertSame(FiscalStatus::Fiscalized, $finalized->fiscal_status);
    $this->assertNotNull($finalized->fiscal_hash);
    $this->terminal->refresh();
    $this->assertSame($finalized->fiscal_hash, $this->terminal->last_hash);
}

public function test_pay_keeps_pending_when_partial_tender(): void
{
    $draft = $this->createService->createReceipt(...);
    $partial = $this->payService->recordPayments($draft, $this->halfCashTender, $this->cashier);
    $this->assertSame(FiscalStatus::PendingSeal, $partial->fiscal_status);
}

public function test_pay_is_idempotent_against_concurrent_calls(): void { ... }
```

- [ ] **Step 2: Watch tests fail**

- [ ] **Step 3: Refactor `ReceiptPaymentService::recordPayments()`** to write payment rows then check `tendered >= total`; if fully tendered, call `ReceiptFinalizationService::finalize($receipt)`.

- [ ] **Step 4: Update `ReceiptController` flow** to handle the new draft → pay → optional-finalize lifecycle. The two endpoints (`POST /pos/receipts`, `POST /pos/receipts/{id}/payments`) preserved; semantics evolve.

- [ ] **Step 5: Migrate `OrderToReceiptService`** (held-order conversion) — same draft-then-pay pattern.

- [ ] **Step 6: Run preflight + a manual end-to-end smoke** of the cash-sale flow at the POS to confirm receipts still print and chain advances.

- [ ] **Step 7: Commit**

---

## Task 8: Migrate `ReceiptSyncService` to v3-aware finalization with offline_fiscal_hash verification

**Files:**
- Modify: `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php`
- Modify: `apps/api/app/Modules/POS/Application/DTOs/SyncReceiptPayload.php` — add `fiscal_schema_version` to payload
- Modify: `apps/api/app/Modules/POS/Presentation/Requests/SyncReceiptsRequest.php` — accept the version
- Test: `apps/api/tests/Feature/POS/ReceiptSyncServiceV3Test.php`

**Codex review 3 Finding C.** Without this task, offline receipts synced after a v3 cutover break chain continuity.

- [ ] **Step 1: Failing tests**

```php
public function test_sync_v2_payload_against_v2_terminal_recomputes_legacy_hash_and_matches_offline(): void { ... }
public function test_sync_v3_payload_against_v3_terminal_recomputes_v3_hash_and_matches_offline(): void { ... }
public function test_sync_rejects_v2_payload_against_v3_terminal_when_post_cutover_strict(): void { ... }
public function test_sync_accepts_v2_payload_pre_cutover_during_drain_window(): void { ... }
public function test_sync_recomputed_hash_mismatching_offline_hash_fails_with_tamper_error(): void { ... }
```

- [ ] **Step 2: Watch them fail**

- [ ] **Step 3: Implement v3-aware sync**

- Server pulls `fiscal_schema_version` from the offline payload (default 2 if missing for backward compat).
- Server-side recompute: dispatch on the payload's claimed version.
- Server compares its recomputed hash against `offline_fiscal_hash` in the payload. **Mismatch = reject as tamper / version drift.**
- Cutover queue-drain rule (Finding C decision): a terminal at v3 refuses v2 payloads after its cutover timestamp, except those whose `created_at` is BEFORE the cutover timestamp (those are pre-cutover offline drafts allowed to drain).
- Sync writes payment rows + voucher ledger rows + finalizes via `ReceiptFinalizationService::finalize()` in one transaction.

- [ ] **Step 4: Update Tauri client `SyncReceiptPayload` builder** to include `fiscal_schema_version` (the value the terminal was at when the receipt was drafted offline).

- [ ] **Step 5: Commit**

---

## Task 9: Eco-tax columns (Phase 2 forward compatibility) — schema only, no writers

**Files:**
- Create: `apps/api/database/migrations/2026_05_01_000004_add_eco_tax_fields_to_pos_receipt_lines.php`
- Create: `apps/api/database/migrations/2026_05_01_000005_add_eco_tax_fields_to_document_lines.php`
- Modify: `apps/api/app/Modules/POS/Domain/ReceiptLine.php` (add to `$fillable` + casts)
- Modify: `apps/api/app/Modules/Document/Domain/DocumentLine.php` (add to `$fillable` + casts)
- Test: `apps/api/tests/Feature/POS/Migrations/EcoTaxFieldsMigrationTest.php`

**Codex review 3 Finding J.** Spec promises Phase 1 ships columns; plan was missing the migration.

- [ ] **Step 1: Test that columns exist on both tables, all nullable, no defaults**
- [ ] **Step 2: Watch it fail**
- [ ] **Step 3: Write migrations: `eco_tax_amount` decimal(20,5) nullable, `eco_tax_rate` decimal(8,4) nullable, `eco_tax_category` string nullable, on `pos_receipt_lines` and `document_lines`**
- [ ] **Step 4: Update `$fillable` on both models. No writer integration in Phase 1 — fields stay null on every row written.**
- [ ] **Step 5: Run preflight, commit**

**Phase A checkpoint.** Run preflight. Smoke-test:
- A cash sale at the POS still completes (creates draft → records payment → finalizes → chain advances).
- An offline-then-sync cycle still works (Tauri client → ReceiptSyncService → v2 finalization).
- Existing PHPUnit tests pass (some will have been updated to assert `pending_seal` then explicit finalize; document each in commit messages).
- `verify-chain` returns true on a fresh tenant's terminal after a series of test sales.

If green, push the branch. Phase A is done.

---

## Task 6: Migration — `vouchers` + `voucher_ledger` tables

**Files:**
- Create: `apps/api/database/migrations/2026_05_02_000001_create_vouchers_table.php`
- Create: `apps/api/database/migrations/2026_05_02_000002_create_voucher_ledger_table.php`
- Test: `apps/api/tests/Feature/Voucher/VoucherSchemaTest.php`

- [ ] **Step 1: Write the failing schema test**

```php
public function test_vouchers_table_exists_with_required_columns(): void
{
    $cols = DB::select("SELECT column_name FROM information_schema.columns WHERE table_name = 'vouchers'");
    $names = array_map(fn ($c) => $c->column_name, $cols);
    $this->assertContains('code', $names);
    $this->assertContains('initial_balance', $names);
    $this->assertContains('current_balance', $names);
    $this->assertContains('currency', $names);
    $this->assertContains('status', $names);
    $this->assertContains('redemption_mode', $names);
    $this->assertContains('voucher_kind', $names);
    $this->assertContains('redeemable_at_terminal_id', $names);
    $this->assertContains('issued_to_partner_id', $names);
    $this->assertContains('issued_at_terminal_id', $names);
    $this->assertContains('source_receipt_id', $names);
    $this->assertContains('expires_at', $names);
    $this->assertContains('authorized_by_user_id', $names);
}
```

- [ ] **Step 2: Watch it fail**

- [ ] **Step 3: Write `create_vouchers_table` migration**

Internal balance scale = currency_scale + 2 (TND scale 5, EUR scale 4). Use `decimal(20, 5)` to cover the largest scale we expect.

The schema must include the **source discriminator**: column `source` (enum: `refund | exchange_surplus | goodwill | loyalty_credit | gift_card_purchase | promotional`) NOT NULL. Per-source nullable foreign keys: `source_receipt_id` (refund/exchange), `source_loyalty_transaction_id` (loyalty_credit, Phase 1.5 use), `source_promotional_campaign_id` (Phase 2+). Index on `(tenant_id, source, status)` so the back-office "Vouchers & Credits" page can filter by source efficiently.

- [ ] **Step 4: Write `create_voucher_ledger_table` migration**

Append-only: ledger entries never updated. Add a row-level trigger rejecting UPDATE/DELETE.

- [ ] **Step 5: Run schema test**

Expected: PASS.

- [ ] **Step 6: Commit**

---

## Task 7: `Voucher` and `VoucherLedger` Eloquent entities + enums

**Files:**
- Create: `apps/api/app/Modules/Voucher/Domain/Voucher.php`
- Create: `apps/api/app/Modules/Voucher/Domain/VoucherLedger.php`
- Create: `apps/api/app/Modules/Voucher/Domain/Enums/VoucherStatus.php`
- Create: `apps/api/app/Modules/Voucher/Domain/Enums/VoucherEvent.php`
- Create: `apps/api/app/Modules/Voucher/Domain/Enums/RedemptionMode.php`
- Create: `apps/api/app/Modules/Voucher/Domain/Enums/VoucherKind.php`
- Test: `apps/api/tests/Unit/Voucher/VoucherTest.php`

- [ ] **Step 1: Write enum tests**

```php
public function test_voucher_kind_defaults_to_MPV(): void
{
    $this->assertSame('MPV', VoucherKind::MPV->value);
    $this->assertSame('SPV', VoucherKind::SPV->value);
}
```

- [ ] **Step 2: Write enums**

```php
enum VoucherKind: string {
    case MPV = 'MPV';
    case SPV = 'SPV'; // Reserved; Phase 1 issuance refuses SPV.
}

enum VoucherStatus: string {
    case Issued = 'issued';
    case PartiallyRedeemed = 'partially_redeemed';
    case FullyRedeemed = 'fully_redeemed';
    case Expired = 'expired';
    case Voided = 'voided';
}

enum VoucherEvent: string {
    case Issued = 'issued';
    case Redeemed = 'redeemed';
    case PartiallyRedeemed = 'partially_redeemed';
    case Expired = 'expired';
    case Voided = 'voided';
    case Reversed = 'reversed';
    case Transferred = 'transferred';
    case RoundingAdjustment = 'rounding_adjustment';
}

enum RedemptionMode: string {
    case Bearer = 'bearer';
    case CustomerBound = 'customer_bound';
}
```

- [ ] **Step 3: Write the Voucher and VoucherLedger Eloquent models**

Strict types, casts to enums, factory.

- [ ] **Step 4: Run tests + PHPStan + Pint**

- [ ] **Step 5: Commit**

---

## Task 8: `VoucherCodeGenerator` — tenant-prefixed alphanumeric + check digit

**Files:**
- Create: `apps/api/app/Modules/Voucher/Domain/Services/VoucherCodeGenerator.php`
- Test: `apps/api/tests/Unit/Voucher/VoucherCodeGeneratorTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_generates_code_with_tenant_prefix_and_check_digit(): void
{
    $gen = new VoucherCodeGenerator();
    $code = $gen->generate('OTSP');
    $this->assertMatchesRegularExpression('/^OTSP-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{12}-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]$/', $code);
}

public function test_check_digit_validates_correctly(): void
{
    $gen = new VoucherCodeGenerator();
    $code = $gen->generate('OTSP');
    $this->assertTrue($gen->isValid($code));
    // Tamper with one character — check digit fails.
    $tampered = substr_replace($code, 'X', 6, 1);
    $this->assertFalse($gen->isValid($tampered));
}
```

- [ ] **Step 2: Watch it fail**

- [ ] **Step 3: Implement using Damm checksum (handles single-char errors and adjacent transpositions)**

The 32-char alphabet excludes `I`, `O`, `0`, `1` to avoid scanner OCR confusion.

- [ ] **Step 4: Run tests**

- [ ] **Step 5: Commit**

---

## Task 9: `VoucherIssuanceService` (refund + goodwill + exchange-surplus paths) — non-taxable GL

**Files:**
- Create: `apps/api/app/Modules/Voucher/Application/Services/VoucherIssuanceService.php`
- Modify: `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php` (add `createVoucherLedgerEntry()`)
- Test: `apps/api/tests/Feature/Voucher/VoucherIssuanceServiceTest.php`

- [ ] **Step 1: Write the failing test for refund-issuance**

```php
public function test_issues_voucher_from_refund_with_correct_GL_directions(): void
{
    // Setup: tenant chart with sales_returns_clearing_account_id and voucher_liability_account_id.
    // Call: $service->issueFromRefund($creditNote, $amount, $cashier, $terminal);
    // Assert:
    //   - Voucher row exists with current_balance = $amount, status = Issued, redemption_mode = Bearer (default), kind = MPV, source = Refund, source_receipt_id = $creditNote->id.
    //   - First voucher_ledger row: event = Issued, amount = +$amount, gl_journal_entry_id is set.
    //   - The GL entry has two lines: debit sales-returns-clearing, credit voucher-liability. NO VAT lines.
    //   - VoucherIssued event was dispatched.
}
```

Add a parallel test for each issuance source: `issueFromExchangeSurplus` (source = ExchangeSurplus), `issueGoodwill` (source = Goodwill, with the goodwill GL clearing account). Phase 1.5 test stubs (skipped) for `issueFromLoyaltyCredit`.

- [ ] **Step 2: Watch it fail**

- [ ] **Step 3: Add `createVoucherLedgerEntry()` to `GeneralLedgerService`**

Per §5.2 matrix. Switches on `VoucherEvent` to pick debit/credit accounts. **No VAT lines anywhere.** SPV is rejected with a clear "not yet supported" exception.

- [ ] **Step 4: Implement `VoucherIssuanceService`**

Three methods: `issueFromRefund`, `issueFromExchangeSurplus`, `issueGoodwill`. Each in a transaction: create Voucher + first VoucherLedger row + GL entry; emit VoucherIssued event.

`issueGoodwill` enforces:
- Named-customer over `goodwill_named_customer_threshold` (server-side check)
- No issuer-as-recipient (issued_by_user_id != issued_to_partner_id's linked user)
- Daily issuance cap per user
- Bearer goodwill default off (Bearer requires explicit override flag from caller)
- Four-eyes above threshold (a `second_approver_user_id` field is required)

- [ ] **Step 5: Run tests, including a goodwill-self-dealing test that asserts rejection**

- [ ] **Step 6: Commit**

---

## Task 10: `VoucherRedemptionService` — single-terminal scope, validation, partial redeem, residual write-off

**Files:**
- Create: `apps/api/app/Modules/Voucher/Application/Services/VoucherRedemptionService.php`
- Create: exception classes for each rejection
- Test: `apps/api/tests/Feature/Voucher/VoucherRedemptionServiceTest.php`

- [ ] **Step 1: Write failing tests for every rejection path**

```php
public function test_rejects_voucher_from_other_terminal_in_phase_1(): void
{
    $voucher = $this->makeVoucher(issuedAtTerminal: $this->terminalA->id);
    $this->expectException(VoucherNotForThisTerminalException::class);
    $this->service->redeem($voucher, BCAmount::of('5.00'), $this->saleReceipt, $this->cashier, $this->terminalB);
}

public function test_rejects_expired_voucher(): void { ... }
public function test_rejects_voided_voucher(): void { ... }
public function test_rejects_customer_bound_voucher_for_wrong_partner(): void { ... }
public function test_rejects_duplicate_redemption_in_one_transaction(): void { ... }
public function test_partial_redeem_decreases_balance_and_writes_GL(): void { ... }
public function test_full_redeem_transitions_status_to_fully_redeemed(): void { ... }
public function test_residual_below_min_currency_unit_triggers_rounding_adjustment(): void { ... }
public function test_TND_scale_3_partial_redeem_residual(): void { ... }
public function test_EUR_scale_2_partial_redeem_residual(): void { ... }
```

- [ ] **Step 2: Watch them fail**

- [ ] **Step 3: Implement `VoucherRedemptionService::redeem()`**

```php
public function redeem(
    Voucher $voucher,
    BCAmount $amount,
    Receipt $saleReceipt,
    User $cashier,
    Terminal $terminal,
): VoucherLedger {
    // Single-terminal Phase 1 guard.
    if ($voucher->redeemable_at_terminal_id !== $terminal->id) {
        throw new VoucherNotForThisTerminalException();
    }
    // Status, expiry, balance, mode checks ...
    // BCAmount math at internal precision (currency_scale + 2).
    // Decrement current_balance, possibly trigger RoundingAdjustment.
    // Append VoucherLedger row, GL entry, emit event.
    // Update status projection: PartiallyRedeemed or FullyRedeemed.
}
```

- [ ] **Step 4: Run tests, ensure all pass**

- [ ] **Step 5: Commit**

---

## Task 11: `VoucherLookupService` — generic vs in-session disclosure + layered rate limiter

**Files:**
- Create: `apps/api/app/Modules/Voucher/Application/Services/VoucherLookupService.php`
- Create: `apps/api/app/Modules/Voucher/Infrastructure/RateLimit/VoucherLookupRateLimiter.php`
- Test: `apps/api/tests/Feature/Voucher/VoucherLookupServiceTest.php`

- [ ] **Step 1-N: TDD each layered counter (per-terminal/day, per-cashier/day, per-tenant/hour failed, per-IP/hour, per-code-prefix/hour, per-voucher 5-failed-auto-void)**

- [ ] **Implement Redis sliding-window counters**

- [ ] **Implement generic vs in-session response shapes**

- [ ] **Commit**

---

## Task 12: `VoucherCascadeService` — credit-note void cascade (Phase 1 hard-block on redeemed)

**Files:**
- Create: `apps/api/app/Modules/Voucher/Application/Services/VoucherCascadeService.php`
- Create: `docs/runbooks/voucher-redeemed-credit-note-correction.md`
- Test: `apps/api/tests/Feature/Voucher/VoucherCascadeServiceTest.php`

- [ ] **Step 1: Test that voiding a credit note with no redemptions cascades to voucher void**
- [ ] **Step 2: Test that voiding a credit note with any redemption throws `VoucherCascadeBlockedException` with runbook link**
- [ ] **Step 3: Implement; wire as a listener on the credit-note void event**
- [ ] **Step 4: Write the runbook (manual accounting steps for the operator)**
- [ ] **Step 5: Commit**

**Phase B checkpoint.** Voucher core is done end-to-end (issue, redeem, lookup, cascade). Run preflight. Push.

---

## Task 17 (was 13): Extend `Company.ValueObjects.ReservationSettings` with refund-policy fields

**Files:**
- Modify: `apps/api/app/Modules/Company/Domain/ValueObjects/ReservationSettings.php` (correct path — Codex review 3 Finding I)
- Modify: `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php`
- Modify: `apps/api/app/Modules/Company/Presentation/Requests/UpdateCompanyRequest.php`
- Test: `apps/api/tests/Feature/Company/RefundPolicySettingsTest.php`

**Note:** the existing class is `ReservationSettings`, NOT `CompanyReservationSettings`. Do not create a parallel settings class. The first test should assert that no `pos_refund_settings` table or parallel value object is created.

- [ ] **Step 1: Write a failing test asserting all new fields persist on the existing `ReservationSettings` value object + default values**
- [ ] **Step 2: Add a guard test asserting no parallel settings type exists (`assertFileDoesNotExist` for hypothetical alternates)**
- [ ] **Step 3: Add fields to `ReservationSettings` with defaults from spec §3.5**
- [ ] **Step 4: Update `CompanyController` validation + reading (`CompanyController.php:251`, `:266` patterns)**
- [ ] **Step 5: Add a data migration that backfills defaults for existing tenants**
- [ ] **Step 6: Run tests**
- [ ] **Step 7: Commit**

---

## Task 14: `RefundDestinationResolver` — server-side policy enforcement

(... continues with full TDD steps ...)

- [ ] **Test that `out_of_window_policy = voucher_only` forces destination = StoreVoucher**
- [ ] **Test that disallowed destination raises `RefundDestinationNotAllowedException`**
- [ ] **Implement, commit**

---

## Task 19 (was 15): `PaymentRefundService::refundReceiptPayments` — proration + columns + DB-level idempotency + payment_type fix

**Files:**
- Modify: `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php`
- Modify: `apps/api/app/Modules/Treasury/Domain/Payment.php` (add `original_payment_id`, `refund_request_id` to `$fillable`)
- Test: `apps/api/tests/Feature/Treasury/PaymentRefundProrationTest.php`
- Migration: `apps/api/database/migrations/2026_05_03_000001_add_refund_audit_columns_and_unique_index_to_payments.php`
- Migration: `apps/api/database/migrations/2026_05_03_000002_backfill_payment_type_on_refund_payments.php`

**Codex review 3 Finding H.** Today's `payments` table has no `original_payment_id` or `refund_request_id` and `PaymentRefundService` writes refund rows without `payment_type` (defaults to `document_payment`). The plan now adds DB columns + a unique partial index for idempotency at the DB layer.

- [ ] **Step 1: Failing test — proportional proration across two original payments**

- [ ] **Step 2: Failing test — payment_type is set explicitly to `Refund` (not the column default)**

- [ ] **Step 3: Failing test — DB-level idempotency: two concurrent calls with the same `(original_payment_id, refund_request_id)` produce one row**

```php
public function test_concurrent_refund_calls_with_same_request_id_produce_one_row(): void
{
    DB::transaction(function () use (&$threwUniqueViolation) {
        $this->service->refundReceiptPayments(
            original: $this->original,
            totalToRefund: BCAmount::of('25.00'),
            strategy: ProrationStrategy::Proportional,
            refundRequestId: $this->fixedRequestId,
            authorizedByUserId: null,
        );
        try {
            $this->service->refundReceiptPayments(
                original: $this->original,
                totalToRefund: BCAmount::of('25.00'),
                strategy: ProrationStrategy::Proportional,
                refundRequestId: $this->fixedRequestId,
                authorizedByUserId: null,
            );
        } catch (UniqueConstraintViolationException $e) {
            $threwUniqueViolation = true;
        }
    });
    // Idempotent: second call returns existing rows or throws unique-violation that the service catches and reads back.
    $this->assertCount(2, Payment::where('refund_request_id', $this->fixedRequestId)->get()); // one per original payment row
}
```

- [ ] **Step 4: Migration — add columns + unique partial index**

```php
public function up(): void
{
    Schema::table('payments', function (Blueprint $table) {
        $table->uuid('original_payment_id')->nullable();
        $table->uuid('refund_request_id')->nullable();
        $table->uuid('authorized_by_user_id')->nullable();
        $table->string('policy_trigger', 64)->nullable();
        $table->index('original_payment_id');
        $table->index('refund_request_id');
    });

    if (DB::connection()->getDriverName() === 'pgsql') {
        DB::statement(
            "CREATE UNIQUE INDEX payments_refund_idempotency_uniq ".
            "ON payments (company_id, original_payment_id, refund_request_id) ".
            "WHERE payment_type = 'refund' AND original_payment_id IS NOT NULL AND refund_request_id IS NOT NULL"
        );
    }
}
```

- [ ] **Step 5: Backfill migration** — existing rows where `payment_type IS NULL OR payment_type = 'document_payment' BUT amount < 0 AND linked to a credit note` get `payment_type = 'refund'`. Audit one-shot, document the SQL in the migration's docblock.

- [ ] **Step 6: Implement `refundReceiptPayments()` strategies (Proportional / LargestFirst / CashierChoice)** with explicit `payment_type = Refund` on every negative payment row + `original_payment_id` + `refund_request_id` + `authorized_by_user_id`.

- [ ] **Step 7: Tests TND scale-3 and EUR scale-2 residual + concurrency race + LargestFirst + CashierChoice**

- [ ] **Step 8: Commit**

---

## Task 16: Permissions — seeder + migration

**Files:**
- Modify: `apps/api/database/seeders/RolesAndPermissionsSeeder.php`

- [ ] **Add all new permissions from §3.8**
- [ ] **Assign to default roles**
- [ ] **Run seeder + a permissions test**
- [ ] **Commit**

---

## Task 17: `instrument_serial` rename + `instrument_type` discriminator (store_voucher only in Phase 1)

**Files:**
- Migration: `apps/api/database/migrations/2026_05_04_000001_rename_voucher_serial_to_instrument_serial.php`
- Modify: `apps/api/app/Modules/POS/Domain/ReceiptPayment.php`
- Create: `apps/api/app/Modules/POS/Domain/Enums/PaymentInstrumentKind.php` (enum: `restaurant_voucher | store_voucher | gift_card | none`)
- Create: `apps/api/app/Modules/POS/Domain/Exceptions/RestaurantVoucherNotYetSupportedException.php`
- Create: `apps/api/app/Modules/POS/Domain/Exceptions/GiftCardNotYetSupportedException.php`

- [ ] **Step 1: Test that legacy `voucher_serial` data backfills to `instrument_type = 'restaurant_voucher'`**
- [ ] **Step 2: Test that Phase 1 voucher issuance with `kind != store_voucher` raises the right exception**

```php
public function test_phase_1_rejects_restaurant_voucher_issuance(): void
{
    $this->expectException(RestaurantVoucherNotYetSupportedException::class);
    $this->voucherIssuanceService->issueFromRefund(
        creditNote: $this->creditNote,
        amount: BCAmount::of('25.00'),
        cashier: $this->cashier,
        terminal: $this->terminal,
        instrumentKind: PaymentInstrumentKind::RestaurantVoucher,
    );
}
```

(The default `instrumentKind` parameter is `StoreVoucher`. Adding the parameter ensures the rejection path is wired and tested even though no caller passes anything else in Phase 1.)

- [ ] **Step 3: Test that the column constraint accepts `restaurant_voucher` as a value (so the backfill works) but the issuance service rejects it**

- [ ] **Step 4: Rename column + add `instrument_type` column with enum-backed CHECK constraint** (`restaurant_voucher | store_voucher | gift_card | none`)

- [ ] **Step 5: Update model fillable + casts; add `PaymentInstrumentKind` enum**

- [ ] **Step 6: Add Phase 1 reject-guard to `VoucherIssuanceService` and `VoucherRedemptionService` for `kind != store_voucher`**

- [ ] **Step 7: Verify no callers break (`grep voucher_serial`)**

- [ ] **Step 8: Commit**

```bash
git commit -m "feat(pos): rename voucher_serial -> instrument_serial + discriminator; Phase 1 wires store_voucher only"
```

**Phase C checkpoint.** Run preflight, push.

---

## Task 18: Tenant signing keys table + `TenantSigningKey` entity

**Files:**
- Migration: `apps/api/database/migrations/2026_05_05_000001_create_tenant_signing_keys_table.php`
- Create: `apps/api/app/Modules/POS/Domain/TenantSigningKey.php`
- Test: schema + encryption-at-rest

- [ ] **TDD per §4.5 storage requirements**

---

## Task 19: `ReceiptLookupService` — QR token format + verifier

**Files:**
- Create: `apps/api/app/Modules/POS/Application/Services/ReceiptLookupService.php`
- Test: full token verification matrix per §4.5

- [ ] **TDD: token format, key rotation, cross-tenant rejection, constant-time MAC**

---

## Task 20: `CustomerHistorySearchService` — single-terminal scope, audit, alerts

**Files:**
- Create: `apps/api/app/Modules/POS/Application/Services/CustomerHistorySearchService.php`
- Migration: `customer_history_searches` table
- Test: minimum specificity, rate limit, masked totals, terminal-scoped query

- [ ] **TDD: ensure SQL `WHERE terminal_id = ?` constraint cannot be bypassed**

---

## Task 21: Receipt printer — embed QR token

**Files:**
- Modify: receipt PDF / printer-template service
- Test: receipt fixture includes a verifiable QR token

- [ ] **TDD: render → scan → verify roundtrip**

**Phase D checkpoint.** Push.

---

## Phase E — `ReceiptReturnService` refactor (decomposed per Codex review 3 Finding E)

The current `ReceiptReturnService` does sequence/number generation, hash-of-empty-payments, receipt insert, lines/VAT insert, terminal sequence advancement, stock restoration, and event dispatch all inline. Decomposing into 7 focused tasks so each step is testable and reviewable.

## Task 27: Return draft builder — produce a pending_seal return receipt with no chain advancement

**Files:** modify `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php`; new helper `ReturnDraftBuilder`. Test.

- [ ] **TDD: building a return draft writes negative lines + VAT + receipt row with `fiscal_status = pending_seal`, no `fiscal_hash`, no terminal sequence advancement, idempotent on `refund_request_id`**

---

## Task 28: `RefundDestinationResolver` integration into return flow

**Files:** wire the resolver (Task 18) into return processing.

- [ ] **TDD: `out_of_window_policy = voucher_only` forces destination = StoreVoucher, server-side; cashier-supplied destination rejected when policy disallows**

---

## Task 29: Voucher issuance pre-finalization in return flow

**Files:** when destination = StoreVoucher, call `VoucherIssuanceService::issueFromRefund` BEFORE finalization, attach the voucher ledger entry to the pending return receipt's payload, then finalize.

- [ ] **TDD: voucher row created in same transaction; voucher ledger row's `gl_journal_entry_id` non-null; voucher code committed in v3 hash payload (using fixture 05); RoundingAdjustment if applicable**

---

## Task 30: Payment refund + cash drawer side effects

**Files:** wire `PaymentRefundService::refundReceiptPayments` (Task 19) into return flow when destination = OriginalPayment; wire cash-drawer `recordRefund` operation when destination = Cash; per the §2.7 proration strategies.

- [ ] **TDD: each destination type hits the right side effect; mixed-tender proration produces the right number of negative payment rows; cash drawer op carries the right amount**

---

## Task 31: Return-window enforcement + manager-override threshold (audit fields)

**Files:** add return-window check (gates from `ReservationSettings` Task 17); add manager-PIN override application; populate `authorized_by_user_id`, `override_reason`, `policy_trigger`, `out_of_window` audit columns on the return receipt + the voucher row + the refund payment rows.

- [ ] **TDD: in-window default; out-of-window with `voucher_only` policy forces destination; out-of-window with `refuse` blocks unless manager-PIN override; over-threshold prompts manager-PIN; daily cap reached requires `pos.refund_extend_daily_cap`. EUR + TND tenant pairs both tested.**

---

## Task 32: Finalization integration — return receipt sealed via `ReceiptFinalizationService`

**Files:** replace the inline hash/save/sequence-advance code (current `ReceiptReturnService.php:206`-`:283`) with `ReceiptFinalizationService::finalize($returnReceipt)`.

- [ ] **TDD: return receipt becomes fiscalized only at finalization; v3 hash payload includes the voucher ledger entries written by Task 29; legacy inline-seal path removed; chain advances exactly once per return**

---

## Task 33: Chain/inventory rollback test + DB idempotency on `refund_request_id`

**Files:** new test file; migration to add unique partial index on `(company_id, refund_request_id)` for return receipts.

- [ ] **TDD: simulated failure mid-transaction (e.g., voucher GL entry throws) rolls back the whole return — no orphan voucher, no stock movement, terminal sequence unchanged. Concurrent calls with same `refund_request_id` produce one return receipt + one voucher.**

**Phase E checkpoint.** Push.

---

## Task 34: `pos_exchange_requests` table + Eloquent model — idempotency state for exchanges

**Files:**
- Create: `apps/api/database/migrations/2026_05_06_000001_create_pos_exchange_requests_table.php`
- Create: `apps/api/app/Modules/POS/Domain/ExchangeRequest.php`
- Test: `apps/api/tests/Feature/POS/Migrations/ExchangeRequestSchemaTest.php`

**Codex review 3 Finding D.** Without a persistent exchange table, the idempotency triple `(returnReceiptId, saleReceiptId, voucherId?)` cannot be stored against a single `exchange_request_id` (today's per-receipt `idempotency_key` is unique per row). Recovery from a mid-transaction failure has no anchor.

- [ ] **Step 1: Schema test failing — table exists with required columns**

```php
public function test_pos_exchange_requests_schema(): void
{
    $cols = array_map(fn ($c) => $c->column_name, DB::select(
        "SELECT column_name FROM information_schema.columns WHERE table_name = 'pos_exchange_requests'"
    ));
    foreach (['id', 'tenant_id', 'company_id', 'exchange_request_id', 'exchange_group_id', 'status', 'return_receipt_id', 'sale_receipt_id', 'voucher_id', 'failure_reason', 'failure_payload', 'created_at', 'updated_at', 'completed_at'] as $col) {
        $this->assertContains($col, $cols, "Missing column: $col");
    }
}

public function test_exchange_request_id_is_unique(): void
{
    // Insert two rows with the same exchange_request_id; second should fail.
}
```

- [ ] **Step 2: Migration**

```php
Schema::create('pos_exchange_requests', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id');
    $table->uuid('company_id');
    $table->uuid('exchange_request_id'); // client-supplied idempotency key
    $table->uuid('exchange_group_id');
    $table->string('status', 32); // 'pending' | 'completed' | 'failed'
    $table->uuid('return_receipt_id')->nullable();
    $table->uuid('sale_receipt_id')->nullable();
    $table->uuid('voucher_id')->nullable();
    $table->string('failure_reason')->nullable();
    $table->jsonb('failure_payload')->nullable();
    $table->timestamps();
    $table->timestamp('completed_at')->nullable();
    $table->unique(['company_id', 'exchange_request_id']);
    $table->index('exchange_group_id');
});
```

- [ ] **Step 3: Eloquent model with status enum**

- [ ] **Step 4: Run migrations + schema test, commit**

---

## Task 35: `ExchangeService` skeleton with idempotency triple persistence

**Files:** new module-level service.

- [ ] **TDD: idempotency on `exchange_request_id` returns the same triple `(returnReceiptId, saleReceiptId, voucherId?)`. Service uses the Task 34 table:**
  1. SELECT FOR UPDATE on `pos_exchange_requests WHERE company_id = ? AND exchange_request_id = ?`. If row exists with `status = completed`, return its triple. If `status = pending`, return 409 / retry-later. If `status = failed`, replay (idempotency must be safe).
  2. If not found, INSERT a `pending` row with a fresh `exchange_group_id`, then proceed.
  3. Both halves draft + finalize within a single DB transaction (next tasks).
  4. UPDATE row to `completed` with the triple.
- [ ] **TDD: failure mid-transaction marks the row `failed` with `failure_reason` and `failure_payload` for diagnostics; subsequent retry with same key safely replays from the failed state.**

---

## Task 27: Exchange writes both halves with shared `exchange_group_id` committed in v3 hash

- [ ] **TDD: assert hash payloads include `exchange_group_id` for both halves**

---

## Task 28: Exchange stock locking — same-SKU return+sale nets correctly

- [ ] **TDD: returning 2× SKU-A and selling 2× SKU-A in same exchange → both stock movements written, net inventory unchanged**

---

## Task 29: Exchange surplus → voucher path

- [ ] **TDD: net negative invokes `VoucherIssuanceService::issueFromExchangeSurplus`**

**Phase F checkpoint.** Push.

---

## Task 39: `ReportGenerationService` sale-vs-return aggregation split + voucher counters

- [ ] **TDD: Z-report aggregates correctly when both sales and returns are in window; new keys `refunds_count`, `refunds_amount`, `vouchers_issued_count`, `vouchers_issued_amount`, `vouchers_redeemed_count`, `vouchers_redeemed_amount` are emitted at v3**

---

## Task 40: `ZReportHashService` v3 normalization

- [ ] **TDD: v3 keys (refunds_amount, vouchers_*) round-trip canonically; `normalizeForHash` extends per spec §5.3 with `schema_version: 3` branch**

---

## Task 41: `Nf525DataProvider` population — pass refund-flow extension fields to the H3 contract

**Files:**
- Modify: `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php`
- Test: `apps/api/tests/Unit/POS/Nf525DataProviderRefundExtensionTest.php`

**Codex review 3 Finding F.** This was promised in the spec/plan but had no actual task — the H3 contract reserved nullable extension points; refund flow has to populate them. Without this task the export is silently incomplete.

- [ ] **Step 1: Failing test — sale receipt mapping populates `instrumentType`/`instrumentSerial` on each `Nf525ReceiptPaymentData`**

- [ ] **Step 2: Failing test — return receipt mapping populates `authorizedByUserId`, `overrideReason`, `outOfWindow` from the new audit columns**

- [ ] **Step 3: Failing test — receipts in an exchange populate `exchangeGroupId`**

- [ ] **Step 4: Failing test — credit notes populate `voucherLedgerEntries` with one `Nf525VoucherLedgerEntryData` per issuance row; sale receipts that redeemed a voucher populate one entry per redemption row**

- [ ] **Step 5: Implement** — extend `mapPayment()`, `mapSaleReceipt()`, `mapReturnReceipt()` per the H3 contract docblocks (all extension points are nullable, so the change is purely additive)

- [ ] **Step 6: Run the H3 contract isolation test (`Nf525ContractIsolationTest`) to confirm no new direct cross-module imports leaked into Compliance**

- [ ] **Step 7: Commit**

---

## Task 42: Per-terminal v3 cutover action + permission + queue-drain gate

**Files:** new endpoint + permission seeded.

**Decision (Codex review 3 Finding C):** cutover refuses while the terminal has unsynced local receipts in the offline-sync queue. The operator must drain before flipping. No compatibility window.

- [ ] **TDD: cutover refuses on open shift; cutover refuses on un-Z-reported receipts; cutover refuses while `pos_offline_receipts` queue has unsynced rows for this terminal; cutover succeeds on an idle, drained terminal**

---

## Task 43: Golden v2→v3 chain replay test matrix + legacy hash test audit

**Files:** new feature test + audit pass on existing legacy hash assertions.

**Codex review 3 finding C + test-coverage gaps.** Single replay isn't enough.

- [ ] **TDD scenarios in the matrix:**
  1. Empty-shift cutover → write v3 receipts → `verify-chain` PASS
  2. Just-after-Z cutover → write v3 reports whose `previous_z_hash` is the last v2 fiscal hash → `verify-chain` PASS
  3. Pending pre-cutover offline sync drains AFTER cutover (allowed-during-drain rule) → still PASS
  4. Mixed-version refusal: a payload claiming v2 against a cut-over terminal whose `created_at > cutover_at` → REJECTED with tamper error
  5. Concurrent cutover attempts on the same terminal → exactly one wins; loser sees a clear error
- [ ] **Audit pass: existing PHPUnit tests at `apps/api/tests/Unit/POS/ReceiptReturnServiceTest.php:315`, `apps/api/tests/Feature/POS/ReceiptPdfGenerationTest.php:292` and Vitest tests at `apps/pos/src/lib/fiscal/__tests__/hashService.test.ts:89` hard-code the legacy hash format. Update each to either (a) explicitly target v2 (terminal pinned to schema 2), or (b) move to v3 fixtures. Document each change in the commit message.**

**Phase G checkpoint.** Push.

---

## Phase H — Frontend (web back-office + Tauri POS) — reordered per Codex review 3 Finding G

Each task one component or page, full TDD with Vitest, in dependency order. **Local-SQLite mirror moves to FIRST in Phase H** so subsequent scan/cart tasks have local-only lookup available; this satisfies §0.2 ("no API hit during cashier interaction").

### Task 44 (was 46): **POS local-SQLite voucher + receipt mirror tables + sync pipeline**

**Files:**
- Modify: Tauri SQLite migration / `apps/pos/src/lib/offline/db.ts` (or equivalent) — new `vouchers` + `voucher_ledger` + `receipt_qr_index` mirror tables
- Modify: `apps/pos/src/lib/offline/syncService.ts` (or equivalent) — pull voucher updates from server; push voucher writes to server
- Create: `apps/pos/src/lib/offline/voucherRepository.ts` — local-only lookup: `findByCode(code)`, `findReceiptByQrToken(token)`, `findReceiptByNumber(number)`
- Test: Vitest unit tests for the repository + sync replay

- [ ] **TDD: voucher writes locally first; sync queue pushes to server async; on reconnect, sync pulls server-side updates and merges; lookups read SQLite only — assert NO `fetch` calls during cashier interaction (Vitest mock `globalThis.fetch` and assert it's never called from the lookup paths)**

### Task 45: **Vouchers & Credits list page (web)** — unified across sources, source-filter chips/tabs, default to "All". Persist filter in URL.

### Task 46: Voucher detail page with ledger history + Provenance section (web)

### Task 47: Issue Goodwill Voucher modal with four-eyes dual-approval UX (web)

### Task 48: POS Refund Policies settings page (web)

### Task 49: Customer history search audit page (web)

### Task 50: **POS scan dispatcher** (Tauri) — receipt-token format detection: scanned bytes parsed; if format matches `v:kid:receipt_uuid:mac` AND **the local SQLite `receipt_qr_index` from Task 44 confirms the receipt is on this terminal**, show **confirmation sheet** ("This is a sale receipt from [date]. Start a refund or exchange?"). Otherwise fall through to existing product-barcode lookup. **Critical: never silently mutate the cart.** Vitest covers:
- (a) sale-mode-with-old-receipt-scan: cart unchanged until user taps the sheet
- (b) confirmation sheet hydrates from local SQLite only (no `fetch` call)
- (c) declined sheet leaves cart unchanged
- (d) accepted sheet emits a typed `ReceiptTokenAccepted` event for Task 52 to consume

### Task 51: **POS Returns/Exchange home button + receipt-locator screen (Tauri)** — entry button on POS home grid; locator screen with two tabs (scan/type, find by customer); customer tab gated on `pos.search_customer_recent_purchases`. **Locator queries read local SQLite only.**

### Task 52: **POS unified cart with Returning + Buying-new sections (Tauri)** — consumes the `ReceiptTokenAccepted` event from Task 50 (or the locator's "Refund this" action from Task 51); loads original receipt's lines as negative items into the active cart under a "Returning" section header (red, ↺ icon, red `−` prefix); cashier can adjust quantities or remove lines; cashier can scan/add new products which appear as positive items below under "Buying new". Net amount in footer; "Confirm" label adapts ("Charge X" / "Refund X" / "No payment due"). Draft session in local SQLite with `exchange_request_id` (only generated when a positive line is added). Vitest covers: section header rendering, negative-line styling, mid-flow add-new-product transition to exchange mode, draft persistence, draft restore on app restart, **wiring test that confirmation-sheet-decline does NOT hydrate the cart**.

### Task 53: POS RefundDestinationPicker + Manager PIN at confirm + VoucherTender + receipt printer (combined frontend tail)

- RefundDestinationPicker (radios for Original/Cash/Voucher) at confirm step when net is negative, gated by server-side `RefundDestinationResolver` output. Greyed-out destinations, default selection, proration breakdown.
- Manager PIN at confirm, gated on cash + threshold OR no-receipt OR daily-cap. Reuses existing PIN component. **Card-refund-to-original below threshold does NOT prompt.**
- VoucherTender at payment with stacking + duplicate guard. Reads voucher balance from local SQLite (Task 44).
- **Restaurant-voucher tender button is NOT rendered** (Phase 2). The frontend voucher-tender component asserts `instrument_type === 'store_voucher'` and never offers `restaurant_voucher` as a selectable option (Codex review 3 follow-up to Finding J / spec §3.2.1).
- Receipt printer: sale receipts get QR token at footer; refund receipts get "REMBOURSEMENT/AVOIR" header + original ticket reference + original QR re-print + v3 hash footer; exchange receipts render both halves on one page; voucher tickets get code/QR/expiry/balance/bearer-or-customer-bound label.

**Phase H checkpoint.** Full e2e smoke (cash sale → finalize → print → scan QR → confirmation sheet → refund cart → confirm → print AVOIR → voucher issued). Push, open PR.

---

## Final pre-merge

- [ ] **Run full preflight + targeted suites + `pnpm typecheck` in apps/web and apps/pos**
- [ ] **Manual end-to-end smoke** on a dev tenant: scan receipt → partial refund → cash; exchange with voucher surplus; voucher redemption next day same terminal; out-of-window voucher-only path.
- [ ] **Update `MEMORY.md`** with refund-flow status (mark complete).
- [ ] **Update Compliance team** that POS provider populates the H3 contract DTOs; their XML-builder follow-up can land independently.
- [ ] **Open PR `feat/refund-flow → dev`** with full summary.

---

## Self-review checklist (post Codex review 3)

- Every spec section §0.2–§12 has a Task that implements it (eco-tax columns now have Task 9; `Nf525DataProvider` population now has Task 41).
- No "TODO", no "implement later", no "similar to Task N".
- Lifecycle column = `fiscal_status` (NOT a new `status`). Schema version on terminal only. Decisions block at the top of the plan.
- Phase A doesn't just create `ReceiptFinalizationService` — it actually migrates `ReceiptCreationService` (Task 6), `ReceiptPaymentService` (Task 7), and `ReceiptSyncService` (Task 8) to draft-then-finalize. Phase A checkpoint smokes the cash-sale flow + offline-sync round-trip.
- Single-terminal scope is enforced at the SQL/service layer (Voucher tasks), not just UI.
- Voucher GL has no VAT lines anywhere (issuance test asserts this explicitly).
- Voucher entity has a `source` discriminator and the Vouchers & Credits page filters on it.
- France tenants are not gated (no feature flag); editor attestation tracked as backlog only.
- PHP and TS canonical encoders share fixtures with a CI parity check (Tasks 2 + 3 + 7 of Phase A).
- Scan dispatcher never silently mutates the cart (Task 50 explicit Vitest case + Task 52 wiring test).
- Cart-style refund/exchange UX with section headers + red minus + ↺ icon (Task 52).
- Manager PIN at confirm, gated on cash + threshold; card-refund-to-original below threshold does NOT prompt (Task 53).
- **Local SQLite is the default lookup; no API hit during cashier interaction — local mirror lands FIRST in Phase H (Task 44, before scan dispatcher Task 50). Vitest mock-fetch assertion confirms.**
- **`pos_exchange_requests` table is built BEFORE `ExchangeService`** (Task 34 → Task 35). Idempotency triple persisted atomically.
- **`payments` table gets `original_payment_id` + `refund_request_id` columns + a unique partial index** for refund idempotency at the DB layer (Task 19).
- **`Nf525DataProvider` population task added** (Task 41) so JET exports actually carry voucher / exchange / audit fields.
- **Restaurant-voucher tender is rejected at backend AND frontend** (Task 17 backend + Task 53 frontend).
- **Pre-cutover offline-sync drain rule** enforced by Task 42 (cutover refuses while local-sync queue non-empty) + Task 43 cutover test matrix.
- **Legacy hash test audit pass** (Task 43) updates existing PHPUnit + Vitest assertions that hard-code legacy hash format.

End of plan.
