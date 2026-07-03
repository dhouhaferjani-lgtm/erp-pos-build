# Adversarial Review — Procurement Completeness Spec, Revision 3 deltas ONLY

- **Date:** 2026-07-03
- **Reviewer:** Claude (adversarial spec review session; every code claim re-verified against the working tree on `dev`, commit `955fa9831` era)
- **Target:** `docs/superpowers/specs/2026-07-03-procurement-completeness-design.md` (committed `8968cc3a4`), Rev 3 deltas: multi-supplier RFQ groups (§1.2–§1.5, §1.9, §1.12), multi-PO supplier invoices (§3.2 Rev 3 block, §3.11, Wave 8), Procurement Presets (new §), Rev 3 owner-decisions table + deferral consistency.
- **Out of scope:** all Rev 2 content (already reviewed by Codex, 3 rounds).

## VERDICT: NEEDS-REVISION

Three HIGH findings, all fixable with spec text (no architectural rework): the award guard has a TOCTOU hole and specifies no locking (R3D-1); the "re-award after PO cancellation" flow is internally contradictory with the §1.12 status mapping — a listed §1.9 test cannot pass as specified (R3D-2); and the Presets section builds Léger on a `match_mode` that **nothing in the codebase consumes today**, with `two_way` given three mutually inconsistent meanings across the spec and enum docblock (R3D-3). The multi-PO delta's core claims mostly verify — the matcher genuinely never reads `source_document_id` — but the "no reader joins on a multi-PO header" claim misses the document-chain reader (R3D-4).

Positive verifications (claims attacked and found TRUE) are listed at the end.

---

## R3D-1 · HIGH · §1.4 — Award race: the `RFQ_GROUP_ALREADY_AWARDED` guard is check-then-act with no locking specified

**Spec claim:** "Converting a second sibling of an already-awarded group → 422 (`RFQ_GROUP_ALREADY_AWARDED`) … (the guard checks for a live converted PO, not a boolean flag)" and the award "closes every *other* non-converted sibling in the group … in the same transaction" (§1.4).

