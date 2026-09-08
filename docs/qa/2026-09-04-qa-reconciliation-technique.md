# AutoERP / IziPOS — Consolidated QA Reconciliation (technical)

- **Environment:** https://erp.otospex.dev · build `index-CdNqm5xl.js`
- **Code of record:** origin/dev @ 4d5b8812e (2026-09-02) (dev auto-deploys; live build hash not mapped to a commit — treated as ≈ this tip)
- **Date:** 4 septembre 2026
- **Inputs:** Codex sweep (68 tickets DEV-QA-001..068, verdict NO-GO) + Claude paired-testing outputs (F-STG-*, F-W2-*, K-1, opening-balance fix, 11 clean flows)
- **Method:** 6 divergences between the two efforts resolved by reading deployed dev code (browser was locked by a parallel session). `file:line` = deployed worktree paths.

## Reconciliation verdicts (live code verification)

| Ref | Verdict | Right | Root cause (file:line, deployed dev) |
|---|---|---|---|
| DEV-QA-008 / 057 | **CONFIRMED** | Codex | Create: `after_or_equal:document_date` never fires — FE sends `issue_date`, normalization is post-validation in controller (`apps/api/app/Modules/Document/Presentation/Requests/CreateDocumentRequest.php:82-83`; QuoteController::store / PurchaseOrderController::store:372). Update: rule absent entirely (`UpdateDocumentRequest.php:79-80`). No FE guard (`apps/web/src/features/documents/DocumentForm.tsx:621`). |
| DEV-QA-035 | **CONFIRMED** (client crash, not 5xx) | Codex | Wrong response-shape cast returns an object as array (`apps/web/src/features/compliance/api/complianceApi.ts:61`) then unguarded `.every()` throws in render (`ChainVerificationPanel.tsx:44-48`). Backend returns 200 (`Nf525ExportController.php:91-128`). Reconciles Claude's 'zero 5xx'. |
| DEV-QA-043 | **CONFIRMED** (GL only) | Codex | Hardcoded `$` in `apps/web/src/features/finance/components/LedgerTable.tsx:14,68`; never uses `useCompany()`/shared formatter. Trial balance/BS/P&L/VAT all use `formatReportCurrency(..., company.currency)` → correct TND. Bug enshrined by test `GeneralLedgerPage.test.tsx:74` asserting `$100.00`. |
| F-STG-4 | **CONFIRMED** (both paths) | Claude | Path A (from invoice, mode 'all'): FE sends `source_invoice_id` but no `amount`/`lines` (`CreateCreditNotePage.tsx:218-228`) → backend requires `amount` string (`CreditNoteController.php:148-150`) → 422 'montant obligatoire'. Path B (from customer): blank line `unit_price:0` sent as **number** (`DocumentLineEditor.tsx:535,195`) → backend requires string regex (`CreditNoteController.php:166`) → 422 'prix unitaire doit être une chaîne'. Source picker excludes Paid (`InvoiceSearchSelect.tsx:74-75` status=posted; Paid≠Posted `DocumentStatusService.php:424-436`). |
| DEV-QA-067 | **NOT A BUG** | Claude | Full synchronous `DB::transaction` creates Payment (`Treasury/.../PaymentController.php:826,942`), posts GL in-transaction (`GeneralLedgerService.php:994`), cash-out movement, returns 201. All failure branches are loud 422 (`:534,573,589`). Happy path green-tested (`SupplierPaymentGuardTest.php:238`). Observed 'silent' fail ≈ a visible 422 on an under-posted invoice. **Recommend one live reconfirm.** |
| DEV-QA-065 | **NOT A BUG** | — | Aged payables is PO-driven by design; posted-unpaid supplier invoice represented via parent PO which stays Posted with full outstanding (`Accounting/Application/Services/Reports/AgedPayablesService.php:154-169,191-195`). Standalone invoices only via `is_historical` path. Presentation may confuse but figures correct. |
| DEV-QA-052 | **UNCONFIRMED as worded** | — | `document_lines.product_id` is nullable `foreignUuid` (`migrations/tenant/2025_11_30_080001_create_document_lines_table.php:16`). Blank line sends `product_id:''`; Laravel `nullable` skips uuid rule so validation passes, then either `ConvertEmptyStringsToNull`→NULL (line **persists**) or PG rejects `''`→**visible 500**. Neither is 'silent disappearance'. **Needs a 2-min live retest.** |

## Corroborations (both efforts independently found it — high confidence)

