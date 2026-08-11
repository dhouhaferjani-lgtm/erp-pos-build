## Adversarial merge-gate register — M0 (round 2), lens: **treasury**

**Range reviewed:** `7d85232cc54abd6a6b2135f476205ab434e71a66..b65cdaee2` — 2 commits, 6 files, **zero** `.php`/`.ts`/`.tsx` files (`git diff --name-only 7d85232cc..HEAD | grep -E '\.(php|ts|tsx)$'` → empty).
**Acceptance criteria:** brief `docs/handoff/CODEX-DISPATCH-country-defaults-phase-a-2026-08-10.md` §M0 (`:259-285`), §0 deliverable (`:68-71`), §9 (`:822-831`), harness `docs/handoff/SELF-REVIEW-HARNESS.md`.

---

### A. Independently reproduced (treasury lens — COA / purpose content)

I re-derived every number from the seeders directly (grep + code read), not from the report:

| Addendum claim | My result | |
|---|---|---|
| TN 139 / FR 144 / Generic 61 accounts (`ADDENDUM:22-24`) | `grep -cE "^\s*\['code' => "` → 139 / 144 / 61 | ✅ |
| Missing REQUIRED = `[]`,`[]`,`[]` | all 27 enumerated purposes present in all three definition arrays | ✅ |
| The enumerated 27-set (`ADDENDUM:17-21`) | byte-for-byte the spec's REQUIRED list, `…design.md:127` (cases 1–27, same order) | ✅ |
| `SalesStampDutyPayable` TN-only | present only `TunisiaChartOfAccountsSeeder.php:208`; absent FR/Generic | ✅ |
| D-4 TN/FR/wildcard instrument codes | match `InstrumentAccountResolver.php:45-55` (`$isTunisia` branch) exactly; all nine non-TN codes really exist in `GenericChartOfAccountsSeeder.php` | ✅ |
| D-4 demo-consumer codes (TN/FR 7, generic 4) | match `ExpenseCategorySeeder.php:42-49,58-63`, selection `:110-115`; all 7 exist in **both** TN and FR charts | ✅ |
| D-5 failure boundary | matches `ExpenseCategorySeeder.php:77-89` (`$account ??= $fallback` then silent `continue`) | ✅ |
| D-6: 41 enum cases, `requiredPurposes()` = 13 | 41 cases; 13 returned, `SystemAccountPurpose.php:161-182` | ✅ |
| D-7 drift facts | `UpdateCompanySettingsRequest.php:17,41`, `CompanySettingsController.php:113,154`, `create_tax_configurations_table.php:15` (no `company_id`) — all three verified | ✅ |
| D-2 replay statement + S-5 limitation "verbatim" | whitespace-normalised string compare vs brief `:60` / §0.1 S-5 → identical, both S-5 occurrences | ✅ |
| Base pin (V-1) | `git rev-parse origin/dev` = `7d85232cc…` | ✅ |

**Round-1 findings closed (verified, do not re-raise):** #1 D-7 drift now recorded (`ADDENDUM:104-117`); #2 manifest correctly labelled 41 entries / 27 REQUIRED (`ADDENDUM:15-16`); #3 hermetic command with `ReflectionClass::getFileName()` worktree-prefix guard now in the report (`report:184-260`) — the guard is sound, the vendor symlink can no longer smuggle main-repo sources; #4 `--permission-mode plan` (`scripts/adversarial-review.sh:91`, no `bypassPermissions` remains — I confirmed I hold no write tools in this invocation); #5 bash-3.2 `${req,,}` replaced by a `case` map, probed live on bash 3.2.57 → `missing --out` / **exit 3**, and `--nope` → exit 3; #6 the 27 purposes now enumerated durably; #7 `TunisiaChartOfAccountsSeeder.php:208` cited, ticket pointer restored at both `:117` and `:139`; #8 wave3 artifacts gone (`git ls-files | grep wave3-3c-3d` → empty) and the addendum is now the branch's first commit.

