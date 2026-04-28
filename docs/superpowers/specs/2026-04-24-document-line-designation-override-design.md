# Document Line Designation Override — Design

**Date:** 2026-04-24
**Status:** Approved for planning
**Scope:** All five document types sharing the unified `documents` table (quotes, sales orders, invoices, credit notes, delivery notes).

---

## 1. Problem

When a user picks a product or service on a document line, the line's customer-facing name is copied from `product.name` / `service.name` and then effectively frozen from the user's perspective. There is no discoverable way to **override the customer-facing designation** while keeping the internal product linkage intact.

This matters for real-world cases:

- A garage wants the invoice line to read "Brake fitment service — front axle" even though the underlying internal service is named "Brake service (labor)".
- A retailer wants to phrase an item differently for a specific customer or context.
- **E-invoicing** (Tunisia's El Fatura, France's Factur-X, etc.) submits line-level item names to the tax authority. The submitted name must be the customer-facing wording on the printed invoice, not the internal product master name.

Additionally, the automotive workshop flow currently works around a missing "line subtext" slot by concatenating the service name and the work-order detail into a single field separated by an em-dash (`"Brake pad replace — 2.5h × 60/hr"`). A proper two-field split gives the PDF a cleaner header + subtext layout.

## 2. Goals

1. Let users override the customer-facing **designation** (line name) on every document line while the document is in an editable state.
2. Formalize a separate customer-facing **additional description** (line subtext) by activating the already-present-but-unused `document_lines.notes` column.
3. Flow both fields through PDF, print, email, and (future) e-invoicing payload builders.
4. Preserve `product_id` / `service_id` as the authoritative internal linkage for analytics, stock, reporting, and tax rules.
5. Split, rather than concatenate, in the Workshop → document converter.

## 3. Non-Goals

- No per-line **internal** notes column in this feature. A future `document_lines.internal_notes` column can be added when that need is concrete.
- No retroactive migration of historical document lines. AutoERP has no past customers on the posted-document side, so there is no live data to preserve.
- No change to product / service master data, analytics pipelines, stock, tax calculation, or margin reporting.
- No change to customer, price-list, or discount logic.
- No new permission introduced — reuses the existing document-edit permission (see §5.3).

## 4. Constraints

