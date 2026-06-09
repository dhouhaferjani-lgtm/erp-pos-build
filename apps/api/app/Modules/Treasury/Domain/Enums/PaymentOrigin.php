<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

/**
 * Origin of a Treasury `payments` row — which surface authored it.
 *
 * Phase 1 §13 + §7.5 (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md`):
 * the `payments` table gains `origin` + `fiscal_event_id` so the Treasury
 * module can be activated independently per `(tenant, company)` via the
 * `ModuleActivationResolver`. The fiscal engine publishes events; the
 * `TreasuryReceiptBridge` (Task 22) is the projector that — when Treasury
 * is active — creates `Payment` rows from `SALE_RECEIPT` events. Rows
 * authored before this migration (legacy) get `origin = NULL`; the
 * Task 22 backfill / Task 12 production model writers tag new rows
 * explicitly.
 *
 * Token grammar:
 *  - `pos`            — authored by the POS device authority (Tauri or web POS)
 *  - `web_admin`      — authored by an admin-side flow in the web shop
 *  - `mobile`         — authored by a mobile client
 *  - `api`            — authored by a programmatic external API caller
 *  - `unknown_legacy` — pre-migration row whose origin cannot be inferred
 *  - `back_office`    — authored by a server-side back-office flow (e.g. the
 *                       DEPOSIT_RECEIPT treasury bridge: a payment toward a
 *                       customer account recorded without a POS terminal)
 *
 * The string values are stable — they are persisted in the `payments.origin`
 * column. Adding a new origin in a future phase MUST append to this list,
 * never rename or reorder.
 */
enum PaymentOrigin: string
{
    case Pos = 'pos';
    case WebAdmin = 'web_admin';
    case Mobile = 'mobile';
    case Api = 'api';
    case UnknownLegacy = 'unknown_legacy';
    // Appended (never reorder existing cases) — see grammar note above.
    case BackOffice = 'back_office';
}
