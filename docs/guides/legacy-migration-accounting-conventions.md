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

### 3. Sign conventions in source files
- A customer balance is **positive when they owe us** (debit balance on `411`). A credit balance on a customer (advance received) is an *avance client* → `419`, not a negative sale.
- A supplier balance is **positive when we owe them** (credit balance on `401`). If the old system exports "supplier −500", that's money the supplier owes *us* — it flips sides (our importer's `HistoricalOpeningSideReader` handles the side; don't pre-flip in the file).
- Never import into **control accounts** (`411`/`401` totals) directly through the GL batch — the importer refuses this; person-level balances go through the AR/AP opening flow so the subledger and GL stay tied.

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

### 3. Conventions de signe dans les fichiers sources
- Un solde client est **positif quand le client nous doit** (solde débiteur du `411`). Un solde créditeur client = *avance client* → `419`, jamais une vente négative.
- Un solde fournisseur est **positif quand nous devons au fournisseur** (solde créditeur du `401`). « Fournisseur −500 » dans l'ancien système = le fournisseur nous doit → le sens s'inverse (l'importateur gère le sens ; ne pas inverser dans le fichier).
- Ne jamais importer directement dans les **comptes collectifs** (`411`/`401` globaux) via le lot GL — l'importateur le refuse ; les soldes nominatifs passent par la reprise AR/AP pour garder l'auxiliaire et le général alignés.
