M5 owner_gate OQ-12-H-5-count-correction-live RULED (parent orchestrator, 2026-08-19): the gate is AMENDED — the treasury ruling of record (TREASURY-RULING-2026-08-19-t20-option-a.md) queues expert-comptable ratification before count-correction posting GOES LIVE, and explicitly instructed 'record that in the map document and proceed'. Going live means the production flag flip at deploy time — not the dormant implementation. M5's T21/T22 implementation therefore PROCEEDS with the feature flag OFF and both count-correction purposes dormant, exactly as 3C shipped them. The expert-ratification obligation is retained as a DEPLOY-TIME blocker: it stays in this YAML's blockers list (reworded to bind the flag flip), goes in the handback, and the parent carries it on the LEDGER at merge — the flag must not be enabled for any tenant before the ratification is recorded. M5 additionally ADOPTS, as its opening work, the three preserved commits on ref codex/reviewer-round6-unauthorized-mutations (2abf436ea red tests, 3e8d320ea third-plan template completion fallback, 5cdfbc49a checklist qualifiers + requiredPurposes asymmetry documentation) — they close M4-round6's own recorded finding 1 (pre-policy template with no expense-typed 65/6000 hard-aborts tenant registration) and were reverted only because the round-6 process misattributed them to its reviewer; they are unreviewed and fall under M5's own bridge review like any other M5 work.


---

## SUPERSEDED 2026-08-25 — the deploy-time blocker is lifted (lane P-1)

The 2026-08-19 ruling above stands as the record of what was decided THEN, and is not rewritten. Its
operative half — "the flag must not be enabled for any tenant before the ratification is recorded" —
is **SUPERSEDED** by the owner ruling of 2026-08-25.

**The owner ruled:** `inventory.count_correction_gl_posting_enabled` is seeded **true**. Perpetual
inventory means a stock-take difference must reach the ledger, so a dormant count correction is the
wrong resting state for tenant #1, not the safe one. The expert-comptable reviews the Option A
account choice (6586 shortage / 7586 overage) **later at onboarding**; that review is no longer a
gate in front of the flip. Sequencing was "after W4-6 lands" — W4-6 merged as `7833fa144`, whose F-2
pre-count-window fix is what makes the resulting journal entry correct.

Record: `docs/handoff/OWNER-SHEET-2026-08-21-first-client-session.md` (2026-08-25 entry) and
`docs/sessions/session-A-2026-08-24/BRIEF-P1-count-correction-gl-default.md`.

**What lane P-1 implemented, and why the shape changed.** The blocker treated the flag as one global
deploy switch. A per-tenant, per-country setting is what the country-defaults design already
prescribes for this family, so the flip landed in that shape rather than as an `env` edit:

* `country_inventory_settings.count_correction_gl_posting_enabled` — NOT NULL, DEFAULT true, seeded
  ON for every seeded country (TN, FR) and PINNED on every provisioning run by
  `CountryInventorySettingsSeeder`, exactly as the sibling `inventory_valuation_mode` is;
* `companies.count_correction_gl_posting_enabled` — NULLABLE and UNDEFAULTED, the tenant override,
  editable through `PATCH /api/v1/settings/company`; NULL means "never decided", which is what lets
  the backfill migration tell an untouched tenant from one that opted out;
* `CountCorrectionGlPostingResolver` — company override → country row → system default, carrying the
  `source` so an operator can tell their own setting from an inherited one;
* `config('inventory.count_correction_gl_posting_enabled')` — no longer THE gate. It is the SYSTEM
  link of that chain, now defaulted **true** and still `env()`-overridable, i.e. a deployment-wide
  kill switch rather than the tenant-facing control.

**What this does NOT lift.** Nothing here ratifies the Option A liasse presentation. The expert
review is not cancelled, it is re-sequenced to onboarding, and it now has a lever that does not need
a deploy: a tenant whose accountant disagrees turns posting off in settings, keeps the costed
movement rows, and can rebuild the ledger later. LEDGER row **C-33 (iv)** carries the review.

**Carried forward unchanged:** W4-6 residual **R-8** — the pre-count half of the ± ambiguity window
resolves by posting, so a `basket_window` line whose nearby movement was booked before the count but
shelved after it now produces a wrong *journal entry* as well as a wrong stock correction. That
consequence was contingent on this flip and is now live.
