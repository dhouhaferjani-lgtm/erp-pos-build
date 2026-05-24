# Task 16 — `StrictCanonicalParser` — Codex Review

**Commit reviewed:** `7683ce666` on `feat/pos-fiscal-event-engine-phase1`
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1`
**Files under review:**
- `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php`
- `apps/api/app/Modules/Fiscal/Application/DTOs/ParseResult.php`
- `apps/api/tests/Unit/Fiscal/StrictCanonicalParserTest.php`

**Reviewer:** Codex (GPT-5.4 via codex-rescue)
**Codex session ID:** `019e3115-5540-7251-8fbb-513f0611dd34`
**Codex job:** `task-mp8ewrvi-e03mnw`
**Date:** 2026-05-16

> **Transcribed from inline Codex output** — the sandbox could not write to the fiscal-phase1 worktree, so the parent session transcribed verbatim per handoff §4.2 rule 4.

---

## Verdict

**BLOCK**

Task 16 covers the obvious parser hazards: duplicate keys, invalid UTF-8, exponents/floats, and DTO missing/type failures. The problem is that the accepted grammar is still much wider than the canonical fiscal contract. In particular, incomplete envelopes, extra payload fields, whitespace, malformed money strings, and object-shaped list containers all parse as OK. Those are not cosmetic gaps: they turn what should be `canonical_parse_failure` quarantines into parsed payloads, which breaks the validate-then-insert model in spec §7.2/§8.

---

## Findings

| Severity | ID | Location (file:line) | Description | Fix |
|---|---|---|---|---|
| BLOCKER | F1 | `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php:84` | The parser does not validate the §4 canonical envelope shape. It only checks `event_type` and `payload`, so canonical bytes containing just `{"event_type":"SALE_RECEIPT","payload":...}` parse as OK. Spec §4 requires the 14-field canonical object (`business_date`, `company_id`, `event_time_device`, `event_type`, `event_version`, `operator_id`, `payload`, `previous_hash`, `reference_document_id`, `reference_event_id`, `sequence_number`, `signature_version`, `tenant_id`, `terminal_id`), and handoff §4.3 explicitly says Task 16 must assert that shape. This lets incomplete canonical bytes become `parsed` instead of `canonical_parse_failure`. | Add an exact envelope validator before DTO dispatch: require exactly the 14 keys, reject extras/missing keys, assert types/nullability, `event_version` equals registry version, `sequence_number` is positive int, hashes are lowercase hex, timestamps/dates match canonical regexes. |
| BLOCKER | F2 | `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php:107` | Payload DTO construction is used as a partial schema check, but the original payload is returned unchanged at line 120. DTOs only require known fields; they do not reject extra fields. Runtime probe confirmed `SALE_RECEIPT.payload.extra_untyped` parses OK and is preserved. Spec §7.6 requires rejecting event-type schema violations, and handoff §4.3 says field-set drift must surface as `canonical_parse_failure`. | For each event type, reject payload keys outside the exact schema before returning. Do not return unsanitized original payload after a DTO accepted only a subset. |
| BLOCKER | F3 | `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php:408` | Whitespace is accepted before and between tokens via `skipWhitespace()`. Runtime probe confirmed `{ "event_type" : ... }` parses OK. Spec §4 requires RFC 8785/JCS canonical bytes; allowing insignificant whitespace accepts non-canonical bytes as parsed payloads instead of quarantining them. | Reject whitespace outside strings for this parser, or add a canonical-byte grammar mode that fails on any byte-level whitespace token outside string values. |
| BLOCKER | F4 | `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php:489` | Money fields are checked only with `is_string()`, not `CurrencyScale::bcformat()` format. Top-level money fields in `SaleReceiptPayload::fromArray()` also use only `requireString()` (`SaleReceiptPayload.php:52-65`). Runtime probe confirmed `"total":"not-money"` parses OK. Spec §4 requires money as `CurrencyScale::bcformat()` decimal strings. | Validate all money strings against scale-aware regexes derived from `currency_scale`/currency, including top-level `subtotal`, `discount_total`, `tax_total`, `total`, and per-item money fields. |
| P1 | F5 | `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php:471` | `validateListOfAssoc()` does not verify the container is a JSON list. It accepts associative objects for `lines`, `vat_breakdown`, `payment_lines`, `voucher_redemptions`, and `terminals` because it only checks each item. Runtime probe confirmed `"lines":{"k":{...}}` parses OK. This violates the DTO docblocks and the Task 14 carry-forward sub-array contract. | Add `array_is_list($items)` validation for list containers, then keep the existing non-empty associative-object check for each item. |
| P1 | F6 | `apps/api/app/Modules/Fiscal/Domain/DTOs/ChainBreakDetectedPayload.php:40` | Free-form payload strings that are hashes or constrained identifiers are not regex-validated: `last_good_hash`, `new_genesis_reference`, `snapshot_hash`, nested anchor hashes, and similar fields pass as any string. Spec §4 requires lowercase hex hashes and handoff §4.2 flags free-form string fields without regex validation as a recurring P1 pattern. | Add event-type payload validators for lowercase SHA-256 hex (`^[0-9a-f]{64}$`), ISO/date fields where present, and constrained reason/version tokens. |
| P2 | F7 | `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php:62` | Unicode validation is only valid UTF-8 plus surrogate-pair handling. It does not enforce the §4 canonical string constraints: NFC and U+2028/U+2029 stripped at producer. Runtime probe confirmed a payload string containing U+2028 parses OK. | Reject U+2028/U+2029 and non-NFC strings during string token emission, or classify this explicitly as producer-only and add a server-side canonical drift test documenting the accepted risk. |
| P2 | F8 | `apps/api/tests/Unit/Fiscal/StrictCanonicalParserTest.php:454` | Tests assert failure-reason prefix shape only for one duplicate-key case and do not cover the highest-risk gaps above: exact 14-field envelope, envelope extras, payload extras, whitespace, scale-aware money strings, list-vs-assoc containers, NFC/U+2028, or integer overflow at `PHP_INT_MAX + 1`. | Add negative tests for each gap. Include runtime examples from F1-F5 so future parser changes cannot silently widen the accepted grammar. |

---

## Verified by grep / read

- Verified HEAD and worktree target: `git rev-parse HEAD` returned `7683ce6666bcabc7b2277bb6a971c3b0efd372c0`.
- Read grounding docs in requested order with `nl -ba`/`sed`: spec §4 (`docs/...spec-v7.md:229-238`), §7.2 (`329-380`), §7.6/§8 (`460-478`), plan Task 16 (`1166-1214`), handoff §4.2/§4.3 (`95-113`), SoT §5/§7 (`94-140`).
- Read all files under review with `nl -ba`: `StrictCanonicalParser.php`, `ParseResult.php`, and `StrictCanonicalParserTest.php`.
- Read related registry/DTO code with `nl -ba`: `FiscalEventPayloadRegistry.php`, all files in `apps/api/app/Modules/Fiscal/Domain/DTOs/`, and `FiscalEventType.php`.
- Checked PHP cast risk with `rg "(int)|(string)|(array)|(bool)|hexdec"`: no Task 14-style casts from array keys found. The only parser casts are numeric tokenizer internals at `StrictCanonicalParser.php:291`, `309`, and `358`, with overflow round-trip check at `363`.
- Checked duplicate-key enforcement by reading tokenizer: object keys are parsed manually and rejected with `array_key_exists()` at `StrictCanonicalParser.php:168-170`.
- Checked number grammar by reading tokenizer: fractional/exponent numbers rejected at `StrictCanonicalParser.php:352-355`; overflow check exists at `357-365`.
- Checked invalid Unicode paths by reading tokenizer: invalid UTF-8 rejected at `StrictCanonicalParser.php:62`; bad escapes/surrogates rejected at `287-314`; NFC and U+2028/U+2029 are not checked.
- Checked no-throw requirement by re-reading `parse()` and tokenizer catch surfaces: tokenizer `RuntimeException`s are caught at `76`; DTO `Throwable`s are caught at `110`; sub-array `RuntimeException`s are caught at `116`. I did not find a confirmed malformed-input path that propagates a PHP exception out of `parse()`.
- Checked service lifecycle/reentrancy with `rg "StrictCanonicalParser|singleton|bind"`: no container singleton binding for `StrictCanonicalParser` found; current tests instantiate directly. Non-reentrancy is documented in the class, but no confirmed Laravel singleton lifecycle issue in current code.
- Runtime probes with `php -r` confirmed: two-field envelope parses OK; whitespace envelope parses OK; bad top-level money string parses OK; associative `lines` container parses OK; U+2028 string parses OK; payload extra field parses OK and is preserved.

---

## Summary

Task 16 covers the obvious parser hazards: duplicate keys, invalid UTF-8, exponents/floats, and DTO missing/type failures. The problem is that the accepted grammar is still much wider than the canonical fiscal contract. In particular, incomplete envelopes, extra payload fields, whitespace, malformed money strings, and object-shaped list containers all parse as OK. Those are not cosmetic gaps: they turn what should be `canonical_parse_failure` quarantines into parsed payloads, which breaks the validate-then-insert model in spec §7.2/§8.
