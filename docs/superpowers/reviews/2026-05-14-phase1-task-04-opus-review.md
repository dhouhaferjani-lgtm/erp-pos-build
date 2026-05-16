# Opus Review — POS Phase 1 Task 4

**Task:** Canonical serialization golden vectors + PHP hash-only golden test
**Plan:** `apps/erp/docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md`, Task 4 (§ "Task 4: Canonical serialization golden vectors + PHP-side golden test")
**Spec:** `apps/erp/docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md`, §4 (Canonical serialization contract)
**Base SHA (requested):** `0f0e02719000e72a14a5b028d6983741b7c07d9e`
**Head SHA (requested):** `7e469e0a7d32e8f40cfc4ec52dd853cf74c8612e`
**Local equivalents (resolved):** parent `0f0e0271`, head `7e469e0a3557…d866d` — same short prefixes, different full hashes (likely rebased before push); diff content is identical.
**Files in scope:**
- `apps/api/tests/Fixtures/Fiscal/canonical-golden-vectors.json` (NEW, 224 lines)
- `apps/api/tests/Unit/Fiscal/CanonicalGoldenVectorPhpTest.php` (NEW, 61 lines)

---

## Verdict

**APPROVE**

The implementation satisfies the spec §4 contract for the PHP side, covers every required matrix case, and the two new test methods together (hash check + matrix presence) discharge the documented intent: *PHP never serializes; it only confirms hashes for the device's canonical bytes.* No findings rise to BLOCKER/P1/P2. One forward-looking note (see "Forward-looking observations") is worth surfacing for Task 5, but it concerns the **plan text**, not the Task 4 deliverable.

---

## Verification summary

| Check | Result | Evidence |
|---|---|---|
| All 8 SHA-256 hashes match `sha256(expected_canonical_string)` | PASS | `php -r 'hash("sha256", $v["expected_canonical_string"]) === $v["expected_sha256_hex"]'` returned OK for every vector |
| `vendor/bin/phpunit --filter CanonicalGoldenVectorPhpTest` | PASS | 2 tests, 17 assertions, 0 failures (PHPUnit deprecations are pre-existing, unrelated) |
| Top-level keys match spec §4 set, all 14 present | PASS | Cross-checked decoded keys vs `[business_date,…,terminal_id]` for all 8 vectors |
| Top-level keys lexicographically sorted | PASS | `array_keys === sort(array_keys)` for all 8 vectors |
| `expected_sha256_hex` format = 64-char lowercase hex | PASS | regex `^[0-9a-f]{64}$` matched all 8 |
| `previous_hash` format = 64-char lowercase hex inside canonical string | PASS | regex check on decoded `previous_hash` for all 8 |
| Matrix coverage: tnd_3dp / 2dp / 0dp / negative_amount / empty_arrays_null_optionals / multibyte_nfc / line_separator_normalization / non_ascii_key_order | PASS | Vector names contain each required substring; `test_matrix_coverage` enforces this in CI |
| U+2028 / U+2029 stripping captured by vector | PASS | `line_separator_normalization.payload.note` raw bytes = `61 6c 70 68 61 e2 80 a8 62 65 74 61 e2 80 a9 67 61 6d 6d 61` (alpha-LS-beta-PS-gamma); expected canonical contains `"note":"alphabetagamma"` (both stripped) |
| NFC for multibyte case | PASS | `customer_name = "Café Élise"` uses precomposed é (UTF-8 `c3 a9`) / É (`c3 89`); Arabic `note` is NFC by construction; `Normalizer::normalize(…, FORM_C)` is an identity for both |
| Non-ASCII key sort order | PASS | Payload keys in `non_ascii_key_order` vector decode to `["a","é","β"]` in that order — matches codepoint ordering (`U+0061 < U+00E9 < U+03B2`), and since all three are BMP this matches RFC 8785 UTF-16-code-unit ordering required by spec §4 |
| PHP test never calls serializer | PASS | `CanonicalGoldenVectorPhpTest.php:19` only invokes `hash('sha256', …)` over `$vector['expected_canonical_string']`; no JSON encoding, no DTO marshalling |
| DTO input shape matches §4 envelope | PASS | Each `payload_dto_input` includes all 14 spec-required top-level keys; payload sub-objects are valid §4 grammar (decimal-string money, integer `sequence_number`/`event_version`/`quantity`, UTC ISO-8601 second-precision timestamps, `null` for optional `reference_event_id`) |
| Namespace / autoload | PASS | `namespace Tests\Unit\Fiscal;` maps to `tests/Unit/Fiscal/` via composer.json L57 `"Tests\\": "tests/"` |
| Scope discipline (no regression / no out-of-task edits) | PASS | `git diff --name-only` returns exactly the two new files; zero touches to existing modules, providers, DTOs, or registries |
| Module-boundary / type-name drift from Tasks 1–3 | PASS | Test imports no module classes; depends only on PHPUnit's `TestCase`. No coupling to enums introduced in commits `84ff133d` / `0f0e0271`, so no name-drift risk on this commit |

---

## Findings

None at BLOCKER / P1 / P2.

### Forward-looking observations (Task 5 readiness, not Task 4 issues)

**N1 — Plan path casing inconsistency, already correctly resolved by the implementation.**

The plan refers to the fixture as `apps/api/tests/Fixtures/fiscal/canonical-golden-vectors.json` (lowercase `fiscal`) at:
- plan L79 (Shared-fixture section)
- plan L339 (Task 4 Files / Create)
- plan L377 / L392 (PHP test snippet inside the plan)
- plan L418 (Task 4 commit line)
- plan L438 (Task 5 TS import path)
- plan L2342 (Task 14 commit line)

