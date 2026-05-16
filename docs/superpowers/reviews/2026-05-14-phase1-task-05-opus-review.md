# Phase 1 Task 5 — `FiscalEventCanonicalEncoder` — Opus review (round 2, post-P1/P3 fixes)

**Date:** 2026-05-15
**Reviewer:** Opus (headless review gate)
**Scope:** Task 5 from `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md` (POS device-side canonical encoder), re-review of fixes against round 1 findings.
**Base SHA:** `7e469e0a` (Task 4 head — golden vectors + PHP hash-only test)
**Head SHA:** `296d9d61` (Task 5 — amended over prior round-1 head `a1384abd`)
**Diff vs. base:** 3 files added, 263 lines.
- `apps/pos/src/lib/fiscal/FiscalEventCanonicalEncoder.ts` (+187)
- `apps/pos/src/lib/fiscal/__tests__/FiscalEventCanonicalEncoder.test.ts` (+45)
- `apps/pos/src/lib/fiscal/types.ts` (+31)

**Verdict:** **APPROVE**

The two merge-gating round-1 findings — P1 (array-order test/name gap) and P3 (stray `FiscalEventCanonicalInput` re-export) — are fully addressed. The third P3 (ambiguous `FiscalEventRow` shape) was addressed via the round-1 fix's option (b): a one-line transitional comment was added pointing at Task 13 as the split point. The two P2 findings (hand-rolled SHA-256 not vetted; encoder duplicates the v3 `canonicalJson.ts` core) were explicitly waived as non-blocking by round 1 and remain tracked as follow-ups before Task 15 — the implementation is unchanged on those two fronts, which is consistent with the round-1 recommendation. Vitest run locally: 10/10 pass on the same `canonical-golden-vectors.json` the PHP side asserts hash-only on. No new findings, no regressions, no module-boundary violations introduced by the amendment.

---

## Verification summary (round 2)

| Round-1 finding | Status | Evidence |
|---|---|---|
| **P1** — test name overstates ("…and arrays") + array-order contract not asserted | **FIXED** | `__tests__/FiscalEventCanonicalEncoder.test.ts:25` renamed to `'sorts object keys, preserves array order, rejects floats, formats money as decimal strings'`; `:33` adds `expect(out).toContain('"lines":[{"z":1},{"a":1}]')` against the deliberately non-alphabetical input `{ z: 1 }, { a: 1 }` from `:29`. The assertion would fail under either a "sort arrays" or a "reverse arrays" bug. |
| **P3** — stray `export type { FiscalEventCanonicalInput };` re-export at encoder file bottom | **FIXED** | `git diff a1384abd 296d9d61 -- FiscalEventCanonicalEncoder.ts` shows both the unused `import type { FiscalEventCanonicalInput } from './types';` and the trailing `export type { FiscalEventCanonicalInput };` are removed. The encoder no longer references `./types` at all; consumers have a single import path (`./types`) for the type. |
| **P3** — `FiscalEventRow` conflates device + server shapes; status-string literals duplicate Task 3 PHP enums | **PARTIALLY FIXED (option b accepted)** | `types.ts:8–9` adds a one-line comment `// Transitional Phase 1 row shape; Task 13 splits the device SQLite row from the server mirror once both tables exist.` This is the round-1 "option (b)" pushback the prior review approved. The status-string-vs-enum drift (TS literal unions duplicating `PayloadParseStatus.php` / `SignatureStatus.php`) is **not** fixed here — but the round-1 review punted that to "the standing typescript:transform pipeline … Task 14 is the natural moment to wire the enum re-export." That deferral still stands; nothing in this commit makes it harder. |
| **P2** — hand-rolled SHA-256, not a *vetted* sync SHA-256 | **DEFERRED (consistent with round-1 recommendation)** | `FiscalEventCanonicalEncoder.ts:73–186` still ships a from-scratch SHA-256 (`sha256Hex` → `sha256Bytes` → `paddedSha256Input` + `add32` / `rotateRight`). No vetted lib (e.g. `js-sha256`, `hash-wasm`) was swapped in. No boundary-targeted test vectors at 55/56/57/63/64/65/119/120 bytes were added. Round 1 explicitly said this "does not need to block Task 5 — should be tracked as a follow-up … before Task 15." Reaffirmed below as a tracked P2; not a Task-5 BLOCKER. |
| **P2** — encoder duplicates `v3/canonicalJson.ts` JCS core instead of reusing it | **DEFERRED (consistent with round-1 recommendation)** | `FiscalEventCanonicalEncoder.ts:18–67` still re-implements `encodeValue` / `encodeObject` / `encodeString` instead of sharing a `jcs.ts` core with `apps/pos/src/lib/fiscal/v3/canonicalJson.ts:35–87`. The only material difference remains the `normalizeFiscalString` (NFC + U+2028/U+2029 strip) wrap around `JSON.stringify`. Round-1 recommendation to factor a shared core before Task 15 stands; reaffirmed below as a tracked P2. |

