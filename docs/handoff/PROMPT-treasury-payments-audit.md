# PROMPT — Treasury / Payments module audit (cutoff vs deferred)

> Paste into a fresh session. READ-ONLY. The owner's read: "it's okay but far from comprehensive and
> based on real usage needs." Goal: find what's missing, classify each gap as **demo cutoff** vs
> **deferred**, grounded in how a multi-shop Tunisian parapharmacy actually operates.

---

**You are auditing the Treasury / Payments module of AutoERP (`apps/erp/apps/api`,
`app/Modules/Treasury` + related Payment code). READ-ONLY — do not change code. Save the report to
`docs/superpowers/audits/<date>-treasury-payments-audit/`.**

### 1. Map what exists
- Payment methods / instruments model: `PaymentMethod`, `PaymentInstrumentKind`
  (`apps/api/app/Modules/POS/Domain/Enums/PaymentInstrumentKind.php`), country payment settings
  (`country_payment_settings`), `PaymentToleranceService`. List supported tenders (cash, card,
  voucher, gift card, store-credit, …) and how each flows: POS fiscal-event path vs B2B
  `/payments` (single + `storeMultiple`) path.
- The Payment write paths + controllers, and how payments post to GL (cross-check the accounting
  audit: customer payment wired; supplier payment via procurement; tolerance unwired).
- Treasury accounts / cash registers / bank accounts model; shift cash-counting; X/Z reconciliation;
  how POS cash drawer ties to Treasury.

### 2. Assess against real parapharmacy usage
Walk the real flows and find gaps:
- **POS tenders:** cash (with change/rounding — TN cash rounding to nearest coin?), card, split/mixed
  tender, store-credit/voucher redemption. Is within-tolerance / under-tender handled (currently POS
  hard-requires full tender; tolerance config exists server-side but POS never reads it)?
- **Cash management:** open/close drawer, cash count, pay-in/pay-out (petty cash), safe drops,
  variance handling, multi-terminal + multi-shop reconciliation, cross-terminal refunds.
- **Supplier/expense outflows:** paying suppliers (procurement), recording expenses, the treasury
  side of those (cash vs bank), and whether they reduce the right balances + post GL.
- **Refunds/returns** money movement and how it reconciles to the drawer + GL.
- **Reporting:** daily cash position, by-shop / by-terminal, bank vs cash split — does the owner
  dashboard reflect treasury reality?

### 3. Output
For each finding: severity, evidence (file:line), and a classification:
- **CUTOFF** — needed for a credible multi-shop parapharmacy demo (cash + card sales, drawer
  open/close + X/Z, basic refunds). Keep this list SHORT and justified.
- **DEFERRED** — everything else → the post-launch integration branch (e.g. TN cash-rounding /
  tolerance feature, petty-cash workflows, advanced bank reconciliation, multi-currency treasury).
End with: (a) the cutoff shortlist with effort sizing, (b) the deferred backlog, (c) the single
biggest real-usage gap. Respect: precision rules 19/20 (money as strings, no float), POS cross-layer
contracts rule 20 (SQLite TEXT timestamps, named queues in horizon.php, projections run with no
CompanyContext), and never run the full PHPUnit suite.
