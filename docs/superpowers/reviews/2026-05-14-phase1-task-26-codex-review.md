# Codex Review - Phase 1 Task 26 (`31d2c94a6`)

**Verdict**: REQUEST-CHANGES

## Architectural carve-out verification

REFUTED. I did not find an explicit server-authoring carve-out for `TERMINAL_REGISTRY_SNAPSHOT`.

Spec §11 says: "`TERMINAL_REGISTRY_SNAPSHOT` - implemented in Phase 1. It has no closure dependency: a registry snapshot (the authoritative list of terminals expected for a company at a point in time; carries a hash; links to the prior snapshot) can be emitted at terminal provisioning and on demand. Phase 1 delivers the event type, the payload DTO, the `append()` handler, and an initial-snapshot emission path."

That text confirms the event type and emission use case, but it does not say "server-authored", "server may author", or otherwise waive the core rule. The core rule remains explicit: spec §3 says "Device SQLite is authoritative; server PostgreSQL is a verbatim verify-only mirror", and spec §4 says "the device serializes once; the server never re-serializes." The implementation's server-authored path is therefore not supported by an explicit §11 carve-out.

## Findings table

| Severity | ID | File:Line | Description | Required Fix |
|---|---|---|---|---|
| BLOCKER | T26-B1 | `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/apps/api/app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php:20` | The service is explicitly a "Server-authored `TERMINAL_REGISTRY_SNAPSHOT` emitter" and writes `FiscalEvent::query()->create(...)` at lines 162-193. This silently expands the server from verify-only mirror to chain author. The only cited spec text (§11) does not explicitly allow that, while spec §3/§4 explicitly forbid server serialization/re-authoring. `FiscalEvent` itself still says writes happen exclusively through `OutboxIngestor` at `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/apps/api/app/Modules/Fiscal/Domain/Models/FiscalEvent.php:19`. | Either amend the locked spec with an explicit server-authorable company-integrity carve-out and its invariants, or remove this direct server writer and route snapshot authoring through the device `FiscalEventEngine.append()` plus `OutboxIngestor` verify-only path. |
| P1 | T26-P1 | `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/apps/api/app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php:305` | `resolveChainPlacement()` reads the prior head with an unlocked `SELECT ... orderByDesc('sequence_number')->first()` and the insert is a plain Eloquent `create()` at line 164. This does not use Task 19's standing `INSERT ... ON CONFLICT ON CONSTRAINT fiscal_events_tenant_terminal_sequence_unique DO NOTHING RETURNING id` pattern. Concurrent snapshot emissions on the same terminal can compute the same next sequence and prior hash; one will surface as a raw unique violation instead of a controlled conflict/retry/quarantine path. | If server authoring is kept after a spec change, wrap head resolution and insert in a transaction with a real terminal/company lock or use the named-constraint `ON CONFLICT` pattern plus deterministic retry/return semantics. Add a concurrency test. |
| P2 | T26-P2 | `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/apps/api/app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php:127` | The service constructs a DTO and immediately marks `payload_parse_status=parsed` at line 192, but it does not call `FiscalPayloadConstraintValidator::validatePayloadKeySet()` or `validatePerEventConstraints()`. The current payload happens to satisfy the validator, but this direct writer bypasses the same constraint surface that `StrictCanonicalParser` and corrected-payload resolution share. | Before writing `payload` as trusted/parsed, run the shared payload key-set and per-event validator, or provide an explicit server-authoring parser/validator path in the spec and implementation. |
| P2 | T26-P3 | `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/apps/pos/src/lib/fiscal/FiscalEventEngine.ts:547` | The TS engine accepts `TERMINAL_REGISTRY_SNAPSHOT` with no payload validation because the comment says there is no Phase 1 TS authoring path. But the TS registry marks the type implemented at `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts:63`, and `append()` can author any implemented type. If §11 is not server-only, cross-language drift matters and TS currently allows payloads PHP would reject (`snapshot_hash`, `prior_snapshot_link`, `terminals` constraints). | If this event is device-authorable, mirror PHP validator constraints in TS. If it is server-only, make TS `append()` reject it or remove it from the device implemented set, with an explicit spec note. |
| P3 | T26-P4 | `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/apps/api/tests/Feature/Fiscal/TerminalRegistrySnapshotTest.php:325` | Coverage proves first and second snapshots, but misses third-snapshot linking, concurrent emission, invalid prior snapshot payload, malformed terminal DB values, and soft-delete/insert races during list construction. | Add narrow tests for at least concurrent emission and third snapshot linking; add malformed-prior/terminal fixtures if server authoring remains. |

## CLEAN observations

