# NF525 / fiscal-POS Z-report payment & cash-reconciliation standards

> Deep-research report (2026-06-11) commissioned to settle the signed-Z cash model for the
> POS fiscal-event engine (launch blockers H1+H2). 5 search angles, 21 sources fetched, 69
> claims extracted, 25 adversarially verified (23 confirmed / 2 killed). Run `wf_995c9402-e4f`.

## Bottom line

The owner's hypothesis — *"for cash deposits and drawer operations, log what's actually in the
drawer; for everything else, log what has been tendered"* — is **validated for the drawer/account
side and REFINED for the per-method side**:

- ✅ **Drawer operations + customer account/credit payments → log what's physically in the drawer.**
  These are distinct cash-balance events folded into the *theoretical/expected cash*, NOT sales
  payment-method totals.
- ⚠️ **Per-payment-method totals are NET / allocated-to-the-sale, NOT gross tendered.** The
  "gross tendered" reading was explicitly **refuted (0-3)**. Change is *netted into* the cash
  figure (no separate change line); the cash method total = **net cash retained in the drawer**
  (tendered − change).

## Answers to the four questions

1. **Per-method breakdown** — each method records the amount *allocated/settled* to that method
   (net), kept structurally separate from sales-revenue aggregation. Split tenders = multiple
   payment rows per receipt (DSFinV-K `Bonkopf_Zahlarten`), aggregated per method into the Z
   payment table (`Z_Zahlart` / French *soldes par modes de règlement*). The cash type ("Bar")
   = *all net cash movements in the register*, not gross tender.

2. **Change rendered** — NOT a separate Z field. Implicitly netted into the cash figure. Handing
   back change reduces the recorded cash / theoretical drawer cash. DSFinV-K Anhang D enumerates
   exactly 7 payment types with **no `Rückgeld`/change field**.

3. **Drawer movements + account payments** — paid-ins, payouts/décaissements (supplier payments,
   petty expenses), opening float, bank/safe transfers are a **closed list** of cash-balance-only
   events folded into expected cash (DSFinV-K `GV_TYP`: `Anfangsbestand`, `Einzahlung`,
   `Auszahlung`, `Geldtransit`, `DifferenzSollIst`, …). A customer **credit sale** is a receivable
   creation (`Forderungsentstehung`, explicitly *not* a payment type); the later **cash receipt**
   (`Forderungsauflösung`) materialises as drawer cash at settlement. France isolates *crédit
   client* collections on distinct lines from the day's sales (CGI art. 286-I-3° + annex IV art. 37).
   Paid-outs must be entered **before** cash is physically removed so expected cash stays accurate.

4. **Canonical expected-cash formula** —
   ```
   expected_cash = opening_float
                 + cash_sales (net of change)
                 − cash_returns
                 + paid_ins
                 − payouts/drops
                 ± cash collected against customer accounts
   ```
   reconciled against the physically counted cash → the logged difference (`DifferenzSollIst`).

## Regulatory envelope (NF525)

NF525 (Infocert/AFNOR, legal trigger CGI art. 286-I-3° bis / Art. 88 anti-VAT-fraud law) mandates
the **existence and securing** of cumulative Z closures, per-payment-method balances, and cash-
collection data under its four ISCA pillars (Inalterability, Security — *condensed data + electronic
signature*, Conservation, Archiving). This is the legal basis for a device-authority, hash-chained,
on-device-signed Z. NF525 does **not** publish a field-level schema; Germany's **DSFinV-K** (primary,
current) is the canonical *structural* reference and is consistent with the French model in principle.

## Caveats / gaps (flagged)

- **DSFinV-K is the field-level reference; NF525 is the legal envelope.** Some NF525 field-level
  behavior is inferred by analogy. Treat as "consistent in principle."
- **MENA coverage = ZERO verified material.** No authoritative claims were found for **Tunisia's
  MDF / fiscal-POS transmission law (eff. 1 Jul 2026)**, Morocco, Egypt, or KSA ZATCA — nor Italy
  RT / Portugal SAF-T. These remain open. Tunisia MDF is a known separate workstream
  (`project_tunisia_nacef_fiscal`); the MDF signs/transmits each ticket and our hash chain does
  NOT substitute for it.
- French cash-ledger "out-movement" practices (booking cheque/card batches as a cash *sortie*) are
  professional-firm guidance for the *livre de caisse*, distinct from the certified-software Z.

## Open question for our signed Z (needs a compliance read, not in this research)

Does NF525 require the expected-vs-counted **difference** and the cash-drawer **movement events**
to live *inside the signed/hashed canonical Z bytes*, or can they sit in an unsigned reconciliation
layer? DSFinV-K keeps them in the closing structure. Default recommendation: include them in the
signed `report_data` (we already sign `report_data`), since clean-slate means no chain to migrate.

## Sources (top)

- DSFinV-K v2.0 (primary): https://dfka.net/wp-content/uploads/2019/08/20190802_DSFinV_K_V_2_0.pdf
- Infocert NF525 (primary): https://infocert.org/en/nf525/ · https://infocert.org/wp-content/uploads/2025/03/Plaquette-NF525-FR-ENG.pdf
- FIDUCIAL tenue de caisse (secondary): https://www.fiducial.fr/Expert-comptable/Tenue-de-caisse-les-bonnes-pratiques
- fiskaltrust DSFinV-K docs (secondary): https://docs.fiskaltrust.cloud/docs/poscreators/middleware-doc/germany/dsfinv-k
- Lightspeed/ShopKeep & Shopify expected-cash (secondary, arithmetic confirmation only)
