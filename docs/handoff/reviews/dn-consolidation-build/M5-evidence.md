# M5 — WHOLE-BRANCH GATE evidence (no new implementation)

**Wave:** `dn-consolidation-build` · **Branch:** `codex/dn-consolidation-2026-08-12`
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/dn-consolidation`
**Pinned base:** `60df88a01b52828665caf33809486bdf0a699bbc`
**M4 verdict carried in:** `docs/handoff/reviews/dn-consolidation-build/M4-round3.md` (ACCEPT)
**M5 opening commit:** `cd62067fd` (Phase 2.5.0 — record-only: R3-a / R3-b / R3-c)
**Evidence tip:** see §8.

M5 adds no implementation. Everything below was re-run or re-derived at this tree; nothing is quoted
from an earlier milestone's handback.

---

## 1. §6 verification contract — item by item

### 1.1 Preflight, both PATH variables set (never `PREFLIGHT_SCOPE=full`)

The §6 invocation was run **whole** first. It stops at the PHPStan gate (`scripts/preflight.sh:2`
is `set -e`), because the whole-repository PHPStan run reproduces the two **pinned inherited** C-3
residuals. Under the 2026-08-19 owner gate amendment that is a *pass* (touched files green,
whole-repo failure set not larger than base) but it is still a non-zero exit, so the run was then
repeated with the PHPStan gate scoped to the branch's own 22 touched `app/` paths in order to drive
the rest of the pipeline. Both invocations are recorded; neither narrows the **PHPUnit** or
**Vitest** path sets, which are the §6 registry verbatim.

**Invocation A — §6 registry verbatim (whole-repo PHPStan):**

```bash
PREFLIGHT_TEST_PATHS='tests/Feature/Document tests/Unit/Document tests/Feature/Partner tests/Feature/Accounting/LaneSeparationReportTest.php tests/PHPStan' \
PREFLIGHT_VITEST_PATHS='src/features/documents src/features/partners src/features/finance src/routes src/components/organisms/Sidebar' \
  ./scripts/preflight.sh
```

| Gate | Result |
|---|---|
| Pint (whole repo) | `{"result":"pass"}` — **green** |
| PHPStan level 8 (whole repo, 3013 files) | **2 errors**, both `precision.hardcodedBcmathScale`, both at `app/Modules/Document/Domain/Services/Conversion/Concerns/CopiesDocumentData.php:309` and `:310`, reported *in the context of class* `…Converters\PurchaseQuoteRequestToPurchaseOrderConverter`. These are pin entries 1 and 2 verbatim. Run aborted here (`set -e`). |

**Invocation B — same registry, PHPStan scoped to the branch's touched `app/` paths (22 files):**

```bash
PREFLIGHT_PHPSTAN_PATHS='<the 22 paths from `git diff --name-only 60df88a01..HEAD -- apps/api/app/*`>' \
PREFLIGHT_TEST_PATHS='tests/Feature/Document tests/Unit/Document tests/Feature/Partner tests/Feature/Accounting/LaneSeparationReportTest.php tests/PHPStan' \
PREFLIGHT_VITEST_PATHS='src/features/documents src/features/partners src/features/finance src/routes src/components/organisms/Sidebar' \
  ./scripts/preflight.sh
```

| Gate | Result |
|---|---|
| Pint | **green** |
| PHPStan level 8, 22 touched paths | **[OK] No errors** |
| PHPUnit, §6.1 registry by path | **1072 passed, 32 skipped, 2 failed** (4570 assertions, 778.62s) — the 2 failures are the inherited default-SQLite `InventoryGlCompositeRootTest` fixture reds, see §1.2 |

**Invocation C — registry minus `tests/Feature/Document` (the directory holding the two inherited
reds), run only so the pipeline proceeds past PHPUnit into the frontend gates:**

| Gate | Result |
|---|---|
| PHPUnit (`tests/Unit/Document tests/Feature/Partner tests/Feature/Accounting/LaneSeparationReportTest.php tests/PHPStan`) | **407 passed, 10 skipped** (1664 assertions) |
| `typescript:transform` + `generated.d.ts` drift guard | **drift** — 2 insertions / 1 deletion, exactly pin entry 7 (`SystemAccountPurpose` gains `inventory_shrinkage_expense` + `inventory_gain_income`; new `MovementGlKind`). Inherited 3C lane artefact; the working tree was restored with `git checkout -- packages/shared/types/generated.d.ts` and left clean. Run aborted here. |

The remaining preflight gates were then executed directly, in preflight's own order and with
preflight's own commands (§1.3).

### 1.2 §6.1 backend tests by path — and the PostgreSQL control

`tests/Feature/Document` (69 files) · `tests/Unit/Document` (20) · `tests/Feature/Partner` (20) ·
`tests/Feature/Accounting/LaneSeparationReportTest.php` · `tests/PHPStan` (5) — **all run**, totals in
§1.1 invocation B.

Named §6.1 rows observed green in that run include
`Tests\PHPStan\DeliveryNoteBillingWritesOnlyViaClaimServiceTest` — **6/6**, including
*"rule exists and is registered"*, *"reports each enumerated literal write form"*,
*"stays silent inside claim service and on unrelated model"*, *"reports auxiliary external literal
claim set issuance"*, *"reports literal delivery note payload raw sql without marker table"* — and the
lane-separation regression (*"viewing the report creates no journal entry"*, *"it reports the resolved
policy in force"*).

**The 2 failures**, both `Tests\Feature\Document\InventoryGlCompositeRootTest` at
`InventoryGlCompositeRootTest.php:76`:
`SQLSTATE[HY000]: General error: 1 no such table: tenants (Connection: sqlite, Database: :memory:)`.
This is a fixture-only default-SQLite red inherited from the merged 3C lane (pin entry 4). Proven so
by the PostgreSQL control on the **dedicated scratch database** (§1.4):

```
php artisan test -c phpunit-pgsql.xml tests/Feature/Document/InventoryGlCompositeRootTest.php
  → Tests: 2 passed (19 assertions), Duration 17.39s   EXIT=0