- **Fiscal immutability.** Once a document is posted / finalized it joins the hash chain and is immutable. The override and additional description lock in alongside quantity and unit price. Corrections post-posting happen through the normal flow (a credit note, which itself can be overridden on its own lines).
- **Module boundaries (CLAUDE.md rule #6).** All changes live inside the Document module and its existing callers. No cross-module contract changes.
- **No magic strings / strict typing (rules #3, #9).** All new enum-able states use enums; DTOs remain strictly typed.
- **i18n (rule #11).** All new user-facing strings go through `t()` keys.
- **Design tokens (rule #18).** New UI code uses design tokens, not hardcoded Tailwind colors.
- **Events immutable (rule #8).** If a new domain event is required, it gets a versioned name (e.g., `DocumentLineDesignationOverriddenV1`). If existing `DocumentLineCreated` / `DocumentLineUpdated` events already cover the write path, no new event is needed — confirmed in §12 Discovery.

## 5. Data Model

### 5.1 Column changes on `document_lines`

One nullable column is added. Other column semantics are formalized but unchanged.

| Column | Role after this feature | Nullable | Max length |
|---|---|---|---|
| `description` | Customer-facing **designation** (line name / header). Defaults to `product.name` / `service.name` at creation. User-overridable while editable. Required non-empty on save; trimmed. | NOT NULL (existing) | 500 chars |
| `notes` | Customer-facing **additional description** (secondary subtext). Defaults to empty. User-editable while editable. | Nullable (existing) | 1000 chars |
| `designation_default_snapshot` | **NEW.** Captures `product.name` / `service.name` at line creation. Drives the "overridden" indicator: `description !== designation_default_snapshot`. Immutable after creation; survives product renames and soft-deletes. Null only for legacy rows (none exist today). | Nullable (new) | 500 chars |
| `product_id` / `service_id` | Unchanged. Authoritative linkage for analytics, stock, reporting, tax. | Existing | — |
| `product_code` | Unchanged. SKU snapshot, rendered in PDF / e-invoicing as the item identifier. | Existing | — |

One migration adds `designation_default_snapshot` as a nullable text column with max length 500. No backfill needed (no existing posted data to care about; drafts can also be ignored — they'll re-snapshot on next edit or stay null, and the indicator hides when the snapshot is null).

### 5.2 Character-length rules

- `description` — server-side validation: `required|string|min:1|max:500`, trimmed. Frontend enforces non-empty on save and a `maxLength` of 500.
- `notes` — server-side validation: `nullable|string|max:1000`, trimmed. Frontend enforces a `maxLength` of 1000.
- `designation_default_snapshot` — populated server-side only; never accepted from client input. Server trims and truncates to 500 at capture time.

### 5.3 Authorization

Reuses the existing document-edit permission for the relevant document type (e.g., `invoices.update`, `quotes.update`, etc., whichever guards the current line-edit routes). No new permission is introduced in this feature. If stricter tenant-specific gating is needed later, it can be added as a policy layered on top — out of scope here.

## 6. Lifecycle

Editable states (draft, pending, or whatever the document type's pre-finalized state is) allow free editing of both `description` and `notes` on each line. Existing editability guards on the document apply uniformly — this feature confirms their coverage and extends them if necessary (see §12 Discovery, Item 0).

Once the document is posted / finalized, both fields are frozen alongside quantity and unit price by the existing post-lifecycle rules.

**Conversions** (Quote → Sales Order, Sales Order → Invoice, Sales Order → Delivery Note, Invoice → Credit Note, etc.) copy `description`, `notes`, **and `designation_default_snapshot`** line-for-line from the source document. The target document is itself editable on creation, so the user can re-override if they want. The snapshot carries through so the "overridden" indicator stays consistent across the conversion chain.

## 7. Backend Changes

All changes live inside `apps/api/app/Modules/Document/` and its resources.

### 7.1 Line persistence

- **`DraftPersistenceService`** (and any sibling line-creation/update service): populates `description` from `product.name` / `service.name` AND captures the same value into `designation_default_snapshot`. Accepts `notes` from the request DTO and persists it.
- **Request DTOs / validation** for line create and update:
  - `description` — `required|string|min:1|max:500`, trimmed. Empty or whitespace-only values are rejected with an i18n'd validation message.
  - `notes` — `nullable|string|max:1000`, trimmed.
  - `designation_default_snapshot` — never accepted from client input. Ignored if present in the payload.
- **Posted-line guard** — the canonical guard that blocks line updates on posted documents is identified and, if it doesn't already cover `notes` and `description`, extended to cover them. Exact guard location is a discovery step (see §12 Item 0).

### 7.2 Workshop → document converter

`DocumentGenerationAdapter` (or the canonical current converter from `Modules/Workshop/` into a draft document) currently does:

```php
'description' => $wol->display_name . ($wol->description !== null ? ' — '.$wol->description : '')
```

Change to:

```php
'description'                    => $wol->display_name,
'notes'                          => $wol->description,
'designation_default_snapshot'   => $wol->display_name,
```

No historical data migration (no past customer data). Fresh conversions after this ships split correctly.

### 7.3 Document-to-document converters

Existing converters (`SalesOrderToInvoiceConverter`, `SalesOrderToDeliveryNoteConverter`, `QuoteToSalesOrderConverter`, `InvoiceToCreditNoteConverter`, etc.) already copy `description`. Extend each to also copy `notes` and `designation_default_snapshot`. Same per-line loop, two extra fields.

### 7.4 PDF / print template

`apps/api/resources/views/documents/components/line_items.blade.php` and any country-specific overrides.

Switch the primary line header from `$line->product->name` (live lookup) to `$line->description` (stored). Keep SKU. Render `$line->notes` as the secondary detail.

```blade
<strong>{{ $line->description }}</strong>
@if($line->product_code)
  <span class="sku">[{{ $line->product_code }}]</span>
@endif
@if($line->notes)
  <div class="item-description">{{ $line->notes }}</div>
@endif
```

No fallback branch is needed: `description` is NOT NULL and validated `min:1` on every write path.

Applies uniformly to all five document types. Country-specific template variants inherit the same partial. Existing PDF bidi handling (Arabic RTL) continues to work because it operates on the text at render time regardless of which column it comes from.

**PDF caching** — if the app caches rendered PDFs for draft documents, the cache must be invalidated when any line field (including `description` / `notes`) changes. Exact cache layer location is a discovery step (see §12 Item 1).

### 7.5 E-invoicing payload builders

**Current state:** exploration confirmed no e-invoicing payload builders (El Fatura, Factur-X, etc.) currently exist in the codebase. §12 Item 3 confirms this during implementation.

**Future contract** — when builders land, they map:

- Item name / designation → `line.description`
- Item description / long description → `line.notes`
- Item identifier → `line.product_code`
- Internal tax category, unit of measure, reporting → driven by `product_id` / `service_id`

## 8. Frontend Changes

All changes live inside `apps/web/src/features/documents/`.

### 8.1 Line editor (`DocumentLineEditor.tsx`, `DocumentLineRow.tsx`)

**Designation cell — view state**

- Shows the current designation text (truncated with tooltip on overflow, matching existing truncation pattern).
- On row hover or keyboard focus, a small pencil icon fades in to the right of the text. Icon button uses the existing icon-button idiom and design tokens.
- If `line.description !== line.designation_default_snapshot` (and snapshot is non-null), a subtle indicator — final visual picked during implementation by matching the closest existing subtle-state pattern (likely a small muted dot in `tokens.textColorsMuted`) — sits next to the text. Hover / focus tooltip on the indicator reads:
  - `"Designation overridden — original: {originalName}"`
  - i18n key: `documents:lines.designation.overriddenTooltip` with interpolated `{{originalName}}` = `line.designation_default_snapshot`.

**Designation cell — edit state**

- Click the text, click the pencil, or press Enter/Space while the pencil is focused → inline input appears, focused, contents selected. Input has `dir="auto"` for RTL-safe editing.
- `maxLength={500}`; enforces non-empty on save (blur with empty value restores previous value and shows a brief inline hint).
- Enter commits, Esc cancels.
- A "Reset to product name" link sits under the input while editing, visible only when the current value differs from `designation_default_snapshot` AND the snapshot is non-null. Clicking it restores the snapshot value and exits edit mode. If the underlying product / service has been soft-deleted, the link is present but disabled with a tooltip `"Product no longer exists"`.
  - i18n key: `documents:lines.designation.resetLink` / `documents:lines.designation.resetDisabledTooltip`.

**Additional description (`notes`) slot**

- A second slot under the designation in each line row.
- View state:
  - If set: muted smaller text showing current value.
  - If empty: a `"+ Add description"` affordance that fades in on row hover **or keyboard focus** (same treatment as the pencil).
  - i18n key: `documents:lines.additionalDescription.addLink`.
- Edit state: click or Enter/Space on the affordance opens a multiline input (`<textarea>`) with `dir="auto"` and `maxLength={1000}`. Enter commits, Shift+Enter inserts a newline, Esc cancels.

**Accessibility (both slots)**

- Pencil and "+ Add description" affordances are keyboard-reachable via Tab, with a visible focus ring (`focus-visible:` utility pair from design tokens), and activate on Enter/Space.
- Each icon-button and affordance carries an `aria-label` (i18n'd): `"Edit designation"`, `"Add description"`, `"Reset to product name"`.
- Overridden indicator has `role="status"` + `aria-label` that reads the original name, so screen readers announce it when the row is navigated.
- `dir="auto"` on inputs ensures Arabic / RTL content renders correctly within a document whose overall direction may differ.

**Affordance consistency** — the row remains visually clean in the default state. Pencil and "+ Add description" only appear on hover or keyboard focus. The overridden indicator is always visible when the condition is met (it signals state, not availability).

### 8.2 Read-only detail pages

Document detail pages render `description` as the primary line text and `notes` as smaller secondary text underneath when present. The "overridden" indicator and its tooltip are shown here too — internal users viewing a posted document can see which lines were customized. Indicator derivation uses `designation_default_snapshot` (stable, drift-free); never uses a live `product.name` lookup. The indicator is UI chrome only; it never appears on the PDF, email, or e-invoicing output.

### 8.3 Types

No manual TypeScript edits (rule #7). Run `php artisan typescript:transform` after any backend DTO changes so `packages/shared/types/` regenerates with the new `designation_default_snapshot` field.

### 8.4 i18n keys (new)

In the relevant `documents` namespace:

- `documents:lines.designation.editTooltip` / `editAriaLabel`
- `documents:lines.designation.overriddenTooltip` with `{{originalName}}`
- `documents:lines.designation.resetLink` / `resetAriaLabel` / `resetDisabledTooltip`
- `documents:lines.designation.emptyHint` — inline hint shown when the user tries to commit an empty designation
- `documents:lines.additionalDescription.addLink` / `addAriaLabel`
- `documents:lines.additionalDescription.placeholder`

All languages currently supported in the `documents` namespace get entries (including Arabic — text content is flat strings, no bidi markup needed; `dir="auto"` on inputs handles RTL at render time).

## 9. Testing Strategy (TDD)

### 9.1 Backend (PHPUnit with `RefreshDatabase`, real Eloquent, seeded permissions)

- Creating a draft line with `description` and `notes` persists both fields and populates `designation_default_snapshot` from product/service name.
- Creating a line with empty or whitespace-only `description` is rejected with a 422.
- Creating a line with `description` longer than 500 chars is rejected.
- Creating a line with `notes` longer than 1000 chars is rejected.
- Request ignores any client-supplied `designation_default_snapshot` — server always captures from product/service.
- Updating `description` / `notes` on a draft line succeeds.
- Updating `description` / `notes` on a **posted** line is rejected by the lifecycle guard.
- Workshop → invoice conversion writes `display_name` to both `description` and `designation_default_snapshot`, and `wol.description` to `notes`. No em-dash concatenation.
- Document conversions — **all five pairs** relevant to the unified documents table: Quote→SalesOrder, SalesOrder→Invoice, SalesOrder→DeliveryNote, Invoice→CreditNote, DeliveryNote→Invoice (where applicable) — copy all three fields (`description`, `notes`, `designation_default_snapshot`) line-for-line.
- PDF render fixture: a line with overridden designation + notes produces the expected primary header + secondary subtext; SKU is still present. Test against rendered HTML (rule #17).
- Rename scenario: create a line with `description == designation_default_snapshot`, then rename the underlying `product.name`. The indicator derivation remains stable (stays not-overridden) because it's based on the snapshot, not on live `product.name`.
- Soft-delete scenario: create a line with a snapshot, soft-delete the product, confirm the line still renders with its captured designation and snapshot.

### 9.2 Frontend (Vitest + React Testing Library)

- Hovering a line row reveals the pencil icon and the "+ Add description" affordance.
- Tabbing to the row reveals the same affordances (keyboard parity).
- Clicking the pencil or pressing Enter on it enters edit mode; Enter commits the new value; Esc restores the original.
- Committing an empty designation is rejected with an inline hint; the previous value is retained.
- The reset link appears only when the current designation differs from `designation_default_snapshot` and the snapshot is non-null; clicking it restores the snapshot and exits edit mode.
- The reset link is disabled (with tooltip) when the underlying product is soft-deleted.
- The overridden indicator appears when `description !== designation_default_snapshot`, and its tooltip renders `designation_default_snapshot` as the original name.
- The overridden indicator does NOT appear when only `product.name` changed (snapshot-based derivation is drift-free).
- The `notes` slot: empty → shows the add affordance on hover / focus; filled → shows muted secondary text; edit → multiline input, Shift+Enter inserts a newline, Esc cancels.
- Read-only detail page: indicator + tooltip render correctly on posted documents; no pencil / add affordance exposed.
- Arabic / RTL: a designation with Arabic content in a French-locale document renders right-aligned in the input and in the read-only display; both `description` and `notes` inputs respect `dir="auto"`.

### 9.3 End-to-end sanity

Create a draft invoice, add a product, override the designation, add an additional description, post the invoice, generate the PDF. Verify the customer-facing output shows the override as the primary name, SKU beside it, and additional description underneath.

### 9.4 PDF cache invalidation (if applicable)

If the app caches rendered PDFs for draft documents (confirmed in §12 Item 1): editing `description` or `notes` on a draft line invalidates the cached PDF and a subsequent render produces the new output.

### 9.5 Event sourcing

Confirm the write path emits the canonical `DocumentLineUpdated` (or equivalent) domain event with both old and new `description` / `notes` values in the payload, so audit / replay stay consistent. If a new versioned event is required (§12 Item 2), its schema test goes here.

## 10. Acceptance Criteria

- [ ] A user can override a line's designation on all five document types while the document is in an editable state.
- [ ] A user can add / edit a per-line additional description on all five document types while the document is in an editable state.
- [ ] Both fields render on the customer-facing PDF as the primary header + secondary subtext; SKU is preserved.
- [ ] The "overridden" indicator and tooltip appear wherever the designation is shown in the app, based on the stable `designation_default_snapshot`, and never appear on PDF / email / e-invoicing output.
- [ ] Overrides, additional descriptions, and default snapshots carry through all document conversions.
- [ ] Workshop-generated documents populate `description`, `notes`, and `designation_default_snapshot` separately, with no em-dash concatenation.
- [ ] `product_id` / `service_id` / analytics / stock / tax / reporting are unaffected.
- [ ] Empty / whitespace-only designations are rejected (frontend and backend).
- [ ] Character-length limits enforced (`description` 500, `notes` 1000).
- [ ] Hover-only affordances are reachable and activatable via keyboard; overridden indicator is announced by screen readers.
- [ ] Arabic / RTL text renders correctly in both editor inputs and PDF output.
- [ ] Behind the rollout feature flag (see §11) — default off in production until explicit enablement.
- [ ] `./scripts/preflight.sh` passes cleanly (PHPStan level 8, Pint, PHPUnit, TypeScript strict, ESLint).

## 11. Rollout

Ship behind a boolean feature flag (e.g., `documents.line_designation_override.enabled`). Default off in production, on in staging and development. Enable for internal testing, then flip on for all tenants once PDF output and e-invoicing mapping are verified on real documents. The schema migration ships un-gated (column is nullable, so it's harmless when the feature is off).

Rollback plan: flip the flag off. The backend still accepts requests without override (default behavior pre-feature); the frontend hides the pencil, indicator, and "+ Add description" affordances. The new column stays in place but is inert.

## 12. Discovery Items (to resolve during planning / early implementation)

These are unknowns that require reading code before the implementation plan can nail down exact file edits. Each is a short, bounded investigation.

**Item 0 — Posted-line guard location.** Identify the canonical service, policy, or observer that blocks line updates on posted documents today. Confirm it covers `description` and `notes` equally. If it only covers quantity/unit-price, extend it (same pattern) to cover the two text fields. Output: file path + test confirming posted-line update of either field returns the expected 4xx.

**Item 1 — PDF cache layer.** Grep for PDF-caching code (Redis, file system, or response cache) around `DocumentPdfService`. If a cache exists for draft PDFs, wire invalidation into the line update path. If no cache exists, document that and skip. Output: yes/no + invalidation hook location.

**Item 2 — Domain events on line updates.** Confirm `DocumentLineUpdated` (or equivalent) event exists and is already emitted on the write path. If yes, reuse. If no, add `DocumentLineUpdatedV1` with the full line snapshot in payload, guarded by rule #8 versioning. Output: event class + test.

**Item 3 — E-invoicing builders audit.** Confirm no El Fatura / Factur-X / equivalent payload builder currently exists. If one does, extend it immediately to use `description` as the item name. If none, document the mapping from §7.5 as the contract for future work. Output: affirmative statement + (if applicable) PR scope note.

## 13. Future Extensions (Out of Scope Here)

- **Per-line internal notes** — add `document_lines.internal_notes` with a dedicated edit affordance when a concrete need lands. Matches the document-level `internal_notes` pattern.
- **E-invoicing payload builders** — consume the mapping documented in §7.5 when the El Fatura / Factur-X / equivalent builders are implemented.
- **Audit display** — event-sourcing already records each line change; if needed, a small UI affordance can surface the override history inline (e.g., a "history" popover on the indicator).
- **Permission-gated override** — if a tenant needs stricter gating beyond document-edit, a dedicated `document_line.override_designation` permission can layer on top.
