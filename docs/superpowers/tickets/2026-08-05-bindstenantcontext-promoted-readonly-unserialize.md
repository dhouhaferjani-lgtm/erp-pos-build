# Ticket — `BindsTenantContext` adopters with a promoted `readonly string $tenantId`

**Opened:** 2026-08-05
**Source:** re-gate finding **N6** of
`docs/superpowers/reviews/2026-08-05-part-a-tenant-filter-bindscontext-review.md`
(itself a follow-on of review finding **R3**, fixed for the two Marketplace jobs in `24dac38ee`).
**Status:** open — scoped, not started. Deliberately NOT widened into the Wave-1 conversion commits.

---

## The failure mode (R3, verbatim shape)

`Illuminate\Queue\SerializesModels::__unserialize()` **skips payload keys that are absent**. A queue
payload that was serialized *before* a job gained its `$tenantId` anchor therefore restores the
object with that property never assigned. If the property is a **promoted `public readonly string`**
it stays *uninitialized*, and the first read — which is `BindsTenantContext::withTenantContext()`
reading `$this->tenantId` at `apps/api/app/Jobs/Concerns/BindsTenantContext.php:79` — fatals with:

```
Typed property <Job>::$tenantId must not be accessed before initialization
```

A fatal inside `unserialize`/`handle()` is not a catchable job failure the operator can fix: the
`failed_jobs` row is **permanently un-retryable**. Every retry reproduces the same fatal.

The property contract that came out of R3 now lives on the trait
(`BindsTenantContext.php:41-50`): a class that was **ever dispatched WITHOUT** the anchor MUST use a
declared, defaulted `public ?string $tenantId = null;` and handle the null case explicitly in
`handle()`. A promoted `public readonly string $tenantId` stays correct for a job that has carried
the anchor since its **first** dispatch.

## Fix shape (proven in `24dac38ee`)

From `SyncSellerListingsJob` / `ReconcileListingsJob`:

1. Replace the promoted parameter with a **declared, nullable, defaulted** property plus a plain
   constructor parameter that assigns it:

   ```php
   /** …why nullable: see the trait's property contract… */
   public ?string $tenantId = null;

   public function __construct(
       public readonly string $sellerId,
       string $tenantId,
   ) {
       $this->tenantId = $tenantId;
   }
   ```

   `__serialize()` omits default-valued properties, so **new payloads do not grow**; new dispatches
   always supply the anchor via the constructor, so the property is never actually null in a fresh
   payload.

2. Guard the top of `handle()`: on `null`, **discard with a WARNING** rather than guessing a tenant —
   never fall through to central. Whether "discard" is acceptable depends on the job (see the table);
   where it is not, the alternative is to re-derive the tenant from a central-resolvable identifier
   the payload already carries.

3. Test per job: assert the **serialized payload genuinely lacks the key** (build the legacy payload
   by `unserialize(str_replace(...))` or by serializing a stub without the property, as
   `MarketplaceListingJobsTenantContextTest` does) and that `handle()` discards it without touching
   data.

---

## Scope — corrected against the code (this is NOT seven jobs)

N6 named nine promoted-`readonly` adopters and treated them as one class of exposure. Checking each
job's git history against the commit that introduced its anchor shows only **two** were ever
dispatched without one. The exposure is defined by *"did a payload without the key ever exist"*, not
by the property shape alone.

| Job | `$tenantId` | Created | Anchor added | Legacy payloads possible? |
|---|---|---|---|---|
| `Import/Application/Jobs/ProcessImportJob.php:64` | promoted `readonly string` | `aee9892cc` 2025-12-27 | `b059bb2c5` **2026-05-07** | **YES — 4½ months of pre-anchor dispatches** |
| `Import/Application/Jobs/ProcessProductImageImport.php:56` | promoted `readonly string` | `9ee3132ab` 2026-01-08 | `b059bb2c5` **2026-05-07** | **YES — 4 months of pre-anchor dispatches** |
| `Channel/Application/Jobs/IngestChannelOrderJob.php:25` | promoted `readonly string` | `99908bc06` 2026-05-25 | `99908bc06` (same commit) | No |
| `Channel/Application/Jobs/DispatchProductToChannelJob.php:29` | promoted `readonly string` | `99908bc06` 2026-05-25 | `99908bc06` | No |
| `Channel/Application/Jobs/DispatchStockChangeToChannelJob.php:38` | promoted `readonly string` | `99908bc06` 2026-05-25 | `99908bc06` | No |
| `Channel/Application/Jobs/ChannelReconciliationJob.php:28` | promoted `readonly string` | `99908bc06` 2026-05-25 | `99908bc06` | No |
| `Media/Application/Jobs/GenerateRenditions.php:61` | promoted `readonly string` | `bb05d0422` 2026-06-24 | `bb05d0422` | No |
| `DocumentIngestion/Application/Jobs/ExtractDocumentJob.php:45` | promoted `readonly string` | `b39833694` 2026-07-07 | `b39833694` | No |
| `Product/Application/Jobs/PersistEnrichmentImagesJob.php:45` | promoted `readonly string` | `d437412f0` 2026-07-08 | `d437412f0` | No (not in N6's list — added since the review) |

(For completeness: `Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob` uses **no** anchor at all —
it is annotated system-scoped, `:132` — and `SyncSellerListingsJob:68` /
`ReconcileListingsJob:80` already carry the fixed `public ?string $tenantId = null;` shape.)

### What to actually do

- **P1 — `ProcessImportJob`, `ProcessProductImageImport`.** Genuinely exposed. Apply the
  `24dac38ee` shape. Discarding is the WRONG default for these two: an import job dropped silently
  loses a user-initiated upload with no re-fan-out equivalent to `marketplace:delta-sync`. Prefer
  **re-derive**: both carry an `ImportJob` id whose row could be located from a central pointer, or
  — cheapest and honest — mark the `import_jobs` row failed with an operator-visible reason before
  returning. Decide during implementation; do not blind-discard.
- **P3 — the other seven.** No change required by this ticket. They satisfy the trait's contract as
  written. Their promoted `readonly` is a *latent* hazard only if the anchor is ever removed and
  re-added, which the trait docblock already warns against. Leave as-is; do not churn.
- **Ops question, not answerable from code:** whether pre-2026-05-07 `ProcessImportJob` /
  `ProcessProductImageImport` rows still sit in `failed_jobs` ~3 months later. Check staging/prod
  before scheduling the P1 work — if the table has been purged, the fix is prophylactic and can ride
  any later import-module commit.

  ```sql
  SELECT payload::json->>'displayName' AS job, count(*), min(failed_at), max(failed_at)
  FROM failed_jobs
  WHERE payload LIKE '%ProcessImportJob%' OR payload LIKE '%ProcessProductImageImport%'
  GROUP BY 1;
  ```

## Guard worth adding with the fix

Nothing today prevents the next `BindsTenantContext` adopter from retrofitting an anchor onto an
existing job as a promoted `readonly`. `tests/Architecture/QueueJobTenantContextTest.php` already
walks every `ShouldQueue` class in `app/Modules/*/{Jobs,Application/Jobs,Infrastructure/Jobs}` — it
could additionally assert that a class using the trait declares `$tenantId` as a **nullable,
defaulted** property, with a fixture-listed exemption for jobs proven to have carried the anchor
since birth (the "No" rows above). That converts this ticket's table from prose into a gate.
