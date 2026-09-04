# Kit de test staging — Dhouha — 2026-09-04

> Kit prêt à l'emploi : un tenant staging déjà chargé, 28 produits avec codes-barres **valides et scannables**,
> une planche à imprimer, du stock en place, et les scénarios à dérouler.
> Rien à installer côté serveur : tout est déjà en ligne.

---

## 1. Accès

| | |
|---|---|
| **Dashboard web** | https://erp.otospex.dev |
| **API** | https://api.erp.otospex.dev (`/api/v1/health` → 200) |
| **Tenant** | `PharmaBio Tunisie SARL` — tenant id `019ee4d7-0814-72fa-a109-256fd9973249` |
| **Société à sélectionner** | **PharmaBio Tunisie SARL** (le tenant en contient 3 : *jerbi* et *Otospex* sont des coquilles vides, ne pas les utiliser) |
| **Identifiant principal** | `owner@pharmabio.tn` — rôle **admin** |
| **Mot de passe** | **à demander à Houssam** (jamais écrit dans ce dépôt) |
| **Comptes secondaires** | `manager@pharmabio.tn` (rôle *manager* → le « compteur »), `cashier@pharmabio.tn` (rôle *cashier* → POS uniquement), plus 4 caissiers rattachés à une boutique : `tunis1.` / `tunis2.` / `sousse.` / `sfax.cashier@pharmabio.tn`. **Même mot de passe, à demander à Houssam.** Les codes PIN POS sont déjà définis (demander aussi). |

### Emplacements

| Rôle dans les tests | Code | Nom | POS | Caisse |
|---|---|---|---|---|
| **Emplacement principal** | `STORE-TUN1` | PharmaBio Tunis — Lac | oui | `POS01` |
| **2ᵉ emplacement** (transferts, second-of-everything) | `STORE-TUN2` | PharmaBio Tunis — Centre | oui | `POS01` |
| Autres | `STORE-SOU`, `STORE-SFA`, `gabes1`, `shop23` | Sousse / Sfax / Gabès / shop23 | oui | 2 caisses |
| Entrepôt (non-POS) | `WH-01` | PharmaBio Entrepôt Central | non | — |

---

## 2. La planche de codes-barres

| Fichier | Usage |
|---|---|
| `docs/qa/testkit/2026-09-04-dhouha-barcodes.html` | **la planche à imprimer** (A4, 3 colonnes, 28 vignettes) |
| `docs/qa/testkit/2026-09-04-dhouha-barcodes.csv` | la même liste en `name,sku,ean,location` (pour copier/coller un code au clavier) |
| `docs/qa/testkit/products.json` | les données source (catégorie, unité, décimales, lot, stock relevé le 2026-09-04) |
| `docs/qa/testkit/gen-barcodes.py` | le générateur (`python3 gen-barcodes.py` régénère HTML + CSV) |

### Impression — important

1. Ouvrir le `.html` dans Chrome (double-clic sur le fichier).
2. `Cmd/Ctrl + P` → **Échelle : 100 %** (surtout **pas** « Ajuster à la page » : une réduction rend les barres illisibles).
3. Marges : par défaut (10 mm sont déjà dans le CSS `@page`). Papier **blanc ordinaire**, pas de recto-verso.
4. Vérifier à l'œil qu'une vignette fait bien ~6 cm de large. Scanner une vignette au hasard avant de commencer.

### Ce que contient la planche

| Groupe | Vignettes | Symbologie | Particularité |
|---|---|---|---|
| Compléments alimentaires | 10 | EAN-13 | 8 suivis par lot |
| Soins & Cosmétiques | 8 | EAN-13 | 6 suivis par lot |
| Bébé & Matériel | 5 | **Code 128 (SKU)** | teste la résolution par SKU, pas par code-barres |
| Stock zéro | 3 | EAN-13 | quantité **0** à `STORE-TUN1` (pour `include_zero_stock`) |
| Vrac & Pesée | 2 | EAN-13 | unité **kg**, **3 décimales** (`KIT-VRAC-001` 12,500 kg · `KIT-VRAC-002` 8,250 kg) |