- **Cashier permissions too broad** — Codex `DEV-QA-027`/`028` ≡ Claude `F-W2-14` (measured: cashier can validate/pay supplier invoices). Owner decision pending.
- **'Total' input mode inflates VAT into cost** — Codex `DEV-QA-011` ≡ Claude `K-1` (fix already assigned).
- **Landed cost distorts WAC / phantom revenue** — Codex `DEV-QA-059` ≡ Claude `F-W2-18`.
- **Environment identity ambiguous** — Codex `DEV-QA-013`; Claude docs label the same URL both 'staging' and 'DEV'.

## Full consolidated register (classified by use case)

Legend — Status: `CONF`=verified live, `NOTBUG`=verified not-a-bug, `UNCONF`=needs live retest, `CORROB`=both efforts, `REPORTED`=reported by one effort, not re-verified live.

### Inscription, environnement & premier jour

| Ref | Sev | Use case | Screen & control | Source | Status | Note |
|---|---|---|---|---|---|---|
| DEV-QA-001 | P1 | Mentions légales publiées | Site public — page Confidentialité | Codex | REPORTED | Adresse de confidentialité provisoire en ligne. |
| DEV-QA-002 | P2 | Sécurité du site | En-têtes HTTP (SPA + API) | Codex | REPORTED | En-têtes de sécurité absents ; en-têtes API dupliqués. |
| DEV-QA-024 | P2 | Référencement | Routes robots/sitemap/manifest | Codex | REPORTED | Renvoient la coquille SPA sans balise noindex. |
| DEV-QA-003 | Obs | Cohérence de marque | Bandeaux/titres IziPOS vs AutoERP | Codex | REPORTED | Dénomination incohérente — à trancher (produit). |
| DEV-QA-013 | Obs | Identité de l'environnement | Bandeau/URL de l'environnement | Codex + Claude | CORROB | Ambigu : nos deux campagnes l'ont nommé tantôt « staging », tantôt « DEV ». |

### Connexion, session & sécurité du compte

| Ref | Sev | Use case | Screen & control | Source | Status | Note |
|---|---|---|---|---|---|---|
| DEV-QA-004 | P1 | Rester connecté | Navigation authentifiée (toutes pages) | Codex | REPORTED | Perte de session en navigation complète. |
| DEV-QA-030 | P1 | Se connecter | Écran de connexion — champ e-mail | Codex | REPORTED | Connexion sensible à la casse de l'e-mail. |
| DEV-QA-031 | P1 | Se connecter | Écran de connexion — messages d'erreur | Codex | REPORTED | Les messages permettent d'énumérer les comptes existants. |
| DEV-QA-029 | P1 | Inviter un utilisateur | E-mail d'invitation — lien « définir le mot de passe » | Codex | REPORTED | Le lien pointe vers une route absente. |

### Rôles & permissions

| Ref | Sev | Use case | Screen & control | Source | Status | Note |
|---|---|---|---|---|---|---|
| F-W2-14 / DEV-QA-027-028 | P1 | Un caissier utilise l'appli | Tableau de bord & Achats — actions caissier | Codex + Claude | CORROB | Les deux campagnes le confirment : le caissier voit/atteint des actions Achats interdites (et, mesuré côté Claude, peut valider/payer des factures fournisseurs). Décision propriétaire demandée. |
| DEV-QA-028 | P1 | Caissier limité à un site | Tableau de bord caissier | Codex | REPORTED | Divulgue des résumés Achats non autorisés. |
| DEV-QA-023 | P1/Obs | Propriétaire non vérifié | Politique de mutation des données | Codex | REPORTED | Comportement ambigu — décision produit/identité. |
| DEV-QA-018 | P2 | Ajouter un utilisateur | Formulaire « Ajouter un utilisateur » | Codex | REPORTED | Conserve les données de l'invité précédent. |
| DEV-QA-041 | P2 | Gérer les permissions | Écran des permissions — totaux | Codex | REPORTED | Totaux affichés contradictoires. |
| DEV-QA-033 | P2 | Manager consulte la trésorerie | Page Trésorerie | Codex | REPORTED | Affiche une synthèse interdite au Manager. |

### Ventes — devis & clients