### Independent re-verification against spec §4 and plan §424–484

| Check | Result | Evidence |
|---|---|---|
| Files exist at spec'd paths | ✓ | `apps/pos/src/lib/fiscal/{FiscalEventCanonicalEncoder.ts, types.ts, __tests__/FiscalEventCanonicalEncoder.test.ts}` matches plan §428–431 and §75. |
| TS test imports the PHP-side golden-vector fixture | ✓ | `__tests__/FiscalEventCanonicalEncoder.test.ts:2` → `../../../../../api/tests/Fixtures/Fiscal/canonical-golden-vectors.json`. Path resolves; Vitest run loads the JSON without `resolveJsonModule` (same idiom as `apps/pos/src/lib/i18n.ts`). |
| TS test iterates **every** PHP golden vector | ✓ | `it.each(goldenVectors as GoldenVector[])` over all 8 vectors: `tnd_3dp_simple_sale`, `eur_2dp_simple_sale`, `jpy_0dp_simple_sale`, `negative_amount_refund_shape`, `empty_arrays_null_optionals`, `multibyte_nfc_customer_note`, `line_separator_normalization`, `non_ascii_key_order` (confirmed via `python -c 'json.load…'`). Matches spec §4 matrix requirement. |
| Tests assert canonical string **and** hash (non-tautological) | ✓ | `:20–21` asserts both `canonical === expected_canonical_string` and `sha256Hex(canonical) === expected_sha256_hex`. The PHP side asserts only `hash('sha256', expected_canonical_string) === expected_sha256_hex` (`apps/api/tests/Unit/Fiscal/CanonicalGoldenVectorPhpTest.php`), so the TS encoder must reproduce both bytes and hash from `payload_dto_input` alone — a buggy encoder *or* a buggy hash would fail. |
| All tests pass | ✓ | `cd apps/pos && pnpm vitest run src/lib/fiscal/__tests__/FiscalEventCanonicalEncoder.test.ts` → **10/10 pass** (8 golden + 2 supplementary), 5 ms test time, 708 ms total. |
| Device serializes **once**; bytes pass through to hash | ✓ | `encode(input)` returns the canonical string; `sha256Hex(input)` rehashes the exact same string. No re-serialization, no JSON round-trip. Matches spec §4 D2. |
| Sorted-key JCS / RFC 8785 over object members | ✓ | `encodeObject` (`:54`) calls `Object.keys(value).sort((a, b) => (a < b ? -1 : a > b ? 1 : 0))` — JS `<` is UTF-16 code unit comparison, which matches RFC 8785 §3.2.3 (code-unit order) for BMP code points and matches the PHP `ksort(..., SORT_STRING)` byte-order behavior for NFC-normalized BMP strings. Golden vector `non_ascii_key_order` reproduces verbatim (97 < 233 < 946). |
| Array order **preserved**, not sorted | ✓ | `:42–44` `Array.isArray(value)` branch: `value.map(...).join(',')` — no `.sort()`. Matches RFC 8785 §3.2.2 (array members keep input order). Round-1 P1 fix now pins this contract in tests (`:33`). Note: spec §4 line 234 still reads "sorted arrays; sorted object keys" — that wording remains inaccurate vs. JCS, and the round-1 spec rewording recommendation is **outside Task 5 scope** and tracked there. The implementation is correct. |
| Integers only — floats rejected | ✓ | `:28–35` throws `CanonicalEncodingError` for `!Number.isInteger(value)`. Negative integers pass (golden vector `negative_amount_refund_shape` quantity = -1). Supplementary test `:34` exercises `1.5` → throws `/Non-integer/`. |
| Decimal money as strings; timestamps as strings | ✓ | Strings route through `encodeString` → `JSON.stringify(normalizeFiscalString(value))`. `"12.345"`, `"-4.500"`, `"2026-05-14T10:00:00Z"`, `"2026-05-14"` all preserved verbatim across the 8 golden vectors. Supplementary test `:35` checks `"total":"0.000"` survives literally. |
| NFC normalization at the producer | ✓ | `:70` `value.normalize('NFC')`. Golden vector `multibyte_nfc_customer_note` reproduces a precomposed `"Café Élise"`-style string verbatim. Supplementary test `:39–41` writes the decomposed `'Café'` and asserts the composed `'Café'` lands in the canonical output. |
| U+2028 / U+2029 stripped at producer | ✓ | `:70` `.replace(/[  ]/g, '')`. Golden vector `line_separator_normalization` reproduces stripped output verbatim (`canonical bytes=521`). Supplementary test `:42–43` asserts the raw chars are absent. |
| SHA-256 = lowercase hex over UTF-8 canonical bytes | ✓ | `:74` `new TextEncoder().encode(input)` (UTF-8) → custom SHA-256 → `:75–76` `.map(byte => byte.toString(16).padStart(2, '0')).join('')` (lowercase, fixed-width). All 8 PHP-generated hex hashes reproduce. (See P2 deferred below re. *vetting* of the hand-rolled implementation.) |
| Module boundary | ✓ | Encoder has **zero** imports. `types.ts` has zero imports. Test imports `vitest`, the fixture JSON (cross-language test-time ground truth), and `../FiscalEventCanonicalEncoder`. No PHP edits, no provider wiring, no cross-feature-module imports. Plan §38 correctly excludes Task 5 from `FiscalServiceProvider` ownership. |
| Type/name consistency with frozen plan §2446 names | ✓ | Class `FiscalEventCanonicalEncoder`, type `FiscalEventCanonicalInput`, `CanonicalEncodingError` all match. File names and path casing (`apps/pos/src/lib/fiscal/`, lowercase) match plan §75 and the existing `apps/pos/src/lib/fiscal/v3/` sibling. |
| Path casing | ✓ | Lowercase `fiscal/` matches the existing `apps/pos/src/lib/fiscal/v3/` sibling and the plan. No case drift. |
| Regressions | ✓ | Diff vs base is exactly 3 new files (additive). Diff vs round-1 head `a1384abd` is two test-text additions, one comment in `types.ts`, and removal of one unused import + one unused re-export. Zero edits to pre-existing code. Nothing to regress. |