```

Pin entry 3 (`CompleteSalesCycleWithReturnTest`) again did **not** reproduce as a failure: it is
**skipped** with *"Complete movement-keyed sales-cycle coverage requires PostgreSQL root
transactions."* — consistent with the pin's own wording.

### 1.3 Remaining preflight gates, run directly

| Gate | Command | Result |
|---|---|---|
| Frontend permission map drift | `php artisan permissions:export-frontend-map` + `git diff` | **in sync** (no diff) |
| TypeScript | `pnpm typecheck` | **clean** (exit 0) |
| ESLint | `pnpm lint:eslint` | **0 errors, 6459 warnings** |
| TanStack key audit | `pnpm audit:keys` | Gate C **0**; baseline **0 acknowledged, 0 new, 0 stale** |
| Design-system audit | `pnpm audit:design-system` | **728 acknowledged, 0 new, 0 stale** |
| Quantity-display audit | `pnpm audit:quantity` | **0 total, 0 baselined, 0 new, 0 stale** |
| POS ESLint rule tests | `pnpm --dir apps/pos test:eslint-rules` | **pass** — `no-hardcoded-step` 6 valid/3 invalid; `no-raw-quantity-input` 6 valid/1 invalid |
| Route manifest drift | `scripts/factory/check-manifest-drift.sh` | **drift, inherited and lane-clean** — see §5 R5 |
| Fiscal v3 fixture parity | `apps/pos/scripts/check-fiscal-fixture-parity.sh` | **2 files / 29 tests passed** |
| §14.3 chokepoint completeness | `apps/api/scripts/check-saleReceipt-chokepoints.sh` | **1 UNRECONCILED, inherited** — `InventoryCountingController.php:135`; manifest `receiver_type` validator **PASS (6 entries)**; see §5 R6 |

### 1.4 §6.2 — `DeliveryNoteConsolidationConcurrencyTest`, PostgreSQL

The test hard-requires PostgreSQL — six guards read *"Billing-claim concurrency policy requires
PostgreSQL."* (`:165`, `:211`, `:257`, `:281`, `:326`, `:373`), and the two-process proof adds
*"Two-process billing-claim contention requires PostgreSQL."* (`:1084`) plus a `pcntl` guard
(`:1087`). It is therefore **skipped** in the default-SQLite registry run and must be run under
`phpunit-pgsql.xml`.

A **dedicated scratch database** was created for every M5 PostgreSQL run — the shared `autoerp_test`
was never touched:

```bash
psql -h 127.0.0.1 -p 5433 -U autoerp -d postgres -c "CREATE DATABASE autoerp_dn_m5 OWNER autoerp;"

cd apps/api && DB_HOST=127.0.0.1 DB_PORT=5433 DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret \
  DB_DATABASE=autoerp_dn_m5 DB_CENTRAL_DATABASE=autoerp_dn_m5 \
  php artisan test -c phpunit-pgsql.xml tests/Feature/Document/DeliveryNoteConsolidationConcurrencyTest.php
```

**Result: 12 passed (148 assertions), 40.33s, EXIT=0.** All twelve:

| # | Test |
|---|---|
| 1 | real query pdo nested and deadlock exception shapes are retried at most twice |
| 2 | commit time serialization failure retries in a fresh transaction |
| 3 | unrelated real database error is not retried or translated |
| 4 | sales order commit exhaustion preserves infrastructure error without phantom 422 |
| 5 | delivery note commit exhaustion preserves infrastructure error without phantom 422 |
| 6 | later pre resolution retries do not reuse a rolled back delivery note identity |
| 7 | overlapping consolidations serialize at the seeded sequence barrier |
| 8 | consolidation wins against the sales order lane without deadlock |
| 9 | sales order wins against consolidation without deadlock |
| 10 | same order auto create path serializes without extra draft or number |
| 11 | failed backend pid publication is bounded and reaps the owned child |
| 12 | integrity regression net detects both marker payload disagreement directions |

### 1.5 §6.3 frontend tests

Per the parent's instruction the exact §6.3 path sweep was run single-worker (the M4-round2 N4 record
identifies `PartnerForm.test.tsx:356` as a **default-pool** contention flake):

```bash
cd apps/web && pnpm vitest run --maxWorkers=1 \
  src/features/documents src/features/partners src/features/finance src/routes src/components/organisms/Sidebar
