# Scan-to-Document — Upload & Review UX Redesign (Spec)

**Date:** 2026-07-09 · **Branch base:** `feat/scan-to-document` (worktree `apps/erp.scan-to-doc`) or fresh branch off post-merge `dev` · **App:** `apps/web` (React 19 / TS strict / Tailwind 4 / TanStack Query 5)

## 1. Why
The scan flow works end-to-end (upload → async OCR → review → commit) but the UX has concrete, owner-reported problems:
1. **Blank document preview** — `SourceViewer` renders `<img>` only; **PDFs silently fail** → blank fallback box. (Root cause confirmed.)
2. **Imbalanced layout** — grid gives the preview `1fr` (dominant) and the review `minmax(420px,0.8fr)` (cramped).
3. **Bare product `<select>`** — should reuse the app's real product picker (search existing OR create-new-with-prefill).
4. **Supplier creation navigates away** to the full-page form — should be an **in-page modal** (the pattern used on purchase/sales document screens), staying on the review.
5. **Upload is a modal that just closes** on submit — no picker feedback, no processing/"scanning" state; opening the detail mid-extraction wrongly shows "Extraction is not available".

Goal: a coherent, evidence-based **upload → live processing → review** flow that reuses existing components and fixes the above. This spec feeds a fresh implementation session (subagent-driven TDD) and a parallel mobile handover.

## 2. Research basis (cited DO/DON'T — full report: deep-research 2026-07-09, 110 agents)
- **Match indicator to wait:** <1s none; 2–10s indeterminate spinner; **10s+ determinate** (percent OR staged steps). OCR is 10–30s ⇒ a bare looping spinner / lone "scanning" animation is the wrong primary signal. [NN/g — progress-indicators, designing-for-waits]
- **Staged step-status** ("Uploading → Extracting → Ready") is the NN/g-sanctioned substitute when a true percentage isn't computable. A progress indicator raised tolerable wait ~9s→~22.6s; animated feedback ≈3× willingness to wait. [NN/g]
- **Don't trap the user** watching a processing view — let them keep working and **notify on completion**. [NN/g]
- **Honest progress** — no fake bars that stall at 99%; if animating, slower-then-faster. [NN/g]
- **Upload** — dropzone is good but MUST also be a keyboard-operable click-to-browse button (WCAG 2.2 SC 2.5.7 + 2.1.1); show accepted types + max size; **specific** invalid-file errors; explicit completion feedback (Dext). 
- **Review** — side-by-side document + fields with **per-field confidence routing** (only low-confidence needs attention); machine-validated (grey tick) vs human-validated (green check) states. [Rossum, Nanonets]
- **Errors** — name the specific problem + concrete retry; "An error occurred" is the named anti-pattern; never style routine/empty states as red errors.

## 3. Owner decisions (locked)
- **Processing UX:** staged stepper is the **primary** signal; a **subtle** scan-line over the thumbnail as flavor; **non-blocking** (can leave, auto-advance + toast on ready).
- **Review layout:** **review-primary** (wider); document in a **narrower sticky pane** that stays visible while scrolling lines, **click-to-zoom** (lightbox). Side-by-side, rebalanced.

## 4. Reused components (do NOT rebuild — paths verified)
| Need | Component | Path | Change needed |
|---|---|---|---|
| Product search per line | `ProductPicker` | `src/components/molecules/pickers/ProductPicker.tsx` | none (props: `value: ProductPickerValue \| null`, `onChange`, `productType`) |
| Create product inline | `AddQuickProductModal` | `src/components/organisms/AddQuickProductModal/` | **add `prefill` prop** (seed name/price/tax); `onSuccess(product)` |
| Create supplier inline | `AddPartnerModal` | `src/components/organisms/AddPartnerModal/` | **add `prefill` prop** (consume `PartnerPrefill`); `onSuccess(partner)` |
| Dialog primitive | `Modal` (+Header/Content/Footer) | `src/components/organisms/Modal/Modal.tsx` | none |
| Supplier prefill contract | `PartnerPrefill` / `readPartnerPrefill` | `src/features/partners/partnerPrefill.ts` | reuse; modal reads it directly |

Note: `PartnerPicker.allowNewInline` does `window.open('/partners/new')` (new tab) — NOT the target; use `AddPartnerModal`.

## 5. Design — by unit

### 5.1 Upload page (`/purchases/scans/new`) — replaces `UploadIngestionDialog`
- Real **dropzone**: drag-and-drop **and** a keyboard-focusable "browse" button (both trigger the hidden `<input type=file accept="image/*,application/pdf">`). Dashed drop target with hover/drag-over state.
- Doc-type select (delivery note / supplier invoice) — existing options.
- **Selected-file feedback**: filename, size, and a **thumbnail** (image → object-URL preview; PDF → first-page thumbnail via pdf.js or a PDF glyph + name). Remove/replace affordance.
- Show accepted types + **max size** (config `media.documents.*`); **specific** errors ("PDF or image up to 20 MB — this file is 24 MB").
- Primary "Start scan" → POST (existing `uploadDocumentIngestion`) → navigate to `/purchases/scans/:id`.
- The scans list keeps its "Upload scan" button but it now routes to this page (not a modal).

