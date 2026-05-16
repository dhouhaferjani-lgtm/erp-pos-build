# Task 16 — `StrictCanonicalParser` — Opus Review

**Commit reviewed:** `7683ce666` on `feat/pos-fiscal-event-engine-phase1`
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1`
**Files under review:**
- `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php`
- `apps/api/app/Modules/Fiscal/Application/DTOs/ParseResult.php`
- `apps/api/tests/Unit/Fiscal/StrictCanonicalParserTest.php`

**Reviewer:** Opus 4.7 (1M)
**Date:** 2026-05-16

---

## Verdict

**APPROVE-WITH-MINOR-EDITS**

The implementation is sound. The hand-rolled tokenizer correctly addresses the four explicit spec §7.6 contracts (duplicate keys, out-of-grammar numbers, invalid Unicode, event-type schema violations), the sub-array carry-forward from Task 14 P2-2 is implemented for every implemented event type, and the never-throws contract is upheld end-to-end (verified by empirical edge-case probing — see Verified section). Surrogate-pair handling, integer-overflow detection, and the duplicate-key-after-unicode-escape-normalization are all correctly implemented. Tests pass (`34 tests / 175 assertions`), phpstan level-8 clean, pint clean.

The one P1 worth landing before Task 19 wires the parser into `OutboxIngestor` is the **format gap on payload-level hash fields** — the analogue of Task 15 P1-1 on the server side. Two P2 test-coverage gaps (surrogate-escape branches entirely untested; non-`SALE_RECEIPT` event types' top-level schema gates have thin coverage) are worth filling at the same commit so the regression net catches a future refactor. The remaining items are P3 polish.

---

## Findings

| ID | Severity | Location | Description | Fix |
|---|---|---|---|---|
| P1-1 | P1 | `StrictCanonicalParser.php:115` + payload DTOs | **Hash-format hole — direct analogue of Task 15 P1-1 on the server side.** `last_good_hash` (`ChainBreakDetectedPayload`), `new_genesis_reference` (`ChainRestartPayload`), `snapshot_hash` (`TerminalRegistrySnapshotPayload`), and nested hash fields (`last_good_anchor.hash`, `offending_record_reference.observed_previous_hash`) flow through the parser as opaque strings — neither the DTO `requireString` guard nor `validateSubArrays` enforces the 64-char lowercase hex format. Empirically confirmed: parser accepts `last_good_hash:"NOT_HEX"`. Spec §4 + §9 are explicit that previous_hash/hashes are 64-char lowercase hex. Task 15 fixed exactly this class of leak on the device boundary (regex-validate `event_time_device`, `business_date`); the server parser is the symmetric backstop and currently doesn't enforce it. Carry-forward §4.3 says the parser MUST match the device engine's payload-validation contract; the device contract (Task 15 `validateRequestPayload`) regex-checks hash fields; the server parser doesn't. | Add a `validateHashFields()` helper that asserts `/^[0-9a-f]{64}$/D` on the listed fields in each payload type, invoked from `validateSubArrays()` (rename to `validatePerEventConstraints` since it covers more than sub-arrays). The same helper covers `last_good_anchor.hash` and `offending_record_reference.observed_previous_hash` (nested). Failure prefix: `invalid_hash_format`. Add 2-3 tests: per-payload-type bad-hex assertion. |
| P2-1 | P2 | `StrictCanonicalParserTest.php` (no test method) | **Unicode-escape branch coverage is zero.** The parser's `parseUnicodeEscape()` is 36 lines with three exception sites (lone low surrogate, lone high surrogate, invalid second-half of pair) and an arithmetic path with surrogate decomposition (`0x10000 + ((high - 0xD800) << 10) + (low - 0xDC00)`). None of these branches is exercised by the 34 tests — invalid-UTF-8 tests cover the `mb_check_encoding` upfront gate, not the escape sequences. Tested empirically: parser correctly decodes `😀` to `\xF0\x9F\x98\x80` (😀), correctly rejects lone high (`\uD83D` followed by non-`\u`), lone low (`\uDE00` alone), and high+non-surrogate (`\uD83DA`). The coverage gap matters because the offset arithmetic at `:300-:314` is the kind of code Codex has caught Opus missing — it is correct today but trivially broken by a future refactor. | Add 4 tests: (a) valid surrogate pair decodes round-trip (`{"x":"😀"}` payload decodes `x` to `\xF0\x9F\x98\x80`); (b) lone high surrogate at end-of-input rejected; (c) lone low surrogate rejected; (d) high+non-low pair rejected. Same commit can add a test for `A`-style BMP escapes decoding to ASCII (basic happy path also missing). |
| P2-2 | P2 | `StrictCanonicalParserTest.php` (no test method per type) | **Thin happy-path + thin schema-violation coverage on the 3 non-SALE_RECEIPT event types.** Each of `CHAIN_BREAK_DETECTED`, `CHAIN_RESTART`, `TERMINAL_REGISTRY_SNAPSHOT` has exactly one happy-path test (`:47-:56`, `:58-:65`, `:67-:74`) — and the latter two assert only `result->ok`, not any payload field. Schema-violation coverage is sparse: no test asserts the parser rejects a `CHAIN_RESTART` with missing `new_genesis_reference`, or a `TERMINAL_REGISTRY_SNAPSHOT` with `terminals` not present, etc. The single `test_rejects_payload_missing_required_field` only covers `SALE_RECEIPT`. A future field-set drift on a chain-recovery payload won't be caught by the existing test net. | Add one `test_rejects_*_missing_required_field` per non-SALE_RECEIPT type (3 tests). Add one `test_returned_payload_carries_typed_fields_for_*` per type (assert specific field values, mirroring the SALE_RECEIPT test at `:34-:45`) — strengthen the round-trip evidence for the 3 underspecified types (3 more tests). |
| P2-3 | P2 | `StrictCanonicalParser.php:437-441` | **`validateSubArrays` `default => null` is a silent-fall-through trap for future event types.** The match handles the 4 currently-implemented types and silently no-ops for everything else. Today this is unreachable because line `:94` (`dtoClassFor`) throws first for unimplemented types — but the day a 5th Phase 1 event type lands without a corresponding sub-array clause, the parser will admit malformed sub-arrays silently. Same anti-pattern Task 14 P2-2 was about: trust by omission. | Either (a) throw on `default` with a clear "event type registered in registry but missing parser sub-array clause — update `validateSubArrays`" message; or (b) replace match with an explicit per-type method and have the registry's `dtoClassFor` and the parser's per-type method use a shared single-source-of-truth enum-keyed table. (a) is the minimum. |
| P2-4 | P2 | `StrictCanonicalParser.php:80-91` | **Envelope `is_array(envelope)` is true for an empty list `[]`.** Empirical check: input `"[]"` passes the line `:80` check, then fails at line `:85` with `event_type_mismatch:expected=SALE_RECEIPT,envelope=null` — practically correct rejection, but the error message says `envelope=null` even though the envelope was an empty array (the `is_string($envelopeType) ? $envelopeType : get_debug_type($envelopeType)` ternary stringifies the missing-key default `null`, not the envelope shape). Forensically misleading on the rare case where someone has to debug a quarantined payload. Could similarly distinguish "envelope is a list, not an object." | Add an `array_is_list($envelope) || count($envelope) === 0 ? distinct error : continue` shape check; failure prefix `envelope_not_object` with reason `got=list/empty_array`. |
| P3-1 | P3 | `StrictCanonicalParser.php:43` | **Non-reentrancy is documented but not tested.** Verified empirically that calling `parse()` twice on the same instance with different inputs works (state resets correctly via `:66-:68`). Test net never exercises the reuse path. | Add a single `test_parser_reuses_safely_across_calls` test: parse-fail, then parse-ok, then parse-fail; assert each result is independent. |
| P3-2 | P3 | `StrictCanonicalParser.php:363` | **`-0` is silently admitted as a special case.** Comment claims `-0` is "the one accepted divergence" — RFC 8259 allows `-0`, but spec §4 grammar is "integers only" and the canonical encoder (device-side) should never emit `-0`. The parser's leniency creates a subtle exception class: a malicious or buggy device that emits `-0` for any int field passes the parser and may break downstream invariants (`-0 === 0` in PHP, but JSON re-serialization with strict canonical form would round-trip differently — though this never happens because the parser doesn't re-serialize). Low priority because the canonical hash check upstream is the real defense, but worth removing the special case once we're sure no test relies on it. | Drop the `&& $token !== '-0'` carve-out. If a real fixture needs `-0`, surface it explicitly. |
| P3-3 | P3 | `StrictCanonicalParser.php:317-320` | **Comment claims `mb_chr` cannot fail; ignores `false` return type contractually.** `mb_chr` returns `string\|false` per PHP 8 docs. The comment justifies the omitted check, but if PHP ever tightens the spec or codepoint validation drifts, this becomes a return-type mismatch silently. Phpstan accepts it because `mb_chr` is permissive — but the parser would benefit from a belt-and-braces `if ($r === false) throw new RuntimeException('invalid_unicode_escape:mb_chr failed at offset '.$this->pos);`. | Add the explicit `false` check around `mb_chr($codepoint, 'UTF-8')`. |
| P3-4 | P3 | `StrictCanonicalParser.php:108` | **`@var array<string, mixed> $payload` annotation is a phpstan hint, not a runtime guarantee.** PHP's `is_array(...)` (line `:103`) returns true for both list and assoc. If the payload happens to be a list `[]` (legal JSON), it would be passed to `$dtoClass::fromArray(...)` which expects `array<string, mixed>` — `FiscalPayloadArrayGuards::assertKey` would fail with "missing required key currency" (line `:115` from FiscalPayloadArrayGuards). The behavior is correct (eventually rejected) but the error message would say "missing required key" instead of "payload must be an object, not a list." | Add a `if (array_is_list($payload) && count($payload) > 0) return ParseResult::failure('payload_not_object:got=list');` guard between lines `:103` and `:107`. Edge case but improves forensics. |
| CLEAN-1 | CLEAN | `StrictCanonicalParser.php:60-78` | **Never-throws contract is upheld end-to-end.** Verified by inspection + empirical probing: every value-parsing exception is caught by the try/catch at `:70-:78`; the `validateSubArrays` exception is caught at `:114-:118`; the DTO `fromArray` `Throwable` is caught at `:107-:112`; the `FiscalEventTypeNotImplemented` is caught at `:95-:97`. No path in `parse()` can throw to the caller. | None. |
| CLEAN-2 | CLEAN | `StrictCanonicalParser.php:323-368` (parseNumber) | **Integer overflow detection via round-trip stringification is correct.** Verified empirically: `99999999999999999999` correctly rejected with `out_of_grammar_number:integer overflow`. PHP_INT_MIN (`-9223372036854775808`) round-trips correctly. The `-0` special-case is intentional (see P3-2). | None. |
| CLEAN-3 | CLEAN | `StrictCanonicalParser.php:152-189` (parseObject) | **Duplicate-key detection at every depth, including after unicode-escape normalization.** `array_key_exists` on the post-escape-decoded key correctly identifies `{"A":1,"A":2}` as a duplicate. PHP's numeric-key coercion does NOT apply to canonical decimal strings like `"01"` (only to canonical-decimal ints like `"1"`); both behave correctly. | None. |
| CLEAN-4 | CLEAN | `StrictCanonicalParser.php:285-321` (parseUnicodeEscape) | **Offset arithmetic on surrogate-pair handling is correct.** Verified by trace + empirical test: `😀` decodes to the correct UTF-8 bytes `\xF0\x9F\x98\x80`; lone high, lone low, and high+non-low all rejected with the right error class. Bounds check `$this->pos + 6 >= $this->len` correctly admits the case where the last hex digit is exactly the last byte before `"`. | None — but see P2-1 (untested). |
| CLEAN-5 | CLEAN | `ParseResult.php:23-46` | **DTO shape correctly enables the `payload=NULL, payload_parse_status='failed', integrity_status='quarantined', integrity_exception_class='canonical_parse_failure'` quarantine path per §8.** `failureReason` is the structured `<snake_case_prefix>:<context>` per the regex assertion in `test_failure_reason_is_machine_readable_kebab_or_snake_case_prefix`. Private constructor + named factory methods enforce the invariants. | None. |
| CLEAN-6 | CLEAN | `StrictCanonicalParser.php:449-453` | **Sub-array shape coverage is complete for all 4 implemented event types.** `SALE_RECEIPT`: `lines`, `vat_breakdown`, `payment_lines`, `voucher_redemptions` all validated with the right monetary-string fields. `CHAIN_BREAK_DETECTED`: `offending_record_reference` validated as non-empty assoc. `CHAIN_RESTART`: `last_good_anchor`, `operator_authorization_evidence`, `provenance_link` validated. `TERMINAL_REGISTRY_SNAPSHOT`: `terminals` validated as list of non-empty assoc. The full Task 14 P2-2 carry-forward set is covered. | None. |

