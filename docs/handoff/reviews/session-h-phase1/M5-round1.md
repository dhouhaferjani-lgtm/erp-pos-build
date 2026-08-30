## M5 adversarial merge-gate register — round 1

**Range reviewed:** `a7c638c28..HEAD` = one commit, `8997f14ff h1-a1 M5.1: pin populated buyer canonical bytes` (the base `a7c638c28` is a YAML-only state commit). 4 files, all test/fixture; **zero production code**.

**Authority applied:** `.superpowers/sdd/CODEX-DISPATCH-session-H-phase1-2026-08-29/task-5-brief.md` (not present in this worktree — read from the main repo; worktree-invisible-docs gotcha). Its browser-gate exception (golden fixture *is* the gate, no Playwright spec) overrides the dispatch's general browser-gate wording and is correctly exercised.

### M5 gate evidence (required by the brief — quoted)

| Side | Pin site | Hash |
|---|---|---|
| Device | `apps/pos/src/lib/fiscal/__tests__/saleReceiptV5CanonicalParity.test.ts:17` | `02bbf732ede83ae131989df29db7deb651548704024527a43c24a86e186d4fef` |
| Server | `apps/api/tests/Unit/Fiscal/SaleReceiptV5GoldenParityTest.php:36` | `02bbf732ede83ae131989df29db7deb651548704024527a43c24a86e186d4fef` |

**Identical.** Independently recomputed from the fixture bytes (`apps/api/tests/Fixtures/Fiscal/sale-receipt-v5-populated-buyer-golden.json:6`) with Python `hashlib` → same digest. Both sides re-run by me, not taken from the report: PHPUnit `5 tests / 21 assertions OK`; vitest `saleReceiptV5CanonicalParity` + `FiscalPayloadKeyDrift` `17/17 passed`; `tsc --noEmit` exit 0; `git status --porcelain` empty.

### Domain lenses

- **fiscal-pos — applies, passes.** Canonical-bytes/hash parity is real, not tautological (see bypass log). Key set frozen: the populated fixture's top-level key set is byte-identical to the null golden; only `buyer` differs. The vector routes through `FiscalPayloadConstraintValidator::validateBuyer` at `eventVersion: 5` (`SaleReceiptV5GoldenParityTest.php:151`), which exercises the M4 uuid gate (`FiscalPayloadConstraintValidator.php:2362-2371`, `SALE_RECEIPT_POST_DISCOUNT_BASE_VERSION = 5` at `:433`) *and* the TN `buyer.tax_number` path via seller-country fallback (`:2379-2382`) — non-vacuous.
- **tenancy-authz / treasury / inventory-costing — do not apply.** No routes, no permissions, no GL/stock, no runtime code.

### Findings

1. **P3 — CONFIRMED — `apps/api/tests/Unit/Fiscal/SaleReceiptV5GoldenParityTest.php:126-141`.** The server "parity oracle" is a decode → re-encode round trip of the *device-authored* string, not an independent server-side semantic build. Failure scenario: a divergence that PHP's `json_decode` normalises away (e.g. a duplicate key, or a numeric literal the device emits unquoted) would survive the round trip. Mitigated in practice: `CanonicalJsonEncoder::encodeObject` re-sorts keys (`CanonicalJsonEncoder.php:73-84`), so key **ordering** parity *is* genuinely cross-checked, and the codebase has no server-side SALE_RECEIPT builder to compare against (docblock `:31` — "the server never re-serializes fiscal events"). Note only; this test is *stronger* than the pre-existing V2/V4/V5 goldens, which merely hash the fixture's own string (`SaleReceiptV2GoldenParityTest.php:41`, `CanonicalByteHashV4ParityTest.php:48`).
2. **P3 — CONFIRMED — fixture `:5`.** The vector is pure ASCII (`Atelier Carthage SARL`, `9876543AB000`). The highest-value cross-language string risk for a buyer block is the documented PHP-vs-JS divergence on U+2028/U+2029 (`CanonicalJsonEncoder.php:96-104`); a buyer `name` is now the first *free-text human-supplied* string in the sealed SALE_RECEIPT buyer path, so that divergence is newly reachable. Out of M5's amended definition ("one populated-buyer vector") — flagging for a follow-up vector, not blocking.
3. **P3 — CONFIRMED — commit graph.** Red-first is narrative-only: `8997f14ff` lands fixture + both tests in one commit, so no red state is reproducible from the branch. For a test-only milestone this is largely structural. I substituted a mutation probe (below), which satisfies me that the pin discriminates.
4. **P3 — CONFIRMED — `tests/Helpers/Fiscal/GoldenFixtureBuilder.php:578-584`.** Two PHP canonical implementations coexist (`jcsCanonicalEncode` sort+`json_encode` vs the production `CanonicalJsonEncoder`). M5 pins only the production one; a drift between them stays invisible for this vector. Pre-existing, out of scope.
5. **P3 — CONFIRMED — new fixture location.** `tests/Fixtures/Fiscal/sale-receipt-v5-populated-buyer-golden.json` sits outside the raw-bytes integrity guard, which is scoped to `tests/Fixtures/Fiscal/v3-golden-hashes` (`FixtureIntegrityTest.php:42`, `:69-80`). So a silent fixture mutation is caught only by the two hash pins, not by an unguarded-file gate. Consistent with its siblings (`sale-receipt-v5-golden.json` etc.); the report's "no registry exists for this pattern" claim is accurate.

