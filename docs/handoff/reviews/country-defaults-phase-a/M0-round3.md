## Adversarial merge-gate register — M0 (round 3), lens: **treasury**

**Range reviewed:** `7d85232cc54abd6a6b2135f476205ab434e71a66..HEAD` (`2163203d8`) — 5 commits, 7 files, **zero** executable files (`git diff --name-only 7d85232cc..HEAD | grep -E '\.(php|ts|tsx|vue|py)$'` → 0 lines).
**Acceptance criteria:** brief `docs/handoff/CODEX-DISPATCH-country-defaults-phase-a-2026-08-10.md` §M0 (`:259-285`), §0 deliverable (`:68-71`), §0.1 (`:73-118`), §9 (`:822-831`); harness `docs/handoff/SELF-REVIEW-HARNESS.md`.

---

### A. Independently reproduced (treasury lens — COA / purpose / instrument-account content)

I re-derived everything from source, not from the report or the prior registers.

| Claim | My independent result | |
|---|---|---|
| TN 139 / FR 144 / Generic 61 definition rows (`ADDENDUM:31-33`) | `grep -cE "^\s*\['code' => "` → 139 / 144 / 61; reflection over `getAccountsDefinition()` agrees | ✅ |
| Missing REQUIRED = `[]`,`[]`,`[]` | re-ran the committed command end-to-end; output **byte-identical** to `ADDENDUM:130-142` | ✅ |
| Hermeticity guard actually binds | instrumented `ReflectionClass::getFileName()` → all five sources resolve under `…/.worktrees/country-defaults-phase-a/`, despite `apps/api/vendor` being a symlink into the main repo | ✅ |
| The enumerated 27-set (`ADDENDUM:17-21`) is the **spec's** classification | member-for-member identical to spec §4.2.1 `…design.md:127` (cases 1–27, same order); both in-addendum copies (prose + `$required`) are 27 unique and mutually identical | ✅ |
| `SalesStampDutyPayable` TN-only | present only `TunisiaChartOfAccountsSeeder.php:208`; absent FR/Generic | ✅ |
| The scope rule is *substantively* grounded, not just formally | `AccountingService.php:216-221` selects the stamp account **by mere existence** before the rounding absorber → a stamp row in an FR/Generic template would silently absorb rounding residuals into a stamp liability; FR/Generic carry `SalesRoundingDifference{Income,Expense}` instead, TN does not | ✅ |
| D-4 TN instrument codes (`5312,4035,413,403,5313,5314,6275,43666,416`) | exactly `InstrumentAccountResolver.php:46-54` `$isTunisia` branch (`:45`) | ✅ |
| D-4 FR **and wildcard** = the same non-TN set | the resolver branches only on `strtoupper($countryCode)==='TN'`, so wildcard ≡ FR by construction; all 9 codes exist in **both** `FranceChartOfAccountsSeeder` and `GenericChartOfAccountsSeeder` | ✅ |
| D-4 demo-consumer codes (TN/FR 7, generic 4) | `ExpenseCategorySeeder.php:42-49` (7 non-null) / `:58-63` (4), selection `:110-114`; all 7 exist in both TN and FR charts, all 4 in the generic chart | ✅ |
| D-5 failure boundary | matches `ExpenseCategorySeeder.php:81-89` (`$account ??= $fallback`, then silent `continue`) — an *absent mapped code* is today indistinguishable from a deliberate `null` | ✅ |
| D-6: 41 enum cases, `requiredPurposes()` ≠ manifest | 41 cases; helper returns 13 | ✅ |
| D-7 drift facts | `UpdateCompanySettingsRequest.php:41`, `CompanySettingsController.php:113,150`, `create_tax_configurations_table.php:15` (`char('country_code',2)`, **no `company_id`**) — all verified; ticket `2026-08-10-country-code-mutable-authz-authority.md` exists and says what is cited | ✅ |
| D-2 replay statement + S-5 limitation "verbatim" | whitespace/emphasis-normalised compare vs brief `:60` and §0.1 S-5 → identical (S-5 drops only the brief's meta-sentence "This limitation goes verbatim into…", and relocates the ticket pointer to an explicit *Owner-visible follow-up* line) | ✅ |
| Brief's own cited definition-array spans (TN `:125-350`, FR `:124-429`, GEN `:106-237`) | real method spans are `125→347`, `124→426`, `106→233` — the brief's ranges enclose them | ✅ |
| Base pin (V-1) | `git rev-parse origin/dev` = `7d85232cc…`; seeders/enum/resolver/registry byte-unchanged base→HEAD | ✅ |
| Brief + harness not silently amended by the implementer | `diff` vs the orchestrator's copies in the main repo: **byte-identical** for the brief and `SELF-REVIEW-HARNESS.md`; only `adversarial-review.sh` (the round-1 fix) and the progress YAML differ | ✅ |
| §9 shape | addendum is in the **first** commit `c744c19cc`; branch not pushed (no `origin/codex/country-defaults-phase-a`, no remote-tracking ref); accepted spec body untouched | ✅ |

**Round-2 findings — all closed (verified independently, do not re-raise):**
- **#1 (D-3)** — `ADDENDUM:169-179` now states pinned-base reality with citations, and the anchors are exact: `CountryTaxConfigurationRegistry.php:24-33` still holds `supports_stamp_duty` in `MAP`, `:48-50` still answers from it. It explicitly says "M0 does not claim that rewire is already complete." Closed.
- **#2 (evidence trail)** — every SHA the report now cites (`c744c19cc`, `b65cdaee2`, `08f8c1583`, `118190f1f`) is an ancestor of HEAD; `codex/country-defaults-phase-a-pre-rewrite` resolves to `7a4d65f25` with `3b1a37fe8` reachable there; the contradicting "rewrite was explicitly forbidden" paragraph is replaced by `report:321-327`. `M0-round1.md:3-11` carries the provenance map without altering the original reviewer text. Closed.
- **#3 (evidence only in gitignored files)** — the hermetic command *and* its verbatim output are now committed inside the addendum (`:36-142`). Closed.
- **#4 (YAML pairing)** — `commit: 118190f1f`, `fix_rounds: 2`, `verdict: …/M0-round2.md`. This is now the only state the harness schema can represent between a fix round and its re-review. Closed.

**Treasury sub-lenses that are N/A, by mechanical check rather than omission:** payments/GL posting, partial-write atomicity, Rule 19 float-on-money, migrations/unattended safety, named queues + Horizon coverage, tenant scoping, `app()`/constructor injection, en+fr strings — the range contains no executable file.

---

### B. Findings

**1. [P3] `docs/superpowers/specs/…-ADDENDUM-2026-08-10.md:99` — CONFIRMED — the committed evidence command proves *worktree* provenance but not *commit* provenance; `base_sha` is printed from `origin/dev`, not from the tree the numbers were read out of.**
The round-1 symlink hole is genuinely closed (the reflected paths are asserted under `$root`), but `echo "base_sha=" . … rev-parse origin/dev` labels the run with a ref that is independent of the files just read. Nothing in the command asserts that the worktree's seeders equal the pinned commit's seeders. *Failure scenario:* run in a worktree with an uncommitted or branch-local seeder edit, the block still prints `base_sha=7d85232cc…` beside numbers derived from the edited chart — a certification-grade output that reads as pinned but is not. This is harmless **for M0** (I verified `git diff 7d85232cc..HEAD` on the three seeders + the enum is empty), but the method is inherited by M4's data-driven fixture/delta suite. *Concrete fix (two lines):* also print `git rev-parse HEAD`, and hard-fail on `git diff --quiet <base_sha> HEAD -- <the three seeder paths> <enum path>`.

**2. [P3] `scripts/adversarial-review.sh:17,89-92` — PLAUSIBLE — "plan-mode permissions enforce no writes" is stronger than what plan mode demonstrably enforces; `--permission-mode plan` gates `Edit`/`Write`, not Bash-mediated mutation.**
Round 2 correctly refuted the strong form (no `Write`/`Edit` tool in a bridge-launched reviewer). The residual is narrower: under plan mode in this interactive invocation, a Bash call created a file on disk (`touch /tmp/…` succeeded). I could **not** verify whether headless `claude -p --permission-mode plan` classifies and blocks mutating Bash, so I am not asserting the bridge is exploitable — only that the comment states an enforcement guarantee the script does not establish. *Failure scenario (unproven):* a future reviewer "helpfully" applies a finding via `sed -i`/`git apply`, and round *n+1* reviews the reviewer's own edits while the YAML's recorded `commit` no longer describes the reviewed tree. *Concrete fix:* pass an explicit read-only `--allowedTools` set (Read/Grep/Glob + a read-only Bash allowlist) alongside plan mode, or soften the comment to match what is enforced.

No P1. No P2.

---

### C. Bypasses / refutation attempts that FAILED (do not re-assert these)

1. **"The stamp purpose is missing from the TN chart — `grep -c sales_stamp_duty_payable` returns 0 in all three seeders."** Refuted, and it is a *false-negative trap*: the seeders reference `SystemAccountPurpose::SalesStampDutyPayable->value`, never the string literal. `TunisiaChartOfAccountsSeeder.php:208` (code `4375`, `État - Droit de timbre à reverser`, liability) carries it. Any future drift scan that greps snake_case purpose strings over these seeders will silently report every purpose as absent.
2. **"The pin is stale — the unpushed 38-commit local `dev` batch will change the reconciled numbers when promoted."** Refuted with evidence prior rounds did not gather: `git diff --stat 7d85232cc..dev` (local `dev` = `8ce919be2`) over the three seeders, `SystemAccountPurpose`, `InstrumentAccountResolver`, `ExpenseCategorySeeder` and `CountryTaxConfigurationRegistry` is **empty**. The M0 numbers survive promotion of that batch.
3. **"The implementer edited the brief to fit the work."** Refuted: the branch's brief and `SELF-REVIEW-HARNESS.md` are byte-identical to the orchestrator's main-repo copies; the accepted spec body is untouched in the range.
4. **"The 27-purpose list was invented, or silently narrowed by a duplicate."** Refuted: both in-addendum copies are 27 *unique* entries, mutually identical, and member-and-order identical to spec §4.2.1; the command also hard-fails on any name that is not a real enum case.
5. **"Protected codes are protected but absent from the charts they protect."** Refuted: all 9 TN and all 9 non-TN instrument codes, all 7 French-plan demo codes (TN *and* FR), and all 4 generic demo codes exist in their respective seeders.
6. **"TN cannot absorb a positive rounding residual (no `SalesRoundingDifference*` purpose)."** Refuted at `AccountingService.php:216-221` — stamp is selected first by existence; TN has it. Negative residuals are refused for every chart at `:215-216`.
7. **"The addendum omits the definition-array line ranges the brief cites, so the counts are unauditable."** Refuted as material: the committed reflection command supersedes line-range citation, and the brief's ranges do enclose the real method spans.
8. **"The verdict parser can be fooled by registers quoting each other's `VERDICT:` lines."** Refuted: `adversarial-review.sh:102` anchors `^VERDICT:` and takes `tail -1`; quoted verdicts in these registers are indented and would not match anyway.
9. **Rule 19, migration safety, Horizon coverage, tenant scoping, `app()`, en+fr** — N/A by mechanical check (zero executable files in range), not by omission.

---

### D. Assessment

M0's stated treasury obligation — *"do the reproduced numbers match the charts?"* — passes for the third consecutive round, and this time the *method* is auditable from the committed branch alone: I re-ran the addendum's own command and got byte-identical output, and separately re-derived every figure by grep and code read. Both round-2 P2s are properly closed rather than papered over — D-3 now reads as reality with exact anchors and an explicit disclaimer that the M1 rewire has not happened, and the evidence trail's SHAs all resolve on the current ancestry with the pre-rewrite objects preserved on a named branch.

Two P3s remain, neither in the accounting content: one method hardening the addendum's command should inherit before M4 leans on it, one over-strong comment about the gate bridge's read-only guarantee. Per `SELF-REVIEW-HARNESS.md`, P3 may ship with a ticket recorded — both should be carried into the M7 checklist (finding 1 explicitly, since M4 reuses the reflection method).

VERDICT: ACCEPT