```

**Test Files 2 failed | 91 passed (93) · Tests 2 failed | 725 passed (727).**

The two failures are exactly the pinned inherited `ui-wave0 M0b`-owned finance reds:
`src/features/finance/api.test.ts` (*"fetches upcoming payments from the C3 endpoint without
double-unwrapping"*) and `src/features/finance/hooks/__tests__/tenantScope.test.tsx`
(*"wraps finance report query keys and gates missing tenant/company"*). Pin entries 5 and 6.
**`PartnerForm.test.tsx:356` did not reproduce**, consistent with the N4 record.

### 1.6 UI E2E

See §7.

---

## 2. LOCK INVENTORY AS BUILT — re-derived from code

Derived at this tree by reading the writers, not by copying the spec. Sources: every writer of
`payload.invoiced_at` / `invoice_id` / `invoiced_via`, every caller of `DocumentNumberingService`,
every `appendToSourcePayload` call site, every `lockForUpdate()` in the Document module, and every
nested `DB::transaction` on the claim paths.

### 2.1 The three claim-bearing writers — there are exactly three

`grep -rl 'DeliveryNoteBillingClaim\|BillingClaim' apps/api/app` returns five files; two are the
service itself and its PHPStan rule. The **three production callers** are:

| Lane | Entry point | `invoiced_via` |
|---|---|---|
| Consolidation (Views A/B, `POST /delivery-notes/consolidate-to-invoice`) | `DeliveryNoteToInvoiceConverter.php:134` | `consolidation` |
| Sales-order conversion | `SalesOrderToInvoiceConverter.php:165` | `order_conversion` |
| C-5 guided pre-post delivery | `InvoiceController.php:1017` | `pre_post_delivery` |

The fourth enum case `legacy_unknown` (`DeliveryNoteBillingLane.php:12`) is **write-once historical**
and is refused at the runtime boundary: `DeliveryNoteClaimRequest.php:35-37` throws
`InvalidDeliveryNoteClaimRequestException('legacy_unknown is reserved for migration backfill.')`.

### 2.2 Lock kinds and acquisition points

| # | Lock kind | Acquired at | Held until | Notes |
|---|---|---|---|---|
| L0 | Transaction boundary + server-side waits | `DeliveryNoteBillingConcurrencyRetrier.php:40`, then `:42-43` `SET LOCAL lock_timeout='5s'` / `SET LOCAL statement_timeout='30s'` | commit/rollback | One outer transaction per attempt; at most `MAX_RETRIES = 2` retries (`:24`), retryable **only** on SQLSTATE `40P01`/`40001` (`:72`). A third retryable failure becomes the attributed `DeliveryNoteAlreadyClaimedException` (`:53-55`). |
| L1 | Sales-order **header** row, `SELECT … FOR UPDATE` | `SalesOrderToInvoiceConverter.php:171-178` — Layer 0b step 2, **immediately after BEGIN**, before scenario detection | commit | SO lane only. Serialises two concurrent conversions of the *same* order before either can take L2. |
| L2 | `document_sequences[company, delivery_note, year]` row, `lockForUpdate()` | `DocumentNumberingService.php:50` (inside its own `DB::transaction`, `:44` — a **savepoint** frame when the caller already holds one), reached from `DeliveryNoteFromDocumentFactory::createDraftFrom` (`:63`, numbering call at `:84`), itself called from `SalesOrderToInvoiceConverter::createDeliveryNoteForOrder` (`:763`) on the auto-create branch (`:204`) | commit of the **outer** transaction | The factory opens **no** transaction of its own (no `DB::transaction` in `DeliveryNoteFromDocumentFactory.php`), which is precisely why its DN number, its rows, its `quantity_delivered` stamps (`:185`) and the order-payload linkage (`SalesOrderToInvoiceConverter.php:770-776`) now share the conversion's rollback boundary — the OI-14 correction, and its throughput consequence: DN numbering serialises to commit. The separate standalone SO→DN converter (`SalesOrderToDeliveryNoteConverter.php:162` / `:219`, via `CopiesDocumentData::createTargetDocument` `:52`, numbering at `:76-80`) takes the same L2 lock from its own root transaction; it is not on this lane's path. |
| L3 | Delivery-note `documents` rows, `orderBy('id')->lockForUpdate()` | Consolidation: `DeliveryNoteToInvoiceConverter.php:381-382`. SO: `SalesOrderToInvoiceConverter.php:644-646` (`lockCompleteDeliveryNoteSet`, called at `:211`) | commit | **Ascending `id`** on both lanes. Both lanes also `sort($deliveryNoteIds, SORT_STRING)` before querying (`:374` / `:622`). |
| L4 | Per-DN row-write lock on `documents` + unique insert on `delivery_note_billing_marks` | `DeliveryNoteBillingClaimService::reserve()` — conditional `UPDATE` at `:65-76`, marker `INSERT` at `:83-89`, iterating `sort($deliveryNoteIds, SORT_STRING)` (`:58`) | commit | `delivery_note_id` is the marker table **primary key** (`…create_delivery_note_billing_marks_table.php:42`), so a competing insert blocks on the same key until the holder commits or rolls back. |
| L5 | `document_sequences[company, invoice, year]` row, `lockForUpdate()` | `DocumentNumberingService.php:50`, reached from the claim **closure** via `createTargetDocument` | commit | Consolidation: `DeliveryNoteToInvoiceConverter.php:236` / `:298`. SO: `SalesOrderToInvoiceConverter.php:237`. |

C-5 (`InvoiceController.php:921-1024`) takes a different, shorter chain: the **invoice** `documents`
row `lockForUpdate()` first (`:935-938`, explicitly "🚨 THE LOCK, FIRST"), then L2 through the
delivery-note factory (`:984`), then confirm (`:1008`), then `post()` (`:1011`), then the claim
(`:1017-1024`) whose closure only returns the **already-existing** invoice id.

### 2.3 Acyclicity

Every writer acquires a strictly increasing subsequence of one global order:

```
L1 sales-order header row
  <  L2 document_sequences[delivery_note]
    <  L3 delivery-note documents rows, ASCENDING id
      <  L4 delivery_note_billing_marks rows, SAME ascending DN id
        <  L5 document_sequences[invoice]