| Ref | Sev | Use case | Screen & control | Source | Status | Note |
|---|---|---|---|---|---|---|
| DEV-QA-008 | P1 | Créer un devis | Devis — champs Date d'émission / Échéance | Codex | CONF | VÉRIFIÉ : le devis accepte une échéance antérieure à l'émission. Cause : la règle de contrôle ne se déclenche pas à la création et est absente à la modification. |
| DEV-QA-025 | P1 | Créer un devis | Devis — sélecteur de client | Codex | REPORTED | Un client inactif reste sélectionnable. |
| DEV-QA-052 | P1 | Ligne libre sur un devis | Devis — bouton « Ajouter une ligne » + Enregistrer | Codex | UNCONF | À RETESTER : le code ne produit pas de « disparition silencieuse ». Soit la ligne est conservée (product_id vide → NULL), soit l'enregistrement échoue avec une erreur visible. Reformuler après retest live. |
| DEV-QA-007 | P2 | Enregistrer un devis vide | Devis — bouton Enregistrer | Codex | REPORTED | Échec silencieux (aucun message). |
| DEV-QA-036 | P2 | Remise sur une vente | Ligne de vente — champ remise | Codex | REPORTED | Rejet d'une remise excessive silencieux. |
| F-STG-1 | P3 | Facturer (brouillon) | Facture — ligne timbre fiscal | Claude | REPORTED | Timbre libellé « Adjustment » au brouillon (correct une fois comptabilisé). |
| F-STG-3 | P3 | Encaisser une facture | Facture — en-tête « Montant dû » | Claude | REPORTED | En-tête non rafraîchi après paiement (F5 nécessaire) — risque de double encaissement perçu. |

### Retours & avoirs (remboursements clients)

| Ref | Sev | Use case | Screen & control | Source | Status | Note |
|---|---|---|---|---|---|---|
| F-STG-4 | P1 | Rembourser / reprendre une marchandise | Ventes → Avoirs → Nouveau (les 2 chemins) | Claude | CONF | VÉRIFIÉ — bug réel et précis. Chemin « depuis facture » : le formulaire n'envoie pas le montant → « montant obligatoire ». Chemin « depuis client » : le prix unitaire est envoyé en nombre alors que le serveur exige une chaîne → « prix unitaire doit être une chaîne ». De plus, les factures PAYÉES sont exclues de la liste des factures ssource. Aucun retour possible. Codex n'a pas testé les retours (angle mort). |
| F-STG-5 | P3 | Créer un avoir | Avoir — sélecteur de facture source | Claude | REPORTED | Affiche « undefined / unknown » au lieu du n° et du client. |

### CRM — contacts & sociétés

| Ref | Sev | Use case | Screen & control | Source | Status | Note |
|---|---|---|---|---|---|---|
| DEV-QA-051 | P1 | Créer un client | Fiche client — champ e-mail | Codex | REPORTED | Unicité de l'e-mail sensible à la casse. |
| DEV-QA-054 | P1 | Créer un contact | Fiche contact — date de naissance | Codex | REPORTED | Une date de naissance future est acceptée. |
| DEV-QA-055 | P1 | Rattacher un contact | Contact — rattachement à une société | Codex | REPORTED | Un contact existant ne peut pas être rattaché. |
| DEV-QA-053 | P2 | Utiliser le CRM | CRM — validations & notifications | Codex | REPORTED | Clés de traduction brutes exposées. |

### Catalogue — produits & menus

| Ref | Sev | Use case | Screen & control | Source | Status | Note |
|---|---|---|---|---|---|---|
| DEV-QA-046 | P1 | Enregistrer un produit | Fiche produit — champ catégorie | Codex | REPORTED | La catégorie n'est pas conservée après sauvegarde. |
| DEV-QA-039 | P1 | Créer un article de menu | Menu — champ TVA | Codex | REPORTED | La TVA de l'article n'est pas conservée. |
| DEV-QA-040 | P1 | Créer un menu | Menu — dates de disponibilité | Codex | REPORTED | Les dates de disponibilité sont perdues après création. |
| DEV-QA-026 | P2 | Créer un produit (SKU) | Produit — champ SKU | Codex | REPORTED | Rejet d'un SKU dupliqué silencieux. |
| DEV-QA-045 | P2 | Attributs produit | Attribut — valeur dupliquée | Codex | REPORTED | Erreur serveur générique au lieu d'un message clair. |
| DEV-QA-056 | P2 | Unités de mesure | Produit — UOM | Codex | REPORTED | Rejets d'UOM invalides sans retour utilisateur. |
| DEV-QA-006 | P3 | Créer un fournisseur | Formulaire fournisseur — libellé | Codex | REPORTED | Utilise un libellé « client » à la place de « fournisseur ». |
| F-STG-7 | P3* | Produit à lots — stock initial | Nouveau produit — quantité initiale | Claude | REPORTED | Crée un lot « DEFAULT » sans n° ni péremption (silencieux) — candidat, décision propriétaire. |