---

## Verified

Specific claims checked against the code + spec + empirical probes:

- **Never-throws contract** — `StrictCanonicalParser.php:70-78, :107-:112, :114-:118` all catch and convert to `ParseResult::failure(...)`. Empirically confirmed: top-level `null`, top-level int, top-level array, top-level garbage all return ParseResult::failure (Verified via `/tmp/test_misc.php` probe).
- **Duplicate-key detection at every depth** — Spec §7.6 first contract. Code: `StrictCanonicalParser.php:169`. Tests: `:80-:89` (envelope), `:91-:105` (payload), `:107-:122` (sub-array item). Empirically confirmed: `{"a":1,"a":2}` → `duplicate_key:a`.
- **Out-of-grammar numbers** — Spec §4 + §7.6. Code: `:323-:368`. Tests: float (`:128-:136`), `e` exponent (`:138-:146`), `E` exponent (`:148-:156`), leading-zero (`:158-:168`), plus-sign (`:170-:178`). Empirically confirmed: 20-digit overflow → `integer overflow`.
- **Invalid UTF-8** — Spec §4 + §7.6. Code: `:62-:64` (`mb_check_encoding` upfront). Tests: invalid 2-byte sequence (`:184-:193`), lone continuation byte (`:195-:204`).
- **Event-type schema violations** — Spec §7.6 fourth contract. Code: `:107-:112` (DTO `fromArray` Throwable trapping). Tests: missing required field (`:210-:220`), wrong field type (`:222-:235`).
- **Sub-array shape (Task 14 P2-2 carry-forward)** — Handoff §4.3 explicit input to Task 16. Code: `:430-:517`. Tests: scalar in lines (`:298-:310`), empty object in lines (`:312-:323`), int monetary in lines (`:325-:342`), int monetary in payment_lines (`:344-:358`), scalar in terminals (`:360-:371`), empty for provenance_link (`:373-:387`), empty voucher_redemptions accepted (`:389-:399`). **All 4 implemented event types covered** — verified by reading `validateSaleReceiptSubArrays`, `validateChainBreakDetectedSubArrays` (via the registry-style dispatch), `validateChainRestartSubArrays`, and the terminals dispatch.
- **`COMPANY_DAY_CLOSURE_MANIFEST` properly rejected as unimplemented** — Spec §11. Test: `:270-:280`. Code path: `FiscalEventPayloadRegistry::dtoClassFor` throws `FiscalEventTypeNotImplemented` which is caught at `:95-:97`.
- **Integers only (no floats)** — Spec §4. Code: `:323-:368`. Test: `:128-:136` ensures `1.5` rejected.
- **Money as `CurrencyScale::bcformat()` strings** — Spec §4. Code: `:488-:498` in `validateListOfAssoc` (named monetary-string fields enforced when present). Tests: `:325-:342` (unit_price as int rejected), `:344-:358` (amount as int rejected).
- **14-envelope-field check is intentionally NOT in the parser** — Spec §7.6 says the parser's contracts are the 4 enumerated above; review prompt confirms envelope-level checks are `OutboxIngestor`'s job. Code at `:84-:104` only extracts/validates `event_type` + `payload`. **Verdict-relevant: this is correct per the SPEC, despite the handoff §4.3 wording ("asserts the §4 fourteen-field shape") being slightly ambiguous.** Cross-checked against the review prompt grounding.
- **`payload=NULL, payload_parse_status='failed', ...quarantined...` quarantine path enabled** — Spec §8 + §7.6. Code: `ParseResult.php:25-:45` (`{ok: false, payload: null, failureReason: <prefix:context>}`). Test: `:454-:466` enforces `<prefix>:<context>` shape.
- **No `(type) $array['key']` PHP casts** — Task 14 BLOCKER pattern. Grepped the file: zero `(int)`, zero `(string)`, zero `(array)` casts on `$something['key']` patterns. Casts on bare strings (`(int) $token` in `parseNumber:358`) are unrelated.
- **Surrogate pair handling correctness** — Verified by trace through `parseUnicodeEscape:285-:321` AND empirical test: valid pair `😀` decodes to `\xF0\x9F\x98\x80` (😀 = U+1F600 correct UTF-8); lone high rejected; lone low rejected; high+non-low rejected.
- **Reentrancy / state-reset** — Empirically confirmed parser instance survives mixed ok/fail/ok calls without leaking state.
- **Stack depth** — Empirically confirmed parser handles 100,000-deep nested objects without stack overflow (PHP's default stack is generous).
- **BOM rejection** — Empirically confirmed: BOM at start fails at offset 0 with `unexpected_character`.
- **`array_is_list([])` empty-array trap** — Verified: at line `:485` the empty-list check is preceded by the `count === 0` check at line `:482`, so empty objects are correctly rejected before the list check runs.
- **Tests pass + static checks** — `phpunit StrictCanonicalParserTest`: 34 tests / 175 assertions OK. `phpstan analyse --level=8`: no errors. `pint --test`: pass.

---

## Patterns explicitly checked from the review prompt

| Pattern | Result |
|---|---|
| `(type) $array['key']` PHP casts → silent coercion BLOCKERs (Task 14 mechanism) | **CLEAN** — none in the parser. The only `(int)` cast is on a bare-string `$token` in `parseNumber`, guarded by round-trip overflow detection. |
| `payload: unknown` TS seams without runtime validation (Task 15 mechanism) — analogue: unvalidated trust in `DTO::fromArray()` | **P1-1** — DTO `fromArray()` accepts string for hash fields without format validation; parser does not backstop this. Direct analogue of Task 15 P1-1 on the server boundary. |
| Free-form string fields without regex format checks (Task 15 P1-1) | **P1-1** — `last_good_hash`, `new_genesis_reference`, `snapshot_hash`, `last_good_anchor.hash`, `offending_record_reference.observed_previous_hash` all unchecked. |
| DB row `(string) row.key` narrowing | N/A — parser doesn't touch DB. |
| Sub-array shape (Task 14 P2-2 carry-forward) | **CLEAN-6** — fully covered for all 4 implemented event types. |
| `(type) cast` silent coercion | **CLEAN** — none. |
| Off-by-one in tokenizer (string offset arithmetic, surrogate pair handling) | **CLEAN-4** — verified correct by trace + empirical test (but **P2-1** says it's untested). |
| Reentrancy issues (instance state) | Documented + verified safe (single-threaded PHP). **P3-1** — untested. |
| Integer overflow on sequence_number-style large ints | **CLEAN-2** — round-trip-stringification check correctly rejects overflow. |
| Unicode edge cases (lone surrogates, codepoints above 0x10FFFF, BOM) | Lone surrogates / surrogate-pair: **CLEAN** (correct, but untested → **P2-1**). Codepoints above 0x10FFFF: impossible to reach via valid surrogate pair (high+low pair maxes at U+10FFFF), and single `\uXXXX` is 4-hex-digit so max 0xFFFF. BOM: empirically rejected. |
| `array_is_list([])` returns true — does any check incorrectly classify empty arrays? | **CLEAN** — `count === 0` check precedes `array_is_list` check in both `validateListOfAssoc` and `validateNonEmptyAssoc`. But **P2-4** at envelope-level — empty array passes the `is_array` check and falls through to `event_type_mismatch` with a slightly misleading error string. |
| `is_array()` true for both list and assoc | **P3-4** — DTO call at `:109` could be a list silently. Edge case. |
| Never-throws contract | **CLEAN-1** — verified end-to-end. |

---

## Test-quality assessment

| Quality | Count | Notes |
|---|---|---|
| Happy path | 4 (one per event type) | SALE_RECEIPT happy-path asserts payload fields; the other 3 only assert `result->ok`. **P2-2** says strengthen. |
| Duplicate keys | 3 (envelope, payload, sub-array item) | All depths covered. |
| Out-of-grammar numbers | 5 (float, e, E, leading zero, plus sign) | Good. Missing: bare minus (`-`), `-.5`, integer overflow (would round-trip-detect). |
| Invalid UTF-8 | 2 (invalid 2-byte, lone continuation byte) | Adequate. |
| Schema violations | 5 (missing field, wrong type, missing payload key, payload-not-object, event_type mismatch) | Good for SALE_RECEIPT; thin for other event types. **P2-2.** |
| Sub-array shape | 7 | All Task 14 P2-2 carry-forward slots covered. |
| Miscellaneous | 6 (trailing bytes, unterminated string, unbalanced object, empty bytes, payload-doesn't-include-envelope-fields, failureReason format) | Good defensive coverage. |
| **Missing entirely** | — | Unicode escape branch (**P2-1**), parser-reuse (**P3-1**), hash-format (**P1-1**), envelope-as-empty-list (**P2-4**), `default` fallthrough on new event type (**P2-3**). |
| **Total** | 34 tests / 175 assertions | All distinct failure modes; no duplication. |

---

## Spec section coverage

| Spec section | Contract | Coverage |
|---|---|---|
| §4 (canonical serialization contract) | Integers only, no floats; money as `CurrencyScale::bcformat()` decimal strings | **OK** — parser enforces integer-only at the tokenizer; sub-array monetary fields enforced as strings. |
| §4 | UTF-8 NFC strings with U+2028/U+2029 stripped at producer | Parser does NOT re-check NFC normalization or U+2028/U+2029 stripping. **By design** — those are producer-side normalizations; the hash check is the integrity backstop. **OK.** |
| §4 | Sorted arrays, sorted object keys | Parser does NOT enforce sort order. **By design** per session handoff §4.3 ("the parser does NOT enforce sort order (that's the producer's contract upstream)") + comment at `StrictCanonicalParserTest.php:506-508`. **OK.** |
| §7.6 | Rejects duplicate keys | **OK** — covered + tested. |
| §7.6 | Rejects out-of-grammar numbers | **OK** — covered + tested. |
| §7.6 | Rejects invalid Unicode | **OK** — covered + tested. |
| §7.6 | Rejects event-type schema violations | **OK** — covered + tested. |
| §7.6 (carry-forward §4.3) | Validates sub-array per-item shape | **OK** — covered + tested for all 4 implemented event types. |
| §8 | On parse failure: `payload = NULL, payload_parse_status = 'failed', integrity_status = 'quarantined', integrity_exception_class = 'canonical_parse_failure'` | **OK** — `ParseResult.failure(reason)` enables this without exception-based control flow. `OutboxIngestor` (Task 19) consumes the result. |
| §9 | Hash format `lowercase hex 64-char` for chain hashes | **GAP — P1-1** — parser doesn't enforce. |
| §11 | `COMPANY_DAY_CLOSURE_MANIFEST` reserved schema-only | **OK** — rejected as `event_type_unimplemented` via `dtoClassFor`. |

---

## Pre-flight gates run

- `cd apps/api && ./vendor/bin/phpunit tests/Unit/Fiscal/StrictCanonicalParserTest.php` — **34/34 PASS, 175 assertions**.
- `cd apps/api && ./vendor/bin/phpstan analyse <files> --level=8 --no-progress` — **[OK] No errors**.
- `cd apps/api && ./vendor/bin/pint --test <files>` — **{"result":"pass"}**.

---

## Recommendation

Land the P1-1 hash-format guard + P2-1 surrogate-pair tests + P2-2 underspecified-type test pad before wiring `StrictCanonicalParser` into `OutboxIngestor` (Task 19). P2-3 default-fallthrough guard is a one-liner — fold it into the same commit. P2-4 / P3-* can be deferred to a tail-end cleanup commit.

The implementation pattern — never-throws + structured `failureReason` + machine-readable prefix — is sound and should become the canonical pattern for the remaining ingestion-path tasks.