**Treasury sub-lenses N/A, mechanically:** payments/GL posting, partial-write atomicity, Rule 19 float-on-money, migrations, named queues/Horizon, tenant scoping, `app()`/constructor injection, en+fr strings — the range contains no executable file.

---

### B. Findings

**1. [P2] `docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design-ADDENDUM-2026-08-10.md:62` — CONFIRMED — D-3 states a not-yet-true repository state as accomplished fact, and is the only D-row with zero citations.**
"The existing Taxation registry is no longer an independent authority for the timbre predicate." At **HEAD of this very branch**, `apps/api/app/Modules/Taxation/Application/Registries/CountryTaxConfigurationRegistry.php:24-33` still holds `'supports_stamp_duty' => true/false` inside `MAP`, and `:48-50` still answers `supportsStampDuty()` from it. Nothing in the branch touches Taxation; the change is M1 task 1, unstarted. The section heading is "Post-spec repository **deltas**" — its job is reality, and every sibling row (D-1, D-2, D-4, D-5, D-6, D-7, D-8) carries file:line evidence while D-3 carries none. *Failure scenario:* the brief (`:70`) declares the addendum the record "everything downstream cites"; an M7 whole-branch reviewer or the merge-time controller reads D-3 as closed and does not verify that M1 actually deleted the duplicate predicate — the exact duplicate-authority condition S-1 exists to forbid. This is the same defect class round 1 rated **P2** for D-7 (`M0-round1.md` finding #1); the fix round closed D-7 and left D-3 in identical shape.

**2. [P2] `docs/sessions/codex-country-defaults-phase-a-report.md:9,11,310-314` + `docs/handoff/reviews/country-defaults-phase-a/M0-round1.md:3` — CONFIRMED — the §9 evidence trail cites four commits that are unreachable from the branch, and its closing adjudication contradicts HEAD.**
The branch was squashed after the fix round (`git rev-list --count 7d85232cc..HEAD` = **2**). `git merge-base --is-ancestor <sha> codex/country-defaults-phase-a` returns false for **all** of `3b1a37fe8` (report `:9`, "M0 commit"), `7a4d65f25` (report `:11`, "M0 fix-round-1 commit"), `d29b14f33` and `ef57a03ae` (register `:3`, "Range reviewed: `7d85232cc..ef57a03ae` (3 commits; 7 files)"). They survive only on the side branch `refs/heads/codex/country-defaults-phase-a-pre-rewrite` (= `7a4d65f25`). Worse, report `:310-314` still adjudicates a state that no longer exists — "`d29b14f33` still sits between the pinned base and the M0 addendum commit, so the addendum cannot literally be the branch's first commit without rewriting history. History rewrite was explicitly forbidden." — while the addendum **is** now the first commit and the rewrite **did** happen. (I grepped the brief and the harness for any rewrite/amend prohibition: none exists; only CLAUDE.md rule 21, which governs pushes to shared `dev`, not an unpushed feature branch. So the report's "explicitly forbidden" is also unsupported.) *Failure scenario:* §6 (`:775`) requires M7 to re-run "every reviewer who raised a finding at any milestone". That reviewer follows YAML → register → commit, lands on a register whose diff range does not resolve and whose line anchors (e.g. "`ADDENDUM:82-84` D-7 duplicates S-5") point at text that has moved, and cannot mechanically confirm any round-1 finding was closed rather than dropped. The fix is two corrected SHAs plus one rewritten paragraph.

**3. [P3] `docs/superpowers/specs/…ADDENDUM-2026-08-10.md:22-24` — CONFIRMED — the M0 substitute-for-red-first evidence exists only in git-ignored files.**
`git check-ignore -v` confirms `.gitignore:58` ignores `docs/sessions/` (the report holding the hermetic command and its output) and `.gitignore:61` ignores `.superpowers/` (the `m0-reconciliation-command.sh` helper). This is brief-compliant — §9 `:824` names the git-ignored report path — but it means the committed branch diff asserts 139/144/61 and three `[]` results with **no committed way to re-derive them**. *Failure scenario:* M7's evidence manifest and the merge-time controller work from the branch, not from a session-local file that a fresh worktree will not have. Recommend pasting the hermetic command + its verbatim output into the addendum, or committing the helper under `scripts/`.

**4. [P3] `docs/handoff/progress/country-defaults-phase-a.progress.yaml:28-32` — CONFIRMED — the resume state pairs a post-fix commit with a pre-fix verdict.**
`commit: c744c19cc…` already contains the fix-round-1 corrections (D-7 drift, the 27-purpose enumeration, the `:208` citation), yet the same block carries `verdict: …/M0-round1.md` and `last_verdict: CHANGES-REQUIRED`. *Failure scenario:* the harness declares the YAML the resume point; a fresh session reads "commit c744c19cc → CHANGES-REQUIRED" and re-does a fix round already folded into that commit, or concludes the milestone regressed.

---

### C. Bypasses / refutation attempts that FAILED (do not re-assert these)

1. **"TN can't absorb a rounding residual — it has no `SalesRoundingDifference*` purpose"** — refuted. `AccountingService.php:218-221` selects the stamp account *first, by mere existence*, and only falls through to the rounding purpose when stamp is absent; TN has stamp (`:208`). A negative residual is refused for every chart at `:215-216` regardless. This also **independently confirms** the brief's invariant that a stamp row in an FR template would silently misdirect FR residuals — the addendum's scope rule is substantively right, not just formally.
2. **"The generic chart can't carry the wildcard instrument set (4-digit house style)"** — refuted: all nine non-TN codes (`5112`,`4035`,`413`,`403`,`5113`,`5114`,`627`,`44566`,`416`) are present in `GenericChartOfAccountsSeeder.php`.
3. **"The account counts are off by one"** — `grep -c "'code' =>"` yields 140/145/62; the extra hit is each seeder's insert loop. Anchored `^\s*\['code' => ` gives 139/144/61 — the addendum is right.
4. **"The 27-set was invented by the implementer"** — refuted: it is the spec's REQUIRED enumeration at `…design.md:127`, same members, same order, and every member resolves to a real enum case.
5. **"D-4's protected codes are protected but missing from the charts"** — refuted: all 7 demo-consumer codes exist in both TN and FR; all 9 TN and all 9 FR instrument codes exist in their charts.
6. **"origin/dev advanced past the pin (V-1)"** — refuted: `origin/dev` = `7d85232cc`. (The SessionStart "38 commits behind dev" notice refers to *local* `dev` = `8ce919be2`, the unpushed batch; the brief pins `origin/dev`.)
7. **"`--permission-mode plan` is prompt-theatre and the reviewer can still write"** — refuted empirically: launched through the fixed bridge, this invocation holds no write capability.
8. **"The bridge isn't executable, so the harness's documented `scripts/adversarial-review.sh …` invocation fails"** — refuted: mode `100755` in the index.
9. **Rule 19, migration safety, Horizon queue coverage, tenant scoping, `app()`, en+fr** — N/A by mechanical check (no executable file in range), not by omission.

---

### D. Assessment

M0's substantive treasury obligation — *"do the reproduced numbers match the charts?"* — **passes cleanly for the second time**, now with the evidence method fixed: every figure, every purpose set, every protected code and the stamp-scope rule are independently correct at the pinned base, and 6 of the 8 round-1 findings are fully closed with the two P3 leftovers absorbed. No P1; nothing in the accounting content is wrong.

Two P2s remain, both documentary and both cheap, and in a *documentation-only* milestone documentary accuracy is the entire deliverable: the delta record asserts a Taxation change that has not happened (#1, the unfixed half of round 1's own P2), and the §9 evidence trail points at four commits that no longer exist while its closing paragraph describes a branch shape HEAD contradicts (#2). Per `SELF-REVIEW-HARNESS.md:73`, P2 closes before the milestone passes. Fix round 2 should be a ~10-line edit across three files; fix-round budget is 5.

VERDICT: CHANGES-REQUIRED
