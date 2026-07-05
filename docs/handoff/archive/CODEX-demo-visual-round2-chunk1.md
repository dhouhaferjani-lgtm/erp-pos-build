# CODEX HANDOVER — Demo-fix Round 2, Chunk 1 (6 findings)

> Hand this whole file to Codex desktop. It is self-contained: environment, rules, the
> Opus self-review gate, and the 6 findings. Work through the findings ONE AT A TIME, TDD,
> with a mandatory Opus review after each. Do NOT attempt all 6 at once.

Context: these are bugs found in a 2026-06-30 visual/functional test of a Tunisia parapharmacy
ERP demo (tenant **PharmaBio Tunisie**, TN / TND). Themes 1 & 2 (GL integrity + vertical gating)
already shipped to `origin/dev`. This chunk is the first 6 of the remaining Theme 3/4 findings.

---

## 0. Worktree — set up / work in the correct one

All work happens in a dedicated git worktree off `origin/dev`, branch **`fix/demo-visual-round2`**,
at **`/Users/houssamr/Projects/syneriva/apps/erp.demo-fixes`**. Do not switch branches, do not push.

### If the worktree already exists (it should)
```bash
cd /Users/houssamr/Projects/syneriva/apps/erp.demo-fixes
git status                     # confirm branch = fix/demo-visual-round2, clean tree
git rev-parse --abbrev-ref HEAD
php apps/api/artisan --version # sanity: artisan runs on THIS worktree's code
```

### If it is missing, recreate it (from the main checkout)
```bash
cd /Users/houssamr/Projects/syneriva/apps/erp
git fetch origin dev
git worktree add -b fix/demo-visual-round2 ../erp.demo-fixes origin/dev

# --- Test env for the worktree (REQUIRED so tests + artisan run on worktree code) ---
MAIN=/Users/houssamr/Projects/syneriva/apps/erp
WT=/Users/houssamr/Projects/syneriva/apps/erp.demo-fixes
cp "$MAIN/apps/api/.env" "$WT/apps/api/.env"
[ -f "$MAIN/apps/web/.env" ] && cp "$MAIN/apps/web/.env" "$WT/apps/web/.env"
ln -sfn "$MAIN/node_modules" "$WT/node_modules"
ln -sfn "$MAIN/apps/web/node_modules" "$WT/apps/web/node_modules"
# vendor MUST be a real copy (a symlink makes artisan/tests run STALE main-repo code):
cp -R "$MAIN/apps/api/vendor" "$WT/apps/api/vendor"
cd "$WT/apps/api" && composer dump-autoload -o
php artisan --version          # must print the Laravel version = env is ready
```

> Gotcha: `node_modules` may be symlinked, but `apps/api/vendor` must be a **real copy** + a fresh
> `composer dump-autoload -o`, or `php artisan` and PHPUnit silently run the main repo's code.

---

## 1. Global rules (apply to EVERY finding)

1. **TDD always** — write a failing test first (red), minimum code to green, refactor. No
   production change before a red test.
2. **Run tests BY PATH ONLY. NEVER run the full suite** — the full PHPUnit suite crashes the
   machine.
   - Backend: `cd apps/api && php vendor/phpunit/phpunit/phpunit tests/Feature/<Path>Test.php`
     (sqlite `:memory:`).
   - Frontend: `cd apps/web && npx vitest run src/<path>`.
3. **Strict typing** — PHP: no `mixed` (use DTOs / typed arrays). TS: no `any` (use `unknown` +
   guards). **Constructor injection only — never the `app()` helper.** Enums for all status/type.
4. **Money / quantity precision — never let a float touch money or qty.**
   - PHP: use `CurrencyScale::bcformat` / bcmath; never `(float)` on a decimal column.
   - TS: never `parseFloat` / `Number()` on money; use `<MoneyInput>` / `<QuantityInput>` +
     `formatCurrency` / `formatQuantity`; send payloads as strings.
5. **Frontend conventions** — `apiGet`/`apiPost` already unwrap `response.data.data` (do NOT
   double-unwrap); for paginated `{data,meta}` endpoints use `api.get` and return `response.data`.
   All user-facing text via `t()` i18n keys. Use design tokens from `@/lib/designTokens` for any
   colors you touch. Domain types are generated from PHP DTOs into `packages/shared/types/` — do
   NOT hand-edit them; if you change a DTO run `CACHE_STORE=array php artisan typescript:transform`.
