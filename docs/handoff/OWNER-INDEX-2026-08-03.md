# Owner index — 2026-08-03 (post fix-lane marathon, pre wave-campaign)

Everything you need in one place. State of truth: `origin/dev` = `cce3491b6` (all fix lanes
landed + gated + promoted; staging auto-deployed; zero migrations in the batch).

---

## 1. Les trois questions pour l'expert-comptable (à poser telles quelles)

**Q1 — Timbre fiscal sur avoir (note de crédit) : réduit-il la créance client ?**
> Lorsqu'un avoir porte son propre droit de timbre (0,600 TND `STAMP_CREDIT_NOTE`), le montant
> total de l'avoir (montant crédité + timbre) doit-il réduire la créance du client (compte 411),
> ou seul le montant crédité hors timbre réduit-il la créance, le timbre étant traité comme une
> charge fiscale distincte ? Aujourd'hui notre grand livre crédite le 411 du total timbre inclus,
> tandis que le lettrage (sous-ledger) n'impute que le montant hors timbre plafonné au solde de la
> facture — les deux doivent être alignés sur votre réponse.
- Contexte technique : `docs/superpowers/tickets/2026-08-03-credit-note-regate-carryovers.md` (§N1).
- ⚠️ Bloque la certification GL des avoirs (feeds `project_accounting_gl_roadmap` A1).

**Q2 — TVA déductible partielle sur charges : quelle base déclarer ?**
> Pour une charge dont la TVA n'est déductible qu'en partie (ex. 80 %), la base à reporter dans
> la déclaration TVA (côté déductions) est-elle la base au prorata de la déductibilité
> (80,000 sur une charge de 100,000 HT) ou la valeur faciale totale de la transaction
> (100,000) ? Nous appliquons actuellement la base au prorata (identité base × taux = TVA
> déduite) ; merci de confirmer ou corriger.
- Contexte : `docs/superpowers/reviews/2026-08-03-vat-declaration-gate.md` (§V5) — le choix est
  documenté dans le docblock de `ExpenseService`.

**Q3 — Vérification du mapping des cases de la déclaration TVA (formulaire DGI) :**
> Pouvez-vous vérifier, contre le formulaire DGI en vigueur, notre composition de la déclaration
> TVA : (a) le droit de timbre est présenté sur une ligne spéciale distincte et EXCLU des lignes
> de TVA collectée par taux ; (b) le chiffre d'affaires exonéré/à 0 % alimente la case « base
> 0 % » ; (c) les avoirs viennent en DÉDUCTION des bases et TVA collectées ; (d) les bases par
> taux correspondent au net réellement taxé à chaque taux (remises déduites). Merci de valider
> chaque point ou d'indiquer la case correcte.
- Contexte : `docs/superpowers/reviews/2026-08-03-vat-declaration-gate.md` (verdict final
  MERGE-READY, §"Re-gate fix round").

---

## 2. Your staging steps (§A) — the consolidated checklist

**Document: [`docs/handoff/OWNER-CHECKLIST-CONSOLIDATED-2026-08-01.md`](OWNER-CHECKLIST-CONSOLIDATED-2026-08-01.md)**
- §A: staging post-deploy (RolesAndPermissionsSeeder + `permission:cache-reset` REQUIRED, else
  403 on the new fiscal routes) — note: this weekend's lanes introduce ZERO new permissions, so
  no additional reseed is needed for them.
- §B: gate-sheet initials (E-10 already decided). §C: awareness items. §D: campaigns.
  §E: final fiscal re-run + enablement sequence. §F: parked tickets.

**New PRE-FILING runbook item (add to your §A/§E pass):** after
`php artisan vat:backfill-tax-details --apply` on a tenant, resolve EVERY reported "skipped"
document before any VAT declaration is filed — detail:
[`docs/superpowers/tickets/2026-08-03-vat-regate-carryovers.md`](../superpowers/tickets/2026-08-03-vat-regate-carryovers.md) (§N2).

---

## 3. Codex Desktop handover (POS Tauri, §Z — 64 items)

