# POS receipt preview + customization, and printed-ticket defects — design (Lane E)

Date 2026-09-17 · Lane E · branch `feat/pos-receipt-preview` (worktree `.worktrees/pos-receipt-preview`, base `origin/dev` `0b20e28dc`) · PR → `dev`, Houssam merges.
Phase-1 evidence: `docs/sessions/2026-09-17-lane-e/phase1-report.md` (gitignored; every `path:line` below comes from it).

## Summary

The manager, from the **web dashboard** receipt settings (`/settings/company`, tab *receipt*), sees a live preview of the customer ticket exactly as the thermal printer lays it out, and can choose **logo on/off** and **subtotal shown TTC or HT**. The same lane fixes four printed-ticket defects: the tax identifier printed twice, a QR code that means nothing to the customer, `é`/`ç` printed as `Θ`/`τ`, and `TND` printed before the amount with no space.

Preview fidelity is achieved by construction, not by imitation: the receipt layout moves out of Rust (`apps/pos/src-tauri/src/printing/receipt_template.rs:302-868`) into **one TypeScript builder in `packages/shared`** that produces a `ReceiptDoc` segment model. The POS app sends that document to Rust, which only **encodes** segments to ESC/POS bytes and transports them. The web preview renders the same document to a monospace grid at the same column count.

Owner rulings (Dhouha, 2026-09-17, recorded in `.superpowers/sdd/progress.md`):
1. Surface = web dashboard (`apps/web`), not the POS app.
2. Template ported to TypeScript; Rust becomes a pure encoder.
3. Subtotal HT/TTC is a **display** option; amounts and fiscal code are untouched.
4. Backend change limited to the existing receipt-settings endpoint: `receipt_logo` gets its missing validation rule, one new column `receipt_subtotal_mode`.
5. Thermal logo raster is **deferred** (no image command exists in `escpos.rs`); the toggle drives the PDF and the preview now.
6. QR: keep **one** labelled refund-lookup QR, drop the raw fiscal-hash QR.
7. HT mode prints `Sous-total HT`, TVA lines, `TOTAL TTC` — **no Remise line, nothing derived** (r3 ruling: `discount_allocated` is a TTC share; an HT remise would be new printed-money arithmetic).

## Industry baseline (benchmark-first — convention 10)

Flow: POS receipt customization (manager-facing receipt settings + faithful preview + printed ticket). Reference systems: Odoo 17 POS, ERPNext v15 POS, Dolibarr 19 TakePOS. Every reference cell is **from memory** (Phase-1 investigator, 2026-09-17); the gate may challenge any of them.