```

- The **consolidation** lane enters at L3 and never takes L1 or L2 (it converts existing, confirmed
  DNs and creates no delivery note). Its sequence is L3 → L4 → L5.
- The **SO** lane takes L1 → (L2, only on the auto-create branch) → L3 → L4 → L5, in that source
  order: the header lock at `:171-178` precedes the auto-create branch at `:204`, which precedes
  `lockCompleteDeliveryNoteSet` at `:211`, which precedes `claim` at `:290`.
- Within L3 and L4 both lanes are ordered by ascending `id` — the same total order — so two
  overlapping claim sets can only ever wait, never cycle.
- The **C-5** lane takes an *invoice* `documents` row, then L2, then a DN row it created itself in the
  same transaction (uncontended), then L4. It never takes L1 (it never touches a sales order) and
  never takes L5 (its invoice is already numbered), so it cannot close a cycle with either other lane:
  the only lock it shares with them is L2/L4, on which it is a pure waiter.
- No lane ever takes a lock at a lower level after one at a higher level. There is no back edge, so
  the wait-for graph is acyclic and no deadlock is reachable by construction. The three concurrency
  tests that exercise the cross-lane cases (§1.4 rows 8, 9, 10) are green on real PostgreSQL with two
  real backends, and the `lock_timeout`/`statement_timeout` of L0 bound any wait that a *future*
  writer might introduce outside this order.

### 2.4 No writer reaches invoice creation before its claims succeed

**Stated explicitly: on this branch, no writer creates an invoice — or consumes an invoice number —
before every one of its delivery-note claims has succeeded.** The mechanism, in code:

1. `DeliveryNoteBillingClaimService::claim()` (`:40-53`) is the **only public entry point**.
   `reserve()` (`:55`) and `finalise()` (`:107`) are `protected` — not public — so no caller can
   reserve without finalising or finalise without reserving.
2. `claim()` refuses to run outside a caller-owned transaction:
   `if ($this->db->transactionLevel() < 1) throw new DeliveryNoteClaimRequiresTransactionException` (`:44-46`).
3. The body is ordered `reserve → $createInvoice($set) → finalise` (`:48-50`). **Invoice creation is
   the closure argument**, so it cannot run before the reservation returns. Every reservation failure
   throws (`DeliveryNoteAlreadyClaimedException` at `:79` for the payload guard, `:95` for the marker
   collision) *before* the closure is ever invoked.
4. Both invoice-creating lanes pass invoice creation **as that closure** and nothing else:
   `DeliveryNoteToInvoiceConverter.php:235-255` and `:292-345`; `SalesOrderToInvoiceConverter.php:229-285`
   passed at `:296`. `createTargetDocument` — the only path to `DocumentNumberingService` on these
   lanes — is inside the closure (`:236`, `:298`, `:237`), so **L5 is never taken on a losing claim**.
5. The reservation is a conditional write, not a read-then-write:
   `UPDATE documents SET payload = … WHERE id = ? AND type = ? AND company_id = ? AND (payload->>'invoiced_at') IS NULL`
   (`:66-68`), with `if ($affected !== 1) throw` (`:78-80`). A loser observes `0` rows and refuses.
6. `finalise()` is **count-guarded to exactly N on both surfaces**: the marker update must affect
   `$set->count()` rows (`:112-119`, `DeliveryNoteClaimNotFinalisedException::forMarkerCount`) **and**
   the payload projection update must affect `$set->count()` rows (`:122-137`,
   `::forPayloadCount`). Either mismatch throws inside the caller's transaction and rolls everything
   back.
7. The C-5 lane is the one case where the claim runs *after* a `post()`. It is **not** a
   counter-example: that lane creates **no invoice and consumes no invoice number** — the invoice
   pre-exists the request and `DocumentPostingService` never calls `DocumentNumberingService` (no hit
   for `generateNumber` in that file). Its claim, its DN creation, its confirm and its post all share
   the one root transaction opened at `InvoiceController.php:921`, so a losing claim rolls the post
   back with it.
8. Outside the claim service there is **no other writer of the billing triple in `app/`**: a repo-wide
   grep for `invoiced_at` / `invoiced_via` returns only the service, the DTOs
   (`DeliveryNoteBillingState.php`, `DocumentData.php`), the read-side scopes
   (`Document.php:653`, `:664`), read-only guards in the two converters, and the PHPStan rule. The
   only other writer anywhere is the migration backfill
   (`…create_delivery_note_billing_marks_table.php`), which is outside `app/` and therefore outside
   PHPStan's view — a limitation the rule's own docblock states rather than overstates
   (`DeliveryNoteBillingWritesOnlyViaClaimService.php:27-46`: *"deliberately a bounded lint, not proof
   that every possible write is blocked… A green build makes no claim beyond those explicitly covered
   forms."*). The rule **is** registered (`apps/api/phpstan.neon`, the single added line) and its
   registration is itself asserted by a green test (*"rule exists and is registered"*).

---

## 3. OI-14 — the four vanishing artefacts, proven by test

Test: `apps/api/tests/Feature/Document/SalesOrderBillingClaimTest.php:184`
`test_auto_created_delivery_note_and_all_factory_side_effects_roll_back_on_claim_loss_then_reentry_succeeds`
— green in §1.1 invocation B.

Method: the test swaps in an anonymous `DeliveryNoteBillingClaimService` subclass whose `claim()`
throws `DeliveryNoteAlreadyClaimedException` unconditionally (`:201-213`), then drives the **production
entry point** `registry()->convert($order, DocumentType::Invoice)` (`:217`) and asserts the conversion
aborted (`:218-221`).

| OI-14 artefact | Assertion | Line |
|---|---|---|
| (i) no committed orphan **draft DN** | `assertSame(0, Document::query()->where('type', DocumentType::DeliveryNote)->count())` | `:223` |
| (ii) no **consumed sequence number** | `assertSame($deliverySequenceBefore, $this->sequenceNumber(DocumentType::DeliveryNote))` **and** `assertSame($invoiceSequenceBefore, $this->sequenceNumber(DocumentType::Invoice))` | `:224`, `:225` |
| (iii) no `payload['delivery_note_ids']` entry | `assertSame($payloadBefore, $order->fresh()->payload)` (whole-payload equality, so it also covers `fully_invoiced` / `fully_invoiced_at`) | `:226` |
| (iv) no `quantity_delivered` stamp | `assertSame('1.2500', (string) $line->fresh()->quantity_delivered)` — unchanged from the pre-attempt value | `:227` |
| **the order no longer reports itself fully delivered after a failure** | `assertSame($statusBefore, $order->fresh()->getDeliveryStatus())`, where `:198` pins `$statusBefore === DeliveryStatus::PartiallyDelivered` | `:228` |

The same test additionally pins the **`DocumentConverted` regression from the production entry point**:
the `stored_events` count for `DocumentConverted::class` and the `audit_events` count for
`document.converted` are captured before (`:192-197`) and asserted unchanged after the failure
(`:229-236`) — i.e. the event does not escape the rolled-back transaction.

Re-entry is proven in the same test rather than assumed: after restoring the real service (`:238-239`)
the conversion succeeds, the line reaches `'3.0000'` / `FullyDelivered` (`:244-245`), the DN payload
carries the invoice id and `order_conversion` (`:246-247`), and a marker row exists with both
(`:248-252`).

---

## 4. OI-8 — ratified conditions 1–4 (evidence) and proposals 5–7 (status)

### 4.1 Conditions 1–4 — RATIFIED, built, evidenced

| # | Condition | Evidence |
|---|---|---|
| 1 | **Persistent inline error, never a toast** | Three surfaces render a persistent `role="alert"` region held in component state, not a notification: SO lane `SalesOrderDetailPage.tsx:152` (`useState<BillingRefusal>`) → `:434-437` (`role="alert"`, `aria-labelledby="billing-refusal-title"`); consolidation lane `PartnerDeliveryNotesTab.tsx:39` → `:207-213`; View B `ToBillPage.tsx:311` → `:67` (`role="alert"`), `:503-505`. Toast suppression for the attributed 422 is explicit: `useDeliveryNotes.ts` `onError` fires a toast **only** when `parseDeliveryNoteBillingRefusal(error) === null`. |
| 2 | **Every lost document named with its taker** | Each row renders the lost DN's `document_number`, the taking invoice's **date** and the lane label, and **links** to the invoice: `ToBillPage.tsx:100` (number), `:103` (`document.invoice_date` · lane label), `:106-120` (`<Link to={entityRoutes.document(document.invoice_id, {documentType:'invoice'})}>` rendering `openInvoice` at `:115`). Same shape at `PartnerDeliveryNotesTab.tsx:219-258` and `SalesOrderDetailPage.tsx:450-470`. The UI iterates `refusal.documents` in full — it is not a count. Lane labels are human strings, not enum values (§4.2). |
| 3 | **The guarantee in words, en + fr, via `t()`** | Rendered at `SalesOrderDetailPage.tsx:447`, `PartnerDeliveryNotesTab.tsx:216`, `ToBillPage.tsx:78`. Keys exist in **both** locales: `en/sales.json` `orders.billingRefusal.guarantee` = *"No invoice was created. No invoice number was used."* and `deliveryNotes.consolidation.billingRefusal.guarantee` (identical text); `fr/sales.json` both = *"Aucune facture n'a été créée. Aucun numéro de facture n'a été utilisé."* |
| 4 | **No bare Retry on the SO lane; "Remove these N and retry" on the consolidation lane** | SO lane offers **`openInvoice`** (*"Open {{number}}"* / *"Ouvrir {{number}}"*, `SalesOrderDetailPage.tsx:468`) and **`invoiceRemaining`** (*"Invoice remaining lines…"* / *"Facturer les lignes restantes…"*, `:478`) which opens the existing partial-conversion picker (`:707` `remainingTitle`, `:734` `confirmRemaining`) with billed lines excluded (`:322` `billed_order_line_ids`, `:324` `canDetermineRemainingLines`). When nothing remains it says so plainly (`:484` `noRemaining`) and when the remainder is not safely derivable it says that instead (`:489` `remainingUnavailable`) — no invented state mutation on the order. The consolidation lane keeps the specced wording verbatim: `removeAndRetry` = *"Remove these {{count}} and retry"* / *"Retirer ces {{count}} éléments et réessayer"* (`PartnerDeliveryNotesTab.tsx:265` gates the button on `selectedIds.size > 0`, `:270` is the `onClick`, `:273-275` the label; `ToBillPage.tsx:130` gates the button on `remainingCount > 0` computed at `:63`, `:135` is the `onClick`, `:138` the label), resubmitting only the un-refused ids (`ToBillPage.tsx:373-375`). |

Lane labels for condition 2 exist for all four enum cases plus an `unknown` fallback, in both locales:
`billedBy.consolidation` / `.order_conversion` / `.pre_post_delivery` / `.legacy_unknown` / `.unknown`
(en + fr), including the OI-9 neutral *"Billed (source not recorded)"* / *"Facturé (origine non
enregistrée)"*.

### 4.2 Proposals 5–7 — UNRATIFIED, returned to the owner/parent

These are **not** owner conditions and no evidence is claimed for them under the OI-8 heading.

| # | Proposal | Status in this build |
|---|---|---|
| **5** | Queue invalidation on a 422 | **Not built as an OI-8 condition.** An invalidation *does* ship, and it ships **because the spec requires it**: C9, `SPEC-dn-consolidation-billing-2026-08-11.md:718` (*"The replacement mutation used by Views A and B must invalidate the DN detail key, the DN list key, the partner account-balance key, and the new queue key — all `tenantScopedKey`-built"*), listed in the M2 row at `:986`. Built at `useDeliveryNotes.ts:183-204` (`Promise.all` at `:183`, the seven `invalidateQueries` at `:184-202`): seven `tenantScopedKey`-scoped predicates covering `delivery-notes`, `documents`, `invoices`, `delivery-note`, `document`, `partner-account-balance`, `delivery-notes-to-bill`. It was **not widened** to satisfy proposal 5. |
| **6** | A durable after-the-fact trace of a lost claim | **NOT designed and NOT built.** No persistence surface, payload, retention, query surface, permission contract or acceptance test for it exists anywhere in the diff. The mechanism **remains unfrozen** and is an owner gate. The whole of the surfacing obligation shipped is the attributed 422 `details.documents[]` plus the persistent inline region of condition 1. |
| **7** | No client auto-retry on a decisive claim | **No client auto-retry exists in the diff** — recorded as scope discipline, not as a satisfied owner condition. Retry is human-initiated only: the consolidation lane's `removeAndRetry` is an `onClick` handler (`PartnerDeliveryNotesTab.tsx:270`, `ToBillPage.tsx:135`) and the SO lane's remainder path requires a human to confirm the reduced line set. The only automatic retry anywhere is **server-side and bounded**: `DeliveryNoteBillingConcurrencyRetrier` at most 2 retries, PostgreSQL `40P01`/`40001` only (`:24`, `:72`). |

---

## 5. Fresh differential-preflight residual baseline — RE-DERIVED, not inherited

M4 round-1 finding 7 ("STALE PIN") asserted that pin entries 1–2 (the two C-3
`precision.hardcodedBcmathScale` findings) **do not reproduce**, and directed M5 to re-derive the
baseline rather than inherit them. Re-derived here, that finding is **wrong**, and the pin is right.

**Why the two measurements disagree — this is the load-bearing part:**

```bash
# M4's method — single file. Reproduced here verbatim:
./vendor/bin/phpstan clear-result-cache
./vendor/bin/phpstan analyse app/.../Concerns/CopiesDocumentData.php --level=8
  →  [OK] No errors