---

## Findings

### Round-1 findings — re-classification

- **P1 (array-order test/name gap)** → **CLEARED.** Test name + assertion fixed.
- **P3 (stray `FiscalEventCanonicalInput` re-export)** → **CLEARED.** Removed, along with the now-unused `import type` line.
- **P3 (`FiscalEventRow` conflates device + server shapes; status literals duplicate PHP enums)** → **PARTIALLY CLEARED (P3 follow-up).** Transitional comment lands in `types.ts:8–9` per round-1 option (b). The TS-status-literal-vs-PHP-enum drift remains — properly deferred to Task 14 per the round-1 review's own recommendation. **No action required for Task 5.**
- **P2 (hand-rolled SHA-256 not vetted)** → **REAFFIRMED as tracked P2 follow-up before Task 15.** See below.
- **P2 (encoder duplicates v3 `canonicalJson.ts` core)** → **REAFFIRMED as tracked P2 follow-up before Task 15.** See below.

### P2 (tracked, deferred to before Task 15) — Hand-rolled SHA-256 should be replaced with a vetted sync implementation, or pinned by boundary-targeted vectors

**File:** `apps/pos/src/lib/fiscal/FiscalEventCanonicalEncoder.ts:73–186`

Unchanged from round 1. The 8 golden vectors have UTF-8 canonical byte lengths in the 479–559 range (verified: `tnd_3dp_simple_sale=503`, `eur_2dp_simple_sale=549`, `jpy_0dp_simple_sale=548`, `negative_amount_refund_shape=559`, `empty_arrays_null_optionals=548`, `multibyte_nfc_customer_note=542`, `line_separator_normalization=521`, `non_ascii_key_order=479`). None of them lands near the SHA-256 padding boundary at 55/56/57 bytes, the exact single-block boundary at 64, the two-block boundary at 119/120, or 0-byte input. A subtle bug in `paddedSha256Input` (`:167–179`) at those boundaries would pass all 8 fixtures and surface only on a real receipt that happens to canonicalize to that length, landing as `canonical_hash_mismatch` quarantine after the device chain head has already advanced (spec §7.1, §8, §472) — the exact failure mode spec §4 D2 ("server verifies-verbatim, never re-serializes") is designed to make impossible at the *protocol* level but cannot prevent at the *device-side hash* level.

