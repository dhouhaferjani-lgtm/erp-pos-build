# Demande de changement / Change request — DC-[numéro à attribuer par le propriétaire]

> À remplir dès qu'un client demande quelque chose qui n'est pas dans le cahier des charges
> (ou qui est dans la section HORS PÉRIMÈTRE). Prend 5 minutes. S'envoie au manager, pas au client.
> **Tu ne développes rien tant que la décision n'est pas « Accepté et facturé » ou « Offert » — décision du manager, jamais la tienne.**

| Champ | Valeur |
|-------|--------|
| **ID** | DC-[à attribuer] |
| **Projet** | AutoERP / IziPOS — Lane E, aperçu et personnalisation du ticket POS |
| **Date de la demande** | 2026-09-17 |
| **Demandé par** | Dhouha (propriétaire produit) — décision interne de re-cadrage, pas une demande client |
| **Canal** (email / WhatsApp / appel / réunion) | Décision orale relayée par la session superviseur, confirmée directement par Dhouha (« il a juste besoin de quelque chose qui marche, ne complique pas ») |
| **Preuve** (lien email, capture) | `docs/superpowers/specs/2026-09-17-pos-receipt-preview-design.md` — sections « Owner rulings » et « Delivery — RE-SCOPED 2026-09-17 » ; décision consignée dans `.superpowers/sdd/progress.md` |
| **Ticket tk** | # à compléter |

## Description de la demande / What the client asked

Le gestionnaire souhaite, depuis les paramètres POS du tableau de bord web (`/settings/company`, onglet *reçu*), un **aperçu du ticket client fidèle à l'impression thermique**, avec un interrupteur **logo on/off** et un choix d'affichage du **sous-total en HT ou en TTC**. Cette demande avait été validée dans la spec design du 2026-09-17 (`docs/superpowers/specs/2026-09-17-pos-receipt-preview-design.md`) sous la forme d'un plan en deux PR (paquet partagé `ReceiptDoc` + PR 1 ; colonnes API et aperçu web + PR 2). Le même jour, le propriétaire a retiré cette partie du périmètre de livraison immédiate pour ne garder que les correctifs du ticket imprimé existant.

### Livré maintenant

Branche `fix/pos-printed-ticket` : les quatre corrections du ticket imprimé, à l'intérieur du template Rust existant (`apps/pos/src-tauri/src/printing/receipt_template.rs`) et de `apps/pos/src/lib/buildReceiptData.ts`, sans nouveau paramètre, sans aperçu, sans changement de schéma, sans paquet partagé :

