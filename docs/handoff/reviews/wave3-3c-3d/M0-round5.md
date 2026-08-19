## M0 adversarial gate — round 5 register

**Scope/lens applicability.** Diff `26b63f0ff..1d16e5e2c` = 10 files, all `docs/` + `scripts/`; no production code, no money/quantity arithmetic, no migration, no `onQueue`, no user-facing string, no container use. **Rule 19 / i18n / migrations / Horizon / tenancy-authz / constructor injection — N/A, verified.** **inventory-costing** applies (D‑19 register, D‑28 sweep, GR anchor, R‑11 probe, T11c fixtures). **fiscal-pos** applies (POS flush anchor, R‑1 basis, transaction depths).

**Round‑4 dispositions I re-verified independently — all three genuinely fixed.** **P1‑1(a):** the five `DocumentPostingService` rows now relocate onto real successors — `:605 → DeliveryComplianceGate.php:403` (`PhysicalLinePredicate::forLine`), `:616-620/:621-624/:623-624 → :79` (`linkedDeliveryNoteIdsFor()`, which **does** read both payload shapes — `DeliveredQuantityResolver.php:381` and `:394-399`, so plan `:1601-1607`'s load-bearing detail is carried), `:626-630 → :81-99` (the empty-linkage branch) ✓. **P1‑1(b):** twin B `SalesOrderToInvoiceConverter:525-540 → DeliveryNoteFromDocumentFactory.php:111-118` = the scoped `Product` lookup that deliberately keeps an unresolvable id (`:106-118`), now agreeing with `M0-evidence.md:188` ✓. **P2‑1:** both `:218-227` and `:216-227` → `217-226`, `anchor_kind=comment` ✓. **P2‑2:** `GeneralLedgerService.php:4444 → 4708` = `'source_id' => $movementId,` ✓.

---

### P1‑1 — EXTRACTION IS NOT EXHAUSTIVE: 10 real `file:line` citations in plan §§0–4 are structurally invisible to the extractor, including the entire `journal_entries` partial-unique-index table that T12/D‑a's keying rests on · **CONFIRMED**

The brief's EXTRACT step is *"machine-extract **every** `file:line` citation from this brief **and** from plan-wave3.md §§0–4… **No hand-picking**"*, and `N_extracted` is the number that goes in the report. I diffed every `name:line` token in the two sources against the committed CSV. The dispatch is clean (0 uncaptured). **Plan §§0–4 has 10 uncaptured, all resolvable, all load-bearing:**

| Uncaptured citation | Plan site | What it actually addresses (verified on the pinned tree) |
|---|---|---|
| `2026_06_26_120000:45-47` | `:193` | `CREATE UNIQUE INDEX uniq_je_source_procurement ON journal_entries (source_type, source_id) WHERE …` (`database/migrations/tenant/2026_06_26_120000_unique_journal_entries_source_procurement.php:44-48`) |
| `2026_07_12_100000:23-25` | `:194` | treasury-transfer partial unique — **and this timestamp matches TWO migrations**, a real ambiguity needing a recorded rule |
| `2026_07_28_100200:126-128`, `:132-134` | `:195-196` | pos_cash_rounding / pos_tolerance_bridge partial uniques |
| `2026_08_08_120100:41-43` | `:197` | repository_adjustment partial unique |
| `2026_07_08_100400:36-38` | `:198` | `uniq_je_company_chain_sequence` |
| `2025_12_12_120002:19` | `:303` | `document_lines.service_id` nullable FK — the predicate's data basis |
| `2026_04_19_130002:132` | `:305` | the `workshop_work_order_lines` XOR contrast |
| `validateDeliveryCompliance:605` | `:1674` | a **fifth** citation of the deleted delivery-compliance construct — the exact cluster round 4 spent a P1 on |

**Root cause** — `scripts/wave3-citation-inventory.php:74`: the extensionless alternative requires `[A-Z][A-Za-z0-9_]{2,}` (so a lowercase method name and any timestamp-prefixed migration name are unmatchable), and the extension alternative requires a filename with a listed suffix. `resolvePath()` (`:221-271`) additionally has no unique-prefix rule for timestamp-only names.

**Failure scenario.** Plan §0.7 exists to prove **`batch_write_off` is covered by no partial unique index** — the premise for T12/D‑a's `source_type`/`source_id` idempotency keying and for whether M2/M4 must ship a new partial unique for the inventory source types. Every address in that table is absent from the register that `M0-evidence.md:33` declares the sole carrier of addresses (*"stale literals remaining in the authority prose are not implementation addresses"*). At the mandated pre‑M2 re-run these rows still will not exist, so the one number that gates the cutover (`unresolved = 0`) is computed over a corpus that silently excludes them — an unextracted citation is strictly worse than an unresolved one, because nothing reports it. `N_extracted=256` in `M0-evidence.md:27` and the report is under-counted.

---

### P2‑1 — `ParapharmacySeeder.php:971` is classified `statement` although the plan annotates the citation `(comment)`; the T18 target is the `PostCOGSOnInvoice` comment six lines later · **CONFIRMED**

CSV row 228 → `apps/api/database/seeders/ParapharmacySeeder.php:971`, `anchor_kind=statement`, assertion *"createProduct() performs the mapped domain/configuration statement `'company_id' => $company->id,`"*. The source is plan `:3004`, T18's file list: *"`database/seeders/ParapharmacySeeder.php:971` **(comment)**"*. The real subject is the COGS comment at `ParapharmacySeeder.php:977-979` — *"cost_price is the WAC field read by MarginService and **PostCOGSOnInvoice**…"* — i.e. the reference that T17's deletion of `PostCOGSOnInvoice` makes stale, which is precisely why T18 lists the file.

The drift gate cannot see this: at the reference SHA `:971` was `'tenant_id' => $company->tenant_id,`, also a statement, so reference and current agree (containment 0.5) and the row passes. The corpus contains exactly **two** kind-annotated citations; the `(docblock)` one (`RefundResidualTenantIsolationTest.php:544`, CSV row 229) is correctly `comment` — this is the only mismatch, and the source's own parenthetical is a free, machine-checkable signal that is not consumed.

**Failure scenario.** Same class as rounds 3 and 4, lower blast radius: an M3 implementer working T18 through the register edits/verifies the `company_id` assignment and leaves the `PostCOGSOnInvoice` reference in the seeder pointing at a class T17 deleted.

---

### P2‑2 — `relocated` rows bypass the coherence gate **and** are frozen absolute integers, so the mandated pre‑M2 re-run can certify 23 wrong addresses while still printing `unresolved = 0` · **CONFIRMED (by code)**

`scripts/wave3-citation-inventory.php:143` gates the new drift check on `! $relocated`, so the 23 relocated rows are never compared against their reference text; `applyRelocation()` (`:329-395`) returns hard-coded `[path, start, end]` constants; and `scripts/tests/wave3-citation-inventory-test.php:74` compares only `new_line` and `anchor_kind`. Mechanically-mapped rows self-heal through `mapLines()`'s git-diff mapping; relocated rows do not.

**Failure scenario.** M1/M2 are explicitly going to touch these very files — T14 registers the controller closure as C‑3, T16d/T16e edit `PosCoreReceiptProjection`, the seam edits `GoodsReceiptService` and `DeliveryNoteService`. Shift `DeliveryComplianceGate.php` by a few lines and the six rows pinned to `403`/`79`/`81-99` keep printing those constants; if whatever now sits there is any statement, both the drift gate (skipped) and the regression test (line+kind only) stay green, and the pre‑M2 re-run records its second `unresolved = 0` over stale addresses — at the one place the brief calls *"unrecoverable"*. Fix is cheap: run `anchorsCoherent()` on relocated rows too (reference text vs relocated target), or pin relocations by symbol + expected text rather than by integer.

---

### P3 notes

1. **Dead code persists** (round‑4 P3‑2 unaddressed): `semanticAnchor()` (`scripts/wave3-citation-inventory.php:498-536`) and `hasExecutableAnchor()` (`:539-542`) have no call sites anywhere in `scripts/`.
2. **`Product.php:314-317`** — plan `:406` asserts the construct *"reads `$this->weighted_average_cost ?? $this->cost_price ?? '0.00'`"*. On the pinned tree that arm is deleted and the method renamed (`Product.php:307-313`, `:322-325` = `return (string) ($this->cost_price ?? '0.00');`). The register maps to the renamed method but its assertion carries only the signature, not the changed fallback chain; the semantic change survives only in the file's own docblock.
3. **`docs/handoff/progress/wave3-3c-3d.progress.yaml:27`** — M0's title still claims *"V-10 fix"*, which the brief assigns to **M1** (`§V-10: "Scope (an M1 task, red-first)"`) and which M1's row `:33` also lists. M0 correctly delivered no V‑10 fix; the ledger row over-claims.
4. **436 bare `:line` continuations exist in plan §§0–4** versus 13 in the dispatch. Registering only the dispatch's 13 is defensible against the brief's letter (`file:line` citations), but some plan continuations are load-bearing (`Product::isPhysical()` `:289-292`, `document_lines` `indexed :26`) and sit outside the register entirely.

---

### Bypasses I attempted that **FAILED** (claims I tried to break and could not)

- **CSV is hand-edited / not reproducible** → refuted: re-ran to `/tmp`, `N_extracted=256 N_mapped=256 relocated=23 unresolved=0`, exit 0, **byte-identical** to the committed CSV; regression `PASS (256 rows)` exit 0; `--self-test` PASS.
- **`unresolved=0` is bought with the hand table (round‑4 P3‑4's prediction)** → **refuted, decisively**: I emptied `$relocations` in a `/tmp` copy and re-ran — `unresolved=19` (**16 `semantic_drift_requires_relocation`** + 3 `missing_symbol_or_semantic_anchor`), exit 1. The fail-closed drift gate is real and load-bearing, not a rubber stamp.
- **The 0.34/0.35 containment threshold lets junk through** → probed the ratio for every mapped row. The lowest passers are genuine successors: `RefundService:481-484` (`product_id === null` → `PhysicalLinePredicate::forLine`), `DeliveryNoteService.php:257`/`ReturnNoteService.php:682` (`(float)` → `bcformatStrict`), `DeliveredQuantityResolver.php:287` (private → public). Only the `ParapharmacySeeder` row (P2‑1) is a real miss.
- **Round‑4's P1‑1/P2‑1/P2‑2 were papered over** → refuted line by line (see header paragraph); the relocation targets are the correct constructs, and all 16 keys are pinned by `…test.php:47-105`.
- **The base pin was swapped to match HEAD** → refuted independently: `git show 8ce919be2:…progress.yaml` names `26b63f0ff…`; `git merge-base --is-ancestor 6974231d0 26b63f0ff` → IN; both Workshop tickets present in `git ls-tree -r 26b63f0ff`; tree clean, `HEAD=1d16e5e2c` is the disclosed metadata-only successor (`M0-evidence.md:231`).
- **The D‑28 depths are asserted, not derived** → refuted by re-deriving **two writers of my own choosing**, identical to the evidence table: `GoodsReceiptService::post()` via `StandaloneReceiptService.php:117-146` (post at `:134`; the only later write is `procurement_idempotency_keys` `:137-143`, not an inventory-class lock → depth **2**, C‑4 EXEMPT holds) and `PosCoreReceiptProjection::apply()` (`ApplyFiscalEventProjectionJob` opens no transaction; `writePayments` `:457`, `redeemVouchers` `:458`, `earnLoyaltyPoints` `:466`, `applyStockMovementForLines` `:467`, closure ends `:477` → depth **1**, flush belongs between 476 and 477). Call-site grep for all writers reproduces exactly the 10 rows.
- **C‑5's frame is invented** → refuted: `InvoiceController.php:909` root, DN confirm `:995`, `invoiced_at` payload update `:1015`, response `:1017` — the I‑1 tail argument holds.
- **`UndeliveredGoodsLineScanner:92` is the un-relocated twin of the relocated `:100`** → refuted: `:92` is `->where('is_physical', true)` inside the `whereIn` subquery build at `:90-93`, exactly the construct R‑5 cites.
- **The dispatch also has uncaptured citation forms** → refuted (0 uncaptured in the dispatch); the gap is plan-side only (P1‑1).

**Required to clear:** P1‑1 (extend extraction to timestamp-prefixed migration and lowercase-symbol citations, add a unique-prefix resolution rule with the `2026_07_12_100000` ambiguity recorded, re-report `N_extracted`), P2‑1 (`ParapharmacySeeder.php:971` onto the `977-979` comment; consume the source's `(comment)`/`(docblock)` annotation as a required-kind assertion), P2‑2 (apply `anchorsCoherent()` to relocated rows, or pin relocations by symbol + expected text instead of frozen integers).

VERDICT: CHANGES-REQUIRED