**Why this is P2 rather than BLOCKER on Task 5:** The encoder is not yet wired into a chain-advancing code path. `FiscalEventEngine.append()` (Task 15, plan §1075) is where the hand-rolled SHA-256 first writes to the authoritative `fiscal_events` SQLite row. Round 1's recommendation — and this round's — is to land the swap before Task 15 ships, not before Task 5 merges.

**Fix (carry forward to before Task 15):** Either (a) replace `sha256Bytes` / `paddedSha256Input` with a vetted sync SHA-256 (`js-sha256` is ~3 KB MIT and ships with NIST CAVP test vectors; `hash-wasm` is another option) — and leave `apps/pos/src/lib/fiscal/hashService.ts` async path untouched for the existing v3 receipt code; or (b) keep the hand-rolled implementation and add boundary-targeted test vectors — empty string, single byte, 55/56/57/63/64/65/119/120/127/128-byte inputs, each with the PHP-side `hash('sha256', $s)` as the expected hash. Option (a) is cheaper and lower-risk.

### P2 (tracked, deferred to before Task 15) — Encoder duplicates `apps/pos/src/lib/fiscal/v3/canonicalJson.ts` instead of reusing a shared core

**Files:** `apps/pos/src/lib/fiscal/FiscalEventCanonicalEncoder.ts:18–67` vs. `apps/pos/src/lib/fiscal/v3/canonicalJson.ts:35–87`

Unchanged from round 1. Side-by-side, the two implementations are structurally identical (`encodeValue` null → boolean → integer-checked number → string → array-preserved → object-sorted; `encodeObject` identical sort + map + join; `encodeArray` identical map + join) and diverge only at `encodeString`, where the new encoder threads strings through `normalizeFiscalString` (NFC + U+2028/U+2029 strip) before `JSON.stringify` and the v3 sibling does not (and documents that as accepted v3 behavior in its docblock at `canonicalJson.ts:73–85`). Maintaining two implementations of the same JCS pattern in `apps/pos/src/lib/fiscal/` creates a drift surface — a future correctness fix to one (an escape edge case, a JSON-encoding flag, a Unicode normalization tweak) would have to be applied to both by hand or the v3 receipt chain and the fiscal-event chain start producing different bytes for the same input.

**Why this is P2 rather than BLOCKER on Task 5:** The two encoders serve currently-live (v3 receipts) and not-yet-wired (fiscal events, Task 15) code paths respectively, and the drift cost only materializes when both are in production. Both round 1 and this round recommend factoring before Task 15 lands.

**Fix (carry forward to before Task 15):** Extract `apps/pos/src/lib/fiscal/jcs.ts` exporting `encodeJcsValue`, `encodeJcsObject`, `encodeJcsArray`, and `encodeJcsString(value, normalize?)` with a pluggable producer-side normalization hook. Have both `canonicalJson.ts` and `FiscalEventCanonicalEncoder.ts` call into it — v3 passes the identity normalizer; the new encoder passes `s => s.normalize('NFC').replace(/[  ]/g, '')`. PHP-side factoring of `apps/api/app/Modules/POS/Domain/Services/Fiscal/V3/CanonicalJsonEncoder.php` is unnecessary by spec §4 line 237 (the Phase 1 fiscal-event encoder is TS-only; no PHP mirror exists or should exist).

