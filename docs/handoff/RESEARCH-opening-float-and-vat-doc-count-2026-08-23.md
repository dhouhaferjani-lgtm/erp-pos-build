# RESEARCH — Opening cash float (B-2) & VAT declaration `document_count` (B-6(i))

**Date:** 2026-08-23 · **Audience:** owner (non-accountant, technical) · **Scope:** read-only research; no code changed.
**Context:** Tunisian first client (TND, TN chart of accounts, parapharmacy retail); French/EU practice used as secondary reference.

---

## TL;DR

| | Verdict |
|---|---|
| **B-2 opening float** | Your lean is **half right, and the half that's wrong is the dangerous half.** The float *is* an opening balance — but industry-standard systems book it **once, in the accounting opening batch**, and the POS/till side carries it as a **subledger control number with no GL posting of its own**. Today our only operator-facing way to seed a till posts the float to a **revenue account (7580)**, which is not a balance-sheet double count — it is **fake income**. Shipping option (a) with only a runbook line is acceptable *only if the runbook forbids the operator path outright*. Recommended: **(a) a hardened runbook now (S, pre-launch) + (b) the `opening_float` movement kind (M, post-launch)** — and for (b) the enum case *and* the reconciler's no-journal-entry exemption **already exist in the codebase**; only the writer is missing. |
| **🚨 Out-of-scope escalation** | Verifying which GL account the float should debit turned up that **`TunisiaChartOfAccountsSeeder` is a French PCG chart under a Tunisian label** — real TN cash is `54/5411` (our `53` is *Banques* in TN), `63`/`66` and `73`/`75` are swapped, `6580/7580` mean *"modification comptable"* in TN rather than cash over/short, and `6354` contradicts the recorded `TN 6654` rule. Verified against the official OECT nomenclature. **Nothing changed; needs an owner ruling + TN-accountant confirmation.** See §1.3.1 and Recommendation 0. |
| **B-6(i) document_count** | **NO CHANGE NEEDED — the code already does what your ruling asks, symmetrically, on both arms.** Neither arm filters by document type in its `COUNT(DISTINCT …)`, so credit notes (document arm) and return receipts (POS arm) are already counted. The **TVA cadre of the Tunisian monthly declaration carries no count field at all** (verified against the official DGI form) — ours is an internal control column. Three sources confirm the counting rule anyway: **CDET art. 126** *requires* a document count on the same monthly form for stamp duty; DGELF doctrine holds an **avoir bears the timbre as a facture**; and **SAF-T PT** states outright that its count *includes* documents its money totals *exclude*. Action reduces to **pinning the semantics in a test + replacing the "unresolved" comment**. No interaction with the G-4 netting tests (they assert money only). |
| **🚨 Two bigger Part-2 finds** | (i) **Our seeded avoir stamp duty is stale at `0.600` — it should be `1.000`** (the avoir is dutiable *as a facture* under art. 117-I-6°, raised by LF 2023). `stamp_duty_total` is a figure that is actually declared and paid, so this under-collects on every avoir. **Real pre-launch fix, S.** (ii) **Tunisia does not net avoirs into declared turnover** — Code TVA art. 9-I-5 makes it an *imputation* on a "déduction additionnelle" régularisation line, while our code reduces the taxable base. Net tax is identical; declared chiffre d'affaires is not. **Ticket + accountant ruling, not a fix from this brief.** See §2.2.1-bis / §2.2.2. |

---

# PART 1 — Opening cash float (owner sheet B-2)

## 1.1 What the code actually does today — three independent cash rails

There are **three** places a cash number can live, and **none of them talk to each other**.

### Rail A — the accountant's opening balance batch (GL only)

`apps/api/app/Modules/Accounting/Application/Services/AccountingOpeningService.php`

- A batch is imported as rows of `{account_code, debit, credit}`, validated for balance (`validateBatch`, `:90-143`), then posted (`postBatch`, `:233-367`).
- Posting creates **one journal entry** with `source_type = 'opening_balance'`, `is_historical = true` (excluded from the fiscal hash chain), `status = Posted`, numbered `OB-YYYY-NNNNNN` (`:262-274`, `:428-445`).
- One `JournalLine` per row (`:294-302`); any residual imbalance is plugged to **Opening Balance Equity** (`:311-329`).
- On the TN chart, that plug account is **`119 — Solde d'ouverture`** (`apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php:136-137`) and the cash account is **`53 — Caisse`** (`:223-224`, `SystemAccountPurpose::Cash`).
- **It has a real lifecycle:** `Draft → Validated → Locked`, with `isEditable/isDeletable/canPost/canLock/isImmutable` (`apps/api/app/Modules/Accounting/Domain/Enums/OpeningBatchStatus.php`). `postBatch` marks validated, marks rows posted, then **locks the batch in the same transaction** (`AccountingOpeningService.php:331-341`). Re-posting a locked batch is refused (`:239-243`).
- **It writes nothing to Treasury.** No `RepositoryMovement`, no touch of `payment_repositories.balance`.

### Rail B — the operator's "Adjust balance" dialog (Treasury + GL, wrong credit account)

`apps/web/src/features/treasury/components/AdjustBalanceDialog.tsx` → `POST /payment-repositories/{repository}/adjustments` (`apps/api/app/Modules/Treasury/Presentation/routes.php:96-100`, `can:treasury.adjust`) → `apps/api/app/Modules/Treasury/Application/Services/RepositoryAdjustmentService.php`.

The service writes **three artifacts atomically** (`:115-220`): a `repository_adjustments` document, a posted journal entry, and a `RepositoryMovement` that mutates `payment_repositories.balance`.

