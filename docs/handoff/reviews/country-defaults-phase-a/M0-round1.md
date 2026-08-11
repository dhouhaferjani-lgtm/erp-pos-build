## Adversarial merge-gate register — M0 (round 1), lens: **treasury**

> **Provenance after recoverable history cleanup.** This register was generated against the
> pre-cleanup SHAs shown in its original reviewed-range line below; that line remains unchanged as
> historical truth. Those objects remain reachable on
> `codex/country-defaults-phase-a-pre-rewrite` (tip `7a4d65f25`). The current equivalent M0 first
> commit is `c744c19cc` (`Phase 0.0.0: Reconcile country defaults baseline`), which folds in the
> original M0 and fix-round-1 changes. The current commit recording this round-1 register/review
> state is `b65cdaee2` (`Phase 0.0.1: Record M0 review findings`). M7 should use this mapping to
> trace the preserved findings without treating the historical range as current ancestry.

**Range reviewed:** `7d85232cc..ef57a03ae` (3 commits; 7 files; **zero** `.php`/`.ts`/`.tsx` files).
**Artifacts held to the brief:** `docs/handoff/CODEX-DISPATCH-country-defaults-phase-a-2026-08-10.md` §0/§0.1 + "M0" section (`:259-285`), §9 deliverable (`:822-831`).

---

### A. What I independently reproduced (treasury lens — COA/purpose content)

The lens applies to items 1–5 of M0's scope. I re-ran the reconciliation from scratch, not from the report:

| Claim (addendum `:20-24`) | Independent result | |
|---|---|---|
| TN 139 / FR 144 / Generic 61 accounts | 139 / 144 / 61 | ✅ |
| Missing REQUIRED (27-set) = `[]`,`[]`,`[]` | `[]`,`[]`,`[]` — all 27 present in all three definition arrays | ✅ |
| `SalesStampDutyPayable` present TN, absent FR/Generic | present only at `apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php:208` | ✅ |
| D-6: 41 enum cases, `requiredPurposes()` = 13 | 41 cases; 13 returned (`SystemAccountPurpose.php:160-181`) | ✅ |
| D-4 TN/FR instrument codes | match `InstrumentAccountResolver.php:71-81` exactly | ✅ |
| D-4 demo-consumer codes (TN/FR 7; generic 4) | match `ExpenseCategorySeeder.php:42-49, 58-63` and `:107-115` country selection | ✅ |
| D-2 replay statement "verbatim" | byte-identical to brief `:60` | ✅ |
| Base pin unchanged (V-1) | `git rev-parse origin/dev` = `7d85232cc` | ✅ |

**Treasury sub-lenses that do not apply:** payments/GL posting, partial-write atomicity, Rule 19 money/quantity precision, migrations, named queues/Horizon, tenant scoping, constructor injection, en+fr strings — mechanically N/A: the range contains no executable file (`git diff --name-only | grep -E '\.(php|ts|tsx)$'` → empty).

---

### B. Findings

**1. [P2] `apps/.../design-ADDENDUM-2026-08-10.md:82-84` — CONFIRMED — D-7's drift fact is never recorded; the section duplicates S-5 instead.**
Brief §0 Deliverable (`:68-71`) requires the addendum to record **D-1..D-8**. The addendum's "### D-7" heading is followed by the S-5 *decision* paragraph, repeated again verbatim at `:137`. The brief's D-7 **reality** column is absent: `settings.update` authorizes a request that accepts `country_code` (`apps/api/app/Modules/Tenant/Presentation/Requests/UpdateCompanySettingsRequest.php:17,41` — verified), the controller writes it (`CompanySettingsController.php:113,150` — verified), and `tax_configurations` is country-scoped with **no `company_id`** (`database/migrations/tenant/2025_12_30_100000_create_tax_configurations_table.php:14-15,35-36` — verified). *Failure scenario:* the M7 checklist and the certification-scope reviewer derive from the addendum; without the country-scoped-tax-table fact, nobody downstream sees that a `country_code` flip re-points a live company at a different country's tax configuration rows — which is precisely the residual risk S-5 disclaims but does not describe.

**2. [P2] `…design-ADDENDUM-2026-08-10.md:79` — CONFIRMED — the operational manifest is mislabelled "the 27-purpose operational manifest".**
Spec §4.2.1 partitions it as `27 + 1 + 4 + 9 = 41`; brief §1 `:128` prescribes `ProvisioningRequiredPurposesV1.php  # the 41-entry manifest`. 27 is the REQUIRED *classification count*, not the manifest's cardinality. *Failure scenario:* M1 is instructed that "everything downstream cites" the addendum (brief `:70`). A 27-entry `ProvisioningRequiredPurposesV1` leaves SCOPE-REQUIRED (`SalesStampDutyPayable`), the 4 CONDITIONAL and 9 SOFT entries unregistered — so the AST/PHPStan registration ratchet (brief `:134`) either fires on 14 legitimate throwing sites or gets loosened to shut them up, and the conformance suite's CONDITIONAL-gate-evidence assertions plus the deliberately-misclassified fixture have no entries to bind to.

