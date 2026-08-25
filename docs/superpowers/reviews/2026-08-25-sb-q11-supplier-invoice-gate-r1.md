# Gate r1 — Session B lane Q-11 (SupplierInvoice `match()` draft-guard + expense-number advisory lock)

- **Lens:** treasury-reviewer (adversarial, code-grounded)
- **Commit under review:** `1f76745e8` on `fix/sb-q11-supplier-invoice-match-guard`, base dev tip `2288299bf`
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/sb-q11-supplier-invoice-match`
- **Diff scope (verified `git diff --name-only 2288299bf 1f76745e8`):** 4 files
  - `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php`
  - `apps/api/app/Modules/Procurement/Presentation/Controllers/SupplierInvoiceController.php`
  - `apps/api/tests/Feature/Expense/ExpensePostTest.php`
  - `apps/api/tests/Feature/Procurement/SupplierInvoiceApiTest.php`
- **No migration. No new test class** (manifest ceilings untouched).

## VERDICT

**spec ✅ + quality APPROVED-WITH-CONDITIONS (do NOT merge as the whole fix for the multi-company story).**

Both defects named in the brief are really fixed, verified by execution on both drivers. The
DELIBERATE DEVIATION (tenant-keyed lock instead of the brief's company-keyed lock) is **ACCEPTED —
the brief was wrong**. The lane is, however, **not sufficient on its own** for its stated business
outcome: I reproduced on PostgreSQL that the second company of a tenant STILL cannot post an expense
after this commit, because `journal_entries.entry_number` carries the identical scope defect one
layer down (finding **C-1**, out of lane, HIGH). Merging is fine; **claiming "multi-company expense
post is fixed" is not.**

### Conditions (all cheap; none require reopening the two prod files)

1. **C-1 must be dispatched as its own lane before any multi-company tenant is provisioned**, and the
   lane's commit message / progress YAML must stop implying the multi-company post now works
   end-to-end. (Reproduction, blast radius, fix shape and census below.)
2. **Owner ack row** for the numbering-contract change: expense numbers now interleave across the
   companies of a tenant (`EXP-2026-000001` company A, `…-000002` company B, `…-000003` company A) —
   each company's own expense register will show gaps. Accepted here (expenses are internal
   non-fiscal vouchers), but it is user-visible and must be an owner line, not a code comment.
3. **FE follow-up (F-4):** `SupplierInvoiceDetailPage.tsx:353-361` renders the "Re-match" button
   unconditionally and `handleRematch` (`:223-229`) has **no `onError`** — on a posted invoice the
   button now fails silently with the new 422. Gate the button on `!isPosted` (the Post button at
   `:342` already does) or surface the message like `handleLinkReceipts` (`:238-243`) does.
4. **CI ruling below** (append `ExpensePostTest` only; do NOT append `SupplierInvoiceApiTest`).

---

## A. `match()` draft-guard — verified

- Guard: `SupplierInvoiceController.php:272-284` — `if ($doc->status !== DocumentStatus::Draft)` →
  `validationErrorResponse('MATCH_NOT_ALLOWED', …)`. Confirmed 422 + `error.code` by execution.
- **Refusal ordering is correct:** `can:documents.update` route middleware
  (`Procurement/Presentation/routes.php:105-109`) → **403** before the controller; company scoping via
  `baseQuery()` = `Document::forCompany($companyId)`
  (`Document/Presentation/Controllers/Concerns/HandlesDocuments.php:42-47`) + `->find($id)` →
  **404** at `:268-270`; the new **422** is last. 403/404 both precede 422. ✅
- **Claim (1) checked against every status writer.** Grepped all writers of
  `documents.status` for `SupplierInvoice`: `CreateSupplierInvoiceService.php:128` writes `Draft`,
  `SupplierInvoicePostingService.php:295-298` writes `Posted`. The type's legal lifecycle is
  `Draft → Posted` directly (`DocumentStatusMachine.php:82-86,105-113` — `postsDirectlyFromDraft()`
  lists `SupplierInvoice`), then the sales-lifecycle `posted → paid`. No cancel/void writer for this
  type exists. **Draft-only is the exact complement.** ✅
- **Other match-state write paths (the real question the brief asked).** Four writers of
  `documents.match_status` exist for supplier invoices; after this commit all four are Draft-bound:
  1. `CreateSupplierInvoiceService.php:146,220` — create/auto-match, document is Draft by
     construction (`:128`).
  2. `SupplierInvoiceController::match()` — **now** Draft-only (this commit).
  3. `SupplierInvoiceReceiptLinkingService.php:158` (route `POST /supplier-invoices/{id}/link-receipts`,
     `routes.php:111-114`) — already guarded: `:40-42` throws
     `'Only draft supplier invoices can link receipt lines.'`. ✅ **no second hole.**
  4. `RematchDraftSupplierInvoicesCommand.php:47-50` — `->where('status', DocumentStatus::Draft)`. ✅
  **Conclusion: the post-time `match_status` snapshot is now immutable in practice, not just via this
  endpoint.** The remaining theoretical writer is raw SQL / a future service — the CHECK + the
  program WS-B immutability pin remain the right long-term guard (deliberately deferred per brief).
- Test quality: both new tests assert real behaviour (the 422 body AND that `PriceVariance` survives),
  the companion test proves the Draft path still re-matches. `RefreshDatabase` + real models. ✅

## B. Expense numbering — verified

- Generator: `ExpenseService.php:1030-1051`. Lock at `:1032-1037`,
  `pg_advisory_xact_lock(hashtextextended('expense_number:{tenantId}', 0))`, pgsql-guarded — shape
  is a verbatim mirror of `Treasury/Domain/InstrumentRemittance.php:52-70`. ✅
- **Format/prefix unchanged:** `sprintf('EXP-%s-%06d', …)` at `:1049`; scan predicate still
  `LIKE "EXP-{$year}-%"` at `:1043`. `EXP-` is minted nowhere else in `app/` or `database/`
  (grep: only these two lines). `DocumentType::Expense`'s `getPrefix()` is `'EXP'`
  (`DocumentType.php:63`) but `DocumentNumberingService` is not used for expenses. ✅
- **Scope now matches the constraint.** The unique index is
  `documents_tenant_id_type_document_number_unique` on `(tenant_id, type, document_number)` —
  `database/migrations/tenant/2025_11_30_080000_create_documents_table.php:39`, dropped/recreated
  unchanged by `2025_12_30_085029_make_document_number_nullable_on_documents_table.php:18,24`. There is
  no company-grained unique on `document_number`. `Document` has **no global scopes**
  (`Document/Domain/Document.php:108-111` — only `HasFactory`/`HasUuids`), so the widened
  `where('tenant_id', …)` scan really is tenant-wide. ✅
- **Semantics change (accepted, condition 2):** previously per-company sequences, now one interleaved
  tenant sequence per year. Given the index, per-company sequencing was never actually *safe*; the
  alternative (per-company partial unique) is migration-bearing on the hottest table in the system and
  the brief explicitly forbade it. Correct call for a hotfix lane.
- **Both call sites are inside the caller's transaction** (so `xact` scope holds to commit):
  `:322` inside `DB::transaction` opened at `:317` (post), `:851` inside `DB::transaction` opened at
  `:833` (reverse). ✅ `reverse()` still allocates its reversal number the same way and its
  `Document::create` is unchanged otherwise. ✅
- **SQLite fallback:** no lock (driver check at `:1032`), same as `InstrumentRemittance`. Acceptable —
  sqlite is test-only; production and every CI PG lane take the lock. Documented in the docblock
  `:1023-1027`. ✅
- **Residual (LOW, pre-existing, brief-acknowledged):** `substr($lastNumber, -6)` wraps at
  `EXP-YYYY-999999` → next allocation returns `000001` and collides. Not introduced here.
- Test quality: `test_expense_numbers_do_not_collide_across_two_companies_in_the_same_tenant`
  asserts the real allocated number (`…-000002`), not just "no exception".
  `test_expense_number_allocation_takes_the_tenant_advisory_lock` asserts the actual statement +
  binding from the query log and PG-skips elsewhere. Neither mocks the unit under test. ✅

## C. OUT-OF-LANE HIGH — `journal_entries.entry_number` has the same defect (do NOT fix here)

### C-1 [HIGH] `GeneralLedgerService.php:5339-5356` vs `2025_11_30_100000_create_journal_entries_table.php:41`

The unique index is `(tenant_id, entry_number)` (migration `:41`; no later migration alters it —
grepped all 26 migrations touching `journal_entries`). `generateEntryNumber` locks on the **raw
companyId** (`:5348`) and scans `->where('company_id', $companyId)` (`:5353`), prefix `JE-YYYY-%`
(`:5352`), `sprintf('JE-%s-%06d')` (`:5365`). 43 call sites in that one file.

**REPRODUCED BY PATH ON POSTGRESQL** (throwaway DB `autoerp_q11gate_test`, PG 5433, scratch test
outside the worktree; nothing in the repo modified). Two companies in one tenant, each posting one
expense through `POST /api/v1/expenses/{id}/post`, **with this lane's commit applied**:

```
POST status for company Test Company:   200   → EXP-2026-000001, JE-2026-000001
POST status for company Second Company: 500
SQLSTATE[23505]: duplicate key value violates unique constraint
  "journal_entries_tenant_id_entry_number_unique"