| # | Guarantee the baseline gives the user | Odoo | ERPNext | Dolibarr | AutoERP today (path:line) | Gap | Decision |
|---|---|---|---|---|---|---|---|
| B1 | Manager switches the receipt logo on/off from the back office and it prints on the thermal ticket | ✅ | ✅ | ✅ | no raster command in `apps/pos/src-tauri/src/printing/escpos.rs`; PDF-only at `apps/api/resources/views/pos/receipt.blade.php:317-319`; `companies.receipt_logo` has no writer (`UpdateReceiptSettingsRequest.php:21-30`) | MISSING | MATCH for PDF + preview (this lane); **DEFER** thermal raster — ticket E-1 |
| B2 | Header and footer text edited in one place, applied to every print path | ✅ | ✅ | ✅ | three surfaces: `companies.receipt_header/footer`, `locations.receipt_header/footer` (`…2025_11_30_105000_create_locations_table.php:53-54`), device `printerStore.footerText` (`apps/pos/src/stores/printerStore.ts:13`) | WRONG | **DEFER** — ticket E-5 (out of brief) |
| B3 | Manager chooses tax-inclusive or tax-exclusive subtotal display | ✅ | ✅ | ⚠️ | no setting; `Sous-total` is TTC-before-remise by the D-1 ruling (`apps/pos/src/lib/buildReceiptData.ts:217-235`) | MISSING | **MATCH** — this lane (§4.2) |
| B4 | Currency position follows the locale, with a separating space | ✅ | ✅ | ✅ | `format!("{}{}", symbol, amount)` at 17 sites `receipt_template.rs:520…724`; symbol resolved with hardcoded `'en'` (`buildReceiptData.ts:50-60`) | WRONG | **MATCH** — this lane (§4.4) |
| B5 | Accented Latin text prints correctly on a thermal printer | ✅ | ✅ | ✅ | code page and transcoding disagree (`receipt_template.rs:265-280`), `ESC t` suppressed for cp437 (`:312-315`), test page sets none (`:921`) | WRONG | **MATCH** — this lane (§4.3) |
| B6 | Live preview of the ticket in the back office, at the configured paper width, before saving | ✅ | ⚠️ | ❌ | none; TODO at `apps/web/src/features/pos/RECEIPT_PRINTING_INTEGRATION.md:207` | MISSING | **MATCH** — this lane (§3) |
| B7 | Paper width / printer configured per terminal and visible from the back office | ✅ | ✅ | ✅ | device localStorage only (`printerStore.ts:31,64`); `pos_terminals` has no printer columns | MISSING | **DEFER** — ticket E-6; preview carries an unsaved 80/58 mm switch |
| B8 | Settings per company with a per-location override | ✅ | ✅ | ⚠️ | company columns + orphan `locations.receipt_*` read only by the PDF | PARTIAL | **DEFER** — folded into E-5 |
| B9 | A fiscal/verification QR is printed when the country requires it and is inert otherwise | ✅ | ⚠️ | ⚠️ | up to 3 unlabelled QRs (`receipt_template.rs:493-502, 760-764, 775-788`); TN not in `FISCAL_INFO_REQUIRED_COUNTRIES` (`ReceiptSettingsTab.tsx:29`) | WRONG | **MATCH** partially — one labelled refund-lookup QR (§4.1); country-gated fiscal QR not required for TN today |
| B10 | Only privileged roles change receipt settings | ✅ | ✅ | ✅ | write gated `can:settings.update` (`apps/api/app/Modules/Company/routes.php:48`); **read** `GET companies/{id}/pos-settings` has no gate (`routes.php:43-44`) | PARTIAL | write: ALREADY; read gate: **DEFER** — ticket E-4, registered in `docs/qa/DEV-QA-registry.md` (security finding, out of brief) |
| B11 | The ticket looks the same whichever path prints it (thermal vs PDF reprint) | ✅ | ✅ | ✅ | two templates disagree (Rust vs `receipt.blade.php`) on tax lines, money placement, logo, header | WRONG | **DIVERGE** for now — PDF declared a divergent surface in `docs/glossary.md`; unification = ticket E-3 |
| B12 | Changing a display setting never alters the reprint of an issued ticket | ✅ | ✅ | ⚠️ | era discriminator for D-1 subtotal exists (`buildReceiptData.ts:225-235`); settings read live at print time (`:268-271`) | PARTIAL | **DIVERGE** (orchestrator ruling, owner may override): reprints follow the *current* display settings; the signed payload and the D-1 era branch are untouched. Rationale: logo/HT-TTC are presentation, the fiscal content and DUPLICATA marker are unchanged |

Second-of-everything (convention 09): no catalogue table or unique key is introduced. The lane adds: a **second-company** test (two companies with different `receipt_logo`/`receipt_subtotal_mode`, no bleed through `tenantScopedKey(['receipt-settings'])`, `ReceiptSettingsTab.tsx:128`), a **second-location** test (preview and print for a location whose `tax_id`/`vat_number` differ vs one where they are equal — the dedup must not fire wrongly), and a **re-run/idempotency** test (save the same settings twice → one row state, preview document byte-identical).

## Glossary (convention 11)

`docs/glossary.md` row **Receipt** gains: *printed surface* = `ReceiptDoc` built by `@autoerp/shared/receipt` (canonical); *encoder* = `apps/pos/src-tauri/src/printing/doc_encoder.rs`; *divergent surface* = server PDF `resources/views/pos/receipt.blade.php` (ticket E-3); synonyms `ticket`, `reçu`. The voucher ticket (`voucher_ticket.rs`) stays a separate concept row (*Voucher ticket*) with its own Rust layout, ticket E-2.

## 1. Architecture

```
apps/web  ReceiptSettingsTab ──(form values + fixture)──▶ buildReceiptDoc ──▶ <ReceiptPreview/> (monospace grid, 42|32 cols)
                                                                 ▲
packages/shared/src/receipt/  types.ts · buildReceiptDoc.ts · money.ts · fixtures/sampleReceipt.ts
                                                                 │
apps/pos  buildReceiptData ──▶ buildReceiptDoc(data, receiptSettings, printSettings) ──▶ invoke('print_receipt_doc')
                                                                                              │
apps/pos/src-tauri  commands/printing.rs::print_receipt_doc ──▶ printing/doc_encoder.rs (segments → bytes, code page)
                                                              ──▶ printing/mod.rs::send_to_printer (usb | network | windows, unchanged)
```