No P1 or P2 findings.

### Bypasses I tried that FAILED (attacks that found no defect)

- **Vacuity attack on the pin.** Re-encoded the fixture payload through the production `CanonicalJsonEncoder` with mutations: `buyer.name` +1 char → `75e52c15…`; `buyer.customer_id → null` → `580fb0d7…`; `buyer → null` → `4343092a…`. All diverge from the pin. The `buyer → null` mutation reproduces **exactly** the untouched null-buyer golden hash `4343092af0b38704a2ca5f83cb84006db41c1c8c6d39a2dbc6cfb4aaed012391`, proving by construction that the new vector is the null vector with *only* `buyer` populated (also confirmed by a structural diff: `differing top-level keys: {'buyer'}`, key sets equal).
- **Silent re-pin of the null golden.** `sale-receipt-v5-golden.json` is untouched in the diff and still hashes to its pinned `4343092a…`.
- **F-07 / F-15 mutation (STOP condition 1 / brief prohibition).** Both untouched; raw-file SHA-256 recomputed: F-07 `96e325eedc1b5466e3cd0a0c7b74b203b0110617b46459a47ed4236579e46142` (matches the literal at `FiscalPayloadConstraintValidatorTest.php:1389`), F-15 `32c660dbdb080fda26d659f3c5beb22b19ffece0f332a3dd2f98431110169127` (F-15 is guarded by generator-byte-equality at `:1621-1630`, not a literal). Both report-quoted hashes are accurate.
- **Rule 19 (float on money/quantity).** Every monetary/quantity value in the canonical string is a quoted decimal string; the only non-string scalars are `currency_scale` (int) and `training_flag` (bool). No float path, no scale resolver needed (test-only).
- **Missed registry / manifest obligation.** Grepped every consumer of `Fixtures/Fiscal`; no aggregate registry covers the top-level standalone goldens. Report claim stands.
- **Module-boundary violation** from the new `App\Modules\POS\…\CanonicalJsonEncoder` import inside `Tests\Unit\Fiscal`: `deptrac.yaml` scopes `paths: ./app` and excludes `#.*[Tt]est.*#` — not a violation.
- **Type-safety escape** via `GOLDEN_BUYER as const` against `BuyerBlockInput` (`SaleReceiptPayload.ts:97-98`): `tsc --noEmit` exit 0.
- Standing checks with **no surface here**: migrations, named queues/Horizon, i18n en+fr, `app()` in production, tenant scoping — no production or user-facing code changed.

### Scope conformance

Brief §3 M5 requirements all met: populated-buyer golden beside the null one on both sides ✅; identical pinned hash ✅; drift-gate comment noting buyer is value-checked (`FiscalPayloadKeyDrift.test.ts:59-61`) ✅; registry updated only if one exists (none) ✅; null-buyer behaviour unchanged and proven ✅; no key-set/schema/version/migration/AccountCharge change ✅; worktree clean ✅; YAML / registers / lane report correctly left to the controller ✅. No STOP condition tripped. The "no POS device version bump" claim is correct — M5 adds test evidence only.

VERDICT: ACCEPT
