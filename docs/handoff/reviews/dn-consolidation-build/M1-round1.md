## Adversarial merge-gate register — M1 (round 1)

Range reviewed: `60df88a01b52828665caf33809486bdf0a699bbc..HEAD` (22 commits, `e81ac612e` tip). Amending authority applied: the 2026‑08‑19 owner re‑pin + `preflight_policy` block in `docs/handoff/progress/dn-consolidation-build.progress.yaml:10-42` (differential preflight replaces whole‑repo‑green). The brief's `§7` report contract and `§6` evidence rules are **not** amended and still bind.

Lens applicability: **tenancy-authz** — applies, verified. **inventory-costing** — applies (auto‑created DN side effects moved inside the conversion transaction); verified, no finding. **treasury** — applies only weakly: no GL/payment write is added or reordered; `transferPrepayments` sits in the same transaction it always did. No finding. **general** — the P0‑3 race work; findings below.

---

### P1 — blocks

**1. OI‑8 ratified UI conditions 2–4 are not built on the consolidation lane, yet the M1 handback claims conditions 1–4 satisfied.**
`apps/web/src/features/documents/components/DeliveryNoteConsolidation.tsx:160` · CONFIRMED
The consolidation refusal path is `catch (err) { setError(getErrorMessage(err)) }` and renders a single string at `:225-232`. It never parses `error.details.documents[]`, so it renders **no** per‑DN attribution (condition 2: document number + taking invoice number/date + `invoiced_via` lane label + link), **no** *"No invoice was created. No invoice number was used."* sentence (condition 3), and **no** "Remove these N and retry" that deselects exactly the named rows (condition 4's consolidation variant, brief §3). `grep -rn "DELIVERY_NOTE_ALREADY_INVOICED\|details.documents" apps/web/src` returns hits only in `SalesOrderDetailPage.tsx` — the SO lane is fully built and fully tested (`SalesOrderDetailPage.tenantScope.test.tsx:116-185`); the consolidation lane has nothing.
Failure scenario: two clerks consolidate overlapping DNs. The loser's server response carries the full attributed `details.documents[]`, but the page shows only *"Delivery note has already been invoiced"* — no DN number, no taking invoice, no link, no guarantee sentence, and the whole selection stays as it was with no way to prune the named rows. This is exactly the "nothing drops silently" failure OI‑8 exists to prevent.
The M1 milestone contents in the amending YAML (`:68`) name *"the OI-8 REFUSE behaviour with ratified UI conditions 1-4"*, and `HANDBACK-…:“C5/C8 and OI-8 conditions 1–4: … persistent attributed refusal, no-artifact copy”` asserts them DONE. Either build it, or record an explicit deferral to M2/View A (defensible — M4 deletes this page) **and correct the handback claim**; an unevidenced "conditions 1–4 done" is a reportable deviation under §7 item 4.

---

### P2 — fix before merge

**2. `GET /delivery-notes` is now silently narrowed to the company currency, hiding foreign-currency delivery notes from every consumer.**
`apps/api/app/Modules/Document/Presentation/Controllers/DeliveryNoteController.php:109` · CONFIRMED
`baseQuery()` was company-scope-only (`Concerns/HandlesDocuments.php:42-47`); the diff adds `->where('currency', $company->currency)` to the **row** query. Spec `§2.1(d)` (`SPEC…:172`) scopes only the **aggregate**: *"the endpoint scopes the aggregate to the company currency"* — OI‑11 defers multi-currency, it does not authorise deleting rows from the list. DNs can legitimately carry a non-company currency: `DeliveryNoteController.php:303` writes `'currency' => $validated['currency'] ?? $company->currency`, and `CreateDocumentRequest.php:84` accepts `currency` as nullable input.
Failure scenario: a TND company issues one EUR delivery note. It disappears from `/inventory/delivery-notes` entirely — no filter chip, no count, no error — and from `getInvoiceableDeliveryNotes()` (`apps/web/.../api/deliveryNotes.ts:77`), so it can never be consolidated. No test covers it: `DeliveryNoteBillingProjectionTest::test_aggregates_are_opt_in_and_page_invariant…:262` seeds partner/location/date/status/type negatives but no foreign-currency fixture. Not disclosed in the handback.

**3. The DN-consolidation lane emits a phantom "already invoiced" 422 when the retrier exhausts on a deadlock — the SO lane was fixed for exactly this, the DN lane was not.**
`apps/api/app/Modules/Document/Domain/Services/Billing/DeliveryNoteBillingConcurrencyRetrier.php:53-54` · CONFIRMED
On the third `40001`/`40P01`, `run()` throws `DeliveryNoteAlreadyClaimedException($deliveryNoteId, $exception)` with `deliveryNoteNumber = ''`. `SalesOrderToInvoiceConverter::convert` guards this (`:355-386`: `durableDeliveryNoteWinner()` returns `null` when no committed winner exists → `throw $previous`), proven by `DeliveryNoteConsolidationConcurrencyTest::test_sales_order_commit_exhaustion_preserves_infrastructure_error_without_phantom_422:278`. `DeliveryNoteToInvoiceConverter::convert:124` has **no** equivalent catch, so the exception reaches `DocumentConversionController:322-334` and is rendered as `DELIVERY_NOTE_ALREADY_INVOICED` / `reason: claim_lost`.
Failure scenario: a serialization failure with no competing claim. `deliveryNoteFailureDetails()` finds no marker, so the operator is told the DN was already invoiced with `invoice_id`, `invoice_number`, `invoice_date`, `invoiced_via` all `null` — a false diagnosis of a fiscal state, and precisely the asymmetry the SO-lane fix was written to close.

**4. The M1 handback does not carry the §7 report contract's evidence.**
`docs/handoff/HANDBACK-dn-consolidation-build-2026-08-12.md` · CONFIRMED
The M1 section is a prose summary. Missing, against `§7`: item 2 — per-spec-item `DONE/PARTIAL/…` with `file:line` **and the red-first evidence (test file + what its failure looked like before the fix)**; item 3 — **the lock inventory as built**, with the acyclicity argument and the explicit statement that no writer reaches invoice creation before its claims succeed (this is the artefact `§6` says the reviewer must compare against an independent re-derivation); item 4 — OI‑8 conditions itemised 1–4 with per-condition evidence; item 5 — the OI‑14 four-vanishing-artefacts proof; items 8/9/10 — decisions the spec did not specify, discovered out-of-scope findings, deviations.
Failure scenario: the gate cannot verify red-first (`§5`, CLAUDE rule 2) for any of the 22 commits, and finding 6 below (an NG‑2 scope expansion) is invisible to the parent's terminal audit. The underlying code for item 5 *does* exist and is strong (`SalesOrderBillingClaimTest.php:184-253` asserts no DN, no consumed `delivery_note` sequence, unchanged order payload, unchanged `quantity_delivered`, unchanged delivery status, and no `DocumentConverted` stored/audit event) — it is the report that is missing, not the test.

**5. The legacy backfill materialises every delivery note in the tenant, unchunked, inside an unattended `tenants:migrate`.**
`apps/api/database/migrations/tenant/2026_08_18_000002_create_delivery_note_billing_marks_table.php:60-65` · PLAUSIBLE
`DB::table('documents')->select(['id','tenant_id','company_id','payload'])->where('type','delivery_note')->orderBy('id')->get()->groupBy('tenant_id')` loads all rows **including the `payload` jsonb** into PHP memory, then issues one `exists()` + one `insert()` per stamped row.
Failure scenario: pushing to `origin/dev` auto-deploys staging including `tenants:migrate` (CLAUDE rule/D‑1). A tenant with a large delivery-note history OOMs or times out the migrate process mid-run. Because `up()` is transactional it rolls back cleanly, but the deploy fails unattended and every later tenant in the loop is blocked. `chunkById` over `documents` is the fix; the counters already aggregate per tenant.

---

### P3 — note / ticket

**6.** `apps/api/.../SalesOrderToInvoiceConverter.php:409` — CONFIRMED, undeclared. `copyOrderLinesWithProvenance()` replaces `copyLines()`/`copyPartialLines()` and now passes `linkSource: true`, so **every** SO→Invoice line carries `source_line_id` where it previously carried `null` (`CopiesDocumentData.php:103-108, 161-168`). It is load-bearing for `billed_order_line_ids` (`DocumentConversionController.php:549-566`), so it is justified by OI‑8 condition 4 — but it is a persisted data-shape change on the SO lane under `NG-2` ("nothing else about that lane moves") and it is not in the handback. Downstream reader checked: `StripSubToleranceDiscountsService.php:106` now emits a non-null `sourceLineId` on `DocumentLineDiscountStrippedAtConversion` — an improvement, not a break. Procurement readers are on a different document path.

**7.** `apps/api/.../DTOs/DocumentData.php:187-193` — CONFIRMED. `fromModel()` issues one extra `Document::find()` per document whenever `payload.invoice_id` is present, for **all** document types. Harmless today (`grep` shows no non-DN writer of a top-level payload `invoice_id`), but it is an N+1 on any invoiced-DN listing — i.e. exactly M2's `?invoiced=1` view.

**8.** `apps/api/.../DeliveryNoteBillingConcurrencyRetrier.php:83-92` — CONFIRMED latent. `rollBackFailedCommit()` calls `$pdo->rollBack()` unconditionally. `$this->db->transaction()` nests via savepoint, so if a converter is ever invoked from inside a caller transaction this destroys the **outer** transaction, and `SET LOCAL lock_timeout` leaks to the outer scope. No such caller exists today (only `DocumentConversionController` and `PurchaseQuoteRequestAwardService`, which drives a different converter) — worth a guard on `transactionLevel()`.

**9.** `apps/api/.../DeliveryNoteBillingConcurrencyRetrier.php:42` + `DocumentConversionController.php:335-349` — CONFIRMED. `SET LOCAL lock_timeout = '5s'` makes `55P03` reachable on the loser's `lockForUpdate()`, and `isRetryable()` covers only `40001`/`40P01`. The diff also removed the old `catch (\RuntimeException)` from `createInvoiceFromDeliveryNotes`, and `QueryException extends PDOException extends RuntimeException` — so a winner whose transaction exceeds 5 s now hands the loser a **500 with a bare driver message** instead of the intended block-then-`already_invoiced` 422.

**10.** `SPEC-dn-consolidation-billing-2026-08-11.md` — CONFIRMED. `C8` appears exactly once in the whole spec (`:985`, the milestone table) and is defined nowhere. The handback asserts *"C5/C8 … closed"* without stating what C8 is. `C5` **is** genuinely closed (`DeliveryNoteToInvoiceConverter.php:290-300`: injected `CompanyContext` tenant+company+type predicate, no `app()`).

**11.** `apps/web/.../DeliveryNoteConsolidation.tsx:165` — pre-existing `parseFloat` on money (rule 19) in a file this lane touched. Untouched lines; recorded, not fixed, per rule 18/19 scope discipline.

**12.** `apps/web/.../SalesOrderDetailPage.tsx:456` — the taking invoice's date is rendered as the raw `Y-m-d` string rather than through the locale formatter used elsewhere on the page.

**13.** `apps/api/.../DeliveryNoteBillingClaimService.php:120-140` — CONFIRMED, narrow. A DN whose payload carries `invoice_id` but **not** `invoiced_at` passes `reserve()` and then fails `finalise()`'s `payload->>'invoice_id' IS NULL` predicate, making it permanently unbillable behind a 422 whose message is *"Delivery-note payload finalisation affected 0 rows; expected 1."*. No writer produces that shape, and the backfill skips it (`migration:80`), so it is only reachable from hand-edited/imported data.

**14.** `migration …000002:101` — `'invoiced_at' => $payload['invoiced_at']` is inserted verbatim into a `timestamptz NOT NULL` column with only a `!== null` guard. A non-timestamp legacy value aborts the tenant loop, against D‑2's *"a dirty legacy row must not abort the tenant loop"*. The four **named** survey counts are all `invoice_id`-shaped, so this is outside the letter of D‑2 but inside its intent.

---

### Bypasses attempted that FAILED (i.e. the build held)

- **Find a production writer that reaches the three payload keys outside the claim service.** `grep -rn "invoiced_at" app/` returns only the claim service, the DTO, the two read scopes, and read-side `empty()` checks. All three legacy writers (`DeliveryNoteToInvoiceConverter::markDeliveryNoteAsInvoiced`, `SalesOrderToInvoiceConverter::markDeliveryNotesAsInvoiced`, `InvoiceController:1010-1014`) are deleted and routed through `claim()`. The PHPStan rule's docblock (`DeliveryNoteBillingWritesOnlyViaClaimService.php:26-46`) is honest about its bounded coverage and never claims to block "any write" — the `§6` trap is not sprung.
- **Break the exact-N finalise guard.** Both halves are separately count-guarded on marker rows *and* payload projection (`:117-146`) and are exercised by the two prescribed same-connection doctored-precondition negatives (`DeliveryNoteBillingClaimServiceTest:265, :274`) — the R4‑ND‑3 second-connection formulation is correctly avoided.
- **Find a lock-order cycle.** SO takes order header → `document_sequences[delivery_note]` → DNs ascending → `document_sequences[invoice]`; consolidation takes DNs ascending → `document_sequences[invoice]`. Consolidation never wants the DN sequence, so no cycle. Proven live by a real two-process test (`DeliveryNoteConsolidationConcurrencyTest:403-455`), with `date('Y')` evaluated once (`:96`) and both reachable years seeded (`:97-105`) exactly as prescribed.
- **Defeat the module gate at one layer.** Four independent tests exist: API `GET /delivery-notes/uninvoiced` (`DeliveryNoteConsolidationAccessControlTest:99`), API `POST /delivery-notes/consolidate-to-invoice` (`:137`), FE route under `ModuleGuard` with an admin holding `invoices.create` (`DeliveryNoteConsolidationRoute.gates.test.tsx`), FE action hidden (`DeliveryNoteConsolidation.gates.test.tsx`). Alias verified real: `bootstrap/app.php:113 'module' => RequireModule::class`. Route ordering verified: `uninvoiced` precedes `{deliveryNote}`, which now carries `whereUuid`.
- **Find a non-DN document whose payload carries `invoice_id` and would be mis-projected by the new `DocumentData` join.** None in `app/`; `CreditNoteService:1318` writes a `CreditNoteAllocation` column, not a document payload.
- **Show the lane edited the reserved `accountant` seeder block (F‑1).** It did not — `deliveries.view` is present at the pin (`RolesAndPermissionsSeeder.php:798-801`) and neither the seeder nor `permissionsMap.generated.ts` is in the diff.

VERDICT: CHANGES-REQUIRED