### Tarification & promotions

| Ref | Sev | Use case | Screen & control | Source | Status | Note |
|---|---|---|---|---|---|---|
| DEV-QA-047 | P1 | Créer une liste de prix | Liste de prix — enregistrement | Codex | REPORTED | Une liste valide échoue sur une propriété « id » absente. |
| DEV-QA-048 | P1 | Créer une promotion | Promotion — soumission | Codex | REPORTED | Une promotion valide disparaît sans erreur. |
| DEV-QA-049 | P1 | Créer un coupon | Coupon — soumission | Codex | REPORTED | Un coupon valide disparaît sans erreur. |
| DEV-QA-015 | P2 | Liste de prix — dates | Liste de prix — plage de dates | Codex | REPORTED | Plage invalide échoue silencieusement. |
| DEV-QA-014 | P2 | Créer une promotion | Formulaire promotion — traduction | Codex | REPORTED | Erreur du moteur de traduction. |

### Achats — commandes, réceptions, factures fournisseurs

| Ref | Sev | Use case | Screen & control | Source | Status | Note |
|---|---|---|---|---|---|---|
| DEV-QA-057 | P1 | Créer un bon de commande | BC — Date d'émission / Échéance | Codex | CONF | VÉRIFIÉ : même faille que le devis — échéance antérieure à l'émission acceptée (BC). |
| K-1 / DEV-QA-011 | P1 | Saisir un prix en mode « Total » | Ligne d'achat — bascule Prix/Total + sélecteur de taxe | Codex + Claude | CORROB | Les deux le confirment : le mode « Total » fait entrer la TVA dans le coût ; le sélecteur de taxe contredit alors les totaux. Correctif déjà attribué côté Claude (K-1). |
| DEV-QA-059 / F-W2-18 | P1 | Frais d'approche (transport) | Réception — coût additionnel | Codex + Claude | CORROB | Les deux le relèvent : un coût additionnel masqué disparaît de l'édition mais continue d'affecter le coût moyen ; écart/« revenu » anormal en comptabilité. |
| DEV-QA-009 | P1 | Enregistrer un BC | Nouveau BC — bouton Enregistrer | Codex | REPORTED | Création de deux brouillons de BC incohérents. |
| DEV-QA-010 | P1 | Confirmer un BC | BC — champ Échéance | Codex | REPORTED | La date d'échéance du BC n'est pas conservée. |
| DEV-QA-058 | P1 | Facture fournisseur | Facture — référence & date | Codex | REPORTED | Référence et date perdues après sauvegarde. |
| DEV-QA-062 | P1 | Créer une demande de prix (RFQ) | RFQ — quantité & validité | Codex | REPORTED | Accepte une quantité nulle et une validité passée, puis perd le produit. |
| DEV-QA-063 | P1 | Réponse fournisseur (RFQ) | RFQ — réponse fournisseur | Codex | REPORTED | Enregistrable avant même l'envoi de la RFQ. |
| DEV-QA-064 | P1 | Nouveau BC depuis fournisseur | BC — raccourci fournisseur | Codex | REPORTED | Un fournisseur inactif est présélectionné. |
| DEV-QA-068 | P1 | Suivi des dûs fournisseur | BC vs factures comptabilisées | Codex | REPORTED | Le montant dû du BC diverge du total de ses factures. |
| F-W2-40/41 | P1 | Facture avec écart | Facture fournisseur — statut d'écart | Claude | REPORTED | La page facture peut planter sur certains statuts d'écart ; le blocage du bouton Valider côté écran ne fonctionne pas (le serveur, lui, refuse). |
| F-W2-01 | P1 | Double-clic à la réception | Réception — bouton Valider | Claude | REPORTED | La re-soumission séquentielle peut compter le stock en double. |
| DEV-QA-061 | P2 | Facturer plus que reçu | Facture — quantité | Codex | REPORTED | Rejet d'une quantité facturée > reçue silencieux. |
| DEV-QA-060 | P2 | Ouvrir un bon de réception | Lien du bon de réception | Codex | REPORTED | Renvoie au bon de commande au lieu de la réception. |

