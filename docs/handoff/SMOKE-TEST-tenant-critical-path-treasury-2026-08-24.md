# Manual smoke test — tenant critical path with a treasury lens (owner-run, 2026-08-24)

Purpose: the steps Playwright cannot (or should not) drive — physical POS device, real printer, cash in hand — plus the money-landing checks
that need a human eye. Run on the local stack (API :8010, web :5173, Tauri POS) on a FRESH tenant. Tick each row; note amounts as shown.
Legend: 🟢 automated in wave 4 (verify only) · 🟠 manual.

## 0. Setup
| # | Step | Expect | Check where it lands |
|---|---|---|---|
| 0.1 🟢 | Signup TN parapharmacy, 2 locations POS-enabled | company TND, TVA 19/13/7/exo, 141-row chart | `/settings/setup` 5/7+ |
| 0.2 🟠 | Opening float: drawer 200.000, safe 1 000.000 | repositories show balances | Treasury › repositories; GL 53x = 1 200.000 |
| 0.3 🟢 | Import products (accents, lots/expiry) + opening stock per branch | qty per location; lots with expiry | Inventory per branch; GL Dr 37 / Cr opening |
| 0.4 🟠 | AR/AP opening: customer owes 150.000; we owe supplier 500.000 | partner balances | Customer page "Total receivable" 150.000; supplier page 500.000; aged AR/AP |

## 1. Purchasing → supplier balance
| # | Step | Expect | Money/stock lands |
|---|---|---|---|
| 1.1 🟢 | PO 3 products (check unit price is PURCHASE price — W2-6) | confirmed | none |
| 1.2 🟢 | GRN partial → remainder | stock ↑ per branch, lots created | Dr 37 / Cr 408 |
| 1.3 🟢 | Supplier invoice (3-way) with VAT | supplier balance = 500 + invoice | Dr 408 + 4456 / Cr 401 |
| 1.4 🟠 | Pay supplier from SAFE (cash) | safe ↓, supplier balance ↓ | Dr 401 / Cr 53-safe; Treasury safe balance |
| 1.5 🟠 | Pay supplier by bank transfer | bank ↓ | Dr 401 / Cr 512; bank reconciliation queue |
| 1.6 🟠 | Supplier return / supplier credit note (if shipped) | balance ↓ | Dr 401 / Cr 37 (+VAT) |

## 2. Transfers between branches
| # | Step | Expect |
|---|---|---|
| 2.1 🟢 | Transfer A→B, ship, receive | A ↓ B ↑ exactly; lots follow; no GL (same company) |
| 2.2 🟢 | Cancel an un-shipped transfer | nothing moves |
| 2.3 🟠 | Receive less than shipped | discrepancy path — what document justifies the gap? |

## 3. POS day — B2C (device)
| # | Step | Expect | Lands |
|---|---|---|---|
| 3.1 🟠 | Claim terminal per branch; cashier PIN | only POS-enabled locations claimable | — |
| 3.2 🟠 | Open shift, float 200.000 | shift open | drawer 200.000 |
| 3.3 🟠 | Cash sale 3 lines (7/13/19 %) | receipt VAT per rate; lot decremented (FEFO) | drawer ↑; stock ↓ branch; fiscal chain seq +1 |
| 3.4 🟠 | Card sale | drawer unchanged | card clearing account ↑ |
| 3.5 🟠 | Mixed cash+card, discount | totals reconcile | — |
| 3.6 🟠 | **B2C refund** of a line (cash back) | refund receipt linked to original; stock ↑ | drawer ↓ by refund; GL reversal of the line incl. VAT |
| 3.7 🟠 | Exchange | net zero cash | stock both ways |
| 3.8 🟠 | Held order → recall → complete | one receipt | — |
| 3.9 🟠 | Void before seal / after seal | after-seal = refund path only | chain intact |
| 3.10 🟠 | Print receipt (accents, TND 3dp) | readable | — |
| 3.11 🟠 | Offline sale (API down) → resync | receipt sealed later in order; no duplicate | chain continuous |
| 3.12 🟠 | X-report mid-shift | matches drawer expectation | — |
| 3.13 🟠 | Cash count (short by 5.000) + close shift | variance recorded | variance GL (R-8) |
| 3.14 🟠 | Z-report device vs server | identical totals | — |

## 4. POS/B2B on account → customer balance
| # | Step | Expect | Lands |
|---|---|---|---|
| 4.1 🟠 | Sale on customer account (B2B) | customer balance ↑ | Dr 411 / Cr 70x + 4457 |
| 4.2 🟠 | Customer pays part in cash at POS | balance ↓; drawer ↑ | Dr 53 / Cr 411 |
| 4.3 🟢 | Web: customer page + aged AR | matches 0.4 + 4.1 − 4.2 | GL 411 sub-ledger = page |
| 4.4 🟠 | B2B invoice paid BEFORE posting (N-6) | goes to advances (419), invoice stays confirmed; posting clears | no negative AR |

## 5. Where the cash lands
| # | Step | Expect | Lands |
|---|---|---|---|
| 5.1 🟠 | Remit drawer → safe after close | drawer 200.000 float, safe ↑ | Dr 53-safe / Cr 53-drawer |
| 5.2 🟠 | Safe → bank deposit | safe ↓ bank ↑ | Dr 512 / Cr 53-safe; deposit slip |
| 5.3 🟠 | Expense paid from drawer mid-shift | drawer ↓; count expects it | Dr 6xx (+4456) / Cr 53-drawer |
| 5.4 🟢 | Per-branch cash visibility + consolidated | numbers add up | Treasury dashboards |
| 5.5 🟢 | Trial balance closes; VAT declaration per rate | Dr = Cr; 4457 by rate | Accounting reports |

## 6. Balances sanity (end of day)
Supplier balance = opening + invoices − payments − credits. Customer balance = opening + account sales − payments − refunds. Drawer =
float + cash sales − cash refunds − expenses − remittance. Safe = opening + remittances − supplier cash payments − deposits. Each of these
must be readable on ONE screen per entity and equal the GL sub-ledger. Record any screen where the number shown ≠ the computed one.