- `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/apps/api/app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php:184` writes `canonical_bytes`; line 187 writes `signature_status` as `SignatureStatus::NotRequired`.
- `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/apps/api/app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php:99` scopes the authoring terminal lookup by `tenant_id` and `company_id`; line 219 scopes the terminal roster by both and line 222 excludes soft-deleted terminals.
- `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/apps/api/app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php:262` scopes prior snapshot lookup by `tenant_id`, `company_id`, event type, and verified integrity.
- `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/apps/api/app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php:315` uses `pos_terminals.genesis_seed` as the first event's `previous_hash`, matching spec §3 note that first events use the terminal fiscal-event genesis seed and matching the Task 15 device engine at `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/apps/pos/src/lib/fiscal/FiscalEventEngine.ts:327`.
- `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:201` confirms the `prior_snapshot_link` contract is a nullable 64-hex hash, not a UUID. The service's prior-link choice matches that contract.
- `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:114` and `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:127` show both production projectors handle only `SALE_RECEIPT`; no `fiscal_event_projections` rows for snapshots is currently expected.
- `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/.github/workflows/ci.yml:391` adds `TerminalRegistrySnapshotTest` to the PG merge gate and the comment explains the PG-only hash/type CHECK constraints being exercised.
- `git diff 31d2c94a6^ 31d2c94a6 -- apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php` is empty; no provider edit was made in this commit.

## Premise audit

| # | Premise | Result |
|---|---|---|
| 1 | Local encoder JCS-equivalent for scalar payloads | PARTIAL. Current emitted leaves are strings/bools/ints/null, and the helper sorts object keys recursively with compact JSON, so it is byte-equivalent for this constrained shape. It is not a full RFC 8785 implementation: no NFC/U+2028/U+2029 producer normalization is visible, and future floats/non-scalar leaves would break the premise. |
| 2 | No StrictCanonicalParser round-trip acceptable | REFUTED as implemented. Without an explicit server-authoring carve-out, the server should not author or parse-as-trusted at all. Even with a future carve-out, the service should at least reuse `FiscalPayloadConstraintValidator` before setting `payload_parse_status=parsed`. |
| 3 | No fiscal_event_projections rows for snapshots is correct | CONFIRMED. Grep found only `PosCoreReceiptProjection` and `TreasuryReceiptBridge`, and both `handlesEventType()` methods return true only for `SALE_RECEIPT`. |
| 4 | Registry `all()` backward-compatible | CONFIRMED. The method yields the private sorted projector list without changing `activeProjectorsFor()` or `byName()`, and the existing registry filter passed 16/16. |
| 5 | No provider edit needed | CONFIRMED. The service constructor dependencies are container-resolvable and the commit did not modify `FiscalServiceProvider`. |
| 6 | Task 31 guard test value | CONFIRMED as a defensive incremental-wiring lock. `test_fiscal_service_provider_does_not_yet_wire_verify_event_chain_command` asserts Task 26 does not prematurely wire Task 31's command. |

## Test coverage map

- `test_terminal_registry_snapshot_can_be_emitted`: event type and payload keys.
- `test_emitted_snapshot_persists_a_fiscal_events_row_with_verified_integrity`: persisted row status, tenant/company/terminal/operator, hash shape, sequence positive.
- `test_first_snapshot_links_previous_hash_to_terminal_genesis_seed`: first event anchor and null prior link.
- `test_terminals_payload_includes_authoritative_identity_columns`: roster entry shape.
- `test_snapshot_hash_is_sha256_of_canonical_terminals_list`: snapshot hash recomputation.
- `test_multiple_company_terminals_appear_in_snapshot_sorted_by_code`: deterministic ordering.
- `test_soft_deleted_terminals_are_excluded_from_snapshot`: `deleted_at IS NULL`.
- `test_other_company_terminals_are_excluded_from_snapshot`: tenant/company isolation.
- `test_second_snapshot_links_to_prior_via_prior_snapshot_link_and_advances_sequence`: second snapshot sub-chain and terminal chain advance.
- `test_unknown_terminal_id_throws_at_service_boundary`: fail-closed missing authoring terminal.
- `test_company_day_closure_manifest_throws_not_implemented_on_append`: reserved event behavior.
- `test_fiscal_service_provider_binds_the_seam_interfaces`: cumulative seam/provider wiring.
- `test_fiscal_service_provider_does_not_yet_wire_verify_event_chain_command`: Task 31 omission guard.
- `test_pos_core_receipt_projection_is_resolvable_from_registry`: POS projector lookup.
- `test_treasury_receipt_bridge_is_resolvable_from_registry`: Treasury projector lookup.

Uncovered: concurrent emission race, third snapshot links to the second, malformed prior snapshot payload, malformed terminal DB values, and roster mutation during snapshot assembly.

## Test results

- TerminalRegistrySnapshotTest: 15/15 passed.
- FiscalEventProjectionRegistry: 16/16 passed, with PHPUnit deprecations reported.
- Full Fiscal suite: 216/216 passed, 37 skipped.
- PHPStan level 8: clean, 0 errors.

## Overall recommendation

Request changes. The targeted and fiscal suites pass, and much of the implementation is internally consistent, but the central premise is not independently supported by the spec: §11 does not explicitly make `TERMINAL_REGISTRY_SNAPSHOT` server-authorable. The direct writer also lacks the Task 19 conflict-handling pattern and does not reuse the shared payload constraint gate before marking the payload parsed. Resolve the architecture first; then harden conflict handling and validation around whichever authoring model the spec actually permits.

VERDICT: REQUEST-CHANGES
