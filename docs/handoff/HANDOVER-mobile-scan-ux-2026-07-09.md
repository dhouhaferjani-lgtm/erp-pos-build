# HANDOVER — Mobile scan UX (erp-mobile), aligned to the web redesign

**Purpose:** land the SAME corrected scan patterns in `erp-mobile` (React Native / Expo) so web and mobile behave consistently. Mirror the web spec: `apps/erp.scan-to-document/docs/superpowers/specs/2026-07-09-scan-to-document-ux-redesign.md`.

## Current mobile state
- Capture-only feature exists (handover `erp-mobile/docs/handoff/HANDOVER-mobile-scan-capture.md`); it uploads to the same `/document-ingestions` API. Owner pushes erp-mobile personally.

## What to build/align (mirror web, adapted to native)
1. **Capture/upload** — camera capture + file pick; show the captured thumbnail + retake affordance; accepted types/size; explicit "uploaded" confirmation. (Web = dropzone; mobile = camera-first.)
2. **Processing** — SAME model as web: **staged status** (Uploading → Extracting → Ready) as the primary signal (NOT a lone spinner — the 10–30s wait exceeds the spinner threshold), **non-blocking** (let the user leave; notify/badge on completion), subtle scan flavor only. Poll the ingestion status (same endpoint). Failure → specific message + Retry.
3. **Review** — same review model: document preview (image inline; PDF via a native PDF view), extracted fields with **confidence highlighting**, line items with product search + create; **supplier/product create in a modal/sheet** (not a separate screen) seeded from the extraction.
4. **Reuse the contracts** — the API returns the same extraction/suggestions shape; reuse the `PartnerPrefill`/`ProductPrefill` MAPPING LOGIC (port `buildSupplierPrefill`/`buildProductPrefill` semantics) so create-with-prefill matches web. Watch the SAME snake_case-wire vs camelCase pitfall.

## Shared API facts (no backend change)
- Statuses: uploaded → extracting → needs_review / failed / committing → committed.
- Extraction line fields are snake_case on the wire (`unit_price`, `tax_rate`, `supplier_ref`, `line_total`, `batch_number`, `expiry_date`); `source_bbox` may be null.
- erp-ml extraction needs its keys configured on the deployed env (see web resume doc).

## Sequencing
Do web first (it defines the contracts + component patterns). Then port to mobile. Owner coordinates the erp-mobile branch/push.
