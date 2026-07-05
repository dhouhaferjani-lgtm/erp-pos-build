# POS — Activation Hardening (Option A from research)

**Status:** Planned, not started
**Owner:** TBD
**Scope:** `apps/pos` (frontend), `apps/api` (backend — token lifetime config + small endpoint)
**Estimated effort:** 8–12 working days across 4 phases (can ship phases independently)
**Coordination risk:** Low — touches `authStore`, `AppShell`, `LoginPage`, `TerminalSetupPage`, `settingsStore`. **No overlap with refund-flow (cart/payment) or POS performance (catalog/image) sessions.**

---

## Source documents

- **Research:** [`docs/superpowers/research/2026-04-30-pos-first-launch-offline-activation-research.md`](../research/2026-04-30-pos-first-launch-offline-activation-research.md)
- **Audit:** [`docs/superpowers/audits/2026-04-30-pos-offline-first-audit-codex.md`](../audits/2026-04-30-pos-offline-first-audit-codex.md) (Claude second-opinion audit underway in parallel session)

## Strategic context

The research concluded: **no mainstream POS supports a fully offline first launch** (Square, Shopify, Lightspeed, Toast, Clover, Stripe Terminal, SumUp, Loyverse, Odoo, MS Dynamics all require one online round-trip on day one). Vendors that *appear* offline at first boot pre-activate hardware at the warehouse — a lever unavailable to a software-only POS on commodity laptops.

Decision: **keep the existing one-time-online activation model and harden it.** Reserve per-merchant signed installers as a future enterprise tier, not for parapharmacy go-live.

This plan implements the four pillars of the recommendation:

1. **Demo mode** — let cashiers explore the UI before any account or activation.
2. **Longer-lived device token** — extend Sanctum token from 30 days to 12 months for POS terminals (audit + change).
3. **Better mid-bootstrap error handling** — no silent indefinite spinners when the network drops between login and terminal hydration.
4. **Phone-tether wizard** (deferred to Phase 4 — nice-to-have, not gating).

---

## Phase 1 — REPLACED: Surface existing Training Mode in POS (4 hours)

> **Replaces the original "build demo mode from scratch" Phase 1.**
> Investigation revealed an existing Training Mode feature that is fully wired backend + web back-office, but completely invisible in the POS desktop UI. Building a separate demo system is unnecessary.

### What Training Mode actually does (verified)

- **DB column:** `terminals.is_training_mode` (boolean).
- **Toggle endpoint:** `POST /pos/terminals/{id}/toggle-training` (`apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:480`).
- **Receipt isolation:** `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:466–485` — training receipts get a separate receipt-number prefix and **skip the hash chain entirely** (no `fiscal_hash`, no `previous_hash`, no `chain_sequence`). They don't pollute NF525 fiscal records.
- **Reporting isolation:** `Terminal::scopeProduction()` filters `is_training_mode = false`. Training receipts excluded from Z reports and fiscal exports. NF525 export counts them separately as `MODE_FORMATION` events.
- **Domain event:** `TerminalTrainingModeChanged` is emitted on toggle. Compliance listener writes an audit row.
- **Web back-office UI:** Terminals page has toggle button + badge.
- **POS desktop UI:** **NOTHING.** Zero references in `apps/pos/src` (verified by grep).

### Important caveat (user mental-model correction)