**The journal entry is the problem.** `GeneralLedgerService::createRepositoryAdjustmentJournalEntry` (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1225-1311`) has exactly **two** shapes, both hardcoded to the *payment-tolerance* accounts:

```
direction = In   →  Dr <repository GL account>      / Cr PaymentToleranceIncome
                    ("Repository adjustment")          ("Cash count variance (over)")

direction = Out  →  Dr PaymentToleranceExpense      / Cr <repository GL account>
                    ("Cash count variance (short)")
```

On our TN chart those are **`7580 — Écart de règlement (produits)` (revenue)** and **`6580 — Écart de règlement (charges)` (expense)** (`TunisiaChartOfAccountsSeeder.php:278`, `:322`).

> ⚠️ Throughout Part 1, account codes are quoted **as our seeder defines them**. They are not the official Tunisian codes — see **§1.3.1**, which shows `TunisiaChartOfAccountsSeeder` is a French PCG structure under a Tunisian label (real TN: cash is `54`, not `53`; `658/758` mean something entirely different). That finding is out of B-2's scope but changes which account every recommendation below refers to.

The reason-code list offered to the operator is `count_variance | correction | theft_loss | other` (`AdjustBalanceDialog.tsx:121-126`; enum `apps/api/app/Modules/Treasury/Domain/Enums/MovementReasonCode.php`). **There is no `opening_float` / `opening_balance` reason code, and the reason code does not change the accounts** — every choice lands on 6580/7580.

There is **no draft/validate/lock lifecycle** on this path: the dialog posts straight through to a committed journal entry.

**And that entry is sealed into the fiscal hash chain.** `RepositoryAdjustmentService` posts via `createRepositoryAdjustmentJournalEntry` → `postEntryNow` (`GeneralLedgerService.php:1308`), which is *"the single source of truth for the hash-chain sealing sequence"* (`:3468-3479`). Contrast the accounting opening batch, which sets `is_historical = true` precisely to **skip** the chain (`AccountingOpeningService.php:271`). So a float entered through the operator dialog is **immutable** — it cannot be edited or deleted, only reversed by a further entry — and it puts a non-transaction into the fiscal chain. B-2's description of the current state is confirmed, and it is slightly worse than described.

### Rail C — the POS drawer float (device/subledger only, no GL, no Treasury)

- Legacy path: `ShiftManagementService::openShift()` writes `pos_shifts.opening_cash` and a `CashDrawerOperation` of type `OPENING` (`apps/api/app/Modules/POS/Domain/Services/ShiftManagementService.php:52-103`), then fires `ShiftOpened`.
- v3 fiscal path: the device authors `SESSION_OPEN` carrying `payload.opening_float_amount`; `ZSessionLifecycleProjection::projectPosShiftOpen` copies it onto `pos_shifts.opening_cash` (`apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:170-184`).
- Expected drawer at close = `OPENING + SALE − REFUND/DEPOSIT/PAYOUT`, i.e. **the float is inside the expected figure** (`apps/api/app/Modules/POS/Domain/Services/CashDrawerService.php:387-413`).
- **`recordOpening` dispatches no event at all** — this is registered finding **SV-4** (`docs/handoff/FINDINGS-shift-variance-gl-2026-08-11.md:149-157`): *"no event of any kind, so there is no hook a fix session could attach a booking listener to."*
- Consequently **SV-3**: the float, and mid-shift deposits/payouts, are unbooked in Treasury and the GL (`FINDINGS-…:126-147`).

### The rails, side by side

| | GL entry? | Treasury `payment_repositories.balance`? | POS expected drawer? | Lifecycle? |
|---|---|---|---|---|
| **A** accounting opening batch | ✅ Dr 53 / Cr 119 | ❌ | ❌ | ✅ Draft→Validated→Locked |
| **B** operator "Adjust balance" | ✅ Dr 53 / **Cr 7580 (revenue)** | ✅ | ❌ | ❌ none |
| **C** POS shift opening float | ❌ | ❌ | ✅ | n/a (device-authored) |

---

## 1.2 The exact double-count scenario — 500 TND, worked four ways

Setup: go-live. The owner puts **500 TND** of change money into the till on day 1.

### Case 1 — BOTH rails used (the double count B-2 warns about)

The accountant's opening trial balance includes `53 Caisse — débit 500`. The operator *also* opens Treasury → the till → "Adjust balance" → In / 500 / reason "correction".

**Ledger:**

```
JE OB-2026-000001 (historical)   Dr 53 Caisse           500.000
                                 Cr 119 Solde d'ouv.            500.000

JE (repository_adjustment)       Dr 53 Caisse           500.000
                                 Cr 7580 Écart règl.            500.000   ← REVENUE
```

- **Balance sheet:** `53 Caisse` = **1 000 TND**. Physical drawer = 500. The balance sheet overstates cash by 500 and never self-corrects.
- **P&L:** 500 TND of **phantom revenue** in 7580. This is materially worse than a pure balance-sheet double count — it inflates the result of the exercise and therefore the **corporate income tax (IS)** base. It also contaminates the exact account pair the per-receipt cash-rounding/tolerance lane reconciles against (`FINDINGS-…:398`).
- **Treasury:** till book balance = 500 (correct against the physical drawer).
- **POS drawer expectation:** the device's `opening_cash` is 500 from the `SESSION_OPEN` payload; expected at close = 500 + cash sales. An honest count reconciles. **`pos_shifts.variance` = 0.**
- **Z-report variance:** **none.** The Z report never reads the GL or the Treasury balance.
- **VAT:** **no impact.** The declaration is built from `document_tax_details` + `pos_receipt_vat_details` (see Part 2), never from the GL. A phantom 7580 credit is invisible to the TVA. *(It is visible to the IS.)*

### Case 2 — accountant only (the correct-ish path)

- **Ledger:** `53 Caisse` = 500. Correct. Credit leg is equity (`119`), correct.
- **Treasury:** till book balance = **0**. Understated by the float, permanently.
- **POS:** expected includes the float; variance = 0. Fine.
- **Live consequences of the Treasury understatement:**
  1. **Cash payouts / safe drops / bank deposits can be refused.** `TreasuryMovementService::assertOutflowAllowed` (`apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:508-535`) throws `InsufficientRepositoryBalanceException` for any OUT movement that would take the cached balance negative, and cash registers ship with `allow_negative = false` (only `bank_account` rows are flipped true — `apps/api/database/migrations/tenant/2026_08_06_100000_add_allow_negative_to_payment_repositories.php:35-41`). A genuine 500 TND deposit to the bank from a drawer whose book balance is 500 short **refuses**.
  2. **Cash-position screens under-report the till** by exactly the float, against both the GL and the physical drawer.
  3. **The shift-variance GL leg would mis-book** — which is precisely why it is shipped disabled (`apps/api/config/treasury.php:20-28`, verbatim: *"stays off because Treasury does not yet book the opening float or the mid-shift drawer operations that form that expected balance (SV-3/SV-4)"*), and why `insufficient_repository_balance` would fire on genuine shortfalls (SV-18).

### Case 3 — operator only (the *most likely* tenant-#1 outcome)

For tenant #1 the owner **is** the operator and there is no separate accountant doing a formal opening trial balance. So the realistic default is: nobody runs the accounting opening batch; the operator uses the dialog because it is the only visible affordance.

- **Balance sheet:** `53 Caisse` = 500. **Correct in amount.**
- **P&L:** 500 TND of phantom revenue in 7580. **Wrong.**
- **Treasury:** 500. Correct.
- **POS / Z:** clean.

So the *cash* number is right and the *income* number is wrong. This is the failure mode a runbook line most needs to prevent, and it is **not** the one B-2's wording ("double-count risk") describes.

### Case 4 — neither (do nothing)

- GL cash = 0, Treasury = 0, physical drawer = 500. The drawer holds untracked money. The POS still reconciles (its expected includes the declared float), so **nothing anywhere alerts.** The 500 quietly becomes an unexplained surplus the first time anyone counts the till against the ledger.

> **Caveat on the "no false variance" conclusion.** It rests on the expected-drawer basis *including* the float — true on the legacy server path (`CashDrawerService::calculateExpectedCash`, `:387-413`) and asserted for the device whole-drawer basis in the shift-variance dossier (`FINDINGS-shift-variance-gl-2026-08-11.md`, R15 §D.1-iv: *"expected includes the float while the repository balance does not"*). On **v3 terminals** that basis has its own separate registered defects — **SV-5**: v3 drawer movements never reach `pos_cash_drawer_operations` server-side, and the device's `zReportService` derives `expected_cash` from an `offline_cash_drawer_ops` table that `cashDrawerApi` early-returns out of on cutover terminals. Those are float-independent bugs in their own lane; they do not change B-2's answer, but they mean "the Z variance is trustworthy" is not something this brief establishes.

### Quantified answer to "what actually goes wrong"

| Failure mode | Case 1 | Case 2 | Case 3 | Case 4 |
|---|---|---|---|---|
| Cash double-counted on balance sheet | **Yes, +500** | No | No | No (−500 understated) |
| Phantom revenue in P&L / IS base | **Yes, +500** | No | **Yes, +500** | No |
| False variance at first shift close | **No** | **No** | **No** | **No** |
| Wrong VAT | **No** | **No** | **No** | **No** |
| Treasury till understated vs GL & drawer | No | **Yes, −500** | No | **Yes, −500** |
| Legitimate cash outflow refused | No | **Yes** | No | **Yes** |
| 6580/7580 tolerance-reconciliation contaminated | **Yes** | No | **Yes** | No |

**The headline correction to B-2's framing:** the first-order risk is *not* double-counted cash — it is **misclassified cash**, booked as income. And there is **no false Z-report variance in any case**, because the drawer expectation and the ledger are computed from entirely disjoint data.

---

## 1.3 Industry standard — what established systems do

**Every system surveyed treats the drawer float as session/subledger control data, not as an accounting event.** The float enters the GL exactly once — when the cash was physically moved into the till (bank → caisse), or as part of the cash account's go-live opening balance. The POS session merely *declares* the float and *counts* against it. **Only the variance is ever posted.**

### (1) Is the till float a subledger concept, or its own GL posting?

**Subledger. Unanimously.**

- **Odoo POS.** Verified in source (`addons/point_of_sale/models/pos_session.py`, 17.0): the opening balance is **carried forward, not re-entered** — `session.cash_register_balance_start = last_session.cash_register_balance_end_real`. The opening amount is **never posted**; only `_post_statement_difference()` writes anything, labelled *"Cash difference observed during the counting (Loss)"* + `' - opening'` / `' - closing'`, against the cash journal's `loss_account_id` / `profit_account_id`. The docs describe opening purely as a UI control screen ("Opening Cash Control", "Closing Control pop-up… the expected amounts grouped by payment method").
- **ERPNext.** Verified in source (`pos_opening_entry.py`, develop): **there is no `make_gl_entries()` at all** — `on_submit` is just `self.set_status(update=True)`. `POS Closing Entry` likewise creates no GL entries; the ledger impact comes from the POS Invoices and their consolidation. The float is *structurally incapable* of being posted.
- **Square.** Cash drawer shifts live in a **separate API** from Payments/Orders. `CashDrawerShift.opened_cash_money` = *"The amount of money in the cash drawer at the start of the shift"*, and `expected_cash_money` = *"the amount that should be in the cash drawer at the end of the shift, based on the shift's other money amounts."* The float is an **arithmetic term in a reconciliation, never a ledger line**. It is not part of the QuickBooks/Xero sync feed (which carries sales, items, taxes, fees, payouts); practitioners book cash separately via a Drawer Cash / petty-cash asset account.
- **Lightspeed X-Series.** *"The float is the amount of cash that is in your cash drawer before making any sales… When you close a register, the expected amount of cash will be your float plus or minus any cash taken in or removed."* Adding to a float is classified as a **cash movement**, explicitly distinct from a sale.

### (2) Who owns the number at go-live, and how is double entry prevented?

**The accountant's trial-balance figure is the book of record; the operator's physical count is the evidence that tests it.** Migration guidance is consistent: opening balances come from the old system's trial balance, validated by a physical count aligned to the cutover date, with any gap posted as an explicit adjusting entry — never a silent overwrite. (No source names a formal sign-off *role*; this is consistent practice, not a codified rule.)

The anti-double-count mechanisms, as actually implemented:

| System | Mechanism |
|---|---|
| **Odoo** | The float is never re-declared: `balance_start = last_session.balance_end_real`. The float amount posts **zero times**; only deltas post. **This is the single best pattern to copy.** |
| **ERPNext** | Structural: POS Opening Entry has no GL path. Separately, the accounting opening balance is a Journal Entry with **`Is Opening = Yes`** balanced against a **"Temporary Opening"** account which *"will become zero once all your old invoices and opening balances of bank, debt stock etc are entered"* — a **self-detecting** double-entry check. Plus hard uniqueness: a second open POS Opening Entry for the same profile or user is refused. |
| **Square / Lightspeed** | Domain separation — the accounting feed does not consume drawer data. |
| **QuickBooks** | **Opening Balance Equity** is the tell-tale: a non-zero OBE after setup signals an unbalanced or duplicated opening. |

**The universal invariant: exactly one posting event per unit of cash.** The float is debited to the caisse account once — when funded (bank → 58 → caisse) or as part of the go-live à-nouveau. Every session opening thereafter is a *declaration against that standing balance*; the only legitimate new posting is the variance.

### (3) Standard lifecycle for opening balances

Draft → posted → locked is universal; a dedicated "opening balance can only be entered once" flag is **not**. What exists instead is **period locking plus one-shot document semantics**:

- **Odoo** — Draft → Posted, plus **Lock Everything Date** (*"prevents modifications to any posted journal entries with an accounting date on or before the lock date"*) and a **Hard Lock Date** which is *"irreversible and is intended to ensure data inalterability required to comply with accounting regulations in certain countries."*
- **ERPNext** — Draft → Submitted → Cancelled/Amended, plus "Accounts Frozen Upto", Accounting Period (*"No role can submit transactions defined in the Accounting Period"*), and Period Closing Voucher. Correction protocol: *"cancel and amend the incorrect opening document."*
- **Sage 200** — *"You cannot directly amend opening balances. If you make a mistake… you must enter another opening balance transaction to reverse the original transaction."* Then close the period to protect them.

**Our `OpeningBalanceBatch` (Draft → Validated → Locked, auto-locked on post) is already at or above this bar.** The gap is not the batch's lifecycle — it is that the *operator's* path has no lifecycle at all and posts to the wrong account.

### (4) Double-entry convention: booking the float

French practice routes it through the internal-transfer account: Dr **580** Virements internes / Cr **512** Banque (withdrawal), then Dr **531** Caisse / Cr **580** — *"le compte 580 est soldé"* and *"le solde débiteur du compte 531 correspond aux sommes physiquement en caisse."* **The float is never posted separately from the cash account — it *is* the opening debit balance of the caisse account** (or of a per-till sub-account, which is still a caisse account, never a distinct "float" account).

The daily Z booking hits caisse against sales + VAT only; **the float never appears in it.**

Go-live opening entry: *"la reprise des à-nouveaux concerne toujours les comptes de bilan, classes 1 à 5, jamais les comptes des classes 6 et 7"*, entered in a *journal de reprise des soldes* / *journal des à-nouveaux*. The French PCG provides optional accounts **890 Bilan d'ouverture / 891 Bilan de clôture**. The suspense-account pattern (QuickBooks Opening Balance Equity, ERPNext Temporary Opening, PCG 890) is universal.

### (5) NF525 / French caisse practice on the fond de caisse

**French practice states the rule bluntly:**

> *"Le fond de caisse n'est jamais comptabilisé comme chiffre d'affaires. Il s'agit d'un simple transfert de trésorerie entre différents comptes de l'entreprise."*
>
> *"Lors du comptage journalier, excluez le fond de caisse du chiffre d'affaires de la journée. Par exemple, si votre tiroir-caisse contient 170 € et que votre fond de caisse est de 150 €, les recettes espèces du jour sont de 20 €, pas de 170 €."*

The BOFiP doctrine (BOI-TVA-DECLA-30-10-30) scopes the inalterability obligation to *"toutes les données liées à la réalisation d'une transaction"* and mandates cumulative daily/monthly/annual closings producing *"le cumul du grand total de la période et le total perpétuel."* ⚠️ **The BOFiP text does not name the fond de caisse either way** — it is out of the turnover totals by construction, not by an explicit exclusion clause.

**Odoo's certified French implementation is the cleanest confirmation:** `l10n_fr_pos_cert/models/account_closing.py` computes `total_interval = sum(orders.mapped('amount_total'))` over `pos.order` records only. **The file contains no reference to cash-in, cash-out, or opening cash balances.** The float is outside the certified grand total.

Vendor material does indicate NF525 archiving covers *"le journal de rapprochement d'encaissement du traitement de fond de caisse"* — so float handling **is** a traced/archived event even though it is not turnover. ⚠️ **Not verified against the standard itself** (AFNOR/Infocert paywalled); this comes from certified-vendor summaries.

### (6) Cash over/short treatment

- **France:** *"Si la caisse réelle est supérieure à la caisse théorique, enregistrez l'écart comme un produit au compte 758… Si la caisse réelle est inférieure, l'écart est enregistré comme une charge au compte 658."* Plus: *"Documentez chaque écart avec sa cause probable."*
- **Odoo** implements exactly this as the cash journal's Cash Difference Loss/Gain accounts, applied to **both opening and closing** variances.
- **Tunisia:** see the warning in §1.3.1 — the French 658/758 mapping is **not** transferable.

### 1.3.1 ⚠️ HIGH-SEVERITY ADJACENT FINDING — our Tunisian chart of accounts is a French chart wearing a Tunisian label

This is out of B-2's scope and I have changed nothing. But it determines *which account the opening float debits*, so it cannot be omitted from this brief.

I extracted the official nomenclature of the **Système Comptable des Entreprises** (the OECT-published *Nomenclature et Fonctionnement des Comptes*) and compared it line-by-line with `apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php`:

| Our TN seeder | Official Tunisian SCE nomenclature | Verdict |
|---|---|---|
| `53 Caisse` — carries `SystemAccountPurpose::Cash` (`:223-224`) | **`53. Banques, établissements financiers et assimilés.`** Cash is **`54. Caisse.`** → `541. Caisse siège social.` → `5411. Caisse en dinars.` / `5414. Caisse en devises.` / `542. Caisses succursales.` | ❌ **Wrong.** Till cash is being booked into the *banks* class. |
| `531 Caisse siège` (`:225`) | **`531. Valeurs à l'encaissement.`** (`5311 Coupons échus`, `5312 Chèques à encaisser`, `5313 Effets à l'encaissement`, `5314 Effets à l'escompte`) | ❌ **Wrong parent semantics.** Ironically `5312/5313/5314` in our seeder (`:226-228`) are the *correct* TN codes — but the seeder hangs them under a "Caisse siège" parent instead of under `53 Banques → 531 Valeurs à l'encaissement`. |
| `54 Régies d'avances et accréditifs` (`:229`) | **`55. Régies d'avances et accréditifs.`** | ❌ Off by one class. |
| `63 Impôts, taxes et versements assimilés` (`:272`) | **`63. Charges diverses ordinaires.`** Impôts/taxes is **`66.`** | ❌ Swapped. |
| `65 Autres charges de gestion courante` (`:278`) | **`65. Charges financières.`** | ❌ No such TN head. |
| `66 Charges financières` (`:289`) | **`66. Impôts, taxes et versements assimilés.`** | ❌ Swapped with 63. |
| `75 Autres produits de gestion courante` (`:346`) | **`75. Produits financiers.`** The "divers ordinaires" head is **`73.`** | ❌ Swapped. |
| `6580 Écart de règlement (charges)` / `7580 Écart de règlement (produits)` (`:280`, `:324`) — **the accounts every cash adjustment and every shift variance posts to** | **`658. Charges financières liées à une modification comptable à imputer au résultat de l'exercice`** / **`758. Produits financiers liés à une modification comptable…`** | ❌ **Materially different meaning.** In TN, cash over/short belongs in **`63 Charges diverses ordinaires` / `73 Produits divers ordinaires`**, not 658/758. |
| `6354 Droits d'enregistrement et de timbre` (`:273`) | **`6654. Droits d'enregistrement et de timbre.`** | ❌ And this one **directly contradicts an already-recorded rule** in the project memory: *"Country accounting = seeded settings, never hardcoded (TN 6654 vs FR 6354 etc.)"*. The seeder ships the FR code under a TN label. |

The pattern is unambiguous: `TunisiaChartOfAccountsSeeder` is a **French PCG structure with French account meanings, relabelled in French-Tunisian wording.** A handful of individual codes (`5312`, `5313`, `5314`) are genuine TN codes attached to the wrong parents.

**Why this belongs in a B-2 brief specifically:** every recommendation below says "the float debits the till's cash account." On this chart that account is `53`, which in the real Tunisian nomenclature is *Banques*. And every cash-variance posting we make lands in `6580/7580`, which in the real nomenclature is *"charges/produits financiers liés à une modification comptable"* — an account an accountant will read as a change-in-accounting-method adjustment, not a till discrepancy.

**Caveat before anyone acts on this:** charts are per-tenant data and can be remapped after seeding, and it is possible this chart was a deliberate French-shaped starter. **This needs an owner ruling and a TN-accountant confirmation, not a unilateral fix.** But it should be settled before tenant #1's accountant sees a trial balance.

*(Source for the nomenclature: the OECT-published SCE "Nomenclature et Fonctionnement des Comptes"; verbatim class-5 extract: `53. Banques, établissements financiers et assimilés. / 531. Valeurs à l'encaissement. / … / 54. Caisse. / 541. Caisse siège social. / 5411. Caisse en dinars. / 5414. Caisse en devises. / 542. Caisses succursales. / 55. Régies d'avances et accréditifs. / 58. Virements internes.`)*

---

## 1.4 Engaging with the owner's lean

> *"opening cash in a POS machine should be an opening balance for sure"*

**Agreed on the accounting substance; pushing back on where the number is booked.**

Where you are right: the float is **not** revenue, **not** an expense, and **not** a variance. It is an opening asset position — economically identical to the opening bank balance. It belongs in the opening balance batch, dated at the cutover, credited to equity (`119 Solde d'ouverture`), never to 7580. Our current operator affordance gets this wrong and that is the defect worth fixing.

Where the lean needs a qualifier: **"an opening balance" must not be read as "its own GL posting from the POS side."** If the POS entering a float creates a journal entry, and the accountant's opening batch also carries `53 Caisse`, you get Case 1 by construction — and there is no mechanism in either path that can detect the collision (`AccountingOpeningService` never looks at Treasury; `RepositoryAdjustmentService` never looks at opening batches). The float has to be **one GL number, entered once, by the accounting opening batch**, and the POS/Treasury side must carry it as a **subledger balance seed with no journal entry** so the till's book balance and the GL's `53` agree.

The codebase already agrees with this shape and has for a while — see §1.5.

**The strongest form of the standard is stronger still, and worth adopting:** in Odoo the operator never types a float from scratch after day 1 — the session's opening balance is *carried forward* from the previous session's counted close (`balance_start = last_session.balance_end_real`). The float amount is posted **zero times, ever**; only the delta between carried-forward and counted posts, and it posts as an *opening variance* distinguishable from a *closing variance*. If we build (b), defaulting the next shift's float from the previous shift's counted close is a cheap add-on that removes the re-typing error class entirely.

---

## 1.5 The design is already half-built

Two artifacts already exist for exactly the recommended shape, with **no writer**:

1. **`MovementSourceType::OpeningBalance = 'opening_balance'`** (`apps/api/app/Modules/Treasury/Domain/Enums/MovementSourceType.php:16`) — a movement source type that no code path currently produces.
2. **The treasury reconciler already exempts it from requiring a journal entry**, citing a spec section:

   ```php
   /**
    * A movement may legitimately carry a null journal_entry_id only when it is
    * an opening_balance leg or a same-GL-account transfer leg (spec §9.2).
    */
   private function isJournalEntryExempt(RepositoryMovement $movement): bool
   {
       if ($movement->source_type === MovementSourceType::OpeningBalance) {
           return true;
       }
   ```
   `apps/api/app/Modules/Treasury/Presentation/Console/ReconcileTreasuryCommand.php:899-907`

That is the industry-standard shape, already specified and already tolerated by the integrity checker: **a Treasury movement that seeds the till balance and deliberately carries no GL entry, because the GL side belongs to the accounting opening batch.** Only the producer is missing. The frontend already knows the source type too (`apps/web/src/features/treasury/statements/api.ts:26`, `components/RepositoryMovementsTab.tsx:37`).

---

## 1.6 Option (a) — runbook-only, and the wording it needs

Option (a) is defensible for tenant #1 **only if the runbook closes Case 1 and Case 3 by forbidding the operator path**, not merely by describing it.

**And there is currently no runbook to add a line to.** `docs/handoff/STAGING-RUNBOOK-first-tenant-2026-07-31.md` is a *staging/deploy ops* runbook (DB backups, Horizon health, env vars, guarded backfills) with no business-data onboarding step; `docs/handoff/HANDOVER-opening-balance.md` covers **product/inventory** opening only. **No document anywhere tells an operator how to establish cash opening balances.** So option (a) is not "add a line" — it is "write the section."

Proposed wording:

> ### Opening cash float — go-live (do this once, in this order)
>
> **1. The float is an accounting opening balance. It is entered exactly once, in the accounting opening batch — never through Treasury → "Adjust balance".**
>
> Go to **Settings → Opening balances** (`/settings/opening-balances`, type `ACCOUNTING`; the wizard is live — `apps/web/src/routes/index.tsx:2428-2438`, API `Modules/Accounting/Presentation/routes.php:108-126`).
>
> Count the physical cash you are putting in each till *before* the first shift. Include those amounts in the GL opening balance batch as debits to the till's own cash account (on our current TN chart: `53 Caisse`, or the sub-account linked to that payment repository — but see §1.3.1 first). The batch balances itself against `119 Solde d'ouverture`. Post it; it validates and **locks automatically** in the same transaction.
>
> **2. Do NOT use Treasury → repository → "Adjust balance" to seed a till.**
> That dialog books the amount as **income** (`7580 Écart de règlement (produits)`), not as an opening balance. It exists for *count variances and corrections during operation*, not for go-live. If you have already used it: the entry cannot be edited — have the correction posted as a manual journal entry reversing `7580` against `119` for the same amount and date, and do not also include the float in the opening batch.
>
> **3. Enter the same float on the POS when you open the first shift.**
> The device asks for the opening float when a shift is opened. Enter the counted amount. This number is the drawer's own expectation — it does not post anywhere and cannot double-count with step 1.
>
> **4. Known gap while `opening_float` (B-2 option b) is not shipped.**
> The till's balance shown in Treasury will be understated by the float amount. Two consequences to expect:
> - Cash-position screens show the till short by the float. The GL (`53`) and the physical drawer are correct; the Treasury figure is the one that is behind.
> - A cash **outflow** (safe drop, bank deposit, payout) may be refused with "insufficient balance" if it exceeds `till balance shown in Treasury`. Workaround until (b) ships: split the deposit, or keep the float out of the deposit amount.
>
> **5. Do not enable `TREASURY_SHIFT_VARIANCE_GL_ENABLED`.** It must stay `false` until the float is booked into Treasury (SV-3/SV-4). Enabling it first books the float into `6580/7580` on the first close.

**Verdict on (a):** acceptable as a launch posture **for a single-till, owner-operated tenant**, because the operator and the accountant are the same person and step 2 is enforceable by discipline. It stops being acceptable the moment a second tenant with a real bookkeeper onboards.

---

## 1.7 Option (b) — the minimal `opening_float` design + effort

### Shape (matches the standard and the existing spec §9.2 exemption)

**One new adjustment/movement kind that seeds the Treasury till balance and deliberately posts NO journal entry.**

```
Operator (or provisioning) declares a till opening float
   → RepositoryOpeningBalanceService
       → repository_adjustments row  (kind = opening_float, or a dedicated
                                      repository_opening_balances document)
       → RepositoryMovement          (source_type = opening_balance,
                                      journal_entry_id = NULL)   ← already exempt
       → payment_repositories.balance += amount
   → NO JournalEntry.  The GL side is the accounting opening batch's Dr 53 / Cr 119.
```

**Reconciliation against the accounting opening batch** (the anti-double-count control), cheapest defensible version:
- The opening float carries the **cutover date** and the **`payment_repositories.gl_account_id`** it seeds.
- A read-only check (surfaced in the UI and in `ReconcileTreasuryCommand`): *for each GL cash account, Σ(opening_float movements) must equal the debit posted to that account by the locked `ACCOUNTING` opening batch.* Mismatch ⇒ a named refusal/warning, not a silent divergence.
- **Write-once semantics**, mirroring `OpeningBalanceBatch`: one opening float per repository, refused if the repository already has any movement, refused once the accounting batch for that account is `Locked` and the amounts disagree.

**A third benefit, beyond the ledger:** because the opening-float movement carries no journal entry, it never enters the fiscal hash chain — which is correct, since seeding a till is not a transaction. Today's operator path puts one there permanently (§1.1 Rail B).

**Explicitly out of the minimal scope** (they are SV-3's other half and need owner rulings E-2/E-3 first, `FINDINGS-…:326-327`): booking mid-shift `DEPOSIT`/`PAYOUT`/`SAFE_DROP` into Treasury, and giving `recordOpening` a domain event (SV-4).

### Files / modules touched

| Area | File(s) | Change |
|---|---|---|
| Treasury domain | `Domain/Enums/MovementReasonCode.php` (or a new `AdjustmentKind`) | add `opening_float` |
| Treasury application | new `Application/Services/RepositoryOpeningBalanceService.php` | ~150 LOC; mirrors `RepositoryAdjustmentService` minus the GL leg |
| Treasury contracts | `Shared/Contracts/Treasury/…` | one interface + result DTO |
| Treasury presentation | new controller + FormRequest + route (`can:treasury.adjust` or a new `treasury.opening`) | small |
| Treasury console | `ReconcileTreasuryCommand.php` | add the Σ(opening_float) vs opening-batch-debit check (the JE exemption at `:899-907` needs **no change**) |
| Migration | `repository_adjustments` (nullable `kind` column) **or** a new `repository_opening_balances` table + partial unique index per repository | 1 migration |
| Accounting | read-only query helper: debit posted to a GL account by the locked ACCOUNTING batch | small |
| Web | `AdjustBalanceDialog` gains the kind, or a separate `SeedOpeningFloatDialog`; i18n keys | small |
| Types | `php artisan typescript:transform` | mechanical |
| Tests | service unit + reconciliation feature + a "float is never in 6580/7580" regression pin | ~6 tests |

### Effort: **M** (medium)

Not S: it crosses Treasury + Accounting + web + a migration, and the reconciliation check is the part that carries the actual correctness value. Not L: there is no GL posting to design, no hash-chain interaction (the movement carries no JE), the enum case and the reconciler exemption already exist, and the transactional skeleton is a near-copy of `RepositoryAdjustmentService`.

**Sequencing note:** (b) closes the *float* half of **SV-3**, which is one of the two named pre-enable gates on `treasury.shift_variance_gl_enabled` (`config/treasury.php:20-28`). It does **not** close the deposit/payout half, so the flag still stays off after (b).

### A cheaper strictly-defensive variant (S)

If (b) does not fit before launch, the sub-S mitigation that removes the *worst* failure mode (Case 1/Case 3 phantom revenue) is: **make the operator adjustment dialog refuse a `correction`/`other` adjustment on a repository that has zero movements and no prior activity**, with a message pointing at the opening balance batch. ~1 guard + 1 test + 1 i18n key. It does not fix the Treasury understatement, but it stops the ledger being wrong.

---

# PART 2 — VAT declaration `document_count` (owner sheet B-6(i))

## 2.1 What the code does today — verified

`apps/api/app/Modules/Taxation/Infrastructure/Repositories/EloquentVatDataRepository.php`, single method `aggregateByRateAndDirection` (`:27-150`), two arms unioned then re-aggregated.

### Document arm (`:30-65`)

```php
->whereIn('d.type', ['invoice', 'credit_note', 'expense'])   // :42
->where('dtd.is_stamp_duty', false)                          // :43
->whereNull('d.deleted_at')                                  // :44
->selectRaw("
    SUM(CASE WHEN d.type = 'credit_note' THEN -dtd.tax_base  ELSE dtd.tax_base  END) as base_amount,
    SUM(CASE WHEN d.type = 'credit_note' THEN -dtd.tax_amount ELSE dtd.tax_amount END) as vat_amount,
    COUNT(DISTINCT d.id) as document_count,                  // :53
```

**Credit notes ARE counted.** The `COUNT(DISTINCT d.id)` has no type predicate — a credit note deducts from base/VAT (the V2 fix, `:14-26`) *and* adds 1 to `document_count`.

### POS arm (`:94-122`)

```php
->where('r.is_voided', false)                                // :106
->where('r.is_training', false)                              // :107
->where('prvd.tax_rate', '>', 0)                             // :108
->selectRaw("
    SUM(CASE WHEN r.receipt_type = 'return' THEN -ABS(prvd.net_amount) ELSE prvd.net_amount END) as base_amount,
    SUM(CASE WHEN r.receipt_type = 'return' THEN -ABS(prvd.vat_amount) ELSE prvd.vat_amount END) as vat_amount,
    COUNT(DISTINCT r.id) as document_count,                  // :114
```

**Return receipts ARE counted.** Same structure: `-ABS(...)` nets the money (the G-4 fix), the count has no `receipt_type` predicate.

The in-code note at `:90-93` is the open question B-6(i) is answering:

> `document_count` is deliberately left as `COUNT(DISTINCT r.id)`: whether a refund receipt counts as a declared document is a filing-semantics question for the owner, not a sign question. Unresolved (G-4 open item, owner sheet 2026-08-21 B-6).

**So the two arms are already symmetric, and they already include avoirs and refund receipts.** The union then `SUM(document_count)` across both arms (`:132`).

### Where `document_count` surfaces

| Consumer | Field |
|---|---|
| `Infrastructure/Exporters/TeifXmlExporter.php:79`, `:92` | `<NombreDocuments>` per rate line, output and input sections |
| `Infrastructure/Exporters/PdfVatExporter.php:124-125` | a count column per rate row |
| `Infrastructure/Exporters/CsvVatExporter.php:82` | a count column |
| `Domain/Entities/VatPeriodBreakdown.php:42`, `:51` | persisted per-period breakdown row |
| `Presentation/Controllers/VatReportController.php:84`, `:192`, `:213` | API response |

**Crucially, it is NOT one of the declaration's DGI form fields.** `TunisiaVatStrategy::mapToDeclarationFields` emits only `base_{19,13,7,0}`, `vat_{…}`, `total_output_vat`, `total_deductible_vat` (`apps/api/app/Modules/Taxation/Infrastructure/Strategies/TunisiaVatStrategy.php:51-82`). `document_count` never reaches `$fields`.

### There IS a real declared count elsewhere in the same strategy

`TunisiaVatStrategy::getSpecialLineItems` (`:93-123`) returns `stamp_duty_count`, `stamp_duty_total`, `retenue_source_total`. **`stamp_duty_count` is a genuinely declared quantity** — the droit de timbre is a fixed per-document duty. Discussed in §2.2.2; the distinction that matters here is that `document_count` is *not* that number and must not be confused with it.

## 2.2 What the standard says

### 2.2.1 Tunisia — the TVA section has NO document-count field (verified against the official DGI form)

The official form — *Imprimé de la déclaration mensuelle des Impôts* (DGI, `MENSUELLE__2023.pdf`) — was retrieved and text-extracted. The TVA block (page 5) is a five-column table: `Libellés | Montant (D) | Taux | TVA due (D) I | TVA déductible (D) II`, with rubrics:

1. Chiffre d'affaires soumis à la TVA, net de TVA — one row per rate (7 % / 13 % / 19 %, plus blank rows) + livraisons à soi-même
2. Achats soumis à la TVA ouvrant droit à déduction
3. Autres déductions
4. **Régularisations**
5. Total → `Restant (D) dû ou (R) à reporter (I−II)` → excédent du mois précédent → montant restitué

**Every cell is a dinar amount or a percentage. There is no `nombre de factures`, no operation count.** A grep of the full 12-page form for "عدد" (number/count) returns hits only in the stamp-duty block (§2.2.2), the *licence sur débits de boissons* table, and a property annexe.

Filing is monthly — first 15 days for natural persons, first 28 days for legal persons — and is due even when no tax is payable.

**Our implementation already matches:** `TunisiaVatStrategy::mapToDeclarationFields` (`apps/api/app/Modules/Taxation/Infrastructure/Strategies/TunisiaVatStrategy.php:51-82`) emits only `base_{19,13,7,0}`, `vat_{…}`, `total_output_vat` and `total_deductible_vat`. `document_count` never reaches `$fields`.

**So our `document_count` is an internal control/audit figure, not a filed quantity.** It surfaces in the PDF/CSV exports and as `<NombreDocuments>` in `TeifXmlExporter` — but that element sits in our **own invented namespace** (`urn:tn:gov:dgfiscale:vat:declaration:v1`, `TeifXmlExporter.php:60`), not the real TEIF format. Getting it "wrong" cannot cause a filing rejection; getting it *asymmetric between the two arms* would produce an unexplainable reconciliation.

**Related obligations that are per-invoice detail, not counts:** Code de la TVA **art. 18 §II** requires invoices numbered *"dans une série ininterrompue"* and a quarterly *"liste détaillée des factures émises en suspension de la TVA"* (per-invoice rows on electronic support). The *déclaration de l'employeur* carries amounts and identities. Neither carries a document count.

### 2.2.1-bis 🚨 A more consequential Tunisian finding: **avoirs are NOT netted into declared turnover**

This was not what B-6(i) asked, but it is what the research turned up and it is bigger than the count question.

**Code de la TVA, art. 9-I-5:**

> « La taxe sur la valeur ajoutée perçue à l'occasion d'affaires qui sont, par la suite, **résiliées ou annulées**, est **imputée** sur la taxe sur la valeur ajoutée due sur les opérations réalisées ultérieurement. »

The mechanism is **imputation**, not reduction of the taxable base — and the DGI form implements that literally. Rubric 4 of the TVA cadre:

```
4 – Régularisations :
   - Déduction additionnelle :
       * au titre d'opérations de résiliation et d'annulation ....  → col. II (TVA déductible)
       * au titre d'autres opérations ..........................  → col. II
   - Reversement ...........................................  → col. I (TVA due)
```

**So on the Tunisian form: rubric 1 turnover stays GROSS, and the avoir's VAT lands in the "déduction additionnelle" regularisation line on the deductible side.**

Our code does the opposite on **both** arms — the V2 credit-note fix (`SUM(CASE WHEN d.type = 'credit_note' THEN -dtd.tax_base …)`, `:51-52`) and the G-4 POS fix (`-ABS(...)`, `:112-113`) both **reduce the taxable base per rate**.

**The bottom-line tax is the same** — a base reduction at 19 % and an additional deduction of the same VAT both move `I − II` identically. **What differs is the declared chiffre d'affaires.** Our `base_19` understates rubric-1 turnover by the avoir/refund base, and our régularisation lines are empty. A DGI cross-check of declared CA against accounting revenue, or against El Fatoora data, would show a gap.

⚠️ **Do not act on this from this brief.** It is a filing-presentation question that needs a Tunisian accountant's ruling, it may already be how the tenant's accountant prefers to file, and changing it would touch the exact code the G-4 lane just gated. It gets a ticket, not a fix. (Recommendation 10.)

**Also relevant:** the historic art. 9-I-5 obligation to attach an *état* listing VAT on cancelled operations was **abrogated by art. 89 of the loi de finances 2013-54 (LF 2014)** — there is no longer any per-avoir listing obligation.

### 2.2.1-ter El Fatoora / TEIF — credit notes are outside the e-invoicing obligation

**Note commune n° 02/2026 (DGELF, 23 January 2026)**, on art. 53 of LF 2025-17:

> « Le régime de la facturation électronique **ne s'applique pas aux autres documents tenant lieu de facture, à savoir les contrats, les notes de débit ou de crédit et les relevés de compte**. »

⚠️ *Quoted via professional commentary; the primary DGI PDF could not be retrieved.*

The **TEIF format itself** does carry a document-type code list (UN/EDIFACT 1001 style: `380` facture, **`381` note de crédit**, `383` note de débit, `386` acompte, `389` autofacturation) — so the format supports avoirs even though the obligation excludes them. ⚠️ *Secondary source; the TTN XSD was not reached.* **No count of documents is reported to TTN or the DGI anywhere.**

### 2.2.2 ⭐ Tunisia DOES declare a document count — for the droit de timbre — and it is count × unit rate

**This is the decisive finding for B-6(i), and it is statutory.**

**Code des droits d'enregistrement et de timbre (CDET), article 126** (verified verbatim in the official DGELF code editions updated to 1 Jan 2025 and 1 Jan 2026):

> « Tout utilisateur du mode de paiement sur déclaration doit mentionner **sur l'imprimé de la déclaration mensuelle** et **pour chaque entreprise, agence ou succursale**, **le nombre des factures ou des tickets de vente, documents, billets ou certificats soumis au droit** ainsi que le montant des droits exigibles. »
> *(Abrogé et remplacé art. 94 LF 2003-80; modifié art. 54 LF 2021-21 du 28/12/2021.)*

**Art. 124** makes payment-on-declaration **mandatory** for IS-liable legal persons for the stamp duty on factures and tickets de vente.

**The form implements it exactly** — page 7 of the DGI monthly declaration, block `معلوم الطابع الجبائي`:

| Block | Columns |
|---|---|
| 2. Duty on **tickets de vente** | `عدد الفروع` nb of branches · **`عدد التذاكر` NUMBER OF TICKETS** · duty due (D) |
| 3. Duty on **other documents** | `رمز الوثيقة` doc code · `نوع الوثيقة` doc type · `عدد الفروع` branches · **`عدد الوثائق` NUMBER OF DOCUMENTS** · duty due (D) |
| | Total (III) · Grand total (IV) = I+II+III |

Form footnotes, verbatim: duty = **number of sales tickets × 100 millimes**; document code **3 = *facture ou effet de commerce***; rate **1,000 D per facture ou effet de commerce**; branch counts include the head office; and every liable establishment must issue tickets *"dans une série continue et ininterrompue et selon un système fiable"*.

**So the answer to "is there any declared document count in Tunisia" is YES — just not on the TVA cadre.** It is on the stamp-duty cadre of the *same monthly form*, it is a legal obligation under CDET art. 126, and it is per establishment/branch.

**Current rates** (verified in the CDET edition "mise à jour au 1er janvier 2026"):

| Item | Rate | Basis |
|---|---|---|
| **Factures** (art. 117-I-6°) | **1,000 DT / invoice** | décret-loi 2022-79 (LF 2023) |
| **Factures des grandes surfaces commerciales** — **NEW art. 117-I-6 bis** | **1,500 DT** if 50–100 DT; **2,000 DT** if > 100 DT | **art. 20-6 LF n° 2025-17 (LF 2026)** |
| **Tickets de vente** (art. 117-I-10°) | **100 millimes / ticket**, whatever the amount | art. 54 décret-loi 2021-21 (LF 2022), in force 1 Feb 2022 |
| Factures d'exportation | **exempt** (art. 118-29°) | LF 98-111 |

Ticket scope (Note Commune n° 15/2022, DGELF, primary PDF verified): grandes surfaces (>3000 m² built / >1500 m² sales area), magasins à rayons multiples under DGE/DME, franchisés d'une marque étrangère. Paid on the monthly declaration within the first 28 days. **CDET art. 135 bis** mandates the continuous, uninterrupted, auditable ticket series. And: *"Le paiement de ce droit **n'empêche pas** la perception du droit de timbre exigible sur les factures."*

#### Does a facture d'avoir bear the timbre? — **YES, per administrative doctrine.**

**DGELF prise de position n° 99188 du 29 mars 1999**, reported verbatim in the *Manuel Permanent du Droit des Affaires Tunisien*, Feuilles Rapides n° 176 (février 2010) §II:

> « Le droit de timbre sur facture couvre selon **une doctrine administrative toutes les factures y compris les factures partielles ainsi que les factures d'avoir**. … **les factures d'avoir, qui font partie des actes cités par l'article 117 …, supportent un droit de timbre** … »

A grep of the **full CDET 2025 and 2026 texts** for `facture d'avoir` / `note de crédit` / `avoirs` returns **zero hits** — there is **no statutory exemption for avoirs**; the only invoice exemption is art. 118-29° (exports).

⚠️ **Conflict flagged:** one ERP-vendor page asserts avoirs are exempt. That claim is unsupported by the code text and contradicted by the DGELF position. Treat it as wrong absent a newer DGI note (none found).

**This settles B-6(i) on primary sources:** in Tunisia an avoir is a **counted, separately dutiable fiscal document**. Counting it is not merely defensible — the stamp-duty arithmetic on the monthly form *requires* it.

#### 🚨 Concrete defect this exposes in our seeder

```php
[
    'name' => 'Timbre Fiscal - Avoir',
    'code' => 'STAMP_CREDIT_NOTE',
    'amount' => '0.600',          // ← stale
    'document_types' => ['CREDIT_NOTE'],
    'is_active' => true,
],
```
`apps/api/database/seeders/TunisiaTaxConfigurationSeeder.php:116-124`

The avoir is taxed **as a facture under art. 117-I-6°** — i.e. at the **facture rate, currently `1.000`**, which is exactly what the sibling `STAMP_TAX_INVOICE` row already carries (`:88-96`). `0.600` is the pre-LF-2023 rate. **Every avoir issued by tenant #1 would under-collect 0.400 TND of stamp duty**, and `stamp_duty_total` — a figure that *is* declared and paid — would be understated.

Also missing: the **new art. 117-I-6 bis grandes-surfaces tiers** (1,500 / 2,000 DT by invoice band, LF 2026). Not applicable to a parapharmacy, but it is a live 2026 rule with no representation in the seeder — and our tax-configuration model is flat-rate-per-document with no amount-band support, so it would need a model change, not just a seed row.

#### Not settled
Whether a **POS refund ticket** is chargeable/counted for the 100-millime ticket stamp: **NOT VERIFIED.** NC 15/2022 and CDET art. 117-I-10° / 135 bis are silent on annulation/retour/remboursement. The statutory wording *"à l'occasion de la perception du prix"* argues against charging it on a refund, but that is inference, not a ruling. Moot for tenant #1 (the ticket stamp applies only to grandes surfaces / DGE-DME / foreign franchisees, and our seeder correctly ships it inactive).

### 2.2.3 Comparative practice — France, SAF-T, Italy

**France (CA3, formulaire 3310-CA3): no document count either.** The declaration is monetary throughout — cadre A ligne 01 is *"votre chiffre d'affaires hors taxe total, quel que soit le taux de TVA appliqué"*, i.e. the cumulative HT amount of sales invoices for the period. There is **no "nombre de factures" box** anywhere on the form. Avoirs are handled as *régularisations* on the money lines (e.g. ligne 15 for supplier credit notes), never by removing them from a count. Same conclusion as Tunisia, arrived at independently.

**SAF-T is the decisive evidence for the general convention, and it agrees with our code.** In the SAF-T `SalesInvoices` block the header is exactly three elements followed by the invoice records:

```xml
<xs:element ref="NumberOfEntries" />
<xs:element ref="TotalDebit" />
<xs:element ref="TotalCredit" />
<xs:element minOccurs="0" maxOccurs="unbounded" name="Invoice">
```

…and `InvoiceType` enumerates `FT, FR, GF, FG, AC, AR, ND, **NC**, AF, TV, RP, RE, CS, LD, RA` — where **`NC` is *nota de crédito*, the credit note**.

So in SAF-T a credit note **is** an `Invoice` record, it **is** inside `NumberOfEntries`, and its financial effect is separated by the **`TotalDebit` / `TotalCredit` split — not by excluding it from the count.** That is structurally identical to what `EloquentVatDataRepository` already does: **count every document; net the money by sign.**

**SAF-T PT makes the design rule explicit — and it is exactly ours.** The Portuguese specification states that `NumberOfEntries` *"deve conter o número total de documentos, **incluindo** os documentos cujo InvoiceStatus seja do tipo 'A' ou 'F'"* (annulled / billed), **while `TotalDebit` / `TotalCredit` EXCLUDE those same documents.** Count population and money population are deliberately different. `NC` / `ND` are `InvoiceType` values in the same collection, and a type code may not be shared across types — so credit notes necessarily run their own sequence and are still counted.

**Italy — two regimes, both counting credit notes / refunds:**
- **SdI / FatturaPA:** `TD04` (nota di credito) is a first-class document type transmitted like `TD01`. The duplicate-check rule 00404 has an explicit carve-out: *"è ammessa la presenza di due documenti aventi stesso cedente/prestatore, stesso anno e stesso numero **solo qualora uno dei due sia di tipo TD04**"* — the system reasons about TD04 as a document in its own right.
- **Corrispettivi telematici (registratori telematici)** — the closest analogue to our POS arm — carries a **mandatory count field: `NumeroDocCommerciali`**, *"Numero complessivo dei documenti commerciali emessi dall'RT"* (`xs:positiveInteger`), incremented *"all'atto della generazione di ogni documento"* with **no reso/annullo exception**; the daily total covers *"comprese le operazioni di correzione e rettifica"*, and the Z carries **`Totale Annullo` and `Totale Reso` as separate money totals** beside it.

That is a certified fiscal cash-register standard that **counts refund receipts in its document count and nets them on separate money lines** — precisely the shape our two arms already have.

**Austria (RKSV):** *"Trainings- und Stornobuchungen sind wie Barumsätze zu erfassen"* — training and reversal bookings are recorded, signed and numbered like cash sales, though training bookings are excluded from the Umsatzzähler. Same split: numbered/counted, but excluded from a *money* counter.

**France (NF525) — the exception that proves the point: there is no ticket count at all.** The LNE *Référentiel de certification des systèmes d'encaissement* (Rev. 1.8, Dec 2025) and BOFiP BOI-TVA-DECLA-30-10-30 mandate for the closure only *"le total cumulatif de la période et le total perpétuel"* plus a closure sequence number. **No ticket count is required in the Z.** Corrections are made *"par des opérations de « plus » et de « moins » et non par modification directe des données d'origine"* (§ 90), each carrying its own *numéro de justificatif*. In a certified implementation (Microsoft Dynamics 365 Commerce FR), *"les transactions de retour sont considérées comme des transactions de vente régulières … incluses dans la même séquence de signatures"*, and its exported Z contains `TotalCashSales, TotalCashReturns, GrandTotal, PerpetualGrandTotal, PerpetualGrandTotalAbsoluteValue…` — **and no receipt count**.

⚠️ **Confidence markers.** CA3 (impots.gouv.fr), the SAF-T XSD, the Portuguese count/total asymmetry, the Italian RT `NumeroDocCommerciali`, and the LNE référentiel are verified at source. The NF525 référentiel *itself* (AFNOR/Infocert) is paywalled and was not obtained — vendor claims about per-type prefixed sequences are secondary; the LNE referential implements the same BOFiP articles and requires **neither gapless numbering nor a ticket count**.

### The three transferable rules

1. **Count and money have different population rules, on purpose.** Portugal counts annulled documents but excludes them from the totals. **Never derive one from a filter over the other** — which is exactly why our two arms' `COUNT(DISTINCT …)` correctly has no type predicate even though the `SUM(...)` does.
2. **A credit note or refund is never a mutation of the original** — always a new document with its own id referencing the original. True in PT, IT, ES, FR, AT, and TN (an avoir must cite the original invoice n° + date and runs its own series).
3. **Sign handling splits three ways:** element choice (SAF-T `DebitAmount`/`CreditAmount`), type code carrying direction (FatturaPA forbids negatives), or signed/absolute pairs (Italy's `Totale Reso` beside the grand total; France's `PerpetualGrandTotal` **and** `PerpetualGrandTotalAbsoluteValue`). Ours is the first — netted sums with a documented sign convention.

**Convergent answer across every regime examined:** where a document count exists at all, it counts **every fiscal document including credit notes and refund receipts**, and the sign problem is solved on the *money* fields, never by shrinking the count. Our code already does exactly that.

## 2.3 The exact change needed

**None to the aggregation logic. Current behaviour already matches the ruling.**

Your ruling — *"anything that needs to be declared needs to be included"* — applied to `document_count` yields: count every fiscal document that carries declarable VAT, including avoirs and refund receipts. That is exactly `COUNT(DISTINCT id)` with no type predicate on either arm, which is what ships today.

Three independent lines of evidence now converge on that:
- **Tunisian statute** — CDET art. 126 requires *"le nombre des factures ou des tickets de vente, documents…"* on the monthly declaration, and DGELF doctrine (prise de position 99188) holds that a **facture d'avoir bears the timbre as a facture**. An avoir is a counted, dutiable document in Tunisia.
- **SAF-T PT** — `NumberOfEntries` explicitly *includes* documents that the money totals *exclude*. Count and money populations are deliberately different.
- **Italian corrispettivi telematici** — a certified fiscal register standard whose mandatory `NumeroDocCommerciali` counts every commercial document *"comprese le operazioni di correzione e rettifica"*, with `Totale Reso` as a separate money line.

What is worth doing is **converting the current behaviour from accidental to pinned**:

1. **Replace the "unresolved" comment** at `EloquentVatDataRepository.php:90-93` with the ruling: refund receipts and credit notes count as declared documents on both arms; the count is a control/audit figure, not a DGI form field; symmetry between the two arms is a requirement, not a coincidence. (Effort: trivial.)
2. **Add a `documentCount` assertion** to the existing G-4 netting test so a future edit cannot silently change it. Concretely, in `test_pos_refunds_reduce_output_vat_in_both_writer_sign_conventions` (`apps/api/tests/Feature/Taxation/VatDataRepositoryTest.php:326-373`), the fixture is 1 sale + 2 refunds and the current result is `documentCount === 3`; assert it. Add the mirror on the document arm (invoice + credit note ⇒ 2). (Effort: S.)
3. **Optional, label-only:** the exported column reads as a filing figure. The CSV header is a hardcoded English `'Document Count'` (`Infrastructure/Exporters/CsvVatExporter.php:36`), with matching columns in the PDF (`PdfVatExporter.php:124-125`) and the `<NombreDocuments>` element (`TeifXmlExporter.php:79`, `:92`). If it is ever shown to an accountant, label it "documents (incl. avoirs / tickets de retour)". (Effort: trivial.)

## 2.4 Interaction with the G-4 netting tests

**None — verified.** The G-4 test at `VatDataRepositoryTest.php:326-373` asserts `direction`, `taxRate`, `baseAmount` (`'700.000'`) and `vatAmount` (`'133.000'`) only. It does **not** assert `documentCount`. The companion filter-regression test (`test_pos_aggregation_still_excludes_training_and_voided_receipts`, `:379+`) likewise pins money and the `is_voided` / `is_training` exclusions, not counts. `documentCount` is asserted only in the date-range test (`:307`, single invoice ⇒ `1`) and in unit tests that construct `VatAggregation` by hand.

So: **no change to `document_count` semantics is required, and if one were ever made, the G-4 money assertions would not detect it.** That absence of coverage is itself the argument for recommendation 2 above.

## 2.5 Adjacent findings — flagged, NOT in scope (rule 4)

Found while tracing the declaration. Each needs its own lane and its own owner ruling; none is touched here.

1. **Supplier invoices contribute zero input VAT to the declaration.** The document arm's `whereIn('d.type', ['invoice','credit_note','expense'])` (`:42`) excludes `supplier_invoice` / `supplier_credit_note`, which are live Procurement document types (`Modules/Procurement/Presentation/Controllers/SupplierInvoiceController.php:433`). And `CreateSupplierInvoiceService` calls `calculateDocumentTaxes` but **never `snapshotTaxDetails`** (`Modules/Procurement/Application/CreateSupplierInvoiceService.php:197`; the `snapshotTaxDetails` call sites are Quote/SalesOrder/PurchaseOrder/DeliveryNote/ReturnNote/Invoice/CreditNote/Expense only), so supplier invoices carry no `document_tax_details` rows at all. If tenant #1 records purchases as supplier invoices rather than expenses, **their TVA déductible is silently absent from the declaration.** Directly within your "anything declared must be included" principle. Severity: potentially P0 depending on which purchase flow tenant #1 uses. **Verify before launch which flow is used.**
2. **Cancelled documents are still declared.** Neither arm filters `documents.status`; only `deleted_at` (`:44`). A `DocumentStatus::Cancelled` invoice keeps its `document_tax_details` rows (nothing deletes them on cancel) and stays in both the money and the count. Whether that is correct depends on the fiscal rule for cancellation vs. avoir — but it is currently implicit, not decided.
3. **`stamp_duty_count` is fragile — and it is the one count that is legally required.** `TunisiaVatStrategy.php:96-103` counts `document_tax_details` **rows**, not `COUNT(DISTINCT documents.id)`, and has **no `deleted_at` filter** (unlike the VAT arm two files away). CDET art. 126 also requires the figure **per entreprise/agence/succursale**; we aggregate company-wide. The bare `SUM` on `stamp_duty_total` with no credit-note sign handling is **correct** — confirmed: an avoir owes its *own* timbre rather than reversing the invoice's — but the seeded avoir *rate* is stale (§2.2.2, Recommendation 8).
4. **Zero-rated / exempt POS turnover is not declared at all — asymmetrically with documents.** The POS arm filters `->where('prvd.tax_rate', '>', 0)` (`EloquentVatDataRepository.php:108`); the document arm has **no equivalent filter**. `PosCoreReceiptProjection` writes a `pos_receipt_vat_details` row for **every** rate in the canonical breakdown (`:1412-1418`), including 0%, so the rows exist and are being discarded. Meanwhile `TunisiaVatStrategy::getExpectedRates()` explicitly lists `'0.00'` (`:126-129`) and the strategy maps a `base_0` field — i.e. the declaration *expects* an exempt/zero-rated turnover line. Net effect: **a zero-rated or exempt sale rung up on the POS is absent from the declaration; the same sale invoiced as a document is declared.** For a parapharmacy — a sector with exempt/reduced-rate product lines — this is not hypothetical. Squarely within "anything declared must be included."
5. **The ticket timbre can never fire.** `STAMP_FISCAL_RECEIPT` is seeded with `applicable_document_types: ['FISCAL_RECEIPT']` (`TunisiaTaxConfigurationSeeder.php:97-115`), but there is no `fiscal_receipt` case in `DocumentType`, and POS receipts do not flow through `TaxCalculationService`'s document path at all. It is seeded inactive and does not apply to a parapharmacy, so this is dormant — but if a grande-surface tenant ever activates it, it would silently collect nothing.

---

# §Recommendations

Effort key: **S** ≈ ≤half a day · **M** ≈ 1–3 days · **L** ≈ >3 days.
"Pre-launch" applies the owner's standing principle: *correctness items land before the first client.*

| # | Recommendation | Effort | Pre-launch? |
|---|---|---|---|
| **0** | **🚨 ESCALATION — out of scope, found while answering B-2: get a TN accountant to review `TunisiaChartOfAccountsSeeder`.** Verified against the official OECT nomenclature (§1.3.1): cash is seeded as `53` when real TN cash is `54/5411` and real TN `53` is *Banques*; `63`/`66` and `73`/`75` are swapped; `6580/7580` (where **every** cash adjustment and shift variance posts) mean *"charges/produits financiers liés à une modification comptable"* in TN, not cash over/short (which is `63`/`73`); and `6354` contradicts the already-recorded `TN 6654 vs FR 6354` rule. This is a French chart under a Tunisian label. **First step is a ruling + accountant confirmation, not a fix** — charts are per-tenant data and may be intentionally French-shaped. | **S** to confirm, **M–L** to remap | **DECIDE pre-launch.** Tenant #1's accountant will read this trial balance. Everything in this brief about "the float debits the cash account" depends on which account that is. |
| **1** | **Write the opening-float runbook section** (§1.6 wording) — note this means **writing** a section, not adding a line: no document anywhere currently tells an operator how to establish cash opening balances (§1.6). Load-bearing clause: the float goes in the accounting opening batch, **never** through Treasury → "Adjust balance", because that dialog books it to revenue (`7580`). | **S** | **YES — mandatory.** This is the only thing standing between tenant #1 and 500 TND of phantom income. |
| **2** | **Guard the adjustment endpoint against go-live seeding**: refuse a `correction`/`other` adjustment on a repository with zero prior movements, with a message pointing at the opening balance batch. Turns recommendation 1 from discipline into enforcement. | **S** | **YES** — cheap, and it closes Case 1/Case 3 (phantom revenue) mechanically rather than by memory. |
| **3** | **Ship the `opening_float` movement kind** (§1.7): a Treasury movement with `source_type = opening_balance` and **no journal entry**, plus the Σ(opening_float) vs locked-accounting-batch-debit reconciliation. The enum case (`MovementSourceType.php:16`) and the reconciler's JE exemption (`ReconcileTreasuryCommand.php:899-907`, "spec §9.2") already exist — only the writer is missing. Closes the float half of SV-3. Cheap add-on worth taking: default each shift's float from the previous shift's **counted close** (Odoo's `balance_start = last.balance_end_real`), so the number is never re-typed. | **M** | **NO — post-launch**, provided 1+2 ship. Its own failure modes (Treasury till understated, cash outflow refused) are operationally annoying, not ledger-incorrect, and 1's step 4 documents the workaround. Promote to pre-launch if tenant #1 will make routine bank deposits from the till. |
| **4** | **Keep `TREASURY_SHIFT_VARIANCE_GL_ENABLED = false`** through launch. It must not be enabled before recommendation 3 *and* the deposit/payout half of SV-3. No code change; a deploy-checklist assertion. | **S** | **YES** — a config assertion in the launch checklist. |
| **5** | **VAT `document_count`: no logic change.** Both arms already count credit notes and return receipts symmetrically; there is no `document_count` field on the Tunisian declaration. Replace the "unresolved (G-4 open item)" comment at `EloquentVatDataRepository.php:90-93` with the ruling and its rationale. | **S** (trivial) | **YES** — closes B-6(i) at zero risk. |
| **6** | **Pin the count semantics in the G-4 test.** Add `documentCount` assertions to `test_pos_refunds_reduce_output_vat_in_both_writer_sign_conventions` (expect `3`) and a document-arm mirror (invoice + credit note ⇒ `2`). Today nothing detects a change to this figure. | **S** | **YES** — ships with 5. |
| **7** | **Investigate: supplier-invoice input VAT is absent from the declaration** (§2.5-1). `supplier_invoice` is excluded from the document arm *and* never gets `snapshotTaxDetails`. If tenant #1 books purchases as supplier invoices, their TVA déductible is missing. First step is a 1-hour verification of which purchase flow tenant #1 uses — not a fix. | **S** to verify, **M–L** to fix | **VERIFY pre-launch.** Fix pre-launch only if tenant #1 uses the supplier-invoice flow; this is squarely "anything declared must be included." |
| **7b** | **🚨 Investigate: zero-rated / exempt POS turnover is silently absent from the declaration** (§2.5-4). The POS arm drops every `tax_rate = 0` row (`EloquentVatDataRepository.php:108`); the document arm keeps them; the TN strategy expects a `base_0` line. A parapharmacy sells exempt/reduced-rate lines. **This is a larger correctness gap than the `document_count` question B-6(i) actually asked about**, and it is the same "must be declared ⇒ must be included" principle. First step is a data check on tenant #1's catalogue: does anything sell at 0% / exempt through the POS? | **S** to verify, **S–M** to fix | **VERIFY pre-launch**; fix pre-launch if any POS-sold line is zero-rated or exempt. |
| **8** | **🚨 Fix the avoir stamp-duty rate: `0.600` → `1.000`** (`TunisiaTaxConfigurationSeeder.php:116-124`, §2.2.2). The avoir is dutiable **as a facture** under CDET art. 117-I-6° (DGELF prise de position n° 99188), and that rate was raised to 1,000 DT by décret-loi 2022-79 (LF 2023) — the sibling `STAMP_TAX_INVOICE` row already carries `1.000`. Today every avoir under-collects 0.400 TND of a duty that **is declared and paid** via `stamp_duty_total`. Needs a TN-accountant sign-off on the reading, then a one-line seed change + a reseed step for any already-provisioned tenant. | **S** | **YES** — smallest real defect in this brief and it costs money on every credit note. |
| **8b** | **Audit `stamp_duty_count` / `stamp_duty_total`** (§2.5-3): `TunisiaVatStrategy.php:96-103` counts `document_tax_details` **rows** rather than `COUNT(DISTINCT documents.id)`, and has **no `deleted_at` filter** (unlike the VAT arm two files away). CDET art. 126 also requires the count **per entreprise/agence/succursale** — we aggregate company-wide, which may matter once tenant #1 has more than one location. | **S** | **Recommended pre-launch** — this is the count that actually reaches the DGI. | **Recommended pre-launch** — small, and it is the count that actually reaches the DGI. |
| **9** | **Ticket, do not fix now (2):** cancelled documents remain in the declaration (§2.5-2); the ticket timbre config can never match a document (§2.5-5). | **S** each | **NO** — post-launch tickets. |
| **10** | **Ticket + accountant ruling: Tunisia does not net avoirs into declared turnover** (§2.2.1-bis). Code TVA art. 9-I-5 makes it an *imputation* declared on the rubric-4 "déduction additionnelle" line; our two arms instead reduce the taxable base per rate (`EloquentVatDataRepository.php:51-52` and `:112-113`). **Net tax payable is identical** — only the declared chiffre d'affaires differs, and our régularisation lines sit empty. This touches the exact code the G-4 lane just gated, so it must not be changed on this brief's authority. | **S** to ticket, **M** to implement | **NO** — but raise it with tenant #1's accountant before the first filing, since they are the one who signs the return. |
| **11** | **Awareness item, no action yet: Tunisia's certified cash-register mandate** (arrêté du 14 octobre 2025, JORT n° 125). Phased: 1 Nov 2025 (restauration/cafés), **1 Jul 2026** (other legal persons in on-site consumption), 1 Jul 2027 (personnes physiques au réel), **1 Jul 2028** (others — where a parapharmacy lands). Requires approved suppliers, inalterability, encryption, archiving, QR-coded tickets, **mandatory daily closings**, and transmission to a central Ministry of Finance platform (CIMF). Notably it enumerates **refunds and training-mode operations as first-class register operation types** — which our fiscal model already has. ⚠️ The cahier des charges itself was not obtained, so whether the daily closing carries a **ticket count** is unknown — and that would turn `document_count` from an internal figure into a filed one. | **S** to track | **NO** — but put it on the roadmap; it is the single most likely future source of a *mandatory* TN document count. |

---

## Sources

**POS float vs. accounting opening (§1.3)**
- Odoo POS session source — `addons/point_of_sale/models/pos_session.py`, 17.0: https://raw.githubusercontent.com/odoo/odoo/17.0/addons/point_of_sale/models/pos_session.py
- Odoo POS docs (opening/closing control): https://www.odoo.com/documentation/18.0/applications/sales/point_of_sale.html · cash control: https://www.odoo.com/documentation/14.0/applications/sales/point_of_sale/shop/cash_control.html
- Odoo year-end / lock dates: https://www.odoo.com/documentation/18.0/applications/finance/accounting/reporting/year_end.html
- Odoo French certification module — `l10n_fr_pos_cert/models/account_closing.py`: https://raw.githubusercontent.com/odoo/odoo/17.0/addons/l10n_fr_pos_cert/models/account_closing.py · docs: https://www.odoo.com/documentation/18.0/applications/finance/fiscal_localizations/france.html
- ERPNext POS Opening Entry source: https://raw.githubusercontent.com/frappe/erpnext/develop/erpnext/accounts/doctype/pos_opening_entry/pos_opening_entry.py · POS Closing Entry: https://raw.githubusercontent.com/frappe/erpnext/develop/erpnext/accounts/doctype/pos_closing_entry/pos_closing_entry.py
- ERPNext opening balance docs: https://docs.frappe.io/erpnext/user/manual/en/opening-balance · Temporary Opening: https://docs.erpnext.com/docs/v13/user/manual/en/accounts/articles/balance-in-temporary-account · Opening Invoice Creation Tool: https://docs.erpnext.com/docs/v13/user/manual/en/accounts/articles/opening-invoice-creation-tool · freezing: https://docs.erpnext.com/docs/v12/user/manual/en/accounts/articles/freeze-accounting-entries · accounting period: https://docs.erpnext.com/docs/v12/user/manual/en/accounts/accounting-period
- Square Cash Drawers API: https://developer.squareup.com/reference/square/cash-drawers-api · `CashDrawerShift` object: https://developer.squareup.com/reference/square/objects/CashDrawerShift · merchant help: https://squareup.com/help/us/en/article/8344-start-and-end-a-cash-drawer-session
- Lightspeed X-Series float: https://x-series-support.lightspeedhq.com/hc/en-us/articles/25534311609243-What-is-the-float · cash movements: https://x-series-support.lightspeedhq.com/hc/en-us/articles/25534268781851-What-does-each-cash-movement-mean-from-the-register-closure-report *(403 to direct fetch; quoted from indexed snippets)*
- Sage 200 opening balances: https://desktophelp.sage.co.uk/sage200/professional/Content/NL/Enter_opening_balances.htm
- QuickBooks Opening Balance Equity: https://quickbooks.intuit.com/community/other-questions-9/opening-balance-equity-87802
- Fond de caisse comptabilisation: https://www.compta-online.com/comptabilisation-un-fond-de-caisse-ao5455 · https://www.compta-online.com/comptabiliser-le-fonds-de-caisse-t63496 · https://expy.fr/blog/guide-pratique-pour-constituer-et-comptabiliser-votre-fonds-de-caisse/ · https://www.legalstart.fr/fiches-pratiques/comptabilite-entreprise/fonds-de-caisse/
- Écart de caisse (658/758): https://www.compta-online.com/ecart-de-caisse-t14962 · https://www.jdc.fr/blog/erreurs-de-caisse-comptabiliser
- À-nouveaux / reprise des soldes: https://www.compta-online.com/anouveaux-en-comptabilite-ao1119 · https://www.compta-facile.com/reprise-des-a-nouveaux-en-comptabilite/ · PCG 890/891: https://www.comptanat.fr/pcg/fonc8.htm
- BOFiP BOI-TVA-DECLA-30-10-30 (logiciels de caisse / inaltérabilité): https://bofip.impots.gouv.fr/bofip/10691-PGP.html/identifiant=BOI-TVA-DECLA-30-10-30-20251001

**Tunisian chart of accounts (§1.3.1)**
- OECT-published SCE *Nomenclature et Fonctionnement des Comptes* (PDF, extracted and read in full): https://alliance-tunisie.com/wp-content/uploads/2019/04/Nomenclature-et-Fonctionnement-des-comptes.pdf
- Ordre des Experts Comptables de Tunisie — Système comptable tunisien: https://oect.org.tn/systeme-comptable-tunisien/
- Secondary: https://legalstart.tn/le-plan-comptable-tunisien/

**VAT declaration & document count (Part 2) — Tunisia primary sources**
- **Official DGI monthly declaration form** (retrieved and text-extracted): https://www.finances.gov.tn/sites/default/files/2023-04/MENSUELLE__2023.pdf · index: https://www.finances.gov.tn/fr/document/imprime-de-la-declaration-mensuelle-des-impots-2023
- **CDET** (official DGELF editions, updated 01/01/2025 and 01/01/2026) — art. 117 tarif, art. 124, **art. 126 (document count obligation)**, art. 135 bis: https://jibaya.tn/wp-content/uploads/2025/04/Code-des-Droits-dEnregistrement-et-de-Timbre-2025.pdf · https://jibaya.tn/docs/code-des-droits-denregistrement-et-de-timbre-2026/ · art. 117 tarif (secondary): https://www.jurisitetunisie.com/tunisie/index/cdet/droits_timbre.htm
- **Note Commune n° 15/2022 (DGELF)** — ticket-de-vente stamp scope & monthly declaration: https://jibaya.tn/wp-content/uploads/2024/02/Note-Commune-N%C2%B0-15.pdf
- **Code de la TVA art. 9-I-5** (imputation of VAT on résiliées/annulées): https://9anoun.tn/fr/kb/codes/code-taxe-sur-valeur-ajoutee/code-taxe-sur-valeur-ajoutee-article-9 · https://www.jurisitetunisie.com/tunisie/codes/tva/tva1040.htm · **art. 18 §II** (invoice series, liste des factures en suspension): https://www.jurisitetunisie.com/tunisie/codes/tva/tva1060.htm
- **Avoir bears the timbre — DGELF prise de position n° 99188 du 29/03/1999**, reported in *Manuel Permanent du Droit des Affaires Tunisien*, Feuilles Rapides n° 176 (02/2010) §II: http://www.cabinetamamou.net/Archives/folder/Bulle176.pdf
- Avoirs sur achats / reversement: https://www.profiscal.com/etudiants/TCA/tca_ch5_06.htm · form layout in French (teaching case): http://www.ispavocat.tn/images/cours/comptabilite/Etude%20de%20cas.pdf
- **Note commune n° 02/2026** (e-invoicing excludes notes de crédit) — quoted verbatim via professional commentary, primary PDF not retrieved: https://chaexpert.com/facturation-electronique-nc02/
- TN certified cash registers, arrêté du 14/10/2025 (JORT n° 125): https://www.tunisienumerique.com/services-de-consommation-sur-place-la-mise-en-place-des-caisses-enregistreuses-sera-effective-a-partir-de-ces-delais/ · https://caisses.tn/caisses-obligatoires-en-tunisie/
- TEIF document-type codes (secondary): https://noqta.tn/en/tutorials/format-teif-specifications-techniques-tunisie-2026
- ⚠️ Vendor page claiming avoirs are **exempt** from the timbre — contradicted by the CDET text and the DGELF position; recorded as incorrect: https://integrasys-erp.com/ressources/fiscalite-tunisienne/timbre-fiscal-regles

**Comparative standards (Part 2)**
- Formulaire CA3 3310 + notice (impots.gouv.fr): https://www.impots.gouv.fr/sites/default/files/formulaires/3310-ca3-sd/2024/3310-ca3-sd_4722.pdf · ligne-par-ligne: https://www.l-expert-comptable.com/fiches-pratiques/comment-remplir-sa-declaration-de-tva-mensuelle-ca3.html
- SAF-T XSD (`SalesInvoices` → `NumberOfEntries` / `TotalDebit` / `TotalCredit`; `InvoiceType` incl. `NC`), read in full: https://github.com/assoft-portugal/SAF-T-AO/blob/master/XSD/SAFTAO1.01_01.xsd
- Italy SdI TD04 (nota di credito): https://invoicedataextraction.com/blog/italy-credit-debit-note-rules · https://ecosio.com/en/blog/sistema-di-interscambio-sdi-reporting-to-replace-esterometro-declarations-in-2022/
- BOFiP BOI-TVA-DECLA-30-10-30 (§ 90 plus/minus corrections, § 170 closure payload): https://bofip.impots.gouv.fr/bofip/10691-PGP.html/identifiant=BOI-TVA-DECLA-30-10-30-20251001

**Not verified — flagged in text**
- **NF525 référentiel itself** (AFNOR / Infocert, paywalled). The LNE *Référentiel de certification des systèmes d'encaissement* Rev. 1.8 (Dec 2025) was used instead — it implements the same BOFiP articles and requires **neither gapless ticket numbering nor a ticket count**.
- Whether a Tunisian **POS refund ticket** is chargeable/counted for the 100-millime ticket stamp — CDET and NC 15/2022 are silent.
- The Tunisian **cahier des charges** for certified cash registers (arrêté 14/10/2025) — ticket numbering, Z content, refund handling.
- **TTN / TEIF XSD** document-type list (secondary source only); NC 02/2026 primary PDF.
- Agenzia delle Entrate guidance on counting TD04 in aggregate reporting.
- The reported LF-2024/2025 extension of the ticket stamp to all multi-department stores with CA ≥ 100 000 DT: **not present** in the CDET editions updated to 01/01/2025 or 01/01/2026 — treat as unenacted or misreported.
