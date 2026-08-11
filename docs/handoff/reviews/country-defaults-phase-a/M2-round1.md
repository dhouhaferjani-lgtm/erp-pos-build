# M2 adversarial merge-gate review — round 1

**Scope:** brief §M2 (`docs/handoff/CODEX-DISPATCH-country-defaults-phase-a-2026-08-10.md:342-377`), diff `7d85232cc..HEAD`, M2 commits `d148555ba`, `7dc121e59`, `ac01b3e26`.
**Lenses:** tenancy-authz (applies), treasury (applies to the publish gate / absorber-timbre semantics only — **Rule 19 does not apply: M2 introduces no monetary or quantity value anywhere**; no new named queues, so Horizon coverage is N/A; M2 adds no user-facing string, so en+fr is N/A until M3).

---

## Register

**1 — P2 — `apps/api/app/Modules/CountryDefaults/Application/Services/TemplatePublishingService.php:114-135` — CONFIRMED mechanism, latent trigger**
`cloneToDraft()` reads source rows `orderBy('sort_order')` and inserts them one-by-one, but `admin_template_accounts` carries an **immediate** (non-deferrable) composite self-FK `(template_id, parent_code) → (template_id, code)` (`database/migrations/2026_08_11_100100_create_admin_template_accounts_table.php:32-34`; its enforcement is proven by `tests/Feature/CountryDefaults/TemplatePublishGateTest.php:147-176,194-230`). `sort_order` is not a topological order — nothing couples it to the parent/child hierarchy.
*Failure scenario:* a draft is built parent-first (`code='70'`, `sort_order=50`) then child (`code='706'`, `parent_code='70'`, `sort_order=10`) — both writes legal, `validateAccounts()` passes (order-independent, `:276-280`), `serialize()` sorts by `sort_order` so the hash is stable, publish and assignment succeed. `cloneToDraft()` on that published template then inserts `'706'` first → PG `23503` raw `QueryException`, transaction aborts. Since spec §4.1 makes clone→edit→publish→re-point **the only** way to change a published template, such a template is permanently uncorrectable, and the caller gets a 500 rather than a domain error. Untested: every clone fixture (`TemplateImmutabilityTest.php:325-336`, `TemplateAuditTransactionTest.php:48`) uses `parent_code => null`. Fix = topological insert order, or `->deferrable()->initiallyDeferred()` on the FK.

**2 — P2 — `apps/api/tests/Feature/CountryDefaults/CountryCodeNormalizationTest.php:28-45,105` — CONFIRMED**
The brief's M2 required-test list names "`country_code` normalization (`tn` → `TN`) at **every** write boundary". Only the assignment boundary is covered. The **publish** boundary — which writes the immutable `certified_country_codes` (`TemplatePublishingService.php:77`) — is never exercised with unnormalized input: every `publish()` call in the suite passes already-uppercase scopes (`['FR']`/`['TN']`/`[$country]` where `$country` is uppercase at `:105`), and `tests/Unit/CountryDefaults/CertificationScopeTest.php` contains no lowercase/whitespace case either.
*Failure scenario:* the normalization in `CertificationScope::normalizeScopeCode()` (`CertificationScope.php:81-93`) is currently correct, so this is a coverage hole rather than a live defect — but a regression that dropped `strtoupper()`/`trim()` there would persist `['tn']` into an **immutable** published column with zero test signal at the M2 boundary. One assertion closes it.

