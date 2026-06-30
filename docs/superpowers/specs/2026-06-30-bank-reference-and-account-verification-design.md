# Bank Reference Data & Account Verification — Design

> **Status:** Approved design (brainstorming output). Next step: phased implementation plans.
> **Date:** 2026-06-30
> **Branch:** `feat/bank-reference-verification` (worktree `../erp.banks`, off `origin/dev`)

## 1. Goal

Make bank-related data entry faster and less error-prone for customers by:

1. **Seeding a canonical list of banks** per country (Tunisia first, France next) so users pick from a searchable dropdown instead of typing bank names/BICs by hand.
2. **Validating bank account identifiers** (Tunisian RIB, IBAN, BIC) inline wherever they are entered, with a derived IBAN and a green-tick / warning indicator — **never blocking the save**.

This touches four surfaces the owner named:

- B2B **partner / customer** bank details
- The **banks seeder** (replace today's fake example IBANs)
- **Tenders / repositories** (`PaymentRepository`) — a searchable bank dropdown instead of free text
- **Bank drafts / checks** (`PaymentInstrument`)

## 2. Source material (research)

| Resource | What it gives | Gap |
|---|---|---|
| [SwiftCodes `TN.json`](https://github.com/PeterNotenboom/SwiftCodes/blob/master/AllCountries/TN.json) | ~79 entries `{ id, bank, city, branch, swift_code }` — i.e. **BIC/SWIFT codes**. France `FR.json` exists in the same repo. | Some rows are branch/department-level (need dedup to one canonical bank). Contains **no** 2-digit RIB clearing code. |
| [TNRIB](https://github.com/McZen-Technologies/TNRIB) | JS validator for the 20-digit Tunisian RIB; knows the **numeric `rib_bank_code` → bank** mapping and computes IBAN/BIC. | JavaScript only — not usable on the PHP backend; we reimplement the (trivial) mod-97 math. |
| Banque Centrale de Tunisie clearing-code list | Authoritative source for the 2-digit `rib_bank_code`. | Manual curation. |

### 2.1 Key insight — two different bank identifiers

A Tunisian bank has **two** identifiers that our sources split between them:

- **BIC/SWIFT** (8 or 11 chars, e.g. `BEITTNTT`) — from `TN.json`.
- **RIB clearing code** (2 digits, e.g. `07` = Amen) — the first 2 digits of every RIB; from TNRIB / BCT.

The canonical `banks` row must hold **both**, plus the bank name, so that selecting a bank in the dropdown can (a) autofill the BIC and (b) drive RIB validation and IBAN derivation.

### 2.2 Tunisian RIB / IBAN structure

```
RIB (BBAN) = 20 digits:  BB AAA CCCCCCCCCCCCC KK
             bank(2) agency(3) account(13) key(2)

IBAN (24)  = "TN" + check(2) + BBAN(20)
             e.g. 07040005810111129653  ->  TN59 0704 0005 8101 1112 9653
```

- **Clé RIB (`KK`)** and **IBAN check digits** are both **mod-97** checksums. Computed in PHP with **bcmath/gmp integers — never float** (precision contract, rule 19).
- The validator is **country-parameterized** from day one so France (5-digit bank + 5-digit guichet + 11 account + 2 key) drops in without a rewrite.

## 3. Architecture

One shared **foundation** (data + validation + picker), reused by every surface. Validation logic lives **once** as a country-parameterized domain service in `Shared/` so `Partner` never imports `Treasury` internals (module boundaries, rule 6). The frontend gets a thin TS mirror for instant feedback; **the backend is always the source of truth on save**.

```
app/Shared/Banking/
  ├── Domain/BankAccountValidator.php           ← pure, country-parameterized, fully unit-tested
  ├── Domain/ValueObjects/{RibValidationResult, IbanValidationResult}.php
  └── Contracts/BankAccountValidatorInterface.php

app/Modules/Reference/ (or Treasury)            ← banks table + BanksSeeder + GET /banks API

apps/web/src/
  ├── components/banking/BankPicker.tsx          ← reused in all 4 surfaces
  └── hooks/useBankAccountValidation.ts          ← live RIB/IBAN feedback
```

## 4. Components

### 4.1 Banks reference dataset (per-tenant `banks` table)

- Migration `tenant/<ts>_create_banks_table.php`:
  `{ id (uuid), tenant_id, country_code, name, short_name, bic (nullable), rib_bank_code (nullable), city (nullable), is_active (bool), is_custom (bool, default false), position (int), timestamps }`.
  Unique index `(tenant_id, country_code, rib_bank_code)` where `rib_bank_code` is not null.
- **Data build (reconciliation step):** committed JSON `database/data/banks/TN.json` + `FR.json`, produced by:
  1. dedup `TN.json` by bank → one canonical `name` + primary `bic` (drop branch/department rows);
  2. attach `rib_bank_code` from a curated TN clearing-code map (TNRIB / BCT).
  This merged file is the artifact the seeder consumes — the raw upstream `TN.json` is **not** seeded directly.
- `BanksSeeder` reads the JSON with `updateOrCreate(['tenant_id','country_code','name'], …)` (idempotent, like `PaymentMethodSeeder`), invoked per company-country at provisioning. Seeded rows have `is_custom = false`; tenant-added banks set `is_custom = true`.

### 4.2 Shared validator (`BankAccountValidator`)

- API:
  - `validateRib(string $rib, string $country): RibValidationResult`
  - `toIban(string $rib, string $country): string`
  - `validateIban(string $iban): IbanValidationResult`
  - `validateBic(string $bic): bool`
- Result DTO: `{ valid: bool, normalized: string, iban: ?string, bic: ?string, bank_code: ?string, bank_name: ?string, errors: string[] }`.
- TN algorithm: length + **mod-97 clé RIB** + **IBAN MOD-97-10** check digits, big-integer math via `bcmod`/`gmp` (no float). Optional cross-check of `bank_code`/`bic` against the `banks` table to fill `bank_name`.
- **TDD:** known-valid vector `07040005810111129653 → TN59…`, plus corrupted-key, wrong-length, non-numeric, and foreign-IBAN cases.

### 4.3 Reusable `<BankPicker>` (frontend)

- Searchable combobox over `GET /api/v1/banks?country=TN&q=…`; rows show **name + BIC**.
- On select: autofills `bank_name`/`bic`, pins `rib_bank_code` so the adjacent RIB field validates and derives the IBAN live (green tick / red warning — **never blocks**, mirroring the existing business-registration-number validate button).
- "Bank not listed?" → free-text fallback (records a custom bank or plain text).
- Used identically across `PaymentRepository`, `PaymentInstrument`, and partner bank-account rows.
- Backend `GET /api/v1/banks` (read-only, search/filter by country + query) under a small `Reference` controller; routes follow the `['api','auth:sanctum',SetPermissionsTeam::class]` middleware pattern (rule 12).

### 4.4 Partner bank accounts (sub-table)

- Migration `tenant/<ts>_create_partner_bank_accounts_table.php`:
  `{ id, tenant_id, partner_id (FK), label, bank_id (nullable FK to banks), bank_name, rib, iban, bic, currency, is_primary (bool), created_by, timestamps }`.
- `Partner\Domain\PartnerBankAccount` entity + DTO + `hasMany` relation; one row flagged `is_primary`.
- FormRequest: keep numeric/string rules; validate RIB/IBAN/BIC through the shared service in **warn mode** (record validity, do not reject).
- FE: a repeatable "Bank Accounts" section inside `B2BFieldsSection` — each row = `<BankPicker>` + RIB input (auto-derives IBAN, shows tick/warning) + primary toggle.
- Types regenerated from the new DTO via `php artisan typescript:transform` (rule 7).

## 5. Phasing

| Phase | Deliverable | Notes |
|---|---|---|
| **1 — Foundation** | `banks` table + reconciled TN/FR JSON + `BanksSeeder`; `BankAccountValidator` + full unit tests; `GET /banks` API; `<BankPicker>` + `useBankAccountValidation`. | No change to existing screens yet — pure new primitive. |
| **2 — Treasury surfaces** | Wire `<BankPicker>` + validation into `PaymentRepository` (own bank accounts) and `PaymentInstrument` (checks/traites). **Replace fake seeded IBANs:** seed repositories referencing a real bank (`bank_id`) but leave `account_number`/`iban` blank — a blank is better than a fake-looking real IBAN. | |
| **3 — Partner** | `partner_bank_accounts` sub-table + FE section + warn-mode validation; surface the partner's **primary RIB** on B2B invoices/quotes (the "pay-to" block, standard in TN/FR). | |
| **4 — Optional / expansion** | BIC↔bank cross-check, IBAN/RIB on customer-facing PDFs, France SEPA niceties, bank logos, extend the pattern to MA / DZ / IT as markets are added. | |

## 6. Cross-cutting concerns

- **Validation policy:** warn-but-allow everywhere (no hard block). Foreign/legacy/edge accounts must still save.
- **Precision (rule 19):** all checksum math uses bcmath/gmp integers; RIB/IBAN/BIC stored as strings — no float ever touches them.
- **Module boundaries (rule 6):** validator in `app/Shared/Banking`, consumed via interface; `Partner` does not import `Treasury`.
- **Types flow from backend (rule 7):** regenerate TS after new DTOs.
- **i18n (rule 11):** all new labels via `t()`.
- **Vertical gating:** banks/treasury are universal (IziPOS **and** Otospex) — **not** vertical-gated.
- **TDD (rule 2/5):** validator and seeders test-first; verify end-to-end on a real seeded tenant.
- **Branch discipline (rule 21):** work in worktree `../erp.banks`; merge to local `dev` first, promote to `origin/dev` as a clean fast-forward.

## 7. Out of scope (for now)

- Live bank-API account verification (we validate *format/checksum*, not account existence at the bank).
- SEPA/SWIFT payment file generation.
- Non-bank tax/postal reference datasets (matricule fiscale already validated separately).