Totaux : **28 produits**, **28 codes EAN-13 à chiffre-clé valide** (dont 5 imprimés en Code 128 sur leur SKU),
**25 avec stock** à `STORE-TUN1`, **3 à zéro**, **19 suivis par lot** (péremption 2028-07-01), **2 en quantité décimale**,
**3 catégories**, **24 avec du stock aussi à `STORE-TUN2`**.

Les quantités imprimées sont celles du **2026-09-04 à 08 h 45 (Africa/Tunis)** : elles bougeront dès votre premier
test — c'est normal, la référence vivante est l'écran `Inventaire → Stock par emplacement`.

---

## 3. Pré-requis (à cocher avant de commencer)

- [ ] **Build web staging** : ouvrir https://erp.otospex.dev, `Afficher le code source`, relever le nom du bundle.
      Au moment de la rédaction : **`index-CdNqm5xl.js`**. Ce nom doit figurer dans **chaque** rapport de bug.
- [ ] **API** : https://api.erp.otospex.dev/api/v1/health renvoie `{"status":"healthy", ...}`.
- [ ] **Correctif N-1 déployé** : ouvrir une fiche de comptage — la ligne **« Ventes pendant le comptage »**
      (*Sales during count*) doit être visible. Si elle manque, le déploiement n'est pas passé : **prévenir Houssam
      et ne pas dérouler les scénarios A et B.**
      (Le commit `de31017e0` est bien sur `origin/dev`, vérifié sur staging le 2026-09-02.)