**Document: [`docs/handoff/HANDOVER-codex-pos-desktop-campaign-2026-08-02.md`](HANDOVER-codex-pos-desktop-campaign-2026-08-02.md)**
Ready to hand to Codex verbatim: environment recipe (dev bundle, prod-app trap, Wispr overlay),
device-migrations gate v60–67, post-treasury behaviour deltas, evidence contract, execution
order (Z.1 first — it authors the SHIFT-1/SHIFT-2 fixtures §Y consumes).

---

## 4. Mobile — one action: push the prepared branch

Repo `erp-mobile`, local branch **`fix/dashboard-stats-string-shape`** (commit `2c25da7`,
typecheck + 17/17 tests green). Push + release it with (or right after) the backend deploy.
Until it ships: cosmetic "null %" on the mobile dashboard tab for a first-month tenant.
Contract detail:
[`docs/superpowers/coordination/2026-08-03-dashboard-stats-api-shape-change.md`](../superpowers/coordination/2026-08-03-dashboard-stats-api-shape-change.md).

---

## 5. Records index for this pass (if detail is needed)

- Full campaign plan: `docs/qa/2026-08-02-full-e2e-campaign-plan.md` (112 flows, waves W-0..W-11).
- Campaign results (local, gitignored): `docs/sessions/MONEY-CAMPAIGN-RESULTS.md`.
- Review records (all in `docs/superpowers/reviews/`): `2026-08-02-documents-fixlane-gate.md`,
  `2026-08-02-authz-fixlane-gate.md`, `2026-08-02-fe-batch-gate.md`,
  `2026-08-03-credit-note-money-lane-gate.md`, `2026-08-03-dashboard-kpi-gate.md`,
  `2026-08-03-vat-declaration-gate.md`, `2026-08-03-hardening-batch-gate.md`.
- Open tickets (all in `docs/superpowers/tickets/`, prefixes `2026-08-02-*`/`2026-08-03-*`) —
  the most important: `2026-08-03-credit-note-regate-carryovers.md` (Q1),
  `2026-08-03-vat-regate-carryovers.md` (pre-filing runbook),
  `2026-08-03-paid-with-unreconciled-balance-investigation.md`,
  `2026-08-02-authz-gate-followups.md` (m6 dead margins route, F2 scoped DTO),
  `2026-08-03-f2f3-regate-carryovers.md` (R1 rounding unification, R5 credit-note confirm permission).

---

## 6. Manual-testing documents for the team

- **`docs/qa/smoke-test-fr.csv`** — the team-facing manual smoke sheet, **in French**, 70
  numbered cases in spreadsheet format with fill-in columns (Résultat obtenu / Statut / Gravité /
  Commentaire / Capture). Covers the back-office web app end-to-end: login/session, company &
  locations, through documents, robustness, i18n, printing. This is the one to hand to testers.
- **`docs/qa/2026-04-28-izipos-q2-release-smoke-protocol.md`** (+ companion `.csv`, 37 cases) —
  POS **desktop** (Tauri) release smoke protocol, tester-facing prose + scenario table.
- **`docs/qa/desktop-protocols/`** — reusable per-feature desktop protocols for non-developer
  testers: `README.md` (catalog + run order), `TEMPLATE.md`, and `01-customer-account-deposit.md`
  (the only one written so far; most catalog entries are still ⬜ outstanding).
- `docs/testing/` — two older feature-specific manuals (fiscal-period auto-lock, receipt
  printing).

⚠️ Staleness note: all of these pre-date the July–August work (treasury instruments/refund
chain, credit-note fixes, settings gating, dashboard KPIs). They're still valid as base
coverage, but a refresh pass adding the new surfaces to `smoke-test-fr.csv` is worth
dispatching before the team's staging run — ask for it in the next session.

---

## 7. Next session (new) — what to launch

Just say: **"Resume the launch program — execute waves W-3 through W-8 then W-X from
docs/qa/2026-08-02-full-e2e-campaign-plan.md"**. The program memory
(`project_first_tenant_launch_program.md`) is current to commit `cce3491b6`; the 15
bank-reconciliation cases are unblocked by `statement-support.ts`; wave W-X (destructive) must
run ALONE. After the waves: your §A, then the staging campaign, POS §Z (Codex), §Y, production.