**Attack:** two users award different siblings (A and B) of the same group concurrently. Under PostgreSQL READ COMMITTED, each transaction's "is there a live converted PO in this group?" query sees no committed PO from the other → both guards pass → **two POs from one group**, and each transaction Cancels the *other's* winner while it converts — leaving both winners simultaneously "Converted" (has a live child PO per §1.12's definition) and status `Cancelled` (closed as lost). The group's derived state is corrupt in a way no re-check can untangle.

**Code evidence that the inherited machinery gives no protection:**
- The converter pattern the RFQ converter follows does an unlocked in-memory check: `QuoteToSalesOrderConverter.php:81,118` guards on `payload['converted_to_order_id']` read from the passed model — no `lockForUpdate`, no re-read.
- The conversion controller wraps nothing in a transaction: `DocumentConversionController.php:27-51` (`convertQuoteToOrder` calls `$this->converterRegistry->convert(...)` bare).
- Contrast with the house standard the spec should mirror: `SupplierInvoicePostingService.php:61-75` explicitly locks the contended rows (`lockForUpdate()`) inside `DB::transaction` and documents the discipline in its class docblock (:19-35).

**Fix (spec text):** §1.4 must specify the award transaction's locking: at award start, `SELECT … FOR UPDATE` **all sibling document rows of the group** (deterministic order, e.g. `ORDER BY id`, to avoid lock-order deadlocks between two concurrent awards), *then* run the live-converted-PO guard on the locked state, then convert + close siblings. (A `pg_advisory_xact_lock(hashtext(group_id))` is an acceptable alternative single-point serializer.) Add a concurrency test to §1.9: two concurrent awards → exactly one PO, one 422.

---

## R3D-2 · HIGH · §1.4 + §1.9 + §1.12 — "Re-award after the winning PO is cancelled" is unreachable for any sibling except the original winner

**Spec claims in tension:**
1. §1.4: converting a second sibling is rejected "**unless the first PO was cancelled — re-award is then allowed**".
2. §1.9 test list: "re-award allowed after the winning PO is cancelled".
3. §1.4 + §1.9 + §1.12: the converter's precondition is "winner must be `Responded`" / "rejects non-`Responded` source", and §1.12 maps **Responded = `Confirmed` + `payload.rfq.response_recorded_at`**, **Closed = `Cancelled`**.
4. §1.4: the award "closes every *other* non-converted sibling in the group (`Cancelled` + `closed_reason='lost'`)".

**Contradiction:** after the first award, every non-winning sibling is `Cancelled`. When the winning PO is later cancelled, the business need is almost always "award a *different* supplier instead" — but every other sibling is `Cancelled`, and the converter rejects non-`Responded` sources (`Cancelled` ≠ `Confirmed`). So "re-award" as specified only works for **re-converting the same original winner** (whose status stays `Confirmed` with `response_recorded_at` intact). The §1.9 test "re-award allowed after the winning PO is cancelled" is ambiguous, and for the sibling-B case it **cannot pass** alongside the same section's "rejects non-`Responded` source" unit test. No reopen mechanism (Cancelled → Confirmed for `closed_reason='lost'` siblings) is specified anywhere, nor is any hook on PO cancellation that touches the RFQ group.

**Fix (spec text):** decide and specify one of: (a) cancelling the winning PO (or an explicit "reopen group" action) reverts `closed_reason='lost'` siblings to `Confirmed` (clearing `closed_reason`), making them awardable again — state which endpoint/actor does this and that it happens under the R3D-1 lock; or (b) restrict re-award to the original winner and say so explicitly (weaker product-wise). Update the §1.9 test wording to name the sibling being re-awarded. Also define "live converted PO" precisely: PO with `status != Cancelled` **and not soft-deleted** (`documents` has `softDeletes` — `2025_11_30_080000_create_documents_table.php:37`).

---

## R3D-3 · HIGH · §Procurement Presets + Wave 9 + §3.2 — `match_mode` has ZERO consumers today; "honors `two_way`" is unspecified net-new behavior, and `two_way` carries three conflicting meanings in the spec

**Spec claims:** Léger preset = `match_mode='two_way'`; tests: "the §2.5 matcher honors `two_way` (no receipt-line requirement for matching under Léger — two-way = invoice vs PO price only, receipt lines still *created* and *cleared* for GR-IR correctness)"; Wave 9 scopes this as a "two-way-mode matcher test" and claims Wave 9 "needs only Wave 5's matcher for its two-way test".

**Code evidence:**
- `match_mode` is written but **never read** anywhere in matching or posting. Full grep of `app/` (non-test): consumers are only the model itself (`ProcurementPolicy.php:56,69,91`) and the provisioning write (`TenantProvisioningService.php:165`). `SupplierInvoiceMatcher` never touches `$policy->match_mode` — it reads only `variance_tolerance_percent`/`variance_tolerance_max_amount` (`SupplierInvoiceMatcher.php:488,495`), and `SupplierInvoicePostingService` reads only `match_enforcement` (:57). So today `two_way` is a stored no-op; "the matcher honors two_way" is **new behavior with no specification**, not a test over existing behavior.
- The enum's own contract contradicts the preset's semantics: `MatchMode.php:11` — "Two-way: PO ↔ Supplier Bill (**no GR requirement**). Used for service POs." But Léger explicitly *keeps* the GR requirement (receipt lines "still created and cleared"), and must: the matcher hard-Exceptions on unreceived lines (`SupplierInvoiceMatcher.php:346-353`), the posting service's authoritative over-clear guard throws when invoiced > received (`SupplierInvoicePostingService.php:163-172`), and GR-IR clearing has nothing to clear without a receipt accrual. None of these are enforcement-bypassable ("Warn CANNOT bypass them", matcher docblock :25).
- Third meaning: §3.2 says a "truly PO-less supplier invoice (service invoice, `MatchMode::TwoWay`)" is Phase 2. A PO-less invoice cannot be "PO ↔ Bill" matching under anyone's definition — that is *no-way* matching. So within one document, `two_way` means (i) enum: PO↔bill with no GR, (ii) Presets: PO-price-basis with GR still mandatory, (iii) §3.2: PO-less service invoices.

**Unanswered design questions the spec must resolve before Wave 9 is implementable:** under `two_way` post-Wave-5, (a) which basis does `computePriceStatus` use — PO `unit_price` (today's comparison, `SupplierInvoiceMatcher.php:465-466`) instead of receipt-line `accrual_unit_cost`? (b) what does the §2.5 creation-time `price_match_basis` snapshot store? (c) do the hard quantity checks change at all (presumably no)? (d) does `procurement:rematch-drafts` and the §3.5 match-preview endpoint branch on mode? Each of these is code in the matcher/creation/preview paths, not "only a matcher test".

**Fix (spec text):** add a short "two_way semantics (v1)" block to the Presets section: hard quantity/receipt invariants identical to three_way; price comparison basis = PO contractual `unit_price`; snapshot stores that basis with a mode marker; preview/rematch branch accordingly. Rewrite the `MatchMode.php` docblock in the same wave (the "no GR requirement" sentence becomes false), and re-word §3.2's Phase-2 PO-less line to stop borrowing `MatchMode::TwoWay` (it needs a new mode or a null-source contract, as §3.2 itself half-admits). Re-scope Wave 9's estimate: matcher branch + snapshot + preview, not test-only.

---

## R3D-4 · MED · §3.2 — "no reader today joins on a multi-PO header" misses the document-chain reader; PO #2..N silently lose the invoice linkage

**Spec claim (§3.2 alternatives):** "a header-level array in `payload` + first-PO compat column is enough, and **no reader today joins on a multi-PO header**."

**Code evidence:** the document chain IS a reader that joins on the single column, in both directions:
- `Document.php:358-361` — `childDocuments(): HasMany` keyed on `source_document_id`; `Document.php:373-401` — `getDocumentChain()` builds ancestors via `sourceDocument` and siblings via `where('source_document_id', …)` (:389).
- Exposed to users: `DocumentController.php:206,218` (Related Documents endpoint, route at `Document/Presentation/routes.php:311`) → FE `RelatedDocumentsTab.tsx:116-193` renders the chain on detail pages; `DocumentHeader.tsx:101,151` renders exactly one source-document link.

**Consequence:** for a Wave 8 invoice over POs 42 and 57, PO 42's Related Documents tab shows the invoice; **PO 57's shows nothing** — the linkage exists only in `payload->supplier_invoice.source_document_ids`, which no chain code reads. The spec's own §3.2 UI bullet adds "BC liés" chips on the *invoice* page but says nothing about the PO side or the chain endpoint. An AP clerk auditing PO 57 will conclude it is uninvoiced.

**Fix (spec text):** Wave 8 scope must include either (a) teaching `getDocumentChain()`/`childDocuments`-based descendants to also union documents whose `payload->supplier_invoice.source_document_ids` contains the current id (cheap with the same expression-index trick as §1.3), or (b) an explicit accepted-degradation note + a PO-detail "invoices" source that queries the payload array. Also specify what "first PO" means deterministically (request array order? lowest document_number?) — currently unstated.

---

## R3D-5 · MED · §3.2/Wave 8 — the same-supplier/currency guard lives ONLY in the create FormRequest; matcher and posting never verify partner or currency

**Spec claim:** "same-supplier/currency/company guard replaces the single-PO rule" (S4, §3.2), with the Wave 8 exit test "cross-supplier rejected 422".

**Code evidence:** the only partner/currency-vs-PO validation in the entire pipeline is `CreateSupplierInvoiceRequest.php:126-141` (`withValidator`). Downstream, the matcher validates a referenced PO line's parent by **type + company only** — `SupplierInvoiceMatcher.php:335-341` (`$parentDoc->type !== DocumentType::PurchaseOrder || $parentDoc->company_id !== $supplierInvoice->company_id`) — and the posting service's lock query filters by company only (`SupplierInvoicePostingService.php:70-75`). Neither ever checks `partner_id` or `currency`.

Today this is *not* exploitable via API: supplier invoices have no update endpoint (`Procurement/Presentation/routes.php:30-55` — store/match/post only) and the generic document-update path denies `SupplierInvoice` (`DocumentPolicy.php:88-98`, falls to `default => false`). But Wave 8 turns "one guard at one boundary" into the sole enforcement of a cross-document invariant that the spec's own exit test treats as a posting-level guarantee — and the near-term OCR-prefill project (§3.5 "the seam the OCR project will later prefill from") is exactly the kind of second write path that bypasses a FormRequest-only rule.

**Fix (spec text):** add one sentence to §3.2/Wave 8: `assertPostable` (or the Wave 5 receipt-line matcher) additionally asserts every consumed PO's `partner_id === invoice.partner_id` and `currency === invoice.currency` — defense-in-depth, cheap (the parent doc is already loaded at `SupplierInvoiceMatcher.php:333`). Add a posting-level cross-supplier test, not only a 422-on-create test.

---

## R3D-6 · MED · §1.5 — the group comparison view has no sibling line-correlation key; per-sibling edits make "one row per line" undefined

**Spec claim (§1.5):** comparison view = "one column per supplier, one row per line; responded unit prices side by side with best-price-per-line highlighting."

**Attack:** fan-out creates N siblings with identical lines, but §1.4 exposes `PUT /purchase-quote-requests/{id}` as "edit lines / record response" **per sibling**. Nothing links sibling lines to each other: `document_lines.source_line_id` is a conversion-lineage self-FK (`2025_12_11_194522`), null for freshly created RFQ lines, and the payload schema in §1.3 has no line-level key. After supplier B's RFQ gets a line added/removed or a product swapped, "one row per line" has no join key — matching rows by `product_id` breaks on duplicate products/free-text lines, and by `line_number` breaks on any insertion. The best-price highlight would silently compare wrong rows.

**Fix (spec text):** stamp a shared correlation key at fan-out (e.g. `document_lines.payload`-less option: reuse `source_line_id` pointing at the *first* sibling's line, or a `payload->rfq.line_key` uuid per line copied to all siblings) and define comparison behavior for diverged siblings (unmatched rows render in their own row with empty cells). Also state that `payload->rfq.group_id` is **immutable after creation** — §1.4's update endpoint otherwise allows repointing a document into a foreign group, which corrupts award-guard scope.

---

## R3D-7 · LOW · §1.4 fan-out — sequence behavior under a single N-document transaction (verified mostly safe; two properties the spec should state)

Verified: `DocumentNumberingService::generateNumber` (`DocumentNumberingService.php:18-49`) runs `DB::transaction` + `lockForUpdate()` on the `(company, type, year)` sequence row (:20,:28). Called N times inside the fan-out's outer transaction, the inner `DB::transaction`s become savepoints and the **row lock is held until the outer commit** — so (a) concurrent RFQ fan-outs fully serialize on the `purchase_rfq` sequence row for the whole fan-out (acceptable: it contends only with other RFQ creation; sequences are per-type since `2025_11_30_140002` unique `(company_id, type, year)`), and (b) a failure on sibling k rolls back all numbers — **no gaps, no partial groups** (good; worth an explicit §1.9 assertion). One pre-existing sharp edge amplified N×: the first-ever `purchase_rfq` sequence row is created via bare `create` (:33) — two concurrent *first* fan-outs race to a unique violation that aborts the whole fan-out with a 500-shaped QueryException rather than a retry. Spec should note fan-out create can surface this on day one of the feature (fix is a `firstOrCreate`+retry inside the numbering service or catching the violation — one line to mention).

---

## R3D-8 · LOW · §1.3/§1.8(3b) — the expression-index migration is valid PG but must be driver-gated per the house pattern

Verified the syntax targets real columns: `documents.tenant_id` (`2025_11_30_080000_create_documents_table.php:15`) and `payload` jsonb (:34) both exist, so `CREATE INDEX … ON documents (tenant_id, ((payload->'rfq'->>'group_id'))) WHERE type = 'purchase_rfq'` is valid PostgreSQL. But tenant migrations also run on SQLite locally — the sibling procurement migration documents the pattern explicitly ("the SQLite path (local tests) creates the table without them — that is intentional", `2026_06_25_100000_create_procurement_policies_table.php` docblock, pgsql gate at :49-72). §1.8 step 3b should state the index `DB::statement` is wrapped in the same `if (DB::connection()->getDriverName() === 'pgsql')` gate (SQLite's `->>`/partial-expression-index support is version-dependent and the index is a PG query-plan concern anyway). Group-view queries must also carry `whereNull('deleted_at')` (documents soft-delete, :37) — the partial index remains usable either way.

---

## R3D-9 · LOW · §Procurement Presets — endpoint and scoping wording don't match reality (constraints themselves verified safe)

- "**existing policy update path**": there is none. The Procurement module exposes exactly five supplier-invoice routes (`Procurement/Presentation/routes.php:30-55`); a repo-wide grep finds **no** procurement-policies controller, route, or seeder — policies are written only at tenant provisioning (`TenantProvisioningService.php:159-170`, `firstOrCreate` per company). Wave 9 therefore builds the settings **read** endpoint too (the three-card picker + "avancé" raw fields need `GET` current policy), not just the `PUT` — the spec's "(existing policy update path or a thin new endpoint)" hedge under-scopes this.
- Scoping: `procurement_policies` is strictly **per company** (`uq_procurement_policies_company`, migration :46). "Seeder applies per tenant/vertical" must be worded as "per company, defaulted by the tenant's vertical" — a multi-company tenant needs N rows, and the settings `PUT` must resolve the acting company (CompanyContext), which the spec doesn't say.
- Verified safe (attacked, no finding): `chk_pp_match_mode` explicitly allows `'two_way'` (`2026_06_25_100000:55-56`), so Léger violates no CHECK; the new `preset varchar(20) NULL` column collides with no constraint; `ProcurementPolicyResolver.php:43-50` throws only on `bill_control_mode=ordered`, which no preset sets — presets cannot trip the Phase-1 guard.

---

## R3D-10 · LOW · §0/§1.1 stale citation — `DocumentType` now has an 11th case (`Income`) the spec's "verified" cite omits

§0 and §1.1 cite "`DocumentType` cases (`DocumentType.php:9-18`)" listing 10 cases ending at `SupplierCreditNote`. The enum on dev now has `case Income = 'income';` at `DocumentType.php:19` (treasury work, post-Rev-2). Cosmetic, but Wave 1 edits this exact enum and its exhaustive `getPrefix()`/`label()` matches (`:26-38,:46-59` — no `default`, so a new case is compiler/PHPStan-forced, good), and the spec's authority rests on "every claim verified at cited file:line". Also verified while here: proposed prefix `DP` collides with none of QT/SO/PO/INV/CN/DN/RN/EXP/SI/SCN/INC.

---

## R3D-11 · LOW · Wave 8 — multi-PO posting widens an unordered `lockForUpdate` set; add deterministic lock ordering

`SupplierInvoicePostingService.php:70-75` locks the invoice's PO lines with `whereIn(...)->lockForUpdate()->get()` — **no ORDER BY**, so PostgreSQL acquires row locks in scan order. Today two concurrent posts rarely share more than one PO's lines; after Wave 8 an invoice's lock set spans multiple POs, so two invoices over overlapping PO sets can acquire the same rows in opposite orders → deadlock (retry-able but user-visible 500 today). One-line spec fix for Wave 8: `->orderBy('id')` on the lock query (and on the future receipt-line lock query in Wave 5's successor path).

---

## Verified-TRUE Rev 3 claims (attacked, held up — recorded so implementation doesn't re-litigate)

| Claim | Evidence |
|---|---|
| "Matcher is PO-count-agnostic" — the matcher NEVER reads `documents.source_document_id`; it keys purely on line-level `source_line_id`, validating each parent by type+company | whole-file read of `SupplierInvoiceMatcher.php` (grouping :271-435, parent check :331-341); posting likewise iterates lines (`SupplierInvoicePostingService.php:62-75,96-176`); `CreateSupplierInvoiceService.php:109` is the single header write. The phrase "already … at receipt-line grain" is temporally sloppy (the receipt-line matcher is this spec's own Wave 5), but the substance holds at both grains. |
| First-PO compat column keeps the treasury payment guard safe | `f7fcae4a4` guard is keyed on `(source_type='supplier_invoice', source_id=invoice)` + a SupplierPayable credit line with `partner_id = invoice.partner_id` — invoice-keyed and PO-agnostic; same-supplier rule keeps the partner unique. |
| `ExpenseController::linkableInvoices`' `whereNotNull('source_document_id')` predicate survives multi-PO | `ExpenseController.php:271-283` — first-PO compat keeps the column non-null. |
| Posting "needs no multi-PO awareness beyond iterating the lines" (at header level) | `accruedHt` is a pure per-line sum (:114-155); B3 guard per line (:140-152); idempotency keyed on the invoice (:78-83); `balance_due`/status invoice-level (:239-242). (Subject to R3D-5/R3D-11 hardening.) |
| §1.12 supplier-mandatory row's premise | `2026_06_27_110000_make_documents_partner_id_nullable.php` exists on dev; FormRequest-level `required` per sibling is the right re-tightening. |
| `CreateSupplierInvoiceRequest` single-PO contract as described in M8/S4 | `CreateSupplierInvoiceRequest.php:55-59` (single required uuid), `:113-123` (must be PurchaseOrder), `:126-141` (partner+currency vs PO), `:143-162` (every line in that PO). |
| Sequence type `'purchase_rfq'` fits, one sequence per type/year/company | `2025_11_30_080002:16` `string('type',20)`; per-company unique since `2025_11_30_140002`. |
| Rev 3 deferral consistency (S5 receipt-first Phase 2 / S6 invoice-first aside) | Swept the whole doc: §2.10, §2.11, §Presets Léger note, and Wave list all say Phase 2 for receipt-first; no wave ships it; `goods_receipts.purchase_order_id` stays NOT NULL (§2.3/§2.11). Invoice-first: §3.6 stays principle-only; no preset touches `bill_control_mode`, and `ProcurementPolicyResolver.php:43-50` still throws on `ordered` — nothing in Rev 3 can reach invoice-first by accident. The only deferral-adjacent inconsistency found is §3.2's `MatchMode::TwoWay` conflation, covered in R3D-3. |
