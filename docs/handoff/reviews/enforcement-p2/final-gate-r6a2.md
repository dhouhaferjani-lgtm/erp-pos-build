# FINAL-GATE REGISTER — package p2 M4 round 6 attempt 2
accepted_sha: d8a631133db1153c24b0e4c0211fc5c43969a7ec
base_sha: ab94195d8dbd79be44c4b9ce809942250ec37009   # from the parent DISPATCH RECEIPT (/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p2.receipt.yaml), ancestry-verified
dispatch_receipt: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p2.receipt.yaml
snapshot: detached-worktree @ d8a631133db1153c24b0e4c0211fc5c43969a7ec (sealed; re-asserted post-run)
handback_sha256: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p2-registers/HANDBACK-enforcement-p2-2026-08-21-r6a2.md=b90cdfba831a35832335a32c04b4b88be21e46889d804a9a9010e83558f36466   # copied+hashed by this bridge; not in the prompt
manifest_sha256: 7c298d5fbe0b35d9cd3970f57d3c49c03fa219a6eb44976dbe1d478c7da6a811
control_sha256: adversarial-review-final.sh=55ca88b0f9de917a48c8d6b2e4e0cbf8b8a38c29e67a75b696f4419566864c88
control_sha256: brief=63cc3e182d0ab1bd9a2c03f147cb4bd786aa708f8b9f8e234a567cab4261c6c8
control_sha256: harness=6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15
control_sha256: lens:frontend-conventions=278596dbc07e82ece964897d021d2d136dce59f136519578c9a256b39da45385
control_sha256: lens:tenancy-authz=9e2e444acf0b0de7b70f053eaf9948be2f001eb38de132a15103976d78b3a345
max_fix_rounds: 7
---
# ADVERSARIAL FINAL-GATE REGISTER — enforcement p2, M4, round 6

**Snapshot** `/var/folders/yb/rbv331vn481c6blwf61pyp840000gn/T/tmp.D8RhniXSN9/snap` · `HEAD == d8a631133db1153c24b0e4c0211fc5c43969a7ec` (re-asserted post-run; `git status --porcelain` = 0 entries)
**Range** `ab94195d8dbd79be44c4b9ce809942250ec37009..d8a631133db1153c24b0e4c0211fc5c43969a7ec` · 66 commits · 84 paths
**Handback** `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p2-registers/HANDBACK-enforcement-p2-2026-08-21-r6a2.md`
`handback_sha256: b90cdfba831a35832335a32c04b4b88be21e46889d804a9a9010e83558f36466`

---

## 1. Census / classification tables — content-derived evidence of inspection

Every figure below was **re-derived from the sealed snapshot**, not read from the handback. Independent derivation used `auditRoot()`'s own exports, the raw baseline JSON, the manifest JSON, and the on-disk test tree.

### §3a — i18n authored-locale provenance (3 rows)
Row count **3**. First row key **`en`** · last row key **`ar`**.
Re-derived via `auditRoot().wiring.assignments`:

| locale | own | en-aliased | english-spread |
|---|---|---|---|
| en | 55 | 0 | 0 |
| fr | 55 | 0 | 0 |
| ar | **21** | **22** | **12** |

Underlying 55-row assignment table per locale: first key `common`, last key `adminCountryDefaults` (`i18n.ts:454`).
The 22 `en-aliased` set: **22** rows, first `adminCountryDefaults`, last `withholding`.
The 12 `english-spread` set, in full: `compliance, expenses, finance, import, inventory, locations, notifications, pos, products, sales, settings, treasury`.
Restricting the baseline to those **34** namespaces yields **2 640** = `aliased 22 + missing 2 613 + plural 5` — the handback's §3a figure, reproduced exactly. Key-level `4 604 = 2 618 + 1 986` therefore holds.

