# DEV-QA registry — trouvailles de recette ↔ tickets ↔ PR

Registre unique (CLAUDE.md règle 23, point 6). Une ligne par trouvaille, l'ID est définitif et se cite dans la PR (`DEV-QA-0NN`).

- `DEV-QA-001` … `DEV-QA-068` : campagne Codex + tests appairés Claude, consolidés le 2026-09-04 → [`2026-09-04-qa-reconciliation-technique.md`](2026-09-04-qa-reconciliation-technique.md) (verdicts, `file:line`, corroborations). Les IDs de ce document restent la référence ; ne pas renuméroter.
- `DEV-QA-069` … : recette Android + web de Dhouha du 2026-09-07/08 (source `BUGS-CLAUDE-CODE-2026-09-08.md`, IDs d'origine `QA-BUG-01..09`), puis les campagnes suivantes.

Statuts : `OUVERT` (reproduit, non corrigé) · `EN COURS` (lane assignée) · `PR` (correctif en revue, lien) · `MERGÉ` (dev) · `VÉRIFIÉ` (critère de sortie rejoué par le manager) · `PAS-UN-BUG` · `HORS-PÉRIMÈTRE`.

Règle : on n'écrit ici que ce qui est prouvé (scénario rejoué, `file:line`). La colonne « Preuve » pointe la PR, le rapport de gate ou la capture.

## Recette Android + web — 2026-09-08 (build mobile `main@51e3445c`, web `index-DtS1kHol.js`, API `api.erp.otospex.dev`, PharmaBio Tunisie SARL / STORE-TUN1)

| ID | Origine | Prio | Zone | Résumé | Repro | Statut | Lane / branche | Preuve |
|---|---|---:|---|---|---:|---|---|---|
| DEV-QA-069 | QA-BUG-01 | P1 | Mobile · Réception / caméra | Le deuxième scan caméra (retour liste → rouvrir la commande) ne résout plus l'article, aucun message | 2/2 | EN COURS | erp-mobile `fix/qa-bug-01-03-scanner` | — |
| DEV-QA-070 | QA-BUG-02 | P2 | Mobile · Comptage / saisie manuelle | Une saisie manuelle successive est ignorée tant que le scanner n'est pas fermé/rouvert | 2/2 | EN COURS | erp-mobile `fix/qa-bug-01-03-scanner` | — |
| DEV-QA-071 | QA-BUG-03 | P1 | Mobile · Brouillon / saisie manuelle | « Saisir manuellement » n'ouvre aucune modale dans le scanner d'un brouillon ; `product_count` reste 0 | avant/après redémarrage | EN COURS | erp-mobile `fix/qa-bug-01-03-scanner` | — |
| DEV-QA-072 | QA-BUG-04 | P1 | Mobile · Brouillons / persistance | Brouillon synchronisé listé mais « Brouillon introuvable » à l'ouverture après redémarrage | 2/2 | EN COURS | erp-mobile `fix/qa-bug-04-drafts-restart` | — |
| DEV-QA-073 | QA-BUG-05 | P1 | Mobile · Comptage hors ligne | Après une première quantité mise en file, les cartes restent en chargement et le second comptage ne s'ouvre plus | 1/1 | EN COURS | erp-mobile `fix/qa-bug-05-offline-nav` | — |
| DEV-QA-074 | QA-BUG-06 | P2 | Mobile · Réception | Titre « Réception partielle » sur une réception complète 10/10 (API `fully_received`) | 1/1 | EN COURS | erp-mobile `fix/qa-bug-06-receiving-title` | cause : `app/(app)/receiving/[id].tsx:53` compare `'received'`, l'API renvoie `fully_received` (`GoodsReceiptService.php:1232-1234`) |
| DEV-QA-075 | QA-BUG-07 | P1 | Mobile · Liste Tâches | Nouvelle affectation invisible (onglet, pull-to-refresh) jusqu'au redémarrage complet d'Expo Go | 1/1 | EN COURS | erp-mobile PR #2 `fix/list-freshness-policy` (gate) + `fix/qa-bug-07-tasks-freshness` | — |
| DEV-QA-076 | QA-BUG-08 | P1 | Web · Revue de comptage | Aucun champ de note pour justifier une variance auto-résolue ; finalisation possible sans justification | 1/1 | EN COURS | erp `fix/qa-bug-08-variance-note` | cause présumée : `ReconciliationTable.tsx` n'offre l'action que si `is_flagged && final_qty === null` ; `resolveSingleCount` fixe `final_qty` (`CountingReconciliationService.php`) |
| DEV-QA-077 | QA-BUG-09 | P1 | API · Mouvements | L'ajustement de comptage porte `reference=COUNT_REPLAY` et `source_document_id=null` au lieu du n° CNT + lien | 1/1 | EN COURS | erp `fix/qa-bug-09-count-movement-reference` | cause : `StockAdjustmentService.php:69` (constante), `StockMovementController::resolveSourceDocument` ne résout que `Document` |

Critères de sortie (à rejouer tels quels par le manager) : voir la section « Critères de sortie après correction » de `BUGS-CLAUDE-CODE-2026-09-08.md`.
