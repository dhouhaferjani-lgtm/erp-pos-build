# SDD ledger — plan: docs/superpowers/plans/2026-07-27-cash-rounding-server-phase1.md
Task 1: minor (deferred): Rule::unique validates raw code while writes normalize to UPPER — case-collision window; consider functional unique index on UPPER(code)
Task 1: minor (deferred, pre-existing): Rule::unique code check scoped to tenant_id while index is (company_id, code) — spurious 422 in multi-company tenants; ticket-worthy
Task 1: fix round 1/5 (4 addressed [2 partial], 3 NEW open — code-null wipe CRIT, array-code 500, two-variant migration abort; commits f2d0010c6..686ee6437)
Task 1: fix round 2/5 (3 addressed, 0 open; commits 686ee6437..9973f5f39)
Task 1: minor (deferred): case-c' canonical+earlier-variant migration coverage is UUID-order-flaky — add explicit id-ordered test
Task 1: minor (deferred): numeric code payloads now 422 (was coerced) — release-note line
Task 1: complete (commits 534611b43..9973f5f39, review clean after 2 fix rounds)
Task 2: minor (deferred): demo seeders (DatabaseSeeder/CoffeeShop/Parapharmacy) don't invoke CountryPaymentSettingsSeeder — local/demo DBs get no rows until manual run or ops-command upsert; consider wiring in demo-seeder task/ticket
Task 2: note: PaymentRepositorySeeder command->info() null crash pre-existing on base (10 regression errors) — not ours
Task 2: minor (deferred): UPDATE always rewrites row (updated_at churn) — skip when pinned values match
Task 2: minor (deferred): migration down() dropColumn unguarded
Task 2: minor (deferred): migration UPDATE-branch ceiling tightening never asserted; rename test_tn_upsert_state_2
Task 2: follow-up (Task 13 checklist): demo seeders need CountryPaymentSettingsSeeder call (Parapharmacy/CoffeeShop/DatabaseSeeder) — launch demo tenant has no row otherwise
Task 2: cross-task pointer (Task 3 reviewer): confirm resolver re-scales 0.0500 -> '0.050' at company currency scale
Task 2: fix round 1/5 (3 addressed, 0 open; commits 65f748413..85eaecc6b — incl. scoped exception PaymentRepositorySeeder:128 ?-> unblocking live registration path)
Task 2: complete (commits 9973f5f39..85eaecc6b, review clean after 1 fix round)
Task 3: brief-correction: test setUp must seed CountriesSeeder + CountryPaymentSettingsSeeder (country_payment_settings is EMPTY after RefreshDatabase; 2 of the brief's tests would have failed, 4 passed vacuously)
Task 3: brief-correction: is_numeric() guard insufficient for bcmath (accepts "1e5" -> ValueError, not InvalidArgumentException); normalizers unified behind a strict plain-decimal regex, PHPStan L8 clean with no ignores
Task 3: brief-correction: round-trip bccomp scale split into PERCENTAGE_SCALE vs DENOMINATION_STORAGE_SCALE, compared at max(storage, currency scale)
Task 3: cross-task pointer CLOSED — 0.0500 -> '0.050' confirmed at BOTH the DTO hop and the raw HTTP body (added wire test), TS type is string
Task 3: added coverage beyond brief: NULL denomination disables rounding; raw-body string-fidelity assertion (10 tests vs the brief's 8)
Task 3: note: route manifests (scripts/factory/manifests) are FE-route-only — a Laravel API route needs no regeneration
Task 3: complete (commit 9fd4fdffc, 10/10 both drivers, pint+phpstan clean)
Task 3: minor (deferred): endpoint auth/scoping tests missing (401 + cross-company 403)
Task 3: minor (deferred): DTO max_amount docblock says currency, means scale (no FX conversion in v1)
Task 3: minor (deferred): dead is_string guard + double normalize(); unused Request param
Task 3: minor (deferred, ticket): resolver imports Company/CountryPaymentSettings models directly (precedented; proper shape = Shared/Contracts port)
Task 3: note (device brief carry-forward): refreshedAt is ISO-T; device comparisons vs datetime('now') columns must route toSqliteUtc (spec already says)
Task 3: fix round 1/5 (2 Importants addressed, 0 open; commit 9fd4fdffc..94a7ce229) — single scale source (countries.currency_decimal_places wins over ISO map) + static §4.1 denomination caps as a 4th fail-closed branch
Task 3: NEW SHARED SURFACE App\Shared\Domain\CashRoundingCaps (0=>'10', 2=>'1.00', 3=>'1.000'; unlisted scale => disabled) — Tasks 4 (ops command refuse/--verify) and 6 (validator bind) MUST import it, never re-type the literals; values are history-stable (baked into verification of already-signed receipts)
Task 3: note: CurrencyScaleResolverInterface could NOT be injected for the scale fix — its explicit-$currencyCode form short-circuits to the ISO map (:38-41) reproducing the bug, and the no-arg form throws outside CompanyContext; company path mirrored explicitly with citations in the docblock
Task 3: anti-vacuity evidence: 5 of the 6 new tests fail against the pre-fix resolver (stash run recorded in the report); the 6th is an inclusive-boundary non-regression guard by design
Task 3: complete after fix round 1 (16/16 both drivers, pint+phpstan clean)
Task 3: fix round 1/5 (2 addressed, 0 open; commits 9fd4fdffc..94a7ce229 — CashRoundingCaps shared class born, cross-task contract for T4/T6)
Task 3: minor (deferred): resolveScale mirror unpinned to CurrencyScaleResolver (no test fails if resolution order changes); docblock line cites off-by-two
Task 3: complete (commits 85eaecc6b..94a7ce229, review clean after 1 fix round)
Task 4: brief-correction: setUp must seed CountriesSeeder + CountryPaymentSettingsSeeder (same empty-table trap as Task 3; 3 of the brief's 7 tests would have failed)
Task 4: brief-correction: brief resolved scale via CurrencyScale::for() (ISO map) — replaced by the countries.currency_decimal_places mirror of PosPaymentPolicyResolver::resolveScale; mutation-proved
Task 4: brief-correction: is_numeric guard -> strict plain-decimal regex (bcmath ValueError on "1e-2"); + decimal(15,4) truncation check ("0.00255" would have stored silently as 0.0025)
Task 4: CashRoundingCaps CONSUMED (imported, literals never re-typed) on the write path + --verify report, per Task 3's downstream contract
Task 4: added beyond brief: --enable-rounding validates the EFFECTIVE (stored) denomination; --verify fails on enabled+unusable denomination; scale falls back to the countries lookup when NO company uses the country (brief ran zero scale checks in that case -> any value storable)
Task 4: note: PDO_SQLite returns decimal(15,4) as FLOAT — denomination reads go through the CountryPaymentSettings 'string' cast, not the raw query builder (3 sqlite-only failures before the fix)
Task 4: minor (deferred): --verify ignores --country by design; a tenant with zero companies passes vacuously; rounding is enabled per COUNTRY so one flag flips every company in that country
Task 4: complete (commit a9f88d570, 21/21 both drivers, pint+phpstan clean, 2 mutation proofs)
Task 4: minor (deferred): dry-run/apply divergence on unknown country; verify vacuous-pass on zero-company tenant (warn added later?); guard omits pos_tolerance_enabled hasColumn; upsert race non-atomic; --verify+mutation-flags silently ignored; Carbon vs toDateTimeString inconsistency
Task 4: fix round 1/5 (2 Importants addressed, 0 open; commits a9f88d570..7223b6c06)
Task 4: NEW SHARED SURFACE App\Shared\Domain\CountryPaymentDefaults (TN/FR pinned ceilings + backfill denomination) — extracted from CountryPaymentSettingsSeeder's private const; seeder + ops command both read it, never re-type. Task 13 checklist: a new country needs an entry here, not a literal
Task 4: 🔑 DEPLOY CONTRACT — `tenants:run <cmd> -- <flags>` DOES NOT WORK (stancl Run takes 1 arg, flags only via --option='k=v'); Run::handle() returns null so the child EXIT CODE IS ALWAYS SWALLOWED. --verify emits the stable token `CASH-ROUNDING VERIFY FAILURES: <n>` (pinned by test); checklists must tee+grep it, and token ABSENCE = failure
Task 4: fix round 1 note: insert branch now carries the seeder's pinned ceilings (was falling to the 0.50 column default vs TN's 0.1000 -> silent 5x POS tolerance loosening) and REFUSES --enable-tolerance on insert for countries with no pinned defaults
Task 4: complete after fix round 1 (26/26 both drivers, pint+phpstan clean, 3 mutation proofs)
Task 4: fix round 1/5 (2 addressed, 2 new doc-gate importants open — multi-tenant grep gate, plan checklist broken invocation; commits a9f88d570..7223b6c06 — CountryPaymentDefaults shared class born)
Task 4: fix round 2/5 (2 Importants + 2 minors addressed, 0 open; commits 7223b6c06..618bc162d) — docs only, no logic change
Task 4: 🔑 GATE RECIPE (final) — `! grep -qE 'CASH-ROUNDING VERIFY FAILURES: [1-9]' log` AND token-count == tenant-count. NEVER `grep -q '… : 0'` (passes when ANY ONE tenant is clean; a FAILURES: 3 tenant slips through). Recipe proved against 4 synthetic logs
Task 4: plan Task-13 checklist FIXED IN PLACE (:5721+) — --option='k=v' callout box covering EVERY command + robust gate; sibling accounting:backfill-tolerance-purposes `-- --dry-run` fixed pre-emptively for Task 5. Plan's Task-4 BRIEF block (:1498) deliberately left stale (already executed, superseded by the report)
Task 4: complete after fix round 2 (26/26 both drivers, pint+phpstan clean)
Task 4: fix round 2/5 (4 addressed, 0 open; commits 7223b6c06..618bc162d, docs-only)
Task 4: minor (deferred → Task 13 MUST fix in checklist): TENANT_COUNT undefined in pasted gate recipe — define via TENANT_COUNT=$(grep -c '^Tenant: ' /tmp/cr-verify.log); optionally self-announcing || echo GATE FAILED
Task 4: complete (commits 94a7ce229..618bc162d, review clean after 2 fix rounds)
Task 5: minor (deferred, ticket): legacy 658/758 purpose-holders accepted not normalized; Account::findByPurpose ignores is_active (inactive holder gets posted to) — reported by command, resolver unfixed; accounts.balance decimal(19,2) vs rule-19 floor pre-existing
Task 5: minor (deferred): no DB::transaction (recoverable via idempotency; note in checklist); promote doesn't repair parent/name; device-plan :3397 wording codes→purposes; token substring assertion loose
Task 5: fix round 1/5 (3 addressed, 0 open; commits fd0ca9360..5bc5f9c51)
Task 5: minor (deferred, cluster ticket): @cross-tenant-by-design tag semantically inverted for tenant-DB-bound commands — real fix is a third TenantScopedCommand sub-shape or @tenant-db-bound tag
Task 5: complete (commits 618bc162d..5bc5f9c51, review clean after 1 fix round)
Task 6: minor (deferred): toArray() not faithful for explicit-null v3 keys (no callers today; Phase-2 snapshot hazard — carry presence bool); bcmod only inside adj!=0 branch (self-contradictory metadata allowed, spec-conformant — document); stale identity docblock :967; BestEffort/repair hardcode 'operational' chain context (pre-existing ticket); pint drift on 2 untouched fiscal test files (pre-existing)
Task 6: PHASE-2 COORDINATION (carry into Plan B dispatches): TS 30-key mirror + drift gate MANDATORY before device signs v3 (else 100% quarantine); device must canonical-zero-normalize (never emit -0.000); projection still rounding-blind until T8 (ordering fragile but safe)
Task 6: fix round 1/5 (3+2 addressed, 0 open; commits a1ab129cb..d946f7b68, test-only)
Task 6: minor (deferred): inaccurate rationale comment BestEffortPayloadParserTest:374-377 (pre-threading failure = spurious extra_field at same path, not bare payload path)
Task 6: complete (commits 5bc5f9c51..d946f7b68, review clean after 1 fix round)
Task 7: downstream contract (T8/T9/T10 dispatches): total != SUM(vat gross) by exactly one adjustment now legal at DB level — app-level integrity checks must include adjustment term; GL writers must never Draft-then-reinsert same (source_type, source_id) under the unscoped partial indexes
Task 7: minor (deferred → Task 13 checklist): ADD CONSTRAINT takes ACCESS EXCLUSIVE + full scan — note deploy-window lock on large tenants
Task 7: downstream (T10): GL writers must pin duplicate-insert behavior under unscoped partial indexes with a test (retried queued job would 23505 → dead-letter)
Task 7: fix round 1/5 (2 addressed, 1 NEW open — re-apply-after-down aborts on validating ADD CONSTRAINT; commits efe267df5..9c427be1f)
Task 7: fix round 2/5 (1 addressed, 1 NEW open — unfiltered Throwable catch swallows non-23514; commits 9c427be1f..c89dfd566)
Task 7: fix round 3/5 (1 addressed + 2 fold-ins, 0 open; commits c89dfd566..bd7a7e6a5)
Task 7: complete (commits d946f7b68..bd7a7e6a5, review clean after 3 fix rounds; 4-mutation proof ledger in report)
Task 8: cross-task (T9 dispatch): cutover const is private PosCoreReceiptProjection::CASH_ROUNDING_EVENT_VERSION — bridge must gate on same event_version>=3; promote to shared location in T9
Task 8: minor (deferred): no v3 REFUND/VOID projection coverage (T9-10 build on it — fold into their scope); training change_due write unpinned; missing-policy-row alert branch untested; tolerance_writeoff '0.000'-vs-NULL — T9 bridge must handle BOTH (only training is NULL)
Task 8: deploy note (T13): change_due now written for v3 ⇒ expected-cash/variance figures shift at cutover for rounding terminals (correct direction — document)
Task 8: discovered defect (ticket): earnLoyaltyPoints catches WITHOUT savepoint — bare catch poisons PG outer transaction (25P02) — root cause of pre-existing PosCoreReceiptProjectionLoyaltyEarnTest PG errors; fix = savepoint like the new reconcile containment
Task 8: fix round 1/5 (2+2 addressed, 0 open; commits cfa661534..e3dd81ee6, differential-verified)
Task 8: minor (deferred): docblock 'can never cost the sale' overstated for deadlock-in-nested-tx path; PG-runner containment test (real SQL failure) ticketed; 3 extra queries per rounded receipt (telemetry, acceptable)
Task 8: complete (commits bd7a7e6a5..e3dd81ee6, review clean after 1 fix round)
Task 9: deploy gate (T13 checklist HARD): is_cash_tender seeded per tenant BEFORE v3 device cutover — unflagged CASH method silently banks change + alerts every over-tender (verify command checks it)
Task 9: cross-task (T10): tolerance_writeoff '0.000' not NULL on v3 no-shortfall — whereNotNull selects every v3 receipt; no GL entry for netted change here — T10 must not double-count
Task 9: complete (commits e3dd81ee6..9627e2b27, review clean, ZERO fix rounds)
Task 9: PRE-CUTOVER tickets (T13 checklist MUST carry): (a) SalesReportService:166-181 sums TENDERED cash — overstates from cutover (subtract change_due or read payments); (b) ReportGenerationService:513 UPPER(code)='CASH' vs is_cash_tender predicate split — Z vs Treasury disagree on unflagged variants; (c) configure --verify = HARD blocking gate before device cutover; sweep PosAnalyticsService/Nf525DataProvider/ReceiptPaymentService against two-semantics rule
Task 9: minor (deferred): isCashTender defensive maturity exclusion one-liner; notes counts suppressed legs; v3 REFUND over-tender netting untested; scale-3 netting case unexercised
Task 10: parked question (final review / owner): short-tendered canonical REFUND books tolerance same-direction as sale (spec silent; canonical refunds unauthorable in v1) — implemented as written + 🎫
Task 10: deploy (T13): purpose backfill = HARD prerequisite before v3 traffic (precheck silently skips entries otherwise); rounded+shortfall receipt consumes 3 entry numbers
Task 10: complete (commits 9627e2b27..9b81f001e, review clean, ZERO fix rounds)
Task 10: minor (deferred): approval evidence not amount/target-checked (telemetry-mute vector); unreachable defensive probe + misnamed replay test; purpose-recovery replay test unpinned; dual-missing-purpose single alert; wrong index-rationale comment; training receipts reach GL at all (pre-existing 🎫 now internally inconsistent)
PHASE NOTE: deptrac ratchet fails 61->97 IDENTICALLY on dev (pre-existing stale baseline) — branch CI will fail on it regardless of this work; resolve at promotion (rebaseline ticket or coordinate with dev)
Task 11: PLAN B HARD ORDERING CONSTRAINT: device zReportHashService.ts must gain the identical cash_rounding_summary isset-block BEFORE any device emits the key (else false CHAIN_BREAK on legacy-arm re-verification); server zero-shape contract = total_adjustment '0.000' / receipt_count int 0
Task 11: minor (deferred): window-source asymmetry (payload period vs shift opened/closed) comment; microsecond-vs-timestamp(0) note; is_voided filter inert for v3 (defensive); sqlite float-cast guard; scale-3 surface note for T12; findChainBreak no fiscal_event_id filter (pre-existing)
Task 11: fix round 1/5 (2 addressed, 0 open; commits ad46cc3da..4a06cfd76 — expected-set deviation JUSTIFIED, convergence trace clean)
Task 11: complete (commits 9b81f001e..4a06cfd76, review clean after 1 fix round)
Task 11: minor (deferred, 🎫): anti-join needs partial index fiscal_events(terminal_id, event_time_device) WHERE SALE_RECEIPT+verified (seq-scan inside shift lock, grows unbounded); timestampTz-vs-naive GUC hardening; T13 ops note: never-projecting receipt now dead-letters the Z too — remedy fiscal:retry-projections
Task 12: complete (commits 4a06cfd76..fb6b84705, review clean, ZERO fix rounds)
Task 12: PLAN B BLOCKER: toleranceApi.ts field mismatch (receiptId/cashierName vs emitted receiptNumber/userId/userName) — fix CLIENT before enabling drill-down panel
Task 12: minor (deferred): blade bccomp scale-3 hardcode (sibling-consistent); ReceiptPdfService float debt 🎫; NF525 void/return mappers carry no adjustment (refund track); JET element-order external-consumer question; fr locale render unpinned; pos.operate_terminal vs report-only-role visibility question
Task 13: fix round 1/5 (3+7 addressed, 0 open; commits 64acb47e2..f90b43855)
Task 13: minor (deferred → final-review wave): §1.1 heading 'every tenants:migrate' overstates (runs once, recorded); 3 line-pin drifts (:67-72/:75; resolver :172-185)
Task 13: complete (commits fb6b84705..f90b43855, review clean after 1 fix round)
FINAL REVIEW: treasury CHANGES-REQUESTED (3 must-fix: A1 rewrite logging+preflight; rollback claim; journal_entries lock window) + full triage table; fiscal APPROVE-FOR-MERGE (2 pre-Phase-2 doc fixes: §5.1 applied-with-no-row remedy; §0.3 v3-build trigger). Money conservation hand-traced CLEAN across all compositions; v1/v2 immutability HOLDS under composition; quarantine-repair coherent; Z parity coherent. ONE fix wave dispatched (10 items incl. triage promotions + netting docblock purity correction).
PHASE COMPLETE: final fix wave a31decfdd + correction b82ed466b; terminal re-review 10/10 + residuals applied per reviewer prescription.