### §3b — baseline composition (5 metric rows)
Row count **5**. First row key **`namespaces`** · last row key **`structural failures`**.
Baseline artifact `apps/web/tools/i18n-completeness-baseline.json`: **2 924** entries.
**First entry key** `ar|adminCountryDefaults|aliased|*` · **last entry key** `fr|workshop-bundles|plural|interval.months_many`.
Type breakdown re-derived: `ar aliased 22 · ar missing 2 851 · ar plural 9 · fr plural 34 · en plural 8` = 2 924 ✓.
Locale files on disk: `en 56 · fr 56 · ar 34` ✓. `git hash-object` of the working baseline = `26a9ae1688d80e0f450215326b19ccd1701c9a8f`, equal to the YAML mirror pin (`:73`) and the seed `6a0c1cd72…:` blob ✓.
Live tool output reproduced: `en=9228, fr=9244, ar=4697 authored (1986 behind aliases)`, `structural failures 0`, `fresh: []`.

### §3c — Feature-lane census (3 rows)
Row count **3**. First row key **`all of tests/Feature (74 groups)`** · last row key **`minus the 111 allowlisted names inside them`**.
Manifest artifact `apps/api/tests/feature-lane-manifest.json`: **74** group rows. **First row key `(root files)`** · **last row key `Workshop`**.
Re-derived: 73 directories + 1 root group = 74 ✓ · **1 348** `*Test.php` files, **1 335** distinct ✓ · dispositions `lane 3 (Accounting, Security, Treasury) / deferred 71 / excluded 0` · Σ classes in laneless groups = **1 131**, exactly equal to the enforced `debt_ceiling: 1131` ✓.
Allowlist tables: allowlist 1 — **112** entries, first `VoucherLedgerTest`, last `RefundReportingFieldsTest`; allowlist 2 — **16** entries, first `TenantStanclFlipTest`, last `ReceiptChainRebuildTest`; **124** distinct ✓.

### §3d — F-2 measurement (1 row) and §5e scope table (13 buckets, 84 paths)
§5e re-derived: `git diff --name-only base..A` = **84** paths, first bucket `.github/workflows/ci.yml`, last bucket `docs/handoff/…` (25); `generated.d.ts` **absent** ✓; exactly one production source file.

---

## 2. Mechanical preflights (all run by me on the sealed tree)

- **Control-file preflight (R7-C-1):** no `adversarial-review*.sh`, brief, `SELF-REVIEW-HARNESS.md`, `enforcement-control-manifest.yaml`, `.claude/agents/*`, or `*control-manifest*` surrogate in `base..A` — **clean**.
- **Workflow authority (R6-H-5):** no `permissions:` / `contents: write` grant added — **clean**.
- **Forbidden trees:** no `apps/pos|mobile|erp-ml|platform` — **clean**.
- **Candidate YAML field-check (R9-C-1/R10-H-1):** `base_sha` == dispatch base ✓ · exactly one final milestone `M4` at `status: review` ✓ · `fix_rounds 5 ≤ max_fix_rounds 7` ✓ · `control_manifest {path, sha256}` non-null ✓ · `quiet_window_ack`, `ratchet_trust_model_ack`, `i18n_baseline_pin_tag: ci-pin/enforcement-p2-r1` non-null ✓ · `merge_announcement_ack`/`pre_promotion_ci_dispatch`/`closing_receipt` correctly **null**, top-level `status: in_progress` — no self-certification, nothing pre-committed that belongs in C ✓.
- **Event graph:** `on` = push→main, PR→main, PR→dev, workflow_dispatch ✓. `frontend-lint`, `backend-architecture`, `security-regression` each carry **no `if:`** ✓. 18 jobs; `all-checks-pass` `needs` = 15 members including `security-regression` and `frontend-lint` (H-9) ✓.

## 3. Executed guard proofs (not accepted on report)

| case | result |
|---|---|
| i18n gate, authority set | EXIT 0, output byte-matches §5a |
| `env -u I18N_BASELINE_PROTECTED_BLOB` | **EXIT 1** — fail-closed |
| mirror drift (variable ≠ YAML pin) | **EXIT 1** — `MIRROR DRIFT` |
| matched-growth tamper (new key + matching baseline entry) | **`RATCHET GROWTH: 1 baseline entry added … REMOVAL-ONLY`** |

The R2-C-1/R3-C-1 anti-growth anchor is genuinely operative, and there is no `--no-ratchet` escape hatch.