### No new round-2 findings

Reviewed the full diff vs `a1384abd` (round-1 head) and vs `7e469e0a` (base, Task 4 head). The round-2 amendment introduces:

1. Test rename + array-order assertion (`__tests__/FiscalEventCanonicalEncoder.test.ts:25, :33`) — addresses round-1 P1, no new risk.
2. Transitional-shape comment (`types.ts:8–9`) — addresses round-1 P3 option (b), no new risk.
3. Removal of unused `import type { FiscalEventCanonicalInput }` and unused `export type { FiscalEventCanonicalInput }` (`FiscalEventCanonicalEncoder.ts:1, :191`) — addresses round-1 P3, no new risk; the type is still defined in `types.ts` and importable from there.

No other diffs. No new findings, no new regressions, no new module-boundary violations, no new type/name drift, no new path-casing issues, no provider-wiring relevance (Task 5 still correctly absent from plan §38's `FiscalServiceProvider` ownership list).

---

## Concerns explicitly ruled out (round 2)

- **Provider wiring relevance** — Plan §38 still enumerates Task-5-owned `FiscalServiceProvider` edits as **none** (correctly — pure TS, no PHP container binding/route/command). Confirmed in current diff: zero PHP changes.
- **Module-boundary violations** — None. Encoder + types have zero imports. Test imports vitest, a cross-language JSON fixture (acceptable for test-time ground truth), and the local encoder.
- **Type/name drift** — Class name `FiscalEventCanonicalEncoder`, type name `FiscalEventCanonicalInput`, error class `CanonicalEncodingError`, file names, and path casing all match plan §2446 and §75. The `'hash-chain-integrity-v1'` `signature_version` token in the golden-vector fixtures still matches spec §3.1 and Task 6's plan.
- **Path-casing issues** — None. Lowercase `apps/pos/src/lib/fiscal/` matches the existing `apps/pos/src/lib/fiscal/v3/` sibling.
- **Regressions** — Diff is purely additive at the base level (3 new files, 263 lines, 0 edits to pre-existing files). At the round-1-head level the only edits are inside the new files (2 small test edits, 1 comment add, 1 unused-import + 1 unused-export removal). Zero way to regress existing behavior.
- **Cross-language fixture path resolves under Vitest** — Verified by running the test (10/10 pass).
- **Cross-language sort-order parity (object keys)** — JS `<` (UTF-16 code unit) matches RFC 8785 §3.2.3 (code-unit order) and matches PHP `ksort(..., SORT_STRING)` (byte order) for NFC-normalized BMP code points, which is exactly the input set the fixtures exercise (verified by `non_ascii_key_order` reproducing 97 < 233 < 946 verbatim). Supplementary-plane / surrogate-pair drift between the two languages is *theoretically* a surface but is not introduced or worsened by Task 5 — the v3 sibling has the same property — and the launch markets' inputs do not exercise it.
- **Spec §4 "sorted arrays" wording** — Spec §4 line 234 still reads "sorted arrays; sorted object keys"; the round-1 review correctly identified this as inconsistent with RFC 8785 §3.2.2 and recommended the spec be reworded. That is a **spec-doc** change, **out of Task 5 scope**, and tracked there. The implementation is correct (preserves array order) and now test-pinned.

---

## Recommendation

Merge Task 5 as-is. The round-1 merge-gating fixes (P1, P3 re-export, P3 row-shape comment) are all in place; tests pass (10/10); diff is clean and additive; no new findings. The two outstanding P2 items (vet the SHA-256; factor the shared JCS core) remain tracked follow-ups to land **before Task 15** (`FiscalEventEngine.append()`), which is the first task that wires the encoder into a chain-advancing path — at which point an unvetted hash or a drifted JCS core can quarantine real receipts and require chain-recovery events (spec §7.5) to unwind. Doing the swap during the Task 6–14 window is cheap (one library swap or one shared module extraction, in a single PR touching the v3 sibling alongside the new encoder) and lets Task 15 inherit a vetted hash + a single JCS core without a second round of changes.