6. **Per-finding quality gates before "done":** new test(s) green + relevant existing tests by
   path green + `cd apps/api && ./vendor/bin/phpstan analyse <touched files>` (level 8, zero
   errors) + `./vendor/bin/pint <touched files>`; frontend `npx tsc --noEmit` + `pnpm lint` clean
   on touched files.
7. **Scope discipline** — fix ONLY the listed finding. Anything else you notice goes in the final
   report, NOT the diff.
8. **One commit per finding**, **Conventional Commits** style (e.g.
   `fix(import): populate import row data from parsed cells`). Ignore AGENTS.md's older
   `Phase x.y.z:` convention — match the repo's recent history. **Do NOT push** — the orchestrator
   reviews and merges.

---

## 2. Opus self-review gate — MANDATORY after each finding, before the next

The `claude` CLI is installed (`/Users/houssamr/.local/bin/claude`). After a finding is green,
run a real Opus adversarial review of your diff and treat it as a merge gate:

```bash
git diff > /tmp/finding-N.diff
claude -p --model claude-opus-4-8 "You are an adversarial code reviewer for a Laravel 12 + React
ERP. Review this diff for finding N: <one-line finding desc>. Verify against the actual codebase:
(1) the ROOT CAUSE is fixed, not just the symptom; (2) no float-on-money / quantity precision
violation, no strict-typing (mixed/any) violation, no app()-helper DI violation, no hardcoded
user-facing string (must use t()); (3) the new test genuinely FAILS without the production change;
(4) no scope creep beyond this finding. Cite file:line. End with a verdict line: 'SHIP' or
'DO-NOT-SHIP' followed by numbered blocking items. Diff:
$(cat /tmp/finding-N.diff)"
```

- **For findings 3 (Payments KPI) and 4 (payment status)** — GL/treasury-flavored — additionally
  instruct the reviewer to apply the adversarial GL/payments lens described in
  `.claude/agents/treasury-reviewer.md` (verify balance / direction / allocation semantics against
  code, cite file:line, never hallucinate).
- **Address every DO-NOT-SHIP blocking item and re-review until the verdict is SHIP** before
  moving to the next finding. Record each finding's final verdict in your report.

---

## 3. The 6 findings

### Finding 1 — 🔴 Bulk import never extracts data-row values (BLOCKER, backend)
`GET /api/v1/imports/{id}/preview` returns `rows:[{row_number:1,"data":[],is_valid:false,
errors:{sku:["required"],...}}]` — `data:[]` for every row though `total_rows` and `headers` are
correct. No CSV/XLSX import can succeed (reproduced on Products and Partners with valid CSVs).
**Seam:**
- `app/Modules/Import/Presentation/Controllers/ImportController.php` — `store()` (~L146:
  `applyColumnMapping($parseResult['rows'], $columnMapping)` → `addRowsBatch`) and `preview()`
  (~L258 reads `$row->data`).
