# Accounting conventions for legacy-data migration / Conventions comptables pour la reprise de données

> For anyone importing data from a previous system (opening balances, aged AR/AP, opening stock).
> Codes shown are the PCG-style chart our TN/FR tenants use.

---

## English

### 1. The one rule
Every entry has **debits = credits**. An account's *normal balance* tells you what increases it:

| Account family | Examples (code) | Debit does | Credit does | Normal balance |
|---|---|---|---|---|
| Assets | stock `3x`, customers `411`, bank/cash `5x` | **increases** | decreases | debit (*débiteur*) |
| Expenses | purchases `60x`, refund write-off `6590` | **increases** | decreases | debit |
| Liabilities | suppliers `401`, VAT collected `4457` | decreases | **increases** | credit (*créditeur*) |
| Equity & openings | capital `10x`, opening counterpart | decreases | **increases** | credit |
| Revenue | sales `707`, sales returns `709` (contra) | decreases | **increases** | credit |

Memory hook: **D-E-A-D** — Debits increase Expenses, Assets, Drawings; credits increase the rest (Liabilities, Equity, Revenue).

### 2. What each migration operation posts

| Operation (import flow) | Debit | Credit |
|---|---|---|
| Opening stock | `3x` Stock | Opening counterpart (equity) |
| Customer opening (they owe us) | `411` Customer | Opening counterpart |
| Supplier opening (we owe them) | Opening counterpart | `401` Supplier |
| Aged AR: opening credit note | nets **against** the opening invoice on `411` (never a fresh credit) | |
| Opening cash float | `53x` Cash drawer | Opening counterpart |
| Opening bank balance | `512` Bank | Opening counterpart |

After go-live, normal operations move the same accounts:

| Operation | Debit | Credit |
|---|---|---|
| Sales invoice | `411` Customer | `707` Revenue + `4457` VAT collected |
| Customer payment | `5x` Bank/Cash | `411` Customer |
| Supplier invoice | `60x`/`3x` + `4456` VAT deductible | `401` Supplier |
| Supplier payment | `401` Supplier | `5x` Bank/Cash |
| POS refund | `709` Sales returns (+VAT reversal) | `53x` Cash drawer |

### 3. Sign conventions in source files — THE rule (matches the importer, `PartiesRowMapper`)

**Balances are written from YOUR company's point of view.**

| File column | Positive means | Negative means |
|---|---|---|
| `opening_balance` (customer) | **The client owes you** → imported as a historical invoice on `411` | **You owe the client** (advance, overpayment, credit) → imported as a historical **credit note** for the absolute amount |
| `opening_balance` (supplier) | **You owe the supplier** → historical supplier invoice on `401` | **The supplier owes you** (credit, rebate due) → historical **supplier credit note** |

So the sign never flips an account: it selects the DOCUMENT TYPE (invoice vs credit note); the amount imported is always the absolute value. Zero balances are skipped. In the per-invoice aged file (`open_items`), amounts must be **non-negative** and the direction is given explicitly by the `document_type` column (`invoice` or `credit_note`) — a negative amount there is a row error, by design.
- A customer balance is **positive when they owe us** (debit balance on `411`). A credit balance on a customer (advance received) is an *avance client* → `419`, not a negative sale.
- A supplier balance is **positive when we owe them** (credit balance on `401`). If the old system exports "supplier −500", that's money the supplier owes *us* — it flips sides (our importer's `HistoricalOpeningSideReader` handles the side; don't pre-flip in the file).
- Never import into **control accounts** (`411`/`401` totals) directly through the GL batch — the importer refuses this; person-level balances go through the AR/AP opening flow so the subledger and GL stay tied.


### 4. Open-invoice continuity (aged AR/AP)
Open customer and supplier invoices from the old system are imported **one row per open invoice** (not one lump sum). Each becomes a *historical* document in the new system: posted, back-dated to its real date, carrying its open amount — but with no product lines and **outside the VAT declaration** (the old system already declared it). From then on the normal flows apply: when the customer pays, the payment is **allocated against those exact open invoices** (oldest first or as selected) and purges them; supplier payments likewise settle the imported `401` invoices. An opening credit note is netted against its opening invoice at import, never left floating.

---

## Français

### 1. La règle unique
Toute écriture équilibre **débits = crédits**. Le *solde normal* d'un compte indique ce qui l'augmente :