### 1.1 `packages/shared/src/receipt/` (new; follows `packages/shared/src/inventory-counting/`)

`types.ts`
```ts
export type ReceiptAlign = 'left' | 'center' | 'right';
export type ReceiptSize = 'normal' | 'double-height' | 'double-width' | 'double';
export type ReceiptSegment =
  | { kind: 'text'; text: string; align?: ReceiptAlign; bold?: boolean; size?: ReceiptSize }
  | { kind: 'two-column'; left: string; right: string; bold?: boolean; size?: ReceiptSize }
  | { kind: 'three-column'; left: string; middle: string; right: string; bold?: boolean }
  | { kind: 'separator'; char?: string }          // default '-'
  | { kind: 'blank' }
  | { kind: 'feed'; lines: number }
  | { kind: 'cut'; mode: 'full' | 'partial' | 'none' }
  | { kind: 'qr'; payload: string; moduleSize: number; label?: string }
  | { kind: 'logo' }                               // placeholder: preview draws a labelled box; encoder emits nothing (E-1)
  | { kind: 'drawer-kick' };
export interface ReceiptDoc { version: 1; columns: 32 | 42; segments: ReceiptSegment[]; fallback?: 'subtotal-mode' }
export type ReceiptSubtotalMode = 'TTC' | 'HT';    // mirrors the PHP enum; regenerated type from the PHP enum is the source once the DTO lands (rule 7)
export interface ReceiptDisplaySettings { logo: boolean; subtotalMode: ReceiptSubtotalMode; showVatBreakdown: boolean; showFiscalInfo: boolean; showPaymentDetails: boolean; showCustomer: boolean }
```
`ReceiptData` (input) is the existing hand-rolled POS shape (`apps/pos/src/lib/printing.ts:144-223`) moved into the package unchanged, so the six builders in `buildReceiptData.ts` keep compiling. Its money fields are **already strings** (rule 19); the builder never parses them.

`buildReceiptDoc.ts` — pure function `buildReceiptDoc(data: ReceiptData, display: ReceiptDisplaySettings, print: { columns: 32 | 42; cutMode; footerText }): ReceiptDoc`. Ports `format_receipt_with_settings` (`receipt_template.rs:302-868`) branch-for-branch: company header (`:318-374`), DUPLICATA marker (`:453-461`), `receipt_kind` branches (`:465-474, 507-548`), lines and modifiers, totals block (`:590-700`), VAT table vs aggregate (`:630-656`), payments, footer, Z cash-count block (`:806-856`), feed/cut (`:858-865`). The `> 20 chars` no-op branch (`:566-573`) is dropped. Label fallbacks (`:186-192`) move here.

`money.ts` — `formatReceiptMoney(amount: string, currency: string, locale: string): string` = the placement rule of `apps/web/src/lib/format.ts:98-108,134-136` (`currencyAppearsBeforeNumber`) applied to an already-formatted decimal string: `"11.000 TND"` for `fr`/`fr-TN`, `"TND 11.000"` where the locale puts the code first. Never parses; never uses `Number`. The `'en'`-hardcoded `getCurrencySymbol` (`buildReceiptData.ts:50-60`) is replaced by this.

`fixtures/sampleReceipt.ts` — the Phase-1 fixture: company "Café Nour" (TN, `tax_id == vat_number`), lines `Café crème` 2 × 4.500 (7 %), `Garçon` 1 × 2.000 (19 %), a remise, one cash payment, currency TND (3 decimals), locale `fr`. Used by the web preview, the Vitest snapshots and the Rust byte snapshot (§5).

### 1.2 `apps/pos`

- Add the `@autoerp/shared` alias to `apps/pos/vite.config.ts` and `tsconfig.json` (the package is not wired there today).
- `apps/pos/src/lib/printing.ts:338-349` `printReceipt` becomes: build `ReceiptDoc` → `invoke('print_receipt_doc', { doc, connectionType, address, printSettings })`. All six call sites keep their signature (`CheckoutSuccessModal.tsx:53`, `Header.tsx:657`, `refundReceiptPrinting.ts:96`, `RefundPayoutReconciliationModal.tsx:103`, `SettingsPage.tsx:178`, Z via `buildZReceiptData`).
- `ReceiptDisplaySettings` come from the existing `/company/config` read (`CompanyConfigController.php:91-96` → device store) extended with `logo` and `subtotal_mode`; missing fields (older server) default to `logo:false, subtotalMode:'TTC'`.
- `printerStore` is unchanged (paper width, cut, copies, footer, encoding stay device-local; E-6).