- [ ] **Connexion** : `owner@pharmabio.tn` → société **PharmaBio Tunisie SARL** sélectionnée en haut de l'écran.
- [ ] **Planche imprimée** à 100 % + un scanner (douchette USB ou l'appareil photo du téléphone).
- [ ] **IziPOS (caisse)** : la caisse vendeuse est l'**application de bureau IziPOS (Tauri)** — il n'y a **pas** de
      caisse dans le navigateur (le web ne propose que le back-office POS : tickets, shifts, rapports Z).
      L'application de bureau se construit séparément et se connecte au backend staging : **c'est toi qui la
      construis/installes** (procédure habituelle du dépôt POS). Ouvrir un shift avec un fond de caisse avant tout
      scénario POS. Les scénarios B et C ne sont pas prioritaires cette semaine (voir « Priorité » ci-dessous).
- [ ] **Mobile (PRIORITÉ n° 1 — comptage d'inventaire)** : le dépôt `erp-mobile`, branche **`main`**, **une fois
      que Houssam a confirmé que la correction Codex est fusionnée** (2 correctifs en cours : tuile « En vérification »
      supprimée, libellé de zone = nom et non code). **C'est toi qui construis le paquet** :
      ```bash
      git clone <erp-mobile> && cd erp-mobile && git checkout main && git pull
      pnpm install
      cp .env.example .env        # puis : EXPO_PUBLIC_API_URL=https://api.erp.otospex.dev/api/v1
      npx expo start              # Expo Go sur le téléphone (QR code), ou
      npx expo run:android        # build de développement Android (nécessite Android Studio / SDK)
      ```
      Le `README.md` du dépôt détaille l'installation ; vérifier `npx tsc --noEmit` et `npx jest` avant de tester.
      Se connecter en **`manager@pharmabio.tn`** (les brouillons mobiles exigent manager/admin).
      Les 4 vérifications physiques attendues sur mobile (à faire en premier) : scan caméra sur l'écran de réception,
      saisie manuelle Android sur les scanners de comptage ET de réception, « Tout abandonner » avec un vrai
      brouillon de comptage clôturé garé, et un comptage avec `includes_zero_stock` (produits à stock zéro visibles).
- [ ] **Rôles** : `owner` = admin (crée, révise, finalise) · `manager` = compte · `cashier` = POS seulement.

---

## 4. Scénarios

> **Priorité de la semaine : le mobile.** Jouer d'abord **A** (mobile), **F** (mobile), **D** (réception mobile) et
> **E** ; **B** et **C** (caisse de bureau) ensuite, quand tu as le temps.


Les résultats attendus détaillés du comptage (mode bloquant, comptage en direct, zone, hors-ligne) sont dans
`docs/handoff/HANDOVER-DHOUHA-INVENTORY-COUNTING-A2Z-2026-09-02.md` — **le lire avant A et B**, notamment sa
**§3 « Connus, ne pas remonter »** (bugs déjà déposés : ne pas les re-signaler).

---

### A — Comptage d'inventaire par emplacement, **SANS blocage des ventes** (P0)

**Où** : `Inventaire → Comptage` → `/inventory/counting` puis `/inventory/counting/create`.

1. Créer un comptage : périmètre **Produits spécifiques à un emplacement**, emplacement **STORE-TUN1**,
   3 produits scannés depuis la planche (par ex. `PB-SUP-0003`, `PB-COS-0003`, `KIT-VRAC-001`),
   **« Bloquer les ventes » décoché**, fenêtre d'ambiguïté **15 min**, 2ᵉ comptage désactivé,
   compteur = `manager@pharmabio.tn`.
   **Attendu** : l'étape de revue affiche « Bloquer les ventes : Non » ; la fiche affiche
   **« Ventes pendant le comptage : En direct (± 15 min) »**.
2. Activer. **Attendu** : statut « Comptage 1 en cours ».
3. Compter — **web** (`/inventory/counting/:id`) et **mobile** (onglet *Tâches*) :
   scanner le code de la planche, saisir une quantité.
   Mettre volontairement un **écart** sur un produit (ex. stock 9 → compter 7) et la quantité exacte sur les autres.
   **Attendu** : le compteur est **aveugle** — la quantité attendue n'est **jamais** affichée.
4. **Attendu** après le dernier article : le comptage passe en **« en attente de revue »** et **disparaît** de la
   liste du compteur (c'est normal, ce n'est pas un bug).
5. Revue web → corriger l'écart avec une note (une note de **moins de 10 caractères doit être refusée**) → **Finaliser**.
   Si la caisse n'a pas remonté son état de synchro, une case **« risque de synchro POS »** apparaît : la cocher.
6. **Où c'est atterri** :
   - `Inventaire → Stock par emplacement` (`/inventory/stock-by-location`) : la quantité du produit à STORE-TUN1
     = la quantité comptée ;
   - `Inventaire → Mouvements` (`/inventory/movements`) : une ligne **`adjustment`** de la valeur de l'écart,
     référencée au numéro de comptage ;
   - le **rapport d'écarts** (`/inventory/counting/:id/report`) : colonnes attendu / compté / écart / appliqué.

---

### B — Comptage **AVEC blocage des ventes** + fenêtre d'ambiguïté (P0)

**Prérequis** : IziPOS ouvert sur `STORE-TUN1` avec un shift ouvert, et si possible une 2ᵉ caisse sur `STORE-TUN2`.

1. Créer le même comptage mais **« Bloquer les ventes » coché**, fenêtre 15 min.
   **Attendu** : fiche → « Ventes pendant le comptage : **Bloquées** · fenêtre ± 15 min ».
2. Activer.
3. **IziPOS STORE-TUN1, dans les ~60 s** : scanner un produit du comptage → refus avec le toast
   **« Les ventes sont suspendues pendant l'inventaire CNT-… »**.
   Scanner un produit **hors** du comptage → **également refusé** (le blocage est par *emplacement*, pas par produit).
   **La caisse de STORE-TUN2 continue de vendre normalement.** Capture d'écran des deux.
   > La caisse interroge le serveur toutes les 60 s (jusqu'à 5 min en mode dégradé) : **laisser une minute** avant de conclure.
4. **Cas limite « fenêtre d'ambiguïté »** — le comportement attendu, à ne pas confondre avec un bug :
   laisser **un panier déjà ouvert** avant l'activation, puis l'encaisser après le blocage.
   **Attendu (par conception)** : la vente **passe** — le blocage ne filtre que l'*ajout au panier*, pas l'encaissement.
   Le serveur l'accepte et la marque sur le comptage comme **« vente tardive »** ; la page de revue affiche la bannière
   **« 1 vente tardive enregistrée pendant le comptage »**.
   ⚠️ Une vente qui *passerait à l'ajout au panier* est, elle, un bug — à remonter.
5. Compter, réviser, finaliser comme en A.
6. **Attendu dans les ~60 s après finalisation** : la caisse **revend sans redémarrage**.
7. **Variantes à essayer** :
   - **annuler** un comptage bloquant au lieu de le finaliser → la caisse doit se débloquer ;
   - activer un **2ᵉ** comptage bloquant sur le même emplacement pendant qu'un est en cours → **refusé** (garde-fou anti-chevauchement) ;
   - périmètre **Zone**, **Produit** ou **Catégorie** → la case « Bloquer les ventes » doit être **désactivée**
     (un blocage n'a de sens que pour un périmètre couvrant un emplacement).

**Comptage en direct avec vente pendant le comptage** (la contrepartie de B) :
comptage **non bloquant**, compter le produit A **exactement égal** au stock (ex. 9), puis vendre 2 unités de A au POS,
puis finir le comptage. **Attendu** : la revue montre « attendu maintenant 7 », « mouvements depuis le comptage −2 »,
**ajustement 0**, aucun signalement ; après finalisation le stock vaut **7** (et **non** 9 « rétabli »),
et **aucun mouvement `adjustment`** pour A.

---

### C — Vente POS par scan (P0)

**Où** : application **IziPOS (bureau)** — pas de caisse web sur staging.

1. Ouvrir un shift sur `STORE-TUN1` avec un fond de caisse.
2. Scanner 3 vignettes EAN-13 de la planche → les 3 lignes s'ajoutent avec le bon libellé et le bon prix.
3. Scanner une vignette **Code 128** (`PB-BAB-0003` …) → doit se résoudre **par SKU** (déjà vérifié côté API, cf. annexe).
4. Scanner `KIT-VRAC-001` (kg) → saisir **1,250 kg** : le champ doit accepter **3 décimales**.
5. Scanner un produit **stock zéro** (`PB-SPO-0003`) → observer le comportement (refus, alerte, ou vente autorisée
   selon la politique de l'emplacement) et **le noter**, avec ce qui est affiché.
6. Encaisser en espèces. **Où c'est atterri** :
   - le ticket dans `POS → Tickets` (`/pos/receipts`) ;
   - le stock diminué dans `/inventory/stock-by-location` ;
   - un mouvement `sale` dans `/inventory/movements`.
7. Vérifier que le total TTC du ticket correspond au prix affiché sur la vignette (prix TTC, TVA 19 %).

---

### D — Réception d'une commande fournisseur **par scan** (P1)

**Où** : `Achats → Commandes` (`/purchases/orders`) puis `Achats → Réceptions` (`/purchases/receipts`).

1. Créer et **confirmer** une commande fournisseur pour 2 produits de la planche à `STORE-TUN1`,
   dont **un produit suivi par lot** (les vignettes marquées `LOT`).
2. Réceptionner : ligne 1 partielle (ex. 5 sur 10) ; ligne 2 avec **numéro de lot + date de péremption**.
   **Attendu** : la commande passe en **« partiellement réceptionnée »**, le stock monte de 5, un lot avec cette
   péremption apparaît dans `Inventaire → Lots` (`/inventory/batches`).
3. Réceptionner le reste → **« Réception complète »**.
4. Réception **sur mobile** : le **scan de code-barres de l'écran de réception est cassé aujourd'hui**
   (déjà déposé, ticket mobile **M-6**) → utiliser la saisie manuelle, **ne pas re-signaler le scan**.
5. Sur le web, essayer la saisie de ligne **par scan** dans le formulaire de réception (barre de saisie code) :
   c'est ce chemin-là qu'il faut éprouver avec la planche.

---

### E — Transfert de stock entre deux emplacements (P1)

**Où** : `Inventaire → Transferts de stock` → `/inventory/stock-transfers/new`.

1. Source **STORE-TUN1**, destination **STORE-TUN2**.
2. Ajouter les lignes **en scannant** les vignettes (la barre de saisie de ligne résout EAN-13 **et** SKU).
   Inclure au moins un produit `LOT` et **`KIT-VRAC-001`** avec une quantité **décimale** (ex. 2,750 kg).
3. Noter les quantités **avant** aux deux emplacements (`/inventory/stock-by-location`), puis **compléter** le transfert.
4. **Attendu** : source −q, destination +q, deux mouvements dans `/inventory/movements`, le lot suivi jusqu'à destination.
   Aucune quantité décimale arrondie ou tronquée en route.
5. Variante : **annuler** un transfert avant complétion → aucun stock ne bouge.

---

### F — Quantités décimales et produits à stock zéro (P1)

1. **Décimal** : `KIT-VRAC-001` / `KIT-VRAC-002` sont en **kg à 3 décimales**.
   Partout où une quantité se saisit (comptage, ajustement, transfert, réception, POS), le champ doit accepter
   **3 décimales** et l'afficher telle quelle. **Tout arrondi à l'entier, tout `12.5` devenu `13`, tout `NaN` = P0.**
2. **Stock zéro** : `PB-SPO-0003`, `PB-SPO-0016`, `PB-SPO-0024` sont à **0** à `STORE-TUN1` (mais ont du stock ailleurs).
   Créer un comptage de **périmètre Emplacement** sur `STORE-TUN1` : selon le mode d'accueil de l'emplacement,
   ces 3 produits doivent apparaître ou non dans la liste à compter — **noter ce qui se passe**.
   > L'assistant de création web **n'a pas** d'option « inclure les produits à stock zéro » (il suit le mode de
   > l'emplacement) — c'est **déjà connu, ne pas remonter**.
3. **Catégorie** : un comptage de périmètre **Catégorie** sur *Compléments alimentaires* doit ramener exactement
   les 13 produits de cette catégorie (10 + les 3 à stock zéro).

---

### G — Que remonter, et où

- **Le format de rapport est obligatoire** : `docs/qa/MANUAL-TESTING-LOOP.md` **§3**.
  Un rapport auquel il manque un champ **revient au testeur**. Champs : `WHAT / WHERE / TENANT / WHEN / EXPECTED /
  GOT / EVIDENCE / REPRO / SEVERITY`.
- **Un bug = un rapport.** Ne pas grouper.
- Toujours joindre **le nom du bundle web** (`index-CdNqm5xl.js` aujourd'hui) ou l'horodatage `/health` de l'API.
- Sévérité : **P0** argent ou stock faux/perdu · **P1** bloque un parcours · **P2** faux mais contournable · **P3** friction/formulation.
- **Remonter systématiquement** : toute **5xx**, toute **erreur console** dans le navigateur, tout chiffre de stock
  ou de compta qui ne colle pas avec la ligne de mouvement, toute caisse qui n'honore pas un blocage **sous 5 minutes**,
  toute vente tardive absente de la page de revue.
- **Ne PAS remonter** la liste de la §3 du handover A-to-Z (comptage qui disparaît en revue, sélecteur de zone mobile
  vide, scan de réception mobile, toast POS non persistant, lag de 60 s, `idempotency_key` mobile ignoré, etc.).
- Comparer au comportement d'un **ERP grand public** (Odoo / ERPNext / Dolibarr), pas à la spécification :
  « la spec dit ça » ne ferme pas un rapport.

---

## 5. Annexe — vérifications faites sur staging le 2026-09-04

Toutes les requêtes ci-dessous ont été exécutées sur `https://api.erp.otospex.dev` en tant que `owner@pharmabio.tn`,
société `PharmaBio Tunisie SARL` (en-tête `X-Company-Id: 019ee4d7-3104-736a-9358-6594847afbd5`).
La base PostgreSQL de staging n'a été utilisée qu'en **lecture** (`SELECT` uniquement) ; **toutes** les écritures
sont passées par les endpoints de l'application.

| # | Requête | Statut | Résultat |
|---|---|---|---|
| 1 | `POST /api/v1/auth/login` (`owner@pharmabio.tn`) | **200** | jeton émis · rôle `admin` · tenant `019ee4d7-…9973249` |
| 2 | `GET /api/v1/user/companies` | **200** | 3 sociétés ; `PharmaBio Tunisie SARL` = `is_primary` |
| 3 | `GET /api/v1/health` | **200** | `{"status":"healthy"}` |
| 4 | `POST /api/v1/categories` ×3 | **201** ×3 | `#3 Compléments alimentaires`, `#4 Soins & Cosmétiques`, `#5 Vrac & Pesée` |
| 5 | `POST /api/v1/products` ×2 | **201** ×2 | `KIT-VRAC-001` / `KIT-VRAC-002`, unité `kg`, `quantity_decimals: 3` |
| 6 | `POST /api/v1/stock-adjustments` (`post_immediately`) | **201** | `ADJ-2026-0001` posté · +12,5000 et +8,2500 à `STORE-TUN1` |
| 7 | `PATCH /api/v1/products/{id}` ×26 | **200** ×26 | catégorie affectée aux 26 produits existants du kit |
| 8 | `GET /line-entry/resolve-code?code=6190000000033` | **200** | `product` · `product_barcode` · **PB-SUP-0003** — Multivitamin Complex - 30 Tablets |
| 9 | `GET /line-entry/resolve-code?code=6190000003539` | **200** | `product` · `product_barcode` · **PB-COS-0003** — Cleansing Gel Sensitive Skin 200ml |
| 10 | `GET /line-entry/resolve-code?code=6199040000010` | **200** | `product` · `product_barcode` · **KIT-VRAC-001** — Argile verte surfine — vrac (kg) |
| 11 | `GET /line-entry/resolve-code?code=PB-BAB-0003` | **200** | `product` · **`product_sku`** · PB-BAB-0003 — la résolution Code 128/SKU fonctionne |
| 12 | `GET /stock-levels?location_id=STORE-TUN1&search=KIT-VRAC` | **200** | `12.5000` et `8.2500`, `quantity_decimals: 3`, `available` identique |
| 13 | `POST /api/v1/inventory/countings` | **201** | `CNT-2026-0001` créé (statut `draft`, `ambiguity_window_minutes: 15`) |
| 14 | `GET /api/v1/inventory/countings/{id}` | **200** | statut `draft` |
| 15 | `POST /api/v1/inventory/countings/{id}/cancel` | **200** | `CNT-2026-0001` → statut **`cancelled`** — **rien n'est resté ouvert** |

Contrôles hors ligne :

- **Chiffres-clés EAN-13** : les 28 codes du kit ont été validés par l'algorithme **du produit lui-même**
  (`apps/api/app/Modules/PlatformIntegration/Domain/Services/BarcodeNormalizer.php:42-53`) → **0 invalide**.
- **Aller-retour d'encodage** : `gen-barcodes.py` encode puis **redécode** chaque symbole avant d'imprimer
  (28 EAN-13 + 6 Code 128 B, plus les vecteurs de référence `5901234123457` / `4006381333931` et un test négatif
  sur un module inversé) → **0 échec**. La planche n'est jamais produite si un seul aller-retour échoue.

### Ce qui n'a **pas** pu être vérifié

- **La caisse IziPOS** : aucun build de bureau n'est installé ici (les hôtes `pos.`/`izipos.erp.otospex.dev` ne
  répondent pas, ce qui est normal : l'app de bureau se construit à part). Les scénarios **B** et **C** n'ont donc pas
  été rejoués de ce côté-ci.
- **L'application mobile** : la branche `main` d'`erp-mobile` attend la fusion des 2 correctifs Codex (gate ERP du
  2026-09-04). Houssam confirme quand c'est prêt ; le paquet est à construire par la testeuse (voir §3).
- **Le mot de passe** des comptes : jamais testé au-delà de `owner@pharmabio.tn`, et volontairement non écrit ici.
- **La ligne « Ventes pendant le comptage »** dans l'interface : vérifiée uniquement côté API/commit
  (`de31017e0` présent sur `origin/dev`), **pas** visuellement sur le build staging — d'où la case à cocher en §3.