**3 — P2 — `apps/api/tests/Feature/CountryDefaults/TemplateLifecycleRaceTest.php:80,114,163,225,258,272` — CONFIRMED**
On non-pgsql the four race tests replace concurrency with sequential calls plus `addToAssertionCount(5|4|1)` padding — 20 synthetic assertions. That padding is what produces the report's headline parity claim (`docs/sessions/codex-country-defaults-phase-a-report.md`: "SQLite: 42 passed / 326 assertions … PostgreSQL: 42 passed / 326 assertions", justified as "SQLite executed equivalent deterministic lock and transaction-contract assertions so the counts remained identical"). They are not equivalent assertions; they are counters incremented to make the two lanes indistinguishable.
*Failure scenario:* the M7 whole-branch gate re-runs "the full accumulated evidence"; an operator running the SQLite lane alone sees `42/326` — byte-identical to the PG lane — while every lock-ordering, phantom-recheck and deadlock invariant went untested. The correct shape is `markTestSkipped('races require PostgreSQL')` and non-identical, honestly-labelled counts. (The PG lane itself is sound: the forked children pause at a real post-lock seam — `:310-352,383-425` — and the report's one-at-a-time lock-removal mutations went red, so the *substance* of the M2 concurrency claim stands.)

**4 — P3 — `TemplatePublishingService.php:71-83, 174-180` — CONFIRMED**
`publish()` and `archive()` discard the affected-row count of their `->update()`, while `assign()`/`remove()` check `!== 1` (`TemplateAssignmentService.php:129-131,178-180`). Safe today because the template row is held under `lockForUpdate()` from `:41`/`:161` and PostgreSQL's EvalPlanQual re-read makes the `where('status', …)` guard redundant — but the asymmetry means a future refactor that drops the lock degrades silently to "audit row logged, nothing written" instead of throwing.

**5 — P3 — `apps/api/app/Modules/CountryDefaults/Infrastructure/Models/CountryTemplateAssignment.php:28-42` — CONFIRMED**
The `saving` hook normalizes `country_code`, but `creating`/`updating`/`deleting` unconditionally throw and `saving` fires *before* them, so the normalization assignment at `:34` can never persist; all real writes go through the raw query builder in `TemplateAssignmentService`, which re-implements normalization at `:212-220`. The malformed-code rejection at `:30-32` is still a live ordering guard (tested at `CountryCodeNormalizationTest.php:47-61`) — only the normalization branch is dead.

**6 — P3 — `apps/api/tests/Feature/CountryDefaults/CentralConnectionUnderTenancyTest.php:60,66-70` — CONFIRMED**
The prescribed pattern (`tests/Feature/Services/TenantConfigCacheTenancyTest.php:177-203`) exercises the real `DatabaseTenancyBootstrapper` swap. This test instead sets `tenancy.bootstrappers = []` and hand-points `database.default` at a fake in-memory sqlite probe. It does prove the three models pin to `central` under a swapped default (the invariant that matters), but the production swap path is never executed.

**7 — P3 — `database/migrations/2026_08_11_100200_…:24-26` and `…100000_…` — CONFIRMED**
No index on `country_template_assignments.template_id` (PostgreSQL does not auto-index the referencing side of an FK), and none on `admin_templates(domain, status)`. `archive()`/`delete()` do two `where('template_id', …) FOR UPDATE` scans each. Negligible at central-table scale; record it rather than fix it.

**8 — P3 — the three migrations use bare `Schema::connection(...)->create()` with no `hasTable` guard — CONFIRMED, convention-conformant**
§5 requires "idempotent + unattended-safe". Laravel's migration repository makes re-application a no-op, `down()` uses `dropIfExists`, the report proves a clean `migrate:fresh --force` on an empty scratch DB with all three `Ran`, and 34 of 35 existing root migrations use the same bare form. Noting for completeness only.

**9 — P3 — `TemplatePublishingService.php:283` — CONFIRMED**
The publish gate matches the manifest classification with the string literal `'REQUIRED'` instead of `ProvisioningRequiredPurposesV1::REQUIRED`. A rename of the constant would make the 27-purpose REQUIRED loop match nothing and pass vacuously. The report's `MUTATED_REQUIRED` mutation shows the covering test goes red, so the hazard is currently trapped.

**10 — P3 — `TemplateLifecycleRaceTest.php:57,205,246` — CONFIRMED**
`pcntl_fork()` is called on the pgsql branch with no `function_exists('pcntl_fork')` guard; a PG environment without ext-pcntl fatals the M7 lane instead of skipping. Related: the handshake is followed by a fixed `usleep(400_000)` (`:340,410`) — if the parent has not reached the contended lock inside that window the race silently degrades to sequential and still passes green.

**11 — P3 — `tests/Feature/CountryDefaults/TemplateImmutabilityTest.php:364-383` — CONFIRMED**
`outsideCentralTransaction()` commits the `RefreshDatabase` wrapping transaction to observe the "requires an active central transaction" guards, then cleans only the three new tables in `finally`. `super_admins` and `admin_audit_logs` rows created inside the closure are committed and leak across the run.

**12 — P3 — `TemplateAssignmentService.php:71-73`; §1 file map (brief `:126`) — CONFIRMED**
`Domain/Exceptions/TemplateRecertificationRequiredException.php` and `TimbreCountryRequiresExactAssignmentException.php` are in the prescribed file map but do not exist; `assign()` raises a generic `DomainException` for the stale-capability-version case that spec §3.1 explicitly types. Neither is named in M2's own scope text or test inventory, so this is correctly deferred — but M4's `CapabilityRegistryBumpTransitionTest` will require the typed class, and the throw site is already written here.

---

## Bypasses attempted that FAILED (the code held)

- **Audit row escaping the central transaction.** `assertAuditTransaction()` only checks the *central* connection's level, so an audit model on the default (tenant) connection would defeat it — but `AdminAuditLog` carries `CentralConnection` (`app/Models/AdminAuditLog.php:35`), so the write lands on the same connection inside the same transaction. Invariant "a mutation without its audit row cannot commit" holds; rollback proven in `TemplateAuditTransactionTest.php:32-100`.
- **Archive/delete phantom (new assignment inserted after the empty assignment scan).** Defeated by the second `FOR UPDATE` recheck issued *after* the template lock (`TemplatePublishingService.php:162-166,208-212`) — a fresh READ COMMITTED statement snapshot that sees the just-committed insert. The mirror direction is defeated by EvalPlanQual re-read of the template status.
- **Stale in-memory draft mutating a concurrently-published template.** Defeated by `AdminTemplate::lockCurrentDraft()` (`:69-81`) and the account-row equivalent (`AdminTemplateAccount.php:39-52`).
- **Eloquent bypass of the assignment lifecycle.** `creating`/`updating`/`deleting` all throw (`CountryTemplateAssignment.php:37-42`); tested at `TemplateAssignmentServiceTest.php:111-179`.
- **Deleting a referenced template / a referenced parent row.** Composite FK is `NO ACTION`, verified in PG per the report and by `TemplatePublishGateTest.php:194-230`.
- **Assigning a draft / archived / wrong-domain / out-of-scope / stale-version / tampered-content template.** All blocked after the locks (`TemplateAssignmentService.php:65-103`), including a `hash_equals` recompute against the locked rows.
- **Non-timbre stamp-purpose smuggling.** Rejected at publish (`:292-294`) *and* re-run at assignment via `validateAccounts()` (`TemplateAssignmentService.php:100`), for exact **and** wildcard scopes. The `["TN","FR"]` and `["FR","*"]` scope-algebra rejections hold in `CertificationScope.php:31-47`.
- **Deadlock between publish/assign/archive/repoint.** Global order is assignment-rows-first then id-sorted template rows in every path; `publish()` takes no assignment lock, so no cycle exists.
- **`app()` helper, cache, or `config()` in production module code.** `grep` over `app/Modules/CountryDefaults/` returns nothing — constructor injection throughout, and the invariant "provisioning-relevant reads are never cached" is trivially satisfied (no cache introduced).

**Red-first evidence:** present and substantive for M2 — chronological red runs, four one-at-a-time mutation/revert cycles, and five PG lock-removal mutations, all in `docs/sessions/codex-country-defaults-phase-a-report.md`. The standing red-first check passes; finding 3 concerns how the *final* dual-engine numbers are presented, not whether the work was TDD'd.

**Verification limits:** I did not execute PHPUnit (write-side; the house rule forbids broad runs). `php -l` is clean on all M2 files. All findings above are from source and schema reading, cited.

VERDICT: CHANGES-REQUIRED