| Famille de comptes | Exemples (code) | Le débit | Le crédit | Solde normal |
|---|---|---|---|---|
| Actif | stock `3x`, clients `411`, banque/caisse `5x` | **augmente** | diminue | **débiteur** |
| Charges | achats `60x`, perte s/ remboursement `6590` | **augmente** | diminue | débiteur |
| Passif | fournisseurs `401`, TVA collectée `4457` | diminue | **augmente** | **créditeur** |
| Capitaux propres & reprises | capital `10x`, contrepartie d'ouverture | diminue | **augmente** | créditeur |
| Produits | ventes `707`, RRR/retours `709` (soustractif) | diminue | **augmente** | créditeur |

### 2. Ce que chaque opération de reprise comptabilise

| Opération (flux d'import) | Débit | Crédit |
|---|---|---|
| Stock d'ouverture | `3x` Stocks | Contrepartie d'ouverture |
| Solde client d'ouverture (il nous doit) | `411` Client | Contrepartie d'ouverture |
| Solde fournisseur d'ouverture (nous lui devons) | Contrepartie d'ouverture | `401` Fournisseur |
| Avoir d'ouverture (AR âgé) | s'impute **sur** la facture d'ouverture du `411` | |
| Fonds de caisse d'ouverture | `53x` Caisse | Contrepartie d'ouverture |
| Solde bancaire d'ouverture | `512` Banque | Contrepartie d'ouverture |

Après démarrage :

| Opération | Débit | Crédit |
|---|---|---|
| Facture de vente | `411` Client | `707` Ventes + `4457` TVA collectée |
| Règlement client | `5x` Banque/Caisse | `411` Client |
| Facture fournisseur | `60x`/`3x` + `4456` TVA déductible | `401` Fournisseur |
| Règlement fournisseur | `401` Fournisseur | `5x` Banque/Caisse |
| Remboursement POS | `709` Retours (+ contre-passation TVA) | `53x` Caisse |

### 3. Conventions de signe dans les fichiers sources — LA règle (celle de l'importateur)

**Les soldes s'écrivent du point de vue de VOTRE société.**

| Colonne du fichier | Positif signifie | Négatif signifie |
|---|---|---|
| `opening_balance` (client) | **Le client vous doit** → repris comme facture historique sur `411` | **Vous devez au client** (avance, trop-perçu, avoir) → repris comme **avoir historique** pour le montant absolu |
| `opening_balance` (fournisseur) | **Vous devez au fournisseur** → facture fournisseur historique sur `401` | **Le fournisseur vous doit** (avoir, ristourne due) → **avoir fournisseur historique** |

Le signe ne change donc jamais de compte : il choisit le TYPE DE DOCUMENT (facture vs avoir) ; le montant repris est toujours la valeur absolue. Les soldes à zéro sont ignorés. Dans le fichier détaillé par facture (`open_items`), les montants doivent être **positifs ou nuls** et le sens est donné explicitement par la colonne `document_type` (`invoice` ou `credit_note`) — un montant négatif y est une erreur de ligne, volontairement.
- Un solde client est **positif quand le client nous doit** (solde débiteur du `411`). Un solde créditeur client = *avance client* → `419`, jamais une vente négative.
- Un solde fournisseur est **positif quand nous devons au fournisseur** (solde créditeur du `401`). « Fournisseur −500 » dans l'ancien système = le fournisseur nous doit → le sens s'inverse (l'importateur gère le sens ; ne pas inverser dans le fichier).
- Ne jamais importer directement dans les **comptes collectifs** (`411`/`401` globaux) via le lot GL — l'importateur le refuse ; les soldes nominatifs passent par la reprise AR/AP pour garder l'auxiliaire et le général alignés.

### 4. Continuité des factures ouvertes (AR/AP âgés)
Les factures clients et fournisseurs encore ouvertes dans l'ancien système sont reprises **ligne par facture** (jamais en montant global). Chacune devient un document *historique* : validé, à sa date réelle, avec son restant dû — mais sans lignes produit et **hors déclaration de TVA** (déjà déclarée dans l'ancien système). Ensuite les flux normaux s'appliquent : un règlement client est **lettré contre ces factures ouvertes précises** et les solde ; idem pour les règlements fournisseurs sur `401`. Un avoir d'ouverture est imputé sur sa facture d'ouverture dès la reprise, jamais laissé isolé.