DETAIL: Key (tenant_id, entry_number)=(01a03655-e09f-…, JE-2026-000001) already exists.
  … insert into "journal_entries" … description = 'Expense: EXP-2026-000002 - General Expense' …
```

Note the description: the Q-11 fix worked (the *document* number correctly advanced to
`EXP-2026-000002`); the transaction then died on the *journal entry* number. Company B's expense
stays `Draft` and its GL/VAT/WAC writes roll back. Identical failure on sqlite.

**Why the lane's own test does not catch it:** `ExpensePostTest.php` seeds company A's prior expense
with `makeExpense(... 'status' => Posted, 'document_number' => …)` — a direct `Document::create` that
mints **no journal entry**. Legitimate for the numbering pin; it just means the lane's green does not
prove the flow.

**Blast radius (which flows are blocked).** Because company B's failed post leaves company B with
zero `journal_entries` rows, its next allocation is always `JE-YYYY-000001` again — permanently taken
by company A. **The second company of a tenant can never post ANY GL entry for that year.** Every
`GeneralLedgerService` path is affected (43 `generateEntryNumber` call sites): expense post/reverse,
supplier-invoice post (GR-IR 408 clear + 4456 + 401), customer invoice/credit-note post, payments,
treasury transfers/adjustments/remittances, inventory movements, POS bridges.
Sibling generators with the **same** defect (out of lane, same follow-up):
`AccountingOpeningService.php:468-490` (`OB-` prefix, company lock + company scan),
`Inventory/…/OpeningBalancePostingService.php:282` and `ResetOpeningBalanceService.php:257`,
`ArApOpeningService.php:448-470` (`HIST-` prefixes), and `IncomeService.php:206-224`
(`INC-`, company scan, **no lock**, same `documents` tenant-wide index → the exact bug this lane just
fixed for expenses, still live for incomes).
Counter-example proving the intended scope: `JournalEntryController.php:187-204` (manual JE) already
scans **tenant-wide** — with the same `JE-` prefix and **no lock**, so it also races the automated
minter.

**Launch impact rating.**
- **Single-company first tenant: NONE.** One company ⇒ the company scan and the tenant index agree.
  Not a launch blocker for the current first-tenant plan.
- **Any tenant with a 2nd company: CATASTROPHIC and immediate** — the second company is GL-dead from
  its first posting attempt; the user sees a bare 500 (`INTERNAL_ERROR`) with no actionable message,
  and every downstream artefact (AP ageing, VAT return, treasury balance) silently never happens.
  This must be fixed **before** the product offers multi-company provisioning, not after.

**Exact fix shape for the follow-up lane** (mirror this lane, do NOT change the index):
1. `generateEntryNumber(string $tenantId)` — key the lock
   `pg_advisory_xact_lock(hashtextextended('journal_entry_number:'||$tenantId, 0))` and scan
   `->where('tenant_id', $tenantId)`; update all 43 call sites to pass the tenant id.
2. **Lock-ordering is load-bearing:** `sealAndPersistEntry` takes a **per-company** advisory lock at
   `GeneralLedgerService.php:3742` because the hash chain and `chain_sequence` are per company
   (`:3755-3757`) — that one must stay per-company (fiscal). The follow-up therefore holds TWO locks;
   fix a deterministic order (**tenant key first, then company key**) in every path, or the two
   creates can deadlock. Name the ordering in a comment and pin it with a test.
3. Add the same lock to `JournalEntryController::generateEntryNumber` (already tenant-scoped, unlocked).
4. Same treatment for the `OB-` / `HIST-` / `INC-` siblings listed above.
5. Rejected alternative: widening the unique to `(tenant_id, company_id, entry_number)` — migration on
   a hot fiscal table, and `entry_number` is an externally-quoted identifier (FEC/exports). Owner
   ruling required; not the hotfix.

**Census queries (run per tenant DB — db-per-tenant, so loop `tenants:run`).**

```sql
-- 1. Exposure: tenants (i.e. tenant DBs) with more than one company at all.
SELECT tenant_id, count(*) AS companies
FROM companies
GROUP BY 1 HAVING count(*) > 1;