Training mode is **not** "zero-write" — it persists receipts, customers, line items as normal DB rows. What it skips is the **fiscal hash chain**, so the writes are fiscally inert (won't affect NF525 / Z reports / chain integrity). For the cashier-training use case, this is functionally equivalent to a sandbox: the cashier can do anything and it won't hurt the fiscal record. But the data is in the DB.

If the user later wants true zero-write (no DB persistence at all), that becomes a separate, larger feature.

### Goal of this phase

Make the POS desktop **visibly aware** of training mode so cashiers know what state the terminal is in. The backend already does the right thing — we just need the UI surface.

### Design

- **Sticky banner** at the top of the POS (between Header and main content): yellow/amber background, "MODE FORMATION — Aucune vente réelle enregistrée" (i18n key already exists: `trainingModeEnabled`).
- **Visual differentiation:** subtle background tint (e.g., diagonal stripes or amber border) on the cart panel when in training mode, so the cashier can never confuse training and production at a glance.
- **Receipt printing UI:** receipt confirmation modal shows the training prefix prominently. ("Reçu de formation #FORM-2026-0001" instead of "Reçu #2026-0001".)
- **Telemetry / source of truth:** `terminalStore` already fetches the terminal record on shift open. Add `is_training_mode` to the type if it's not already there, expose via selector, gate UI on it.
- **No toggle in POS:** training mode toggle stays in the back-office. The cashier sees the state but can't change it (consistent with current model).

### Deliverables
- `apps/pos/src/stores/terminalStore.ts` — surface `is_training_mode` (likely just type addition; field is in `TerminalResource` JSON).
- `apps/pos/src/components/TrainingModeBanner.tsx` (new) — sticky banner component.
- `apps/pos/src/components/AppShell.tsx` — render banner above `<main>` when terminal is in training mode.
- `apps/pos/src/components/organisms/TransactionCart/TransactionCart.tsx` — optional cart tinting (small CSS change).
- `apps/pos/src/locales/fr/common.json` and `en/common.json` — confirm `trainingModeEnabled` key works (already exists for AR; verify FR/EN).
- Optional: `CheckoutSuccessModal` — emphasize training prefix in the receipt number display.
- Tests: terminalStore selector test, banner-visible-when-training-mode component test.

### Coordination
- Touches `terminalStore`, `AppShell`, `TransactionCart`, `CheckoutSuccessModal`. Refund flow may also touch `TransactionCart` and `AppShell`. Conflict surface is small (banner is additive).
- Recommend: ship after Phase 2 (bootstrap error handling) so the AppShell refactor lands first.

---

## Phase 2 — Token lifetime audit + extension (1 day)

### Goal
Extend the Sanctum bearer token lifetime for POS terminals from 30 days (default) to 12 months. Audit current behavior so we know what cashiers actually experience today.

### Current state (audited)
- `apps/api/config/sanctum.php:53` — `'expiration' => env('SANCTUM_TOKEN_EXPIRATION', 43200)` (30 days in minutes).
- `apps/pos/src/stores/authStore.ts:188` — `checkSession()` hits `/api/v1/auth/me`. Returns 401 when token expired → triggers logout → forces re-login.
- The 30-day expiration applies to **all** Sanctum tokens, not POS-specific. Web back-office sessions also expire at 30 days.

### Design

- **Per-token expiration via abilities:** create a POS-specific ability/scope (e.g. `pos-terminal`) when issuing a token from a terminal-pairing flow, and override expiration in the token-creation call (`$user->createToken('pos-terminal', ['pos:*'], expiresAt: now()->addYear())`). Web back-office tokens stay at 30 days; POS tokens get 12 months.
- **Backend change:** locate the `/auth/login` controller (`apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php` or similar). When the request includes a header like `X-Client-Type: pos-tauri`, issue the long-lived token; otherwise default. Keep web sessions unchanged.
- **Frontend change:** `apps/pos/src/lib/api.ts` adds the `X-Client-Type: pos-tauri` header on every outbound request. This single line announces the client to the backend.
- **Refresh strategy:** when the cashier opens a shift and `checkSession()` succeeds, the backend silently refreshes the token's expiry (sliding window) by reissuing or by extending in-place. POS that's used regularly never needs to re-login. POS that sits idle 12+ months gets a forced re-login (acceptable).
- **Display:** add a small "Last verified online: X ago" indicator next to the cashier's name in the header so the merchant knows when the device last touched the network.

### Deliverables
- `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php` — branch on `X-Client-Type` header
- `apps/api/config/sanctum.php` — keep default at 30 days, add a comment about per-client overrides
- `apps/pos/src/lib/api.ts` — add `X-Client-Type` header
- `apps/pos/src/components/Header.tsx` — "Last verified" indicator
- `apps/pos/src/stores/authStore.ts` — track `lastSessionVerifiedAt: number | null`
- Tests: backend feature test asserting POS header → 12-month token; frontend unit test for the header indicator

### Risks
- **Token revocation:** with 12-month tokens, a stolen device is exposed for up to a year. Mitigation: every successful `checkSession()` re-validates server-side that the token is still in `personal_access_tokens` and the user is still active. An admin can revoke a token via Filament back-office, and the next checkSession call (which the POS does on every shift open) will return 401 and force logout.
- **Migration of existing tokens:** existing POS tokens stay on the old 30-day expiry until they expire and the cashier re-logs in. No backfill needed.

---

## Phase 3 — Bootstrap error handling (3 days)

### Goal
Eliminate the silent indefinite-spinner failure mode where internet drops between the `/auth/login` call and the terminal/company hydration, leaving the cashier on a loading screen with no error message.

### Current state (audited)
- `apps/pos/src/components/AppShell.tsx` — sequential `await` chain: login → fetchCompanies → fetchCompanyConfig → checkTerminal → openShift screen. Each step has its own loading state but **no timeout**, **no retry**, **no user-facing error toast** when an intermediate step fails.
- `apps/pos/src/stores/authStore.ts:188` — `checkSession()` rethrows on non-401 errors but the AppShell layer doesn't catch them — they bubble to the React error boundary which shows a generic "Something went wrong" page (or worse, just hangs).
- The Codex audit (P1 finding) identified this as a real failure mode.

### Design

A standardized **bootstrap state machine** with explicit transitions:

```
   ┌──────────────────────────────────────────────────┐
   │             BootstrapState (Zustand)             │
   ├──────────────────────────────────────────────────┤
   │ phase: 'idle' | 'authenticating' | 'fetching-   │
   │   company' | 'fetching-terminal' | 'opening-    │
   │   shift' | 'ready' | 'error'                    │
   │ error: { message, code, recoverable } | null    │
   │ lastSuccessfulPhase: phase | null               │
   │ retryCount: number                              │
   └──────────────────────────────────────────────────┘
```

- Each phase has a **15-second timeout** (configurable). On timeout: state machine moves to `error`, surfaces a toast/banner with what failed and a "Retry" button.
- On retry: re-runs from `lastSuccessfulPhase` instead of starting over. (No re-login required if only the company-fetch step failed.)
- "Skip and use cached" option appears if SQLite has a usable cached version of the failing data (e.g., last-known company config). Lets the cashier work offline-first even mid-bootstrap.
- A new `<BootstrapErrorScreen>` component replaces the indefinite spinner with: the failed step name, error message in plain language, retry button, "use cached data" button (when available), and a "View details" expander for advanced users.

### Deliverables
- `apps/pos/src/stores/bootstrapStore.ts` (new)
- `apps/pos/src/components/AppShell.tsx` — refactor to use the state machine
- `apps/pos/src/components/BootstrapErrorScreen.tsx` (new)
- `apps/pos/src/lib/bootstrap/withTimeout.ts` — small Promise-with-timeout helper
- Tests: state-machine transitions, timeout behavior, retry-from-last-phase, fallback to cached data
- Manual: simulate network drop after login by killing the API mid-flight; confirm error screen appears within 15s and retry works

### Risks
- **Test flakiness:** timing-sensitive tests are hard. Use fake timers (vitest `vi.useFakeTimers()`) and inject a clock into `withTimeout`.
- **Coordination with refund-flow:** AppShell.tsx is touched. Refund flow may also touch AppShell (to add a /refund route). Keep this phase's changes scoped to the `<main>` boot logic — don't refactor route definitions.

---

## Phase 4 — Phone-tether wizard (deferred, ~5 days)

### Goal
When the cashier is at the pharmacy with no WiFi but has a phone with 4G, walk them through enabling Personal Hotspot on their phone, connecting the laptop to it, and completing first-time activation.

### Why deferred
This is UX guidance, not engineering. It can be a wizard slide deck in the activation flow rather than a code change. **Defer until after Phases 1–3 ship and we see whether real merchants actually hit "no internet at install" in the field.**

### Sketch (for later)
- Detect `navigator.onLine === false` at the LoginPage level.
- Show a 3-step wizard: "1. Open Settings on your iPhone/Android. 2. Turn on Personal Hotspot / Tethering. 3. Connect this laptop to your phone's network." with platform-detected screenshots.
- Once `online` event fires, auto-advance to the login form.
- Track in telemetry whether the wizard was shown and whether activation succeeded after.

---

## Phase ordering & merge strategy (UPDATED 2026-04-30)

Confirmed by user, ordered by impact-per-effort:

1. **Phase 2 (token lifetime, 1 day)** — GO. Smallest, lowest risk, biggest day-one QoL win. Cashiers stop seeing "Session expired" mid-shift after monthly token churn.
2. **Phase 3 (bootstrap error handling, 3 days)** — GO. Directly addresses Codex P1 audit finding. Eliminates the silent indefinite-spinner failure mode on flaky internet.
3. **Phase 1 (surface existing Training Mode, 4 hours)** — GO, but smaller scope than originally planned. Replaces the "build demo mode" idea with "make the existing Training Mode visible in the POS UI." Backend is already done.
4. **Phase 4 (phone tether wizard)** — DEFER. Wait for field data showing real merchants hit "no WiFi at install."

Each phase ships as its own PR. No phase blocks another (they touch different files and stores).

## Open questions for the human

- **POS device fleet:** how many terminals will the parapharmacy have at go-live? If just one, the Phase 4 phone-tether wizard is overkill. If three or more, training/demo mode (Phase 1) becomes more valuable.
- **Token revocation UX:** today, revoking a POS token requires a Filament admin to delete the row from `personal_access_tokens`. Is that workflow acceptable, or do we need a "Remote sign-out" button in the back-office?
- **Sliding window vs hard expiration:** if the merchant's POS sits unused for 11 months (closed for the season), should the token still expire at 12 months and force re-login, or extend on every successful session? Recommend hard expiration to limit blast radius of a stolen device.
- **Demo mode and fiscal compliance:** does any French regulator (NF525) need to be informed that we ship a demo mode? Recommend yes-and-document — the demo banner is permanent and demo state never persists, so no fiscal records are created.

## Acceptance (per phase)

### Phase 1 (Demo Mode)
- [ ] "Try a demo" link visible on LoginPage (FR + EN).
- [ ] Clicking it loads a populated cart UI within 1 second, no API calls fire.
- [ ] Sticky banner "Demo Mode" visible at top throughout the session.
- [ ] All write operations (checkout, hold, discount) appear to work but produce no SQLite or API traces.
- [ ] "Exit Demo Mode" returns to LoginPage with all state cleared.
- [ ] Unit test asserts demo session never calls SQLite repos or fetch().

### Phase 2 (Token lifetime)
- [ ] Backend test: login with `X-Client-Type: pos-tauri` issues a token expiring in 12 months ± 1 hour.
- [ ] Backend test: login without that header issues a 30-day token (no regression for web).
- [ ] Frontend: api.ts sends the header on every request from POS.
- [ ] Header indicator shows "Last verified: just now" after successful login, updates on every checkSession.
- [ ] Manual: log in, advance system clock 6 months in dev mode, confirm session still works.

### Phase 3 (Bootstrap error handling)
- [ ] State machine in `bootstrapStore` covers all 7 phases.
- [ ] Each phase times out at 15 seconds with a user-visible error.
- [ ] Retry button works from `lastSuccessfulPhase` (no full re-login).
- [ ] "Use cached data" button appears when SQLite has the failing resource.
- [ ] Manual: kill the API server during shift open. Confirm error screen appears within 15s, retry works after API comes back, no indefinite spinner.

### Phase 4 (Phone tether) — deferred, no acceptance yet.

## Out of scope

- Per-merchant signed installer (research concluded: overkill, defer to enterprise tier).
- Pre-activation USB-key provisioning (logistical complexity, no demand signal yet).
- Hardware TPM / secure-element integration (we run on commodity laptops, not POS hardware).
- Multi-tenant / multi-company switching during demo (one demo company is enough).