### 5.2 Processing state — on `/purchases/scans/:id` when status ∈ {uploaded, extracting}
- **Poll**: add `refetchInterval` to `useDocumentIngestion` while status ∈ {uploaded, extracting} (mirror the list's `shouldPoll` at 3–5s; stop on terminal states).
- **Staged stepper** (primary): Uploading ✓ → Extracting (active) → Ready. Derive step from status. Elapsed-time text.
- **Subtle scan flavor**: the uploaded thumbnail with a CSS scan-line sweep (respect `prefers-reduced-motion`).
- **Non-blocking**: "You can keep working — we'll notify you when it's ready" + a back-to-list link. When status flips to `needs_review`, the page **auto-transitions** to the review (5.3) and a **toast** fires (also fine if the user navigated away — the list already polls and can surface it).
- **Failure** (status `failed`): specific `error.message` + a **Retry** (re-extract) button. No red styling for the normal processing state.
- Fixes the "Extraction is not available" bug: that message must only show for `failed`, never for in-flight.

### 5.3 Review page redesign (`ReviewIngestionPage`)
- **Preview (fix #1):** new `SourceViewer` that renders **images inline** and **PDFs via pdf.js** (`pdfjs-dist` / `react-pdf`; fetch bytes from the signed URL → render first page to canvas; paged nav optional). This bypasses the `X-Frame-Options: DENY` framing block (canvas render, not framing). Keep a graceful fallback (spinner while rendering; on hard failure show a clear message + open-in-new-tab). **Decision: client-side pdf.js** (FE-only, no backend rendition needed).
- **Layout (fix #2):** replace `grid xl:grid-cols-[minmax(0,1fr)_minmax(420px,0.8fr)]` with **review-primary**: review/lines column wider (e.g. `minmax(0,1.4fr)`), document pane narrower (e.g. `minmax(320px,0.7fr)`), and **`sticky top-…`** on the preview so it stays in view while scrolling lines. **Click preview → lightbox/zoom** overlay.
- **Product per line (fix #3):** replace the `<select>` in `LineMappingTable` with **`ProductPicker`** (search existing). Add a **"＋ New product"** affordance that opens **`AddQuickProductModal` pre-seeded** from the line: `name ← line.description`, `sale_price`/`cost` ← `line.unitPrice` (as strings, precision-safe), `tax_rate ← line.taxRate`; on `onSuccess`, select the created product into that line. (Requires the new `prefill` prop on `AddQuickProductModal` + a `buildProductPrefill(line)` mapper, mirroring `buildSupplierPrefill`.)
- **Supplier (fix #4):** replace the full-page navigation in `ReviewIngestionPage.onCreateSupplier` with **`AddPartnerModal`** opened in-page, `prefill={buildSupplierPrefill(detail.extraction?.supplier)}`, `partnerType="supplier"`; on `onSuccess(partner)`, select it into the supplier picker and close. The `?name=`/navigate path and `buildSupplierPrefill` stay; only the destination changes (modal, not page). Keep the "new supplier" hint.
- **Confidence:** keep low-confidence highlighting; add a machine-validated vs edited/human-validated visual cue per field (grey tick vs green check).

### 5.4 Shared additions (small, reused elsewhere)
- `AddQuickProductModal`: new optional `prefill?: ProductPrefill` → seeds `useForm` defaults on open (create-only, additive; empty when absent → no behavior change for existing callers).
- `AddPartnerModal`: new optional `prefill?: PartnerPrefill` → seeds defaults on open (same additive contract; existing callers unaffected).
- `ProductPrefill` contract + `readProductPrefill` + `buildProductPrefill(line)` — mirror `partnerPrefill.ts` / `buildSupplierPrefill.ts` (whitelist, trim, never-throw, precision-safe strings for price).

## 6. Data flow
Upload page → `uploadDocumentIngestion` → `:id` page polls `useDocumentIngestion` → status-driven render (processing stepper ↔ review) → commit unchanged. No new endpoints; extraction pipeline unchanged. Polling is the only new data behavior (FE).

## 7. Out of scope
- Backend changes to extraction/commit; server-side PDF rendition (FE pdf.js chosen instead).
- Multi-file batch upload (single file per scan stays).
- Auto-commit / auto-create without review.
- Mobile app (separate handover — same patterns: capture → staged processing → review; reuse the prefill contracts).

## 8. Testing (TDD, per unit)
- Dropzone: drag/drop + keyboard browse select a file; invalid type/size → specific error; thumbnail shows.
- Processing: status `extracting` → stepper + non-blocking copy (NOT "extraction unavailable"); `failed` → message + Retry; polling starts/stops by status; auto-advance to review on `needs_review`.
- Preview: image renders `<img>`; PDF renders via pdf.js (mock the render); hard failure → fallback + open-in-tab.
- Product line: `ProductPicker` search selects; "New product" opens seeded `AddQuickProductModal`; onSuccess selects into the line.
- Supplier: create button opens `AddPartnerModal` seeded from extraction (NOT a route change); onSuccess selects supplier; commercial fields never seeded (reuse `PartnerPrefill` guarantees).
- Layout: review column is the wider track; preview is `sticky`.
- Prefill contracts: `readProductPrefill` unit tests mirror `readPartnerPrefill`.

## 9. Rollout
Fresh session: `writing-plans` on this spec → `subagent-driven-development` (TDD, per-task review, whole-branch review) → merge to local dev (not pushed; coordinated promotion). Live-verify with a real PDF **and** a real photographed invoice (per the "test a messy real doc" lesson).