-- 2. Already-interleaved years (proves some company already minted under another's numbers).
SELECT tenant_id, substring(entry_number from 4 for 4) AS yr,
       count(DISTINCT company_id) AS companies_minting, count(*) AS entries
FROM journal_entries
WHERE entry_number LIKE 'JE-%'
GROUP BY 1,2 HAVING count(DISTINCT company_id) > 1;

-- 3. Companies whose NEXT allocation will collide today
--    (own per-company max < tenant-wide max for the current year).
SELECT c.tenant_id, c.id AS company_id, c.name,
       (SELECT max(je.entry_number) FROM journal_entries je
         WHERE je.company_id = c.id
           AND je.entry_number LIKE 'JE-'||to_char(now(),'YYYY')||'-%') AS own_max,
       (SELECT max(je.entry_number) FROM journal_entries je
         WHERE je.tenant_id = c.tenant_id
           AND je.entry_number LIKE 'JE-'||to_char(now(),'YYYY')||'-%') AS tenant_max
FROM companies c
ORDER BY 1,2;
-- rows where own_max IS DISTINCT FROM tenant_max are blocked.

-- 4. Same three shapes for documents/EXP- and documents/INC- (the Income sibling is still live).
```

## Findings (ordered by severity)

- **[HIGH — OUT OF LANE, do not fix here] `GeneralLedgerService.php:5339-5356` +
  `2025_11_30_100000_create_journal_entries_table.php:41`** — per-company entry-number allocation
  against a `(tenant_id, entry_number)` unique index. The 2nd company of a tenant cannot post any GL
  entry. PG-reproduced above. Separate lane; fix shape + census above. *Consequence for this lane: its
  commit message and any progress YAML must not claim the multi-company expense post now succeeds — it
  still returns 500, one layer down.*
- **[HIGH — OUT OF LANE] `IncomeService.php:206-224`** — the *identical* defect this lane just fixed
  for expenses, still live for `Income` documents (company-scoped `max+1`, no lock, tenant-wide unique
  index). One company's `INC-YYYY-000001` permanently blocks the other's. Same fix shape; should ride
  the same follow-up lane.
- **[IMPORTANT] `apps/web/src/features/purchases/supplier-invoices/SupplierInvoiceDetailPage.tsx:353-361`
  + `:223-229`** — the "Re-match" button is rendered for posted invoices too (unlike Post at `:342`,
  which is `!isPosted`-gated) and `handleRematch` has no `onError`, so the new 422 is swallowed: the
  button does nothing, with no message. The backend guard is right; the FE needs the matching gate.
  Not in this lane's 4-file surface — follow-up (condition 3).
- **[IMPORTANT — accepted, needs an owner ack row] `ExpenseService.php:1040-1044`** — numbering
  contract change: expense numbers now interleave across a tenant's companies, so each company's own
  register shows gaps. Correct given the index; must be an owner-visible decision, not only a docblock.
- **[MINOR — pre-existing] `tests/Feature/Procurement/SupplierInvoiceApiTest.php:1260-1265`** —
  `JournalLine::query()->where('company_id', …)`: `journal_lines` has **no** `company_id` column
  (`Accounting/Domain/JournalLine.php:37-45`; no migration adds one). On SQLite the double-quoted
  identifier degrades to a string literal so the query silently returns 0 rows and the assertion
  passes vacuously; on PG it is a hard `42703`. Also `number_format((float) $net408, 3, …)` at `:1265`
  is a float on money in a treasury assertion (rule 19), and it is the reason the vacuous pass looked
  green. Pre-existing, not touched by this commit — see the CI ruling.
- **[MINOR — pre-existing] `tests/Feature/Expense/LinkedCostExpenseTest.php:139,246`** — non-UUID
  literals (`'sale-after-receipt'`) bound into `stock_movements.reference_id` (rule 17). Hard error on
  PG, silently fine on SQLite.
- **[MINOR] `ExpenseService.php:1035` vs `GeneralLedgerService.php:3742,5348`** — this lane namespaces
  its advisory key (`expense_number:{id}`) while the GL locks hash the bare id. Not a defect (disjoint
  key spaces by luck), but the GL's unnamespaced keys are a collision hazard the follow-up should
  namespace while it is in there.
- **[MINOR — observation] `DocumentNumberingService.php:41-66`** — the canonical numbering service is
  also per-company (`DocumentSequence` keyed on `company_id`) against the same tenant-wide `documents`
  unique index, so `INV-YYYY-0001` from a 2nd company collides too. It has a one-shot retry
  (`:32-38`) that re-reads the same company sequence, so the retry does not help. Same class as C-1;
  worth folding into the follow-up census (query 4).

## Gate verified — per file, per driver

Run by path only; never the full suite. Base `1f76745e8`.

| File | SQLite (`phpunit.xml`) | PostgreSQL (`phpunit-pgsql.xml`, own DB `autoerp_q11gate_test`) |
|---|---|---|
| `tests/Feature/Procurement/SupplierInvoiceApiTest.php` | **OK 54/54**, 318 assertions, 53.7s | **54 tests, 309 assertions, 3 ERRORS** — all three inherited, none Q-11 (see below) |
| ↳ `--filter test_match_endpoint` (the 2 new + 1 sibling) | (covered above) | **OK 3/3**, 16 assertions |
| `tests/Feature/Expense/ExpensePostTest.php` | **OK 5/5** (1 skipped: PG-only lock test), 13 assertions | **OK 5/5**, 16 assertions — the lock assertion really executed |
| `tests/Feature/Expense/LinkedCostExpenseTest.php` | **OK 4/4**, 43 assertions | **4 tests, 2 ERRORS** — inherited fixture bug (see below) |
| `tests/Feature/Expense/ExpenseIdempotencyTest.php` | **OK 1/1**, 4 assertions | **OK 1/1**, 4 assertions |

**The 5 PG errors are inherited red, not lane-caused.** Attribution:
- `SupplierInvoiceApiTest::test_link_receipts_requires_pending_draft_invoice` and
  `::test_post_invoice_first_missing_approval_permission_record_returns_domain_error` —
  `22001 value too long for type character varying(20)` inserting `locations.code`
  (`'SI-LINK-NOT-PENDING-WH'`, `'SI-APPROVAL-MISSING-WH'` = 22 chars). Test-fixture data.
- `SupplierInvoiceApiTest::test_pending_link_receipts_then_post_consumes_receipt_and_clears_gr_ir` —
  `42703 column "company_id" does not exist` on `journal_lines` (the MINOR finding above).
- `LinkedCostExpenseTest` ×2 — `22P02 invalid input syntax for type uuid: "sale-after-receipt"`.
- None of the five is in a file/method this commit touches; the commit's 4 files are listed at the
  top and neither `LinkedCostExpenseTest` (last changed `2df310e49`, 2026-08-10) nor those three SI
  methods appear in the diff. The failing statements are authored in the test files themselves.

**Static gates:**
- `./vendor/bin/pint --test` on all 4 changed files → `{"result":"pass"}`.
- `./vendor/bin/phpstan analyse <the 2 prod files> --level=8` → `[OK] No errors`.
- `php tools/feature-lane-manifest-check.php` → **EXIT=0**, "lane manifest OK — 1406 Feature classes
  in 74 groups"; no new class, no ceiling change needed.

## CI reachability ruling (E)

**Confirmed dark.** Neither class is named in either live `--filter` allowlist
(`grep -c 'SupplierInvoiceApiTest\|ExpensePostTest' .github/workflows/ci.yml` → **0**). Both are laned
into **parked** self-hosted jobs: `feature-lane-fiscal-finance/Expense`
(`ci.yml:1499-1500`, gate `vars.SELF_HOSTED_RUNNER_READY == 'true'` at `:1395`;
manifest `runs_on_pr_dev: false`) and `feature-lane-inventory/Procurement` (`ci.yml:1611-1612`, gate
at `:1509`). So **both new pins execute nowhere on PR→dev today.**

**Ruling (I did not edit `.github/**`):**
- **`ExpensePostTest` — APPEND** to the `backend-test-pgsql` `--filter` allowlist (`ci.yml:976`),
  per the B-3 / Q-6-r2-F-7 precedent. It is **PG-green 5/5 in ~27 s** and only 5 tests total, so the
  append enables 3 pre-existing tests, all green. The advisory-lock pin is PG-only and *only* runs
  there — leaving it out means the lock assertion never runs in CI at all.
- **`SupplierInvoiceApiTest` — DO NOT APPEND (yet).** Appending it would enable 52 pre-existing tests
  of which **3 are PG-red today** (the inherited fixture bugs above) → an instantly red gate, and it
  costs ~5 min of PG runtime. **Precondition to append:** a follow-up test-hygiene lane fixes the
  `locations.code` length, the `journal_lines.company_id` query (and its `(float)` money cast) and
  re-runs the file PG-green; then append. Until then the Q-11 match guard is CI-dark — record it as a
  residual, exactly as Q-6 did.

## Scope check (F)

Clean. 4 files, all inside the brief's declared surface. **No touch to Session A's B-19 surface** —
`CreateSupplierInvoiceService.php` and `EloquentVatDataRepository.php` are absent from the diff
(`git diff --name-only 2288299bf 1f76745e8` above), as is `SupplierInvoiceReceiptLinkingService.php`
and anything in the collision matrix. Worktree tree is clean (`git status --porcelain` empty).
No migration. No `app()` in production code. No float touches money in either changed prod file
(the only arithmetic added is an integer `+1` on a sequence counter — not currency).

## Deviation ruling

**Brief §Part 2.1 said: key the advisory lock `expense_number:{companyId}`. The implementer keyed it
`expense_number:{tenantId}` (`ExpenseService.php:1035`) and declared it. ACCEPTED — the deviation is
mandatory and the brief was wrong.**

A serialisation lock must be at least as wide as the critical section it protects. After §Part 2.2
widened the `max+1` scan to `where('tenant_id', …)` (`:1042`), the critical section is tenant-wide:
two concurrent posts in *different companies of one tenant* read the same tenant-wide maximum. A
company-keyed lock would not have excluded them from each other, so the exact race the brief asked to
close would have remained open across companies — the very configuration §Part 2.1 exists to protect.
The tenant key also matches the constraint being satisfied
(`documents_tenant_id_type_document_number_unique`). Correct call, correctly flagged.

## Residuals

1. **C-1 follow-up lane** (`journal_entries.entry_number` + the `OB-`/`HIST-`/`INC-` siblings +
   `DocumentNumberingService`) — HIGH, blocks multi-company; not a single-company launch blocker.
2. **FE re-match button gate + `onError`** (condition 3).
3. **Owner ack** for tenant-interleaved expense numbering (condition 2).
4. **CI:** `ExpensePostTest` append recommended; `SupplierInvoiceApiTest` append blocked on the
   3 inherited PG fixture reds — the match guard stays CI-dark until then.
5. **PG test hygiene lane:** `locations.code` >20 chars, `journal_lines.company_id` phantom query
   (+ its `(float)` money cast), non-UUID `reference_id` literals. SQLite's double-quoted-identifier
   fallback is actively hiding at least one vacuous treasury assertion.
6. **LOW, pre-existing:** `substr(-6)` wrap at `EXP-YYYY-999999` (brief-acknowledged).
7. Deferred per brief and still open: `documents.match_status` CHECK (Slice D), post-time snapshot
   immutability pin (program WS-B).

**What to fix before merge:** nothing in these 4 files — merge as a partial fix, but immediately
dispatch the C-1 lane and correct the lane's own claim that the multi-company expense post now works
(it still 500s on `journal_entries_tenant_id_entry_number_unique`).