# The whole-repository run (preflight's own invocation):
./vendor/bin/phpstan analyse --level=8 --memory-limit=2G
  →  Found 2 errors
     CopiesDocumentData.php:309  bccomp() called with a hardcoded literal scale …
     CopiesDocumentData.php:310  bcdiv()  called with a hardcoded literal scale …
     (in context of class …Converters\PurchaseQuoteRequestToPurchaseOrderConverter)
```

`CopiesDocumentData` is a **trait**. PHPStan analyses trait bodies in the context of the classes that
`use` them; analysing the trait file alone gives it no consuming class, so the rule never fires. The
error header says so explicitly: *"(in context of class … PurchaseQuoteRequestToPurchaseOrderConverter)"*.
M4's `[OK]` was a **measurement artifact of single-file scope**, not evidence that the pin was
inaccurate when written. **M4 round-1 finding 7 should be recorded as refuted; the pinned entries 1–2
stand and must not be removed.**

Both entries remain **inherited**, not introduced:
`git diff --stat 60df88a01..HEAD -- .../CopiesDocumentData.php` is **empty**; the consuming class
`PurchaseQuoteRequestToPurchaseOrderConverter.php` is not among the branch's 100 changed files; the
rule `ForbidHardcodedBcmathScale` is registered at the base (the branch's only `phpstan.neon` change
is the single added `DeliveryNoteBillingWritesOnlyViaClaimService` line). No `ignoreErrors` block, no
baseline absorption, no rule removal.

### The true current whole-repository failure set on the touched surfaces

| Id | Residual, as measured at this tree | Pin status |
|---|---|---|
| R1 | PHPStan level 8, whole repo: **exactly 2** errors — `CopiesDocumentData.php:309` `precision.hardcodedBcmathScale` (`bccomp`), `:310` (`bcdiv`), in the context of `PurchaseQuoteRequestToPurchaseOrderConverter`. Nothing else. | pin entries **1 + 2** — **reproduce**, contrary to M4 finding 7 |
| R2 | Default-SQLite PHPUnit: **exactly 2** — both `InventoryGlCompositeRootTest` at `:76`, *"no such table: tenants"*. **2/2 green under `phpunit-pgsql.xml`** on `autoerp_dn_m5`. | pin entry **4** — reproduces, unchanged |
| R3 | Vitest §6.3 sweep: **exactly 2** — `features/finance/api.test.ts`, `features/finance/hooks/__tests__/tenantScope.test.tsx`. | pin entries **5 + 6** — reproduce, unchanged |
| R4 | `packages/shared/types/generated.d.ts` regenerates with **2 insertions / 1 deletion**: `SystemAccountPurpose` gains `inventory_shrinkage_expense` and `inventory_gain_income`; new `MovementGlKind = 'exit' \| 'entry' \| 'count_correction' \| 'batch_write_off'`. | pin entry **7** — reproduces, **byte-identical** to the pin's description |
| R5 | Route-manifest drift. **Lane-clean:** every drifting path is `/admin/adminRoutePolicies.*.path` (the generator cannot statically resolve those expressions), plus `/finance/lane-separation`, `/pos/receipts*`, `/inventory/stock-adjustments*`, `/settings/support-access`. `grep` over the drift for `to-bill\|delivery\|consolidat` returns **zero** hits, and this lane's own `/sales/to-bill` entry is present and correct in the committed manifest (`routes-web.yaml:806-809`). `/finance/lane-separation` is inherited: at `60df88a01` the route exists in `routes/index.tsx` (1 hit) and is **absent** from the committed manifest (0 hits) — this branch did not touch that route. | not in `expected_inherited_residuals`; recorded in `last_comparison` as an inherited route-manifest residual — **unchanged** |
| R6 | §14.3 chokepoint: **1** `UNRECONCILED` — `apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:135` `$this->countingService->finalize(`. That file is **not** among the branch's 100 changed files, so it is inherited by construction. The manifest `receiver_type` validator is **PASS (6 entries)**. | recorded in `last_comparison` as an inherited SaleReceipt-gate residual — **unchanged** |
| — | Pin entry **3** (`CompleteSalesCycleWithReturnTest`) — again **did not reproduce as a failure**; it is *skipped* (*"requires PostgreSQL root transactions"*). | matches the pin's own wording |

**Verdict against `preflight_policy`:** touched files are green (focused PHPStan `[OK]`, whole-repo
Pint green, all lane tests green, all FE static gates green) and the whole-repository failure set is
**not larger than the pinned base** — every residual above is either a pinned entry reproducing
verbatim or an inherited artefact on a file this branch never touched. **No newly introduced failure
exists.** The one correction the terminal audit must carry forward is R1: the pin is accurate and
M4's contrary finding was scope artifact.

---

## 6. Recorded owner-visible obligations (unchanged, restated for the terminal audit)

- **OI-13 / F-2** — `module:Sales` on the pre-existing `POST /delivery-notes/consolidate-to-invoice`
  (`Document/Presentation/routes.php:299`) is a **revocation**. Built. The live tenant
  module-assignment check and the grant-vs-defer choice are **owner-owned and gate promotion**.
- **OI-1a / F-3** — the existing `/inventory/delivery-notes` route keeps `moduleKey="inventory"`:
  `apps/web/src/routes/index.tsx:1241` (list) and `:1259` (detail). **Not changed**, as instructed.
- **OI-12 / F-4** — the migration's four legacy-survey counts are read from the staging
  `tenants:migrate` log by whoever promotes; the executor cannot promote.
- **OI-9** — `legacy_unknown` ships as the fourth enum case with a neutral badge in en + fr and is
  refused at the runtime claim boundary (`DeliveryNoteClaimRequest.php:35-37`).
- **D-6** — deploy sequencing (P0 migrations + seeder + `permission:cache-reset` on staging before the
  FE that depends on them) is parent/owner-owned.

---

## 7. UI E2E — **attempted and run**, blocked by named environment defects (not by this lane)

M2 recorded the E2E as *"blocked by an unavailable API proxy plus unrelated baseline locator
failures"*. **The proxy half of that blocker no longer holds at M5** and was re-checked rather than
assumed: both servers are up **and both are serving this worktree** —

```
lsof -a -p <vite pid> -d cwd  → …/.worktrees/dn-consolidation/apps/web
lsof -a -p <php  pid> -d cwd  → …/.worktrees/dn-consolidation/apps/api/public
curl -o /dev/null -w '%{http_code}' http://localhost:5173/api/v1/delivery-notes → 401   (proxy OK)
curl -o /dev/null -w '%{http_code}' http://localhost:8010/api/v1/delivery-notes → 401   (API  OK)
```

(`/api/user` returns 404 because this API mounts everything under `api/v1/` — 1082 routes in
`route:list` — so a bare `/api/*` probe is not evidence of an unavailable proxy.)

The suite was therefore actually executed:

```bash
cd apps/web && pnpm exec playwright test --reporter=line --timeout=20000 --global-timeout=1500000
```

**Result: 210 failed · 40 passed · 4 skipped · 211 did not run, of 465, in 25.0m, EXIT=1.**

### The named blockers, with the exact failures

| # | Blocker | Exact evidence |
|---|---|---|
| B1 | **Login rate-limit (HTTP 429)** under the suite's parallel logins | `Error: login as owner -> 429` — 6 occurrences (log lines 6378, 6402, 6441, 6464, 6549, 6572) |
| B2 | **Unseeded local demo tenant — units of measure** | `createProduct(...) failed: 422 {"error":{"code":"VALIDATION_ERROR","message":"The selected unit id is invalid.","errors":{"unit_id":["The selected unit id is invalid."]}}}` at `e2e/money-campaign/w2b-support.ts:80` |
| B3 | **Missing fixture data** | `MTP-TRE-56 requires the GL-less fixture repository 'W2A-NOGL-01'. Repositories present: BANK-01, …, BANK-02.` |
| B4 | **Baseline locator / landing-route drift** (the "unrelated baseline locator failures" M2 named) | `auth.spec.ts:195` `expect(page).toHaveURL('/')` — *Received: `http://localhost:5173/reports`*; i.e. **login itself succeeds**, the app simply lands on `/reports`. Also `company.spec.ts:240` — `getByRole('heading', {name: /create.*account\|sign.*up\|register/i})` **element(s) not found**. |
| B5 | **The `demo-pharmacy-tn` role users are absent locally**, so the whole `money-campaign` pack dies in `beforeEach` | every such failure is `expect(page).not.toHaveURL(/\/login/)` raised inside `loginAsRole` at `e2e/money-campaign/helpers.ts:55`, whose docblock states it logs in *"as one of the three seeded demo-pharmacy-tn roles"* |
| B6 | **Run truncated by the executor's own `--global-timeout=1500000`** | `Timed out waiting 1500s for the test suite to run` → the 211 "did not run". Disclosed as a deliberate laptop-safety bound, not a product defect. |

### Nothing in this failure set is attributable to this lane

- **No e2e spec exercises this lane's new UI at all.** `grep -rl 'to-bill\|toBill\|consolidat\|delivery-note' apps/web/e2e`
  returns exactly two files: `money-campaign/w2c-support.ts` (helpers) and
  `money-campaign/return-notes.spec.ts`. There is **no** spec for `/sales/to-bill`, the partner
  "Delivery notes" tab, the `invoiced_via` badge, or the OI-8 refusal region. A green environment
  would still not have covered them.
- **The one spec that touches the endpoint this lane gated never reached it.** `return-notes.spec.ts`
  (`MTP-RET-01…10`) failed **10 of 10**, but `MTP-RET-05`/`-06` — the two that call
  `POST /delivery-notes/consolidate-to-invoice` (`w2c-support.ts:237-238`) — died in
  `beforeEach → loginAsRole(page, 'owner')` at `helpers.ts:55` (B5), and `MTP-RET-01` — which has
  nothing to do with billing — died on B2. **No `403` was observed**, so the run produced neither
  evidence for nor evidence against the OI-13 `module:Sales` revocation. That verification remains
  owner-owned and promotion-gated, exactly as recorded.
- The failing set spans `add-to-inventory`, `article-detail`, `company`, `documents`, `treasury-*`,
  `i18n-fr` and `auth` — surfaces this branch does not touch.

**Conclusion:** the E2E obligation is discharged as *run, with the blocker named precisely* rather
than omitted. It is **not** a green run and is not presented as one. Per the parent's instruction the
terminal audit performs its own Playwright visual pass; the environment work it needs first is B1
(login throttle), B2/B3/B5 (reseed the local demo tenant incl. units, the `demo-pharmacy-tn` roles and
the `W2A-NOGL-01` fixture) and B4 (refresh the baseline locators).

---

## 8. Commands, artefacts, tip

Scratch database created for this milestone and used for **every** PostgreSQL run
(the shared `autoerp_test` was never touched):

```bash
psql -h 127.0.0.1 -p 5433 -U autoerp -d postgres -c "CREATE DATABASE autoerp_dn_m5 OWNER autoerp;"
```

Run logs (session scratchpad, not committed): `preflight.log` (invocation A), `preflight2.log` (B),
`preflight3.log` (C), `pg_concurrency.log`, `pg_compositeroot.log`, `fe_gates.log`, `other_gates.log`,
`manifest_drift.log`, `vitest63.log`, `e2e.log`. Playwright's own `test-results/` and
`playwright-report/` are gitignored and were not committed.

Working tree left clean (`git status --porcelain` shows only this evidence file before its commit);
**no `git stash` was used at any point**; **nothing was pushed**; `dev` was not touched.

**Tip at the time of writing this evidence:** see the YAML `milestones[M5].commit`.

---

## 9. What M5 hands to the terminal audit

1. **The §6 contract is discharged** — every registry path ran, §6.2 is 12/12 green on real
   PostgreSQL, §6.3 is 725/727 with only the two pinned finance reds, and every static gate is green.
2. **The residual baseline is fresh, and it corrects the record.** M4 round-1 finding 7 declared pin
   entries 1–2 stale; re-derivation shows they reproduce and that M4's `[OK]` came from analysing a
   **trait** file outside the context of any consuming class. The pin must be kept, not pruned (§5).
3. **No newly introduced failure exists** anywhere in the whole-repository set.
4. **The lock inventory is re-derived from code**, with the acyclicity argument and the explicit
   no-writer-reaches-invoice-creation-before-its-claims statement and its seven mechanisms (§2).
5. **OI-14 is proven by one test at the production entry point** (§3); **OI-8 conditions 1–4 are
   evidenced surface by surface** and **5–7 are returned unratified** with what was and was not built (§4).
6. **The E2E was actually run**, not omitted; its blockers are named exactly, and none is attributable
   to this lane (§7). The terminal audit's own Playwright visual pass needs B1–B5 fixed first.
7. **Open owner-visible obligations are unchanged**: OI-13 promotion gate, OI-1a residual, OI-12
   staging counts, D-6 sequencing (§6).
