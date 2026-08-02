# Ticket: authz gate follow-ups (F2 scoped settings DTO, F3 company-create abuse, F4 uuid 500)

From the authz fix-lane gate (2026-08-02, APPROVE-WITH-FIXES —
docs/superpowers/reviews/2026-08-02-authz-fixlane-gate.md). F1 (manager FE affordances) + F5/F7/F9
are being applied in-lane under the orchestrator ruling: **writes stay admin-only
(`settings.update`) — the previously-ungated routes were the anomaly, not manager entitlement; FE
save affordances must reflect the holder's real permission.**

## F2 — P1: reservation-settings read leaks anti-fraud thresholds to cashiers

Live: cashier GET `/companies/{id}/reservation-settings` → 200 including
`manager_override_threshold_amount`, `goodwill_four_eyes_threshold`,
`customer_history_search_alert_thresholds` — the thresholds that police that exact role. Do NOT
blanket-gate: POS/voucher clients legitimately read parts of it as cashier (`voucherApi.ts:63`,
`useCompanySettings.ts:28`). Fix = scoped DTO: split the response into a low-privilege slice
(what POS needs) and a `settings.view`-gated full view. Enumerate consumers before shaping.

## F3 — P2: POST /companies fully ungated (intentional, but unbounded)

Not privesc (gate verified team-scoped roles + no self-assign), but any viewer can create
unlimited companies, each seeding COA + hash chains + tax configs (storage/fiscal-object
amplification). Route comment says intentional for onboarding. Needs a product ruling: gate
behind a permission, or keep + add a per-tenant company-count/plan limit.

## F4 — P1: PG uuid guard absent on companies routes — GET /companies/my → 500

`CompanyController.php:180/226/500` — the KNOWN repo pitfall (validate `Str::isUuid()` before
`where` on uuid columns or PG 500s). Live-reproduced. Sweep the controller (and route-model-less
lookups in the module) and add the guard → 404 not 500.

## Also carried

F6 (logo DELETE inline-only — no hole, consistency); F8 (loginAsRole vacuous-pass hardening in
campaign helpers — `apiRequest` never throws, permissions default `[]`; make the helper fail loud).