### Trésorerie & paiements

| Ref | Sev | Use case | Screen & control | Source | Status | Note |
|---|---|---|---|---|---|---|
| DEV-QA-067 | P1 | Payer un fournisseur | Facture fournisseur — bouton « Enregistrer un paiement » | Codex | NOTBUG | VÉRIFIÉ : PAS un bug sur ce build. Le paiement est créé (201), l'écriture comptable est passée, cas testé en vert. Le « rien ne se passe » observé est très probablement un refus 422 visible (facture pas entièrement comptabilisée) mal interprété. À reconfirmer en live. |
| F-W2-13 | P0 | Rembourser un fournisseur | Paiement fournisseur — Rembourser | Claude | REPORTED | Mesuré côté Claude : le remboursement écrit du côté « clients » et gonfle le solde fournisseur. P0 en attente d'arbitrage. |
| DEV-QA-066 | P2 | Payer depuis une facture | Écran paiement — sélecteur fournisseur | Codex | REPORTED | Ne présélectionne pas le fournisseur. |

### Finance & comptabilité

| Ref | Sev | Use case | Screen & control | Source | Status | Note |
|---|---|---|---|---|---|---|
| DEV-QA-043 | P1 | Consulter le grand livre (tenant TND) | Comptabilité → Grand livre — colonnes Débit/Crédit/Solde | Codex | CONF | VÉRIFIÉ : le grand livre affiche « $ » (USD) en dur au lieu de la devise TND. Les autres états (balance, bilan, résultat, TVA) formatent correctement en TND — ce qui explique que la campagne Claude ait vu les montants TND justes ailleurs. Cause : symbole codé en dur dans un seul écran. |
| DEV-QA-044 | P1 | Exporter la comptabilité | Comptabilité — boutons d'export | Codex | REPORTED | Ne produisent aucun fichier ni erreur. |
| DEV-QA-012 | P2 | Compte de résultat | Résultat — période | Codex | REPORTED | Accepte une période inversée. |
| DEV-QA-065 | P1 | Balance âgée fournisseurs | Comptabilité → Balance âgée | Codex | NOTBUG | VÉRIFIÉ : PAS un bug. Le report est piloté par les bons de commande ; une facture comptabilisée non payée y figure via son BC parent (comportement documenté dans le code). Présentation potentiellement déroutante, mais montants corrects. |
| F-STG-6 | P3 | Déclaration TVA | TVA — liste des périodes | Claude | REPORTED | La liste affiche « - » alors que le détail calcule bien les montants. |

### Rapports & tableaux de bord

| Ref | Sev | Use case | Screen & control | Source | Status | Note |
|---|---|---|---|---|---|---|
| DEV-QA-034 | P1 | Rapport « ventes en direct » | Rapport — sélecteur de période | Codex | REPORTED | « Live sales » ignore la période active du rapport. |
| DEV-QA-021 | P2 | Rapport propriétaire | Rapport — période | Codex | REPORTED | Accepte une période inversée. |
| DEV-QA-032 | P2 | Rapport Manager | Titre du rapport | Codex | REPORTED | Intitulé « Owner Dashboard ». |

### Inventaire / stock

| Ref | Sev | Use case | Screen & control | Source | Status | Note |
|---|---|---|---|---|---|---|
| DEV-QA-017 | P2 | Inventaire tournant | Assistant de comptage | Codex | REPORTED | Avance sans périmètre explicite. |
| DEV-QA-019 | P2 | Produit sans stock | Fiche produit — stock | Codex | REPORTED | Indique qu'aucune localisation n'est configurée. |
| DEV-QA-020 | P2 | Lots & péremption | Recherche/filtre de lots | Codex | REPORTED | Recherche et filtres d'expiration ignorés. |
| DEV-QA-022 | P2 | Créer un lot | Lot — lien retour | Codex | REPORTED | Lien sans nom accessible (accessibilité). |

### POS / caisse & restaurant

| Ref | Sev | Use case | Screen & control | Source | Status | Note |
|---|---|---|---|---|---|---|
| DEV-QA-016 | P2 | Consulter les reçus | POS — filtre de dates des reçus | Codex | REPORTED | Les dates affichées divergent du filtre réellement appliqué. |
| DEV-QA-038 | P2 | Créer une table (restaurant) | Plan de salle — n° de table | Codex | REPORTED | Rejet d'un numéro de table dupliqué silencieux. |
| DEV-QA-035 | P1 | Vérifier les chaînes de conformité | Conformité — bouton « Verify All Chains » | Codex | CONF | VÉRIFIÉ : plante la page (page blanche). C'est un plantage CÔTÉ NAVIGATEUR (le serveur répond 200) — d'où la cohérence avec le « zéro erreur serveur » de la campagne Claude, qui n'a jamais ouvert cette page. Bloque tout audit NF525. |

