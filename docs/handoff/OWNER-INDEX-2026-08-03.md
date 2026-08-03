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

## 2. Vos étapes staging (§A) — le checklist consolidé

**Document : [`docs/handoff/OWNER-CHECKLIST-CONSOLIDATED-2026-08-01.md`](OWNER-CHECKLIST-CONSOLIDATED-2026-08-01.md)**
- §A : post-deploy staging (RolesAndPermissionsSeeder + `permission:cache-reset` REQUIS, sinon
  403 sur les nouvelles routes fiscales) — note : les lanes de ce week-end n'ajoutent AUCUNE
  permission nouvelle, donc pas de reseed supplémentaire pour elles.
- §B : initiales sur la gate sheet (E-10 déjà tranché). §C : awareness. §D : campagnes.
  §E : re-run fiscal final + séquence d'enablement. §F : tickets parkés.

**Nouvel item runbook PRÉ-DÉCLARATION (à ajouter à votre passage §A/§E) :** après
`php artisan vat:backfill-tax-details --apply` sur un tenant, résoudre CHAQUE document « skipped »
rapporté avant tout dépôt de déclaration — détail :
[`docs/superpowers/tickets/2026-08-03-vat-regate-carryovers.md`](../superpowers/tickets/2026-08-03-vat-regate-carryovers.md) (§N2).

---

## 3. Handover Codex Desktop (POS Tauri, §Z — 64 items)

**Document : [`docs/handoff/HANDOVER-codex-pos-desktop-campaign-2026-08-02.md`](HANDOVER-codex-pos-desktop-campaign-2026-08-02.md)**
Prêt à remettre à Codex tel quel : recette d'environnement (bundle dev, piège de l'app prod,
overlay Wispr), gate migrations device v60–67, deltas post-treasury, contrat d'évidence, ordre
d'exécution (Z.1 d'abord — il produit les fixtures SHIFT-1/SHIFT-2 pour §Y).

---

## 4. Mobile — une action : pousser la branche préparée

Repo `erp-mobile`, branche locale **`fix/dashboard-stats-string-shape`** (commit `2c25da7`,
typecheck + 17/17 tests verts). À pousser + livrer avec (ou juste après) le deploy backend.
Tant qu'elle n'est pas livrée : « null % » cosmétique sur l'onglet dashboard mobile pour un
tenant en premier mois. Détail du contrat :
[`docs/superpowers/coordination/2026-08-03-dashboard-stats-api-shape-change.md`](../superpowers/coordination/2026-08-03-dashboard-stats-api-shape-change.md).

---

## 5. Index des enregistrements de cette passe (si besoin de détail)

- Plan de campagne complet : `docs/qa/2026-08-02-full-e2e-campaign-plan.md` (112 flux, vagues W-0..W-11).
- Résultats campagne (local, gitignored) : `docs/sessions/MONEY-CAMPAIGN-RESULTS.md`.
- Reviews (toutes dans `docs/superpowers/reviews/`) : `2026-08-02-documents-fixlane-gate.md`,
  `2026-08-02-authz-fixlane-gate.md`, `2026-08-02-fe-batch-gate.md`,
  `2026-08-03-credit-note-money-lane-gate.md`, `2026-08-03-dashboard-kpi-gate.md`,
  `2026-08-03-vat-declaration-gate.md`, `2026-08-03-hardening-batch-gate.md`.
- Tickets ouverts (tous dans `docs/superpowers/tickets/`, préfixes `2026-08-02-*`/`2026-08-03-*`) —
  les plus importants : `2026-08-03-credit-note-regate-carryovers.md` (Q1),
  `2026-08-03-vat-regate-carryovers.md` (runbook pré-déclaration),
  `2026-08-03-paid-with-unreconciled-balance-investigation.md`,
  `2026-08-02-authz-gate-followups.md` (m6 route morte marges, F2 DTO scindé),
  `2026-08-03-f2f3-regate-carryovers.md` (R1 unification arrondi, R5 permission confirm avoir).

---

## 6. Prochaine session (nouvelle) — quoi lancer

Dire simplement : **« Reprends le programme launch — exécute les vagues W-3 à W-8 puis W-X du
plan docs/qa/2026-08-02-full-e2e-campaign-plan.md »**. La mémoire du programme
(`project_first_tenant_launch_program.md`) est à jour au commit `cce3491b6` ; les 15 cas de
rapprochement bancaire sont débloqués par `statement-support.ts` ; la vague W-X (destructive)
doit tourner SEULE. Après les vagues : votre §A, puis campagne staging, POS §Z (Codex), §Y,
production.