**Anchoring (2(b)) — I re-tested the coverage-neutrality claim rather than trusting it.** Both surviving filters use `/\\(A|B)::/`. All 1 348 `tests/Feature` classes are namespaced (0 without a `namespace` declaration), so the `\` anchor is safe; PHPUnit 11 filters match `FQCN::method`. Simulating old-unanchored vs new-anchored selection: **dropped = ∅ in both allowlists**. `AnalyticsTest`/`ExpenseAnalyticsTest` shadowing is killed without coverage loss. `VoucherLedgerTest` resolves under `tests/Unit/Voucher/Domain/` — the checker deliberately resolves filters across all suites while scoping group dispositions to `tests/Feature`, which is correct for `php artisan test -c phpunit-pgsql.xml`.

**Lens application.** *frontend-conventions*: the sole production change (`statements/api.ts`) is type-only; `OffsetPaginationMeta` has exactly six fields, so `Omit<…,'from'|'to'>` ≡ `Pick<…,'current_page'|'last_page'|'per_page'|'total'>` — the "semantically identical" claim in deviation 3 is **true as verified**, not asserted. New RuleTesters assert `messageId` **and** `data` per distinct pattern; the C6 `Record<X, StatusTone>` case is covered in both its named-token and unnamed-token forms; plural checking derives categories from `Intl.PluralRules(...).resolvedOptions()` with a two-signal heuristic that avoids the `step_one` false positive. *tenancy-authz*: moving `tests/Feature/Security` (17 classes) out of the `if:`-gated `backend-test` into an ungated job strictly increases rule-12 module-gating coverage; `phpunit.xml` pins `sqlite/:memory:`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=array`, `APP_KEY`, so the leaner job's environment is sufficient — no Security test references redis or a PG-only marker. No route middleware, permission catalog, or tenancy code was touched.

## 4. Findings

No Critical and no High finding survived verification. Three P3 observations, none silent, none merge-blocking:

1. **Stale narrative census figures in shipped comments** — `ci.yml` ("990 of 1316 distinct") and `feature-lane-manifest-check.php:19`/`:332` ("1114 -> 1115") are base-time figures against a live 1 008/1 335 and debt 1 131. The **enforced** values (`debt_ceiling: 1131`, per-group ceilings) are current and machine-checked, and the handback §3c labels the 990 stamp a base-time figure explicitly. Documentation drift, disclosed.
2. **`backend-architecture` does not list `pdo_sqlite`** in its `setup-php` extensions while now running three phpunit steps there. It works via setup-php's default extension set, and none of the three tests uses `RefreshDatabase` — but this is first-execution surface that the S-14 dispatch must confirm; §5d already names both steps as verification targets.
3. **The checker scans only `.github/workflows/ci.yml`.** A phpunit `--filter` introduced in `react-doctor.yml`, `smoke-test.yml`, or `sonarcloud.yml` would escape the anchoring/uniqueness assertions. I confirmed none exists today.

## 5. Assessment

Every quantitative claim in the handback that I could re-derive, I re-derived — and all of them reproduced exactly, including the two figures the series previously caught drifting. The one deviation that would have been disqualifying under brief §7 item 4 (`generated.d.ts`) is disclosed, reverted, and mechanically absent from `base..A`. Post-promotion fields are correctly empty, so the R3-C-2 receipt cycle is respected. The two ratchets, the aggregate membership, the discrete-step wiring, and the fail-closed authority all behave as specified under direct execution.

Two evidence layers I could not reproduce here, stated plainly rather than hedged: the sealed worktree has no `apps/api/vendor` and no `node_modules`, so `feature-lane-manifest-check.php`, `FeatureLaneManifestCheckerTest`, `pnpm test:tools`, and `pnpm test:eslint-rules` were **not** re-executed by me. I verified their logic by reading and, independently, reproduced the invariants they assert (74/74 groups assigned, debt == ceiling, both filters anchored, every entry resolving to exactly one class tree-wide). Remote job-graph behaviour remains the owner's pre-promotion `workflow_dispatch` gate on exactly this SHA, per S-14 — that gate is unchanged and still owed.

VERDICT: ACCEPT
