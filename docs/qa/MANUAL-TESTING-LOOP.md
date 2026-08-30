# Manual testing loop — the standing human loop (owner-mandated 2026-08-29)

> Two days of manual onboarding testing found a class of bugs months of adversarial gates missed. This loop
> makes that finding rate permanent: a cadence, a fresh-tenant rule, a bug-report shape that triages itself,
> and a fixed path from a tester's note to a fix lane. It complements — never replaces — the automated
> onboarding campaign (`ONBOARDING-CAMPAIGN.md`): the campaign catches regressions of what we already know; the
> humans find what we do not.

## 1. Cadence

| When | Who | What | Output |
|---|---|---|---|
| **Every staging promotion** (push to `origin/dev`) | 1 tester, 45 min | the **fresh-tenant journey** (§2) on the new build, plus the promotion's own "team retest" lines from the session log | bug reports (§3) or "clean" in the promotion thread |
| **Weekly (Tue)** | 2 testers, half a day | one **role journey** each, rotating: cashier day (open → sales → refund → count → Z), buyer day (PO → GRN → supplier invoice → payment), accountant day (openings → lock → allocations → VAT declaration), back-office day (units, taxes, methods, locations, users, second company) | bug reports + a 5-line "what felt wrong" note (UX friction is a finding too) |
| **Before a tenant go-live** | the onboarding lead + 1 tester | the tenant's *own* data through the journey on a throwaway tenant (their file, their units, their VAT, their two locations) | go / no-go with the open bug list |
| **Monthly** | owner + orchestrator | read the month's reports against the baseline table of the flows' specs (convention 10); promote recurring findings to conventions/guards | changes to conventions, reviewer templates, campaign legs |

Testers compare against **what a mainstream ERP would do** (Odoo / ERPNext / Dolibarr), not against our spec
— "the spec says so" is not a reason to close a report; it is a reason to re-open the spec's baseline section.

## 2. The fresh-tenant rule

**Never test on a tenant that already worked.** Every journey starts with `/register` → a new tenant, a new
company, and — before anything else — a **second company** and a **second location**, because that is where
the last two days' bugs lived. Existing demo tenants have accumulated state that hides day-one defects (units
that were hand-created, drawers moved by hand, sequences already advanced).

The fresh-tenant journey, in order (the campaign scripts the same list — `ONBOARDING-CAMPAIGN.md` §2):

1. register → dashboard; Settings: look at units, taxes, payment methods, repositories, locations **before touching anything** — note anything empty or odd;
2. create a second company; switch to it; refresh the page — still on it? create a second location in company 1;
3. parties import **with opening balances** (the tutorial file, 4 rows: customer +/−, supplier +/−) → open a customer, read the balance;
4. products import with quantity, location, purchase price, one batch-tracked product with an expiry → open the product, read stock per location and the lot's expiry;
5. opening cash float + bank opening → Treasury: where did the money land?
6. lock the opening batch → try another balance import (must be refused);
7. POS (device if available, else the web contract): sale → refund → cash count → Z;
8. allocate a customer payment to a **historical** invoice → the party balance goes to zero?
9. re-run step 3 and step 4 with the same files → nothing doubles; the wizard says "skipped".
10. Reports: VAT declaration, stock valuation, trial balance — do the three agree with what you did?

At every money step ask the campaign's question: **where did it land** — repository, GL account, party balance —
and do the three agree.

## 3. Bug-report shape (copy this; a report missing a field goes back to the tester)

```
WHAT:      one sentence, user language ("after switching company the import put stock in the other company's shop")
WHERE:     app + screen/route + action (web /import/products, step "Options", button "Lancer l'import")
TENANT:    tenant id or the email you registered with · company name · location name
WHEN:      date + time with timezone (Africa/Tunis) — lets us find the API log line
EXPECTED:  what a mainstream ERP does here (one line)
GOT:       what happened (numbers as you saw them, exact strings)
EVIDENCE:  screenshot(s); the file you imported; the receipt/document number
REPRO:     fresh tenant? yes/no · steps 1..n · does it happen twice?
SEVERITY:  P0 money/stock wrong or lost · P1 blocks a journey · P2 wrong but workaround · P3 friction/copy
```

Post it in the testing channel with the **build** (the web bundle name from the page source, e.g.
`index-CPE-8Pez.js`, or the API health timestamp). One report per bug; do not bundle.

## 4. Triage flow (into an F-style session)

1. **Within the day**, the orchestrator on duty reads every report and writes a triage line under the report:
   `TRIAGED → <root cause guess> · owner session <F|G|H|…> · lane id` or `NEEDS-INFO: <field>` or
   `NOT-A-BUG: <reason + baseline reference>` (a NOT-A-BUG that cites only our spec is not allowed — cite the
   industry baseline or the owner's ruling).
2. P0/P1 open a **fix lane** the same day in the session that owns the surface (Session F pattern:
   `docs/sessions/session-F-testing-2026-08-29/F-BUG-1-TRIAGE.md` → `LANE-F1-…-BRIEF.md` → Codex lane →
   adversarial gate → merge → promotion → **team retest line** in the promotion checklist). P2/P3 go to the
   session's queue and are batched.
3. Every fix lane adds **the test that would have caught it** (second-of-everything, convention 09) and, when
   the bug was a journey bug, **a campaign leg or assertion** (`ONBOARDING-CAMPAIGN.md` "how to add a leg").
   A fix without either is CHANGES-REQUESTED at the gate.
4. After promotion, the reporting tester **retests on a fresh tenant** and closes the report with
   `RETESTED OK <build>` — the fixer never closes their own bug.
5. Recurrence (same shape, second time): the monthly review turns it into a convention, a reviewer question,
   or a ratchet (`docs/conventions/08-DETECTOR-LIVENESS.md` applies).

## 5. What the loop feeds

- `docs/handoff/PROMOTION-CHECKLIST-*` — "team retest" lines and the green campaign precondition.
- `docs/conventions/09..11` — the rules distilled from recurring findings.
- `docs/superpowers/audits/*-gap-matrix.md` — findings that are baseline gaps, not bugs, go to the flow's gap matrix.
- The campaign — every journey bug becomes a leg.

Owner of this document: the orchestrator on duty; review it at the monthly slot.