### Internationalisation (i18n) & accessibilité

| Ref | Sev | Use case | Screen & control | Source | Status | Note |
|---|---|---|---|---|---|---|
| DEV-QA-042 | P2 | Interface en arabe (RTL) | Coquille de l'application | Codex | REPORTED | Des éléments restent en anglais. |
| DEV-QA-037 | P2 | Navigation en anglais | Menu — libellé | Codex | REPORTED | Affiche la clé brute « navigation.stockByLocation ». |
| DEV-QA-005 | P2 | Navigation | Menu — libellé | Codex | REPORTED | Clé de traduction brute affichée. |
| DEV-QA-050 | P2 | Import produits (FR) | Import — libellés | Codex | REPORTED | Conserve un libellé anglais et une clé accessible brute. |

## Opening-balance import — fixed & verified (Claude, 2 Sept)

Le bug principal (un import totalement échoué affichait « Terminé » sans rien enregistrer) est corrigé, contrôlé par une revue indépendante, puis vérifié de bout en bout (fichier au compte inexistant → refus AVANT import ; fichier moitié faux → règle tout-ou-rien respectée, 0 importé annoncé honnêtement ; fichier corrigé → 2/2 exact et verrouillé ; zéro erreur technique). Restent 4 défauts d'affichage (P2 message « Import terminé » trompeur ; P2 promesse « 1 ligne importée » impossible sur un import tout-ou-rien ; P3 message hors sujet sur les unités ; P3 bouton « Importer plus » inerte) — aucun ne fausse les chiffres.

## Verified-clean flows (Claude — happy path is solid)

- Vente complète — concordance stock ↓ / caisse ↑ / facture Payée (INV, TVA, timbre corrects)
- Contrôle fiscal — interdiction de facturer avant livraison
- Dépense — sortie de caisse nette + TVA correctes
- Transfert de caisse — total conservé (register → coffre)
- Balance comptable — équilibrée (418 080 = 418 080)
- Déclaration TVA — collectée 13,680 / déductible 7,983 / net 5,697 — exact
- Transfert de stock — total conservé, coût moyen (WAC) préservé
- Refus de sur-transfert — 50 demandés / 8 dispo → refusé proprement
- Ajustement de stock (avarie) — 8 → 7, motif tracé
- Réception par lot — lot + péremption exigés et enregistrés
- Multi-société — étanchéité + persistance après actualisation ; zéro erreur serveur (5xx)

## Coverage gaps & not-yet-executed (from Codex campaign state)

- **Returns/credit notes** — only Claude tested (confirmed broken, F-STG-4). Codex never exercised it.
- **POS desktop (IziPOS)** — real cash-in, payment methods, cancel/refund, Z-close, offline & resync: not executed.
- **Advanced purchases** — over-receipt tolerances, multi-base landed cost, duplicates, successful payment, cancellation, doc scan, async resume: partial.
- **Automobile/Workshop vertical** — blocked: not provisionable via self-signup.
- **Identities** — invited Accountant, Viewer, Technician, cross-tenant restricted user: not activated.
- **Integrations** — email/SMS/payment/fiscal/webhooks: unclassified (sandbox/disabled/real).
- **Specialist** — Firefox/WebKit, screen readers, load/capacity, deep security, DR.

## Recommended retest order

1. Re-verify the requalified pair live: `DEV-QA-067` (supplier payment) and `DEV-QA-052` (free line).
2. Fix + retest P0/P1 confirmed: F-STG-4 (returns), F-W2-14 (cashier perms), DEV-QA-043 (GL currency), DEV-QA-035 (compliance crash), F-W2-13 (supplier refund, P0), DEV-QA-008/057 (date guard).
3. Then: treasury deposit-funded flows, over-receipt tolerance, invoice cancellation, duplicate invoices, VAT closing.

_Verification level reached: code-level (deployed dev branch) for the 6 divergences; the wider 68-ticket register is a reconciliation of the two source campaigns, not re-executed end-to-end. `DEV-QA-052` and `DEV-QA-067` still warrant a live browser confirm._