Every other fixture directory under `apps/api/tests/Fixtures/` is PascalCase (`Fiscal/`, `FacturX/`, `Nf525/`, `TypeScript/`), and the existing receipt-V3 fixtures already live at `apps/api/tests/Fixtures/Fiscal/v3-golden-hashes/` (referenced in `apps/api/tests/Unit/POS/Fiscal/V3/CanonicalPayloadBuilderTest.php` and `apps/pos/scripts/check-fiscal-fixture-parity.sh`). **Using `Fiscal/` is the correct choice for this codebase** and the task prompt confirms this is the intent.

Implication for Task 5 (out of scope here, but flag now to avoid wasted work):
- The Task-5 TS import on plan L438 must become `import goldenVectors from '../../../../../api/tests/Fixtures/Fiscal/canonical-golden-vectors.json';`
- The Task-14 commit-line glob on plan L2342 must reference `apps/api/tests/Fixtures/Fiscal/`.
- macOS APFS is case-insensitive, so the lowercase plan path would silently "work" locally; Linux CI will not. Worth updating the plan in the same series of follow-ups, or noting at the top of Task 5 / Task 14 when those tickets get picked up.

**N2 — `test_matrix_coverage` uses substring matching.**

`CanonicalGoldenVectorPhpTest.php:40` uses `str_contains($name, $required)`. Loose enough to survive cosmetic renames (e.g. `tnd_3dp_simple_sale` → `tnd_3dp_x`), strict enough that dropping a topical case fails the test. This matches the plan's example test and is the intended trade-off. No action.

**N3 — `payload_dto_input` is unused by the PHP test.**

This is deliberate: per spec §4 the PHP side asserts hash-only, while the TS encoder (Task 5) consumes `payload_dto_input` to reproduce `expected_canonical_string`. The PHP-side fixture carries the DTO field purely so the file is the single shared source of truth across both languages. No action.

**N4 — Cross-fixture-format note (informational).**

The existing `apps/api/tests/Fixtures/Fiscal/v3-golden-hashes/*.json` is keyed on `previous_*` / `current_hash` / receipt-specific payload, not on `{ payload_dto_input, expected_canonical_string, expected_sha256_hex }`. The new `canonical-golden-vectors.json` is intentionally a different protocol (fiscal-event envelope, not receipt-V3 envelope) and is correctly placed alongside the V3 fixtures without collision. The reality research at `docs/superpowers/research/2026-05-14-pos-fiscal-codebase-reality.md:37,171` confirms these are distinct protocols. No action.

---

## Spec § 4 ↔ fixture conformance, vector-by-vector

| Vector | TND-3dp | 2dp | 0dp | negative | empty/null | NFC | U+2028/U+2029 strip | non-ASCII key sort | Hash matches |
|---|---|---|---|---|---|---|---|---|---|
| `tnd_3dp_simple_sale` | ✓ (`"total":"12.345"`) | – | – | – | `"lines":[]` | – | – | – | ✓ `5889f5bc…0821e` |
| `eur_2dp_simple_sale` | – | ✓ (`"total":"12.34"`, `"unit_price":"12.34"`) | – | – | – | – | – | – | ✓ `0b959dd7…ac054` |
| `jpy_0dp_simple_sale` | – | – | ✓ (`"total":"1200"`, no decimal) | – | – | – | – | – | ✓ `92c8a75c…f5edb` |
| `negative_amount_refund_shape` | – | – | – | ✓ (`"total":"-4.500"`, `"quantity":-1`) | – | – | – | – | ✓ `3f686d88…679d7` |
| `empty_arrays_null_optionals` | – | – | – | – | ✓ (`"lines":[]`, `"discounts":[]`, `"customer":null`, `"notes":null`) | – | – | – | ✓ `a61197dd…87269` |
| `multibyte_nfc_customer_note` | – | – | – | – | – | ✓ (`Café Élise`, `سيارة`; precomposed) | – | – | ✓ `39836503…9de91` |
| `line_separator_normalization` | – | – | – | – | – | – | ✓ (input bytes `…61 e2 80 a8 62…e2 80 a9 67…`, canonical = `"alphabetagamma"`) | – | ✓ `84005cbf…1ef44` |
| `non_ascii_key_order` | – | – | – | – | – | – | – | ✓ payload sorted `a < é < β` (codepoint order, matches RFC 8785 since all BMP) | ✓ `fc8ba9b3…f4fa2` |

Every required matrix dimension from spec §4 line 238 is covered by at least one vector; the spec doesn't require each dimension on every vector. ✓

---

## What I deliberately did **not** review here

- Task 5 (POS-side `FiscalEventCanonicalEncoder` reproducing these vectors) — separate task, separate review.
- Whether `FiscalEventEngine`, `OutboxIngestor`, or `HashChainIntegrityProvider` correctly *use* this fixture set — not in scope for Task 4 (no engine code exists yet at this commit; the genesis seed, append path, and providers are still upcoming Phase-1 tasks).
- PHPStan / Pint — out of scope for a fixture+hash-assertion commit, and would not be enforced by Task 4's review surface; the preflight gate (Task 1, commit `fc69ee27`) is the place to verify those run cleanly across the whole branch.

---

## Recommendation

Approve and proceed to Task 5 (POS-side `FiscalEventCanonicalEncoder`). Before opening the Task-5 PR, update the plan's lowercase `tests/Fixtures/fiscal/` references to `Fixtures/Fiscal/` so the TS `import` and the Task-14 commit glob stay consistent with the codebase convention this commit (correctly) chose.