### 1.3 `apps/pos/src-tauri`

- New `printing/doc_encoder.rs`: `encode(doc: &ReceiptDoc, settings: &PrintSettings) -> Vec<u8>` — one buffer (Windows transport writes one RAW job, `mod.rs:64-69`). Maps each segment onto the existing `EscPosBuilder` primitives (`escpos.rs:141-204, 213-298, 335-381`). `logo` emits nothing (E-1). `qr` uses `qr_code()` (`escpos.rs:345-381`) and prints `label` centred above it.
- New command `print_receipt_doc` in `commands/printing.rs`, registered in `lib.rs:29-33`; copies loop kept (`:49,60-62`).
- `receipt_template.rs`: `format_receipt_with_settings` and its layout tests are **removed**; `ReceiptData`/`CompanyInfo`/`PrintSettings`/`ReceiptLabels` structs stay only as long as `voucher_ticket.rs:23` and the test page need them (E-2 retires the rest). The old `print_receipt` command is removed so there is no second print path.
- Encoding (§4.3) fixes live in `PrintSettings::code_page`/`encoding` (`receipt_template.rs:265-280`) and in the encoder's prologue.

### 1.4 `apps/api` (bounded by ruling 4)

- Tenant migration: `companies.receipt_subtotal_mode` `string(3)` NOT NULL default `'TTC'`; PHP enum `App\Modules\Company\Domain\Enums\ReceiptSubtotalMode { TTC, HT }` (rule 9); model cast.
- `companies.receipt_logo` keeps its column; model cast `boolean`; `UpdateReceiptSettingsRequest.php:21-30` adds `receipt_logo => boolean`, `receipt_subtotal_mode => Rule::enum(ReceiptSubtotalMode::class)`.
- New Spatie DTO `ReceiptSettingsData` for the `GET companies/{id}/pos-settings` response (`CompanyController.php:575-597`) so the web reads a generated type (rule 7; today there is no DTO). `php artisan typescript:transform` regenerates `packages/shared/types/generated.d.ts`; the shared `ReceiptSubtotalMode` alias points at the generated enum.
- `CompanyConfigController.php:91-96` `receipt_visibility` gains `logo` and `subtotal_mode` (device read; no new endpoint).
- No change to amounts, fiscal payloads, hash chains or the PDF.

### 1.5 `apps/web`

- `apps/web/src/features/settings/components/ReceiptSettingsTab.tsx`: two new controls (logo switch — disabled with a hint when the company has no `logo_path`; subtotal mode radio TTC/HT) in the same react-hook-form + zod form (convention 06), same `PUT` at `:191`, same `canEdit = hasPermission('settings.update')` (`:110-115`).
- New `apps/web/src/features/settings/components/ReceiptPreview.tsx`: props `{ display: ReceiptDisplaySettings; columns: 32 | 42 }`; calls `buildReceiptDoc(sampleReceipt, display, { columns, cutMode:'partial', footerText: form.receipt_footer })` on every form change (watch), renders segments into a `<pre>`-like monospace grid: `two-column`/`three-column` padded exactly like `escpos.rs:213-271` (a shared `padColumns` helper lives in the package so both sides use one rule), `double` sizes as wider/taller spans, `qr` as a labelled QR box, `logo` as a labelled placeholder box, `cut` as a dashed line. Unsaved 80 mm/58 mm switch (default 80 mm). Design tokens only (rule 18). Visible to `settings.view`; editing gated as above.
- Layout: preview in a right column beside the form on ≥ lg, stacked below on smaller widths.

## 2. Data flow

Edit → RHF state → `buildReceiptDoc` (pure, synchronous, < 1 ms) → preview. Save → `PUT /companies/{id}/receipt-settings` → invalidate `tenantScopedKey(['receipt-settings'])` → form and preview re-read. POS device → `/company/config` on its existing refresh cadence → next printed ticket uses the new display settings. Reprints follow current settings (B12).

## 3. Error handling

- `buildReceiptDoc` never throws: unknown `subtotalMode` → `'TTC'`; missing labels → the existing fallback table; empty company fields are skipped as today (`receipt_template.rs:333-340` filter ported).
- Logo switch disabled when `logo_path` is null, with the hint key `settings:receipt.logoMissing`.
- Rust encoder: unknown segment kind → skipped with a `log::warn!`, never a panic; transport errors unchanged.
- Old server (no new fields in `/company/config`) → defaults; old device (no `print_receipt_doc`) is impossible because web and POS ship together in the desktop build.