- `app/Modules/Import/Services/ImportService.php` — `applyColumnMapping()` + `addRowsBatch()`
  (L86-105: raw `DB::table('import_rows')->insert()` with `'data' => json_encode($data)`, which
  bypasses the model's `array` cast — vs `addRow()` which passes an array through the cast).
- `SpreadsheetParser::parse()` (find it).

Empty data means either the parser yields empty per-row arrays or the mapping keys don't align with
the parsed headers. **Test:** drive a real small CSV (Products + Partners) through
parse → map → persist → preview and assert `data` is populated (correct cell values) and rows
validate. Put fixtures under `apps/api/tests/`.

### Finding 2 — 🔴 Dashboard `/documents?limit=5&sort=-created_at` → 500 (backend)
The dashboard "Recent Documents" call 500s (silently masked as "No recent documents").
**Seam:** `app/Modules/Document/Presentation/Controllers/DocumentController.php` `indexAll()` — the
`limit` branch (~L103) does `->with('vehicleContext')->orderBy('created_at','desc')`. Suspects:
`vehicleContext` eager-load against a relation/table absent on a parapharmacy (non-automotive)
tenant, or `DocumentData::fromModel()` throwing on a null relation; the `sort=-created_at` param is
currently ignored (crash is the priority — decide whether to honor it). Reproduce the 500 with a
parapharmacy-like tenant fixture, then fix to 200 returning the recent documents.

### Finding 3 — 🟠 Dashboard "Payments Received" KPI = 0 (backend, treasury lens)
KPI shows 0 despite a completed 15,600 TND inbound payment (which appears in "Recent Payments").
**Seam:** `app/Modules/Dashboard/Presentation/Controllers/DashboardController.php` (~L78-93):
`DB::table('payments')->where('direction','inbound')->where('created_at','>=',$currentMonthStart)
->sum('amount')`. Root-cause the filter mismatch — verify the real `direction` column value/enum
(may not be literal `'inbound'`) and whether the window should key off `payment_date` not
`created_at`. It also `(float)`-sums a money column — switch to bcmath aggregation per the
precision rule. Test asserts the KPI counts a seeded inbound payment.

### Finding 4 — 🟠 Payment-status inconsistent: list "Partial" vs detail "Paid" (backend, treasury lens)
Same invoice shows "Partial" / Balance Due 2,964 in the list but "Paid" on detail.
**Seam:** `app/Modules/Document/Application/DTOs/DocumentData.php` `fromModel()` — the detail path
uses `$document->getPaymentStatus()->value` (source of truth), but list rows are built via
`DocumentData::fromModel($doc, false)` and `balance_due` reads `$document->balance_due`, which is
**NULL on posted invoices** → `$balanceDue = $total` → amount_paid 0 → looks unpaid/partial.
Reconcile so list and detail compute the same payment_status (prefer `getPaymentStatus()`
consistently and/or resolve balance from allocations when the column is null). Test list + detail
DTO for a partially- and fully-paid posted invoice and assert they agree.

### Finding 5 — 🟠 Per-line Tax dropdown empty + product/company default-tax not resolved (backend + frontend)
In the sales/PO/quote line editor the Tax selector shows only "Select tax…/+ Add new tax…"; seeded
TVA 19/13/7/Exonéré don't list, so a line's tax can't be chosen.
- **Frontend:** `apps/web/src/features/documents/components/DocumentLineEditor.tsx` already renders
  `TaxConfigurationSelect` (import L11, used ~L400) → open
  `apps/web/src/components/atoms/TaxConfigurationSelect/TaxConfigurationSelect.tsx`; its options
  query is returning empty / wrong-shaped. Fix so seeded tax configurations populate.
- **Backend (#5B):** sales line builders read only `line.tax_rate ?? 0`; add a resolution order —
  explicit `tax_rate` → line `tax_configuration_id` → product `default_tax_configuration_id` →
  company default — persisting the resolved numeric `tax_rate` (keeps rate-grouping working); add
  the `lines.*.tax_configuration_id` request rule if missing.

Test both the resolution order (backend) and the populated dropdown (frontend).

### Finding 6 — 🟠 No discount field in the sales/PO line editor (frontend)
Line editor columns are Article / Description / Qty / Unit Price / Tax % / Total — no per-line
discount input, so discounts can't be entered (the backend discount math already exists and
applies). **Seam:** `apps/web/src/features/documents/components/DocumentLineEditor.tsx` — add a
discount column to `lineColumns` (~L478), thread discount into `calculateLineTotal(...)` (currently
`(qty, price, tax_rate)`; discount applies before tax) and into the line payload
(`discount_percent` / `discount_amount` as strings, no `parseFloat`). Confirm the backend's
expected field names. Extend
`apps/web/src/features/documents/components/__tests__/DocumentLineEditor*.test.tsx`; assert
discount reduces the line total and the tax base.

---

## 4. Final report (return to the orchestrator)

For each of the 6 findings:
- root cause (1 sentence),
- files changed,
- exact test commands run + their pass output,
- Opus-reviewer final verdict (must be **SHIP**),
- commit SHA (do NOT push).

Also: flag anything out-of-scope you noticed. If a finding turns out to be a non-bug or is blocked,
say so explicitly rather than forcing a change.

---

## 5. What happens next (orchestrator side — for your awareness)

After you report back, the orchestrator (Opus) independently verifies all 6 in the worktree
(re-runs tests by path, PHPStan/Pint/typecheck, reads each diff, and stands up the live
db-per-tenant stack to confirm import + dashboard + tax/discount work end-to-end), then batch-merges
the clean commits to `dev`. Chunk 2 will be findings #12 (bank-recon), #20 (goods-receipts date),
#18 (GR partial/batch capture), #19 (smart-payment allocation UI). Chunk 3: i18n (#21–#24).