| Ticket registre | Défaut | Source |
|---|---|---|
| DEV-QA-092 | Matricule fiscale imprimé deux fois en tête (`MF :` puis `N° TVA :`) | `docs/qa/DEV-QA-registry.md` |
| DEV-QA-093 | QR code en pied de ticket sans effet pour le client (jusqu'à 3 QR non libellés) | `docs/qa/DEV-QA-registry.md` |
| DEV-QA-094 | `é` / `ç` imprimés `Θ` / `τ` sur le chemin brut (page de code) | `docs/qa/DEV-QA-registry.md` |
| DEV-QA-095 | `TND` imprimé à gauche du montant, sans espace | `docs/qa/DEV-QA-registry.md` |

Vérification prévue : test de régression « golden-bytes » (voir §5 de la spec design).

### Différé et pourquoi

Décision du propriétaire (Dhouha, 2026-09-17) : « il a besoin de quelque chose qui marche, sans complexifier ». Trois raisons techniques accompagnent cette décision (spec design, section « Owner rulings ») :
- **Logo thermique** : aucun encodeur raster (`GS v 0`) n'existe dans `apps/pos/src-tauri/src/printing/escpos.rs` — un interrupteur logo on/off pour le thermique n'a rien à piloter tant que ce composant n'existe pas (ticket E-1).
- **Aperçu web fidèle** : nécessite un template partagé TypeScript (`packages/shared/src/receipt`) consommé à la fois par le POS (impression) et par le web (aperçu), pour que l'aperçu soit construit par le même code que l'impression et non une imitation séparée.
- **HT/TTC** : nécessite deux colonnes nouvelles (affichage + un DTO généré pour `GET companies/{id}/pos-settings`) côté API, absentes aujourd'hui (`UpdateReceiptSettingsRequest.php:21-30` ne valide même pas `receipt_logo`).

## Analyse / Analysis

| | |
|---|---|
| **Dans le cahier des charges ?** | ✅ Oui — approuvé dans la spec design du 2026-09-17, puis retiré du périmètre de **livraison immédiate** le même jour (décision propriétaire, pas un refus définitif) |
| **Référence consultée** | `docs/superpowers/specs/2026-09-17-pos-receipt-preview-design.md`, section « Delivery — RE-SCOPED 2026-09-17 » ; plans `docs/superpowers/plans/2026-09-17-pos-receipt-doc-pr1.md` et `docs/superpowers/plans/2026-09-17-pos-receipt-settings-web-pr2.md` |
| **Estimation** (h) | Chiffrage à établir par le propriétaire — voir comptage de tâches ci-dessous (aucune durée ni prix n'est avancé dans cette demande) |
| **Impact sur le planning** | Aucun sur la livraison actuelle (`fix/pos-printed-ticket` suit son propre calendrier) ; le périmètre aperçu web / logo / HT-TTC est reporté à une date à définir par le propriétaire |
| **Risques si on l'ajoute** (plus tard) | Couplage fiscal : la déduplication du matricule ne doit jamais toucher `resolveSellerIdentity` (tests de parité `saleReceiptV5CanonicalParity`, `SaleReceiptV1V2ByteStability`) ; mémoire `cargo test` limitée sur la machine du propriétaire (une seule exécution, budget swap < 9000 Mo) ; comportement de l'imprimante physique non vérifiable avant impression réelle par Dhouha |
| **Risques si on refuse** (si le périmètre différé n'est jamais repris) | Pas d'aperçu fidèle en back-office (le TODO reste ouvert à `apps/web/src/features/pos/RECEIPT_PRINTING_INTEGRATION.md:207`) ; le logo ne s'imprime jamais sur le thermique ; le sous-total reste figé en TTC sans option HT ; le PDF serveur et le ticket thermique continuent de diverger (B11 de la spec) ; les trois surfaces d'en-tête/pied restent dupliquées (B2/B8) |

### Périmètre technique différé

| ID | Élément | Effort (en tâches) | Dépendance |
|---|---|---|---|
| PR 1 (reste) | Paquet partagé `ReceiptDoc` + encodeur Rust — `packages/shared/src/receipt`, `apps/pos/src-tauri/src/printing/doc_encoder.rs`, retrait de `format_receipt_with_settings` | 10 tâches restantes sur 12 (tâches 3 à 12 du plan ; tâches 1 et 2 déjà faites sur la branche parquée `feat/pos-receipt-preview` @ `b6c6b90b0`) | Aucune (base des deux plans) |
| PR 2 | Colonnes API (`receipt_subtotal_mode`, écriture de `receipt_logo`) + aperçu web `ReceiptPreview.tsx` | 8 tâches | PR 1 — la branche est coupée sur `feat/pos-receipt-preview` et importe `buildReceiptDoc`, `sampleReceipt`, `padColumns` du paquet partagé |
| E-1 | Raster logo ESC/POS (`GS v 0` + image monochrome) | Chiffrage à établir par le propriétaire | Imprimante physique requise pour valider (ruling 5 de la spec) |
| E-3 | Unification du PDF serveur (`resources/views/pos/receipt.blade.php`) sur `ReceiptDoc`, ou déclaration d'une divergence permanente | Chiffrage à établir par le propriétaire | PR 1 (le `ReceiptDoc` doit exister) |
| E-6 | Largeur de papier / imprimante configurable par terminal, visible côté serveur (`pos_terminals`) | Chiffrage à établir par le propriétaire | PR 2 (paramètres de reçu) |
| E-5 | Fusion des trois surfaces en-tête/pied (`companies.receipt_header/footer`, `locations.receipt_header/footer`, `printerStore.footerText` device) | Chiffrage à établir par le propriétaire | PR 2 |

## Point de sécurité hors périmètre — à traiter séparément

**DEV-QA-096** (P1, statut OUVERT, ticket **E-4**, `docs/qa/DEV-QA-registry.md`) : `GET companies/{id}/pos-settings` (`apps/api/app/Modules/Company/routes.php:43-44`) est lisible par tout utilisateur authentifié du tenant, caissier compris — l'écriture est correctement protégée par `can:settings.update` (`routes.php:48`), la lecture ne l'est pas (`CompanyController.php:575-597`). Cette trouvaille est annexe à la Lane E, hors périmètre de cette demande de changement et **hors périmètre du PR 2** décrit ci-dessus : le plan PR 2 précise explicitement qu'il ne corrige pas cette route. Elle doit être budgétée et traitée comme un correctif de sécurité indépendant (ticket E-4), pas comme un « nice to have » du périmètre reçu.

## Recommandation / Recommendation

Le périmètre différé (paquet partagé `ReceiptDoc`, aperçu web, logo, HT/TTC — 18 tâches restantes au total entre PR 1 et PR 2) correspond à une fonctionnalité déjà validée par le propriétaire, simplement reportée le jour même pour livrer plus vite un correctif ciblé. Recommandation : le faire rentrer au cahier des charges comme un lot planifié (nouvelle version du document), avec un chiffrage à établir par le propriétaire avant reprise ; traiter **DEV-QA-096 (E-4)** séparément et en priorité, comme un correctif de sécurité indépendant du calendrier de cette fonctionnalité.

## Décision manager / Manager decision

| | |
|---|---|
| **Décision** | ☐ Accepté et facturé ☐ Offert (geste commercial) ☐ Refusé ☐ Reporté |
| **Décidé par** | |
| **Date** | |
| **Preuve écrite** (lien) | |

> Si accepté : le livrable entre au cahier des charges (nouvelle version du document, nouvel ID CDC-x),
> un ticket tk est créé avec cette référence, et l'estimation s'ajoute au budget.