## 4. Defect fixes (all inside the port)

### 4.1 Matricule fiscale twice + QR
- `buildReceiptDoc`: emit the `N° TVA` line only when `normalize(vat_number) !== normalize(tax_id)` (trim, uppercase). The dedup is display-only; `resolveSellerIdentity` (`apps/pos/src/lib/fiscal/sellerIdentity.ts`) and the signed seller block are not touched (parity tests `saleReceiptV5CanonicalParity.test.ts`, `SaleReceiptV1V2ByteStability.test.ts` must stay green). Same rule for the Z header (`Header.tsx:639`, `printing.ts:262`).
- QR: drop the fiscal-hash QR (`receipt_template.rs:760-764`); the hash and signature stay as text (`:745-757`). Keep the token QR (`:775-788`) with label `pos:receipt.qrScanLabel` (« Scanner pour retour / échange »). Refund receipts keep their original-token QR with its existing label (`:496-497`).

### 4.2 Subtotal HT / TTC (ruling 7)
TTC (default; today's post-D-1 layout — see the golden-bytes rule in §5): `Sous-total` (TTC before remise) · `Remise` · VAT table or `TVA` line · `TOTAL`. HT (r3): `Sous-total HT` = Σ VAT net bases already on the ticket (string addition only) · VAT table · `TOTAL` (TTC); no Remise line, nothing derived. All values are already present as strings in `VatBreakdownLine` / `ReceiptData` (`printing.ts:144-223`); when a receipt lacks per-rate remise bases (pre-D-1 era, `buildReceiptData.ts:169-171`), HT mode falls back to TTC for that ticket and the builder marks `doc.fallback = 'subtotal-mode'` so a test can assert it. Labels: `pos:receiptLabel.subtotalHt`, `pos:receiptLabel.totalTtc` (fr/en; the POS has no ar locale).

### 4.3 Code page / encoding (Rust)
`PrintSettings` (`receipt_template.rs:265-280`): `cp437 → ESC t 0` + CP437 bytes (accents become `?`, honest), `cp858 → ESC t 19` + a static 128-entry CP858 table for U+00A0–U+00FF and `€`, `cp1252 → ESC t 16` + `WINDOWS_1252` (unchanged, the correct pairing for Epson TM / Xprinter). `ESC t` is **always** emitted (the `page != 0` guard at `:312-315` goes). `format_test_page_with_columns` (`:921`) and its command (`commands/printing.rs:107-112`) take `PrintSettings` so the operator's test page reveals a mismatch. Fixture expectation: `Café crème` → `43 61 66 E9 20 63 72 E8 6D 65` preceded by `1B 74 10`.

### 4.4 Currency placement
`formatReceiptMoney` (§1.1) formats every money cell once in TypeScript; the Rust encoder prints cells verbatim. The 17 `format!("{}{}", symbol, …)` sites disappear with `format_receipt_with_settings`. `voucher_ticket.rs:172` keeps its bug until E-2 (declared).

## 5. Testing

| Layer | What | Where |
|---|---|---|
| Vitest (shared) | `buildReceiptDoc` snapshots of the fixture: `{TTC,HT} × {logo on,off} × {42,32}`; dedup on/off by location; QR presence + label; HT fallback flag on a pre-D-1 receipt; `formatReceiptMoney` placement for `fr-TN`, `fr`, `en`; `padColumns` parity table copied from `escpos.rs:552-594` | `packages/shared/src/receipt/__tests__/` |
| Cross-language contract | Vitest writes/asserts `packages/shared/src/receipt/fixtures/sampleReceipt.doc.json` (committed); a Rust test loads the same JSON and snapshots the encoded bytes (`ESC t`, `E9`/`E7` bytes, no fiscal-hash QR, one labelled QR, `11.000 TND` right-aligned) | `apps/pos/src-tauri/src/printing/doc_encoder.rs` tests |
| Golden-bytes regression | Capture `format_receipt_with_settings` output for the fixture (sale, refund, Z) at 42 and 32 columns on **unchanged `origin/dev`** and commit them under `apps/pos/src-tauri/tests/golden/`. A Rust test encodes the same fixture through `doc_encoder` and asserts the byte diff is confined to four whitelisted regions: the dropped `N° TVA` line, the dropped fiscal-hash QR block, the `ESC t` prologue, and the currency cells. Any other byte difference fails the test. | `apps/pos/src-tauri/src/printing/doc_encoder.rs` tests + `tests/golden/` |
| Reprint fiscal parity | Explicit test: print a sale, change `subtotalMode` and `logo`, reprint → signed receipt payload, hash chain and DUPLICATA marker are byte-identical to the first print; existing parity suites (`saleReceiptV5CanonicalParity`, `SaleReceiptV1V2ByteStability`) stay green | `apps/pos/src/lib/fiscal/__tests__/` |
| Vitest (pos) | `printReceipt` calls `print_receipt_doc` with a `ReceiptDoc`; display settings defaults when `/company/config` lacks the fields; fiscal parity tests untouched and green | `apps/pos/src/lib/__tests__/` |
| Vitest (web) | `ReceiptPreview` renders the fixture text (`Café crème`, `Garçon`, `11.000 TND`), toggles re-render, 58 mm switch changes column count, logo placeholder appears; `ReceiptSettingsTab` new controls gated by `settings.update` | `apps/web/src/features/settings/components/__tests__/` |
| PHPUnit (scoped) | `UpdateReceiptSettingsRequest`: valid/invalid `receipt_subtotal_mode`, boolean `receipt_logo`; controller round-trip create→edit→revert; **second company** no bleed; **cashier 403** on PUT; save-twice idempotent; `/company/config` exposes the two fields; `ReceiptSettingsData` shape | `apps/api/tests/Feature/Company/ReceiptSettingsTest.php` (lane registered in `feature-lane-manifest.json`) |
| Playwright | settings → change logo/HT → preview updates before save → save → reload → persisted → revert; cashier cannot reach the tab; second company shows its own values | `apps/web/e2e/settings/receipt-preview.spec.ts` |
| i18n | every new `t()` key present in `apps/web/src/locales/{fr,en,ar}/` and `apps/pos` locales (`pnpm lint` audits) | — |
| Physical printer | **not verified** unless Dhouha prints the fixture on the terminal; the PR states this | — |

Gates: `pnpm --filter @autoerp/web test|lint|typecheck`, `pnpm --filter @autoerp/pos test|lint|typecheck`, shared package Vitest, `cargo test -p <pos crate> --lib` **once** at the gate under the machine budget (one process, swap < 9000M), `PREFLIGHT_TEST_PATHS='tests/Feature/Company' ./scripts/preflight.sh`, `scripts/run-feature-lane-local.sh` for the Company lane when Docker is up (Fable owns Docker).

## Delivery: two PRs

| PR | Content | Gate |
|---|---|---|
| PR 1 `feat/pos-receipt-preview` (this branch) | `packages/shared/src/receipt` + POS wiring + Rust `doc_encoder` + the four fixes (§4) + glossary row | shared/POS Vitest, golden-bytes + cross-language contract via **one** `cargo test` run, reprint parity, POS lint/typecheck |
| PR 2 `feat/pos-receipt-settings-web` (off PR 1) | API columns/enum/DTO/config read + web controls + `ReceiptPreview` + i18n | scoped PHPUnit (second company, cashier 403, idempotency), web Vitest, Playwright round-trip, scoped preflight |

## 6. Out of scope — tickets to open (DEV-QA registry + `tk`)

| Ticket | Item | Why deferred |
|---|---|---|
| E-1 | ESC/POS logo raster (`GS v 0` + monochrome) | ruling 5; needs a physical printer |
| E-2 | Port `voucher_ticket.rs` onto `ReceiptDoc` (header divergence, money bug at `:172`) | convention 11 finding; not in the brief |
| E-3 | Unify server PDF (`receipt.blade.php`) onto `ReceiptDoc` or declare it permanently divergent | B11 |
| E-4 | Gate `GET companies/{id}/pos-settings` (`Company/routes.php:43-44`) — **security finding**, cashier can read settings | out of brief; registry row |
| E-5 | Collapse the three header/footer surfaces | B2/B8 |
| E-6 | Paper width / printer per terminal, visible server-side | B7 |

## 7. Risks

1. Fiscal payload coupling: the dedup must never touch `resolveSellerIdentity`; parity tests are the guard.
2. Rust layout tests are removed with the template; their assertions must reappear as Vitest snapshots or the ratchet moves to a lane that does not run (convention 08).
3. `cargo test` memory on the laptop: one run, at the gate, after a swap check; if it cannot run, the PR says the Rust contract test is unverified locally.
4. Physical printer behaviour (`ESC t 16` support on the terminal's firmware) is unverified until Dhouha prints the fixture.
