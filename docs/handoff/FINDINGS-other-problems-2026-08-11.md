# Findings — Other Problems (UI-Audit / First-Tenant Research session, 2026-08-10/11)

**Purpose:** catch-all dossier for problems surfaced during the 2026-08-10/11 UI-audit session that are **not** already owned by:
(a) the UI-audit wave plan (`docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/00-EXECUTIVE-REPORT.md` rev 5 + `docs/handoff/OWNER-DECISIONS-ui-audit-2026-08-10.md`),
(b) the whole-application event-sourcing consolidated register (`docs/sessions/EVENT-SOURCING-AUDIT-2026-08-11/00-CONSOLIDATED-REGISTER.md` + `docs/handoff/HANDOVER-event-sourcing-remediation-2026-08-11.md`),
(c) the shift-variance GL dossier (`docs/handoff/FINDINGS-shift-variance-gl-2026-08-11.md` — sibling deliverable of this same owner ruling, covering cash-count semantics, the whole-drawer ratification, blockers B1/B2/B3, and the takings-formula archaeology).

**Mode:** read-only collation. No source was modified. Evidence pointers trace back to the original research, which already carries CONFIRMED/SUSPECTED markers.

**Sources read in full:** `UI-PRESENTATION-AUDIT-2026-08-10/{00,10,11,12,13,14,15,16}.md`, `OWNER-DECISIONS-ui-audit-2026-08-10.md`, `EVENT-SOURCING-AUDIT-2026-08-11/{00,01,03,04,05}.md` (02 skimmed — no incidental non-event content beyond the register's own rows).

**Legend — Status:** UNOWNED (no lane claims it yet) · RULED-AWAITING-EXECUTION (owner ruled, work not landed) · OPEN OWNER QUESTION · OWNED-ELSEWHERE (navigation only).

> **Boundary correction, entry gate round 1 (2026-08-11).** Three rows violated this dossier's own "not owned elsewhere" definition and have been moved to **§E (cross-references, navigation only)** with their real owner stated: **OP-03** (owned by launch-program **E1** per `HANDOVER-event-sourcing-remediation-2026-08-11.md` §3 interactions table), **OP-17** (duplicates register row **ES-60**), **OP-18** (duplicates register row **ES-19**). Their IDs are retained — do not renumber — and the §Summary counts are recomputed accordingly. Verdict of record: [`docs/superpowers/reviews/2026-08-11-event-sourcing-entry-gate-verdict.md`](../superpowers/reviews/2026-08-11-event-sourcing-entry-gate-verdict.md).

## A. Launch-program items (6)

| ID | Finding | Evidence | Sev | Status | Suggested action |
|---|---|---|---|---|---|
| OP-01 | The 5(+1) `SUM(pos_receipts.total)` sign-blind aggregates named as a hard pre-enable gate for v4 refunds **appear already remediated** (`032e89dea` + `253e564b7`). The ticket `docs/superpowers/tickets/2026-08-01-positive-refund-total-consumers.md` is stale and still cited as an open gate in the launch-program ledger. | `12-research-receipts-web-view.md` §4.4; `14-research…` §3.3, C-i | P3 | RULED-AWAITING-EXECUTION | Verify against current `dev`, close the ticket, remove from the pre-enable gate list. |
| OP-02 | **A-1 accountant access — RULED, not executed.** `/settings/compliance/export` gated `moduleKey="pos"` (→ POS-operator perms); accountant holds none of them but does hold the `compliance.*` grants the page hosts. | `14-research…` §0 fact 4, C-5, A-1 (`routes/index.tsx:2474-2482` vs `usePermissions.ts:56`) | P1 | RULED-AWAITING-EXECUTION | Re-gate on a compliance permission; grant accountant role; deploy = seeder + `permission:cache-reset`. |
| OP-04 | v4 refund capability §16.6: a second device terminal does NOT auto-disable an already-acknowledged refund capability. | `14-research…` §1.3, E1-2 | P2 | UNOWNED | Add to E1 checklist as known-gap acceptance, or fix if terminal #2 is imminent. |
| OP-05 | Refund-rounding delta (D/2=0.025 TND) must be explicable on screen at refund-enable. | `14-research…` §3.2, E1-3 | P2 | UNOWNED | Fold into refunds-register UI work as explicit line item. |
| OP-06 | **E-7 launch gate Status cell still blank** — Lane C merged but not human-signed-off. | `14-research…` §1.3, E1-4 (`OWNER-manual-launch-gates-2026-07-31.md:45`) | P1 | RULED-AWAITING-EXECUTION (human) | Owner/runbook: mark E-7 explicitly. |
| OP-07 | "POS is not a module" — no `ModuleName` case, no `verticals.php` key, `hasModule('POS')` nowhere. Also: 6 POS controllers with zero authorization calls. | `14-research…` §0 fact 5, §5, C-e, C-f; owner escalation "separate packaging lane" | P3 | UNOWNED | Own future lane if POS is sold as a paid module; file the 6-controller authz gap separately (tenancy-authz). |

## B. Small unowned lanes (8)

| ID | Finding | Evidence | Sev | Status | Suggested action |
|---|---|---|---|---|---|
| OP-08 | Sales-withholding-tracking (`/treasury/sales-withholding-tracking`) has no write path — `POST /documents/{id}/record-withholding` has zero web callers; FE/BE permission mismatch too. | `10-verification…` item 4; `OWNER-DECISIONS…` VERIFY-resolved | P2 | UNOWNED (owner wants the page) | New small lane: add "Record withholding" action, align FE guard, then link. |
| OP-09 | A-2 — accountant POS-permission grant scope open: `pos.view_receipts` only, `+pos.view_reports`, or `+dashboard.owner`? | `14-research…` §7 Q1, A-2 | P2 | OPEN OWNER QUESTION | Owner ruling, then seeder grant + cache-reset. |
| OP-10 | No tenant-facing override/manager-PIN audit surface — `audit.view` enforced at zero API call sites. | `14-research…` §2.2, C-9, C-a | P3 | UNOWNED | Future lane: endpoint + real audit page. Not launch-required. |
| OP-11 | No scheduled fiscal-chain verification (`pos:verify-chains`/`fiscal:verify-event-chain` CLI-only). | `14-research…` §2.4, C-10, C-b | P3 | UNOWNED | Scheduled command + failure alerting. |
| OP-12 | Daily dead-lettered-projections + `refund_policy_alerts` check is CLI/curl-only despite E-9 runbook asking daily. | `14-research…` §2.2, C-6, B-7 | P2 | UNOWNED | Small ops panel, or fold into the Compliance section (OQ-10) if convenient. |
| OP-13 | Partner Detail "Documents" tab: unstyled badge + missing i18n key + wrong route (`/sales/invoices/{deliveryNoteId}`) for delivery/return-note rows — independent of the DN-consolidation feature. | `13-research…` §1.1 (`PartnerDetailPage.tsx:662,677,679`) | P2 | UNOWNED | Standalone bug fix (badge/i18n for all doc types, or filter the tab). |
| OP-14 | `pos.void_receipts` still seeded to `manager` though the void route is a 410 tombstone. | `12-research…` §5(d); `14-research…` C-h | P3 | UNOWNED | Housekeeping on next seeder touch. |
| OP-15 | `/pos/shift-history`: tenant-scoped not location-scoped; `parseFloat` on money (rule-19 violation). | `14-research…` §2.2, A-7 (`ShiftHistoryPage.tsx:33,161,177,180,191-197`) | P2 | UNOWNED | Standalone FE fix (`locationScopedKey`, `formatCurrency`); adjacent to but not owned by the shift-variance-GL dossier. |
| OP-21 | `TermsOfServicePage.tsx:37-38,49-50,107-108,131-132` hardcodes the brand six times in non-`t()` inline strings with its own FR/EN branching — brand leak + rule-11 i18n violation, larger than OQ-1's app-name-placeholder scope (found 2026-08-11 during Wave 0 brief verification). | `CODEX-DISPATCH-ui-wave0-2026-08-11.md` out-of-scope findings | P2 | UNOWNED | Small lane: rewrite ToS page copy through i18n + `useProductConfig().productName`; coordinate with legal-copy owner. |

## A2. Late additions (2026-08-12, from research 17/18)

| ID | Finding | Evidence | Sev | Status | Suggested action |
|---|---|---|---|---|---|
| OP-22 | **Unit-designation print obligation (FR consumer price law: Arrêté 83-50/A art. 3 services ≥25€; Arrêté 3-déc-1987 art. 8 weight/measure goods) is unsatisfiable today**: POS receipt lines have no truthful unit (OI-17) and B2B `document_lines` has **no unit column at all**. Remedy path: canonical `unit` at fiscal event_version 5 (POS, queued lane per `18-research-nf525-chain-vs-sidecar.md`) + a document_lines unit column (B2B, separate lane). | `18-research…` §4 | P2 (FR-launch relevant; TN advisory) | UNOWNED — two lanes proposed | Queue the ev5 unit lane (device+server); ticket the B2B unit column. |
| OP-23 | **Second A-1-class dead-permission bug**: accountant holds `fraud-alerts.view`/`fraud-settings.view` but both routes gate `moduleKey="settings"` which the role can't pass. Same fix pattern as A-1. Also: A-1's re-gate needs a NAMED replacement gate — no `compliance` moduleKey exists. | `17-research…` Part 2 | P2 | UNOWNED → fold into receipts-build handover A-1 work | Re-gate both routes alongside A-1 with the composite compliance permission key. |

## C. Standing design constraints (1)

| ID | Constraint | Evidence | Why it matters |
|---|---|---|---|
| OP-16 | Every PDF fetch of a receipt writes a fiscal reprint-audit row + increments `copy_number` (NF525 duplicate marking). | `12-research…` §4.7; `14-research…` C-8 | Constrains ANY future UI with a print button — reprint must always be a deliberate, confirmed detail-page action, never a row-level list button. |

## D. Runtime-confirmation tasks (2)

| ID | Finding | Evidence | What to confirm |
|---|---|---|---|
| OP-19 | Fresh-tenant seeded `payment_methods` set never checked against a live UI (page was orphaned until this session; 6 features read its list). | `10-verification…` item 3, secondary note | Staging check before the newly-linked config page goes in front of a customer. |
| OP-20 | ~~Unconfirmed whether `TerminalResource` emits `v4_refund_authoring_enabled`/`_acknowledged_at`.~~ **RESOLVED 2026-08-11 during receipts-spec drafting**: `TerminalResource.php:134-135` DOES emit both fields; the actual gap is the access path (`TerminalController` reads require `pos.manage_terminals`) — addressed by the spec's S-7 filter-options endpoint. See `SPEC-pos-receipts-reporting-2026-08-11.md` (OI-6). No runtime check needed. | `14-research…` §3.1 A-5; spec verification | — |

## E. Cross-references (navigation only)

**Moved here at entry gate round 1 — owned elsewhere, listed for navigation only. Do not action from this dossier.**

| ID | Finding | Real owner |
|---|---|---|
| **OP-03** | TN VAT declaration doesn't net POS refunds — `EloquentVatDataRepository` has no `receipt_type` discrimination on the POS arm (unlike the credit-note arm); overstates output VAT once refunds enable (`14-research…` §3.4). | **Launch-program E1 refund-enable block.** The handover's interactions table already assigns it: *"The VAT refund-netting gap belongs to E1, not here."* The suggested action (ticket mirroring the credit-note fix + explicit E1 checklist entry) is **E1's to take**. |
| **OP-17** | `stock_movements` is append-only; a row must never be rewritten post-insert — `StockTransferService::markMovementAsTransfer` violates it (event payload contradicts persisted row). | **Register row ES-60** (Lane E). The event-sourcing register owns the fix, including its "fix at insert, not by another post-hoc `UPDATE`" entry criterion. The *standing principle* — review every future stock-movement writer against append-only — survives as guidance, but it is not an unowned item. |
| **OP-18** | Training-mode POS may THROW on operational events (not just fail to audit) — `validateChainContext` may reject before any mutation; SUSPECTED (static only). | **Register row ES-19** (Lane A1), carried there as SUSPECTED. The runtime confirmation on a real device build against staging is **A1's verification step**, not a separate task. |

- **DN-consolidation build** owns: P0 eligibility-filter no-op, `sales.create` alias vs `invoices.create` mismatch (C1), DB double-invoice guard, role-matrix split, dead `consolidation_frequency`, linking `/finance/lane-separation`.
- **Receipts + analytics build** owns: `GET /pos/receipts` filter/field gaps (`location_ids`, `invoice_type_code`, `training_flag`, `fiscal_status`), `canonical_bytes` over-exposure, `quantity_decimals`, refunds-register build, the two broken `/pos/receipts/:id` links, `ShiftReceiptsList` deletion.
- **Shift-variance GL dossier** owns: whole-drawer ratification, B1/B2/B3, takings-formula archaeology, `config/treasury.php` doc corrections, gap-register A-8/A-9/B-3/B-9.
- **Event-sourcing consolidated register** owns all ES-01..ES-88.
- **UI-audit wave plan** owns every resolved VERIFY-then-act ruling (`/pos/shifts` deletion, `/scheduling/capacity` linking, chart-of-accounts dedup, `/marketing`/`/finance` deletion, `/pos/transactions` CTA realignment).

## Summary

**17 items outside the owned lanes**, from the 20 originally collated (OP-01–OP-20); **3 are owned elsewhere and moved to §E** (OP-03 → launch E1, OP-17 → ES-60, OP-18 → ES-19).

| Section | Items | IDs |
|---|---|---|
| A — Launch-program | **6** | OP-01, OP-02, OP-04, OP-05, OP-06, OP-07 |
| B — Small unowned lanes | **8** | OP-08 … OP-15 |
| C — Standing design constraints | **1** | OP-16 |
| D — Runtime-confirmation tasks | **2** | OP-19, OP-20 |
| **Total outside owned lanes** | **17** | |
| E — Cross-references (owned elsewhere, navigation only) | *(3)* | OP-03, OP-17, OP-18 |

**By status** (the 17): **3** RULED-AWAITING-EXECUTION (OP-01, OP-02, OP-06) · **1** OPEN OWNER QUESTION (OP-09) · **10** UNOWNED (OP-04, OP-05, OP-07, OP-08, OP-10 … OP-15) · **1** standing design constraint, no status (OP-16) · **2** runtime-confirmation, unverified (OP-19, OP-20).

*Note: the previous summary's status split (4 / 2 / 11 / 3) did not reconcile against the tables even before the three moves — it over-counted RULED-AWAITING-EXECUTION and OPEN OWNER QUESTION, and omitted the standing-constraint category. The counts above are recomputed row by row from the tables.*