**3. [P2] `docs/sessions/codex-country-defaults-phase-a-report.md:39-40, 62` — CONFIRMED — the M0 substitute-for-red-first evidence is not hermetic to the pinned base.**
The command `require "apps/api/vendor/autoload.php"` resolves through a symlink (`apps/api/vendor -> /Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor`), so the composer classmap loads the seeders from the **main repo working tree**, not this worktree. I re-ran it and instrumented `ReflectionClass::getFileName()`:
```
TN file=/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php
```
— while the same output block prints `base_sha=7d85232cc…`, creating a false impression that the definitions were read at the pin. **The numbers are nonetheless correct** (md5 of all three seeders is identical between main repo and worktree, and `git diff 7d85232cc..dev` on those files is empty), so M0's *conclusion* survives; the *method* does not. *Failure scenario:* the main repo is checked out to another branch or holds an uncommitted seeder edit — the command silently certifies a different chart under the pinned SHA. This matters beyond M0: the M4 data-driven fixture-delta suite (brief `:454`) reads the same classes the same way.

**4. [P2] `scripts/adversarial-review.sh:85` — CONFIRMED — the gate bridge grants the "read-only" reviewer full write access.**
`--permission-mode bypassPermissions` contradicts `docs/handoff/SELF-REVIEW-HARNESS.md:68` ("The reviewer is Opus, read-only… **Never let the review edit the repo**"). The read-only property is prompt text only, with nothing enforcing it. Empirically confirmed from inside this very invocation: I was launched through this bridge and hold unprompted `Write`/`Edit`/`Bash`. *Failure scenario:* a reviewer that "helpfully" applies a finding mutates the working tree mid-gate; round *n+1* then reviews the reviewer's own edits, and the milestone's recorded `commit` SHA no longer corresponds to what the register describes. Use a read-only `--allowedTools` set (or `--permission-mode plan`). *In the reviewed range (commit `d29b14f33`) but outside the M0 brief section.*

**5. [P3] `scripts/adversarial-review.sh:35` — CONFIRMED — `${req,,}` is bash 4.0+; `/usr/bin/env bash` here is 3.2.57 (the only `bash` on PATH).**
Executed with a missing `--out`: `line 35: missing --${req,,}: bad substitution`, **exit 1** — not the documented fail-closed **3** (script `:15`, harness doc `:40`). Only the missing-argument path is hit (bash 3.2 defers the expansion to the `echo`), so well-formed invocations are unaffected; the harness contract has no meaning for exit 1.

**6. [P3] `…design-ADDENDUM-2026-08-10.md:17` — CONFIRMED — the 27 REQUIRED purposes are neither enumerated nor cited in the addendum.**
The list exists only in spec §4.2.1 prose and in the **gitignored** session report (`:46-56`). Since the addendum is the durably-committed artifact "everything downstream cites," a reader of it alone cannot audit or reproduce the `[]` results.

**7. [P3] `…design-ADDENDUM-2026-08-10.md:22-24` — CONFIRMED — scope-dependent row omits the citation the brief supplies** (`TunisiaChartOfAccountsSeeder.php:208`, brief M0 item 2). Content verified correct; only the anchor is missing. Same class of omission at `:84`/`:137`, which drop the S-5 ticket pointer `docs/superpowers/tickets/2026-08-10-country-code-mutable-authz-authority.md:8-23,33-35` (the ticket exists) from text the brief marks "verbatim".

**8. [P3] `d29b14f33` — CONFIRMED — branch-scope bleed.** The first branch commit carries an unrelated wave's artifacts into this branch's diff: `docs/handoff/CODEX-DISPATCH-wave3-3c-3d-2026-08-10.md` and `docs/handoff/progress/wave3-3c-3d.progress.yaml`. It also makes the addendum the branch's **second** commit, where brief §9 `:829` says "The §0 addendum as the **first** commit."

---

### C. Bypasses / refutation attempts that FAILED (do not re-assert these)

1. **"Generic chart can't satisfy the wildcard instrument set"** — refuted: all nine non-TN codes (`5112`,`4035`,`413`,`403`,`5113`,`5114`,`627`,`44566`,`416`) are present in `GenericChartOfAccountsSeeder.php` despite its 4-digit house style. D-4's wildcard claim is accurate.
2. **"Account counts are off by one"** — `grep -c "'code' =>"` yields 140/145/62; the extra hit is the insert loop (`TN:82`, `FR:81`, `GEN:78`). Definition-array counts are 139/144/61, and reflection agrees.
3. **"The vendor symlink makes the reported numbers wrong"** — refuted: md5-identical seeders across main repo and worktree, and `git diff 7d85232cc..dev` on those three files is empty. Downgraded to the method-level finding #3.
4. **"origin/dev advanced past the pin (V-1 violated)"** — refuted: `origin/dev` = `7d85232cc`. The SessionStart "38 commits behind dev" notice refers to **local** `dev` (unpushed batch); the brief pins `origin/dev`.
5. **"The script won't parse on bash 3.2"** — `bash -n` exits 0; only runtime expansion fails, on the missing-arg path only. Downgraded to P3.
6. **Rule 19 float-on-money, migration safety, Horizon queue coverage, tenant scoping, `app()` usage, en+fr** — no executable file in the range; N/A by mechanical check, not by omission.

---

### D. Assessment

M0's substantive treasury obligation — *"do the reproduced numbers match the charts?"* — **passes**: every figure in the addendum is independently correct at the pinned base, and the red-first substitute (a reproducible command with verbatim output) exists in the report. No P1. Four P2s stand, all cheap: two are accuracy defects in the durable artifact that M1/M4/M7 are instructed to cite (#1, #2), one is the evidence method that M4 will inherit (#3), and one disarms the gate that every remaining milestone depends on (#4). Per `SELF-REVIEW-HARNESS.md:73`, P2 must close before the milestone passes.

VERDICT: CHANGES-REQUIRED
