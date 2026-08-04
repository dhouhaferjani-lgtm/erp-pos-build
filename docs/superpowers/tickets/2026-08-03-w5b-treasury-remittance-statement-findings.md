# W-5b findings — remittances / statements / reconciliation / repositories (MTP-TRE-28..59)

**Raised:** 2026-08-04 · **Wave:** W-5b of the pre-launch full-E2E money campaign
**Specs:** `apps/web/e2e/money-campaign/treasury-remittances.spec.ts`,
`treasury-statements.spec.ts`, `treasury-reconciliation.spec.ts`, `treasury-repositories.spec.ts`
**Verdict summary:** 34 authored cases — **33 PASS, 0 FAIL, 1 BLOCKED**. **No money defect was
found.** Every exact-decimal assertion held: remittance totals, statement parsing at scale 3,
reconciliation's exact-`bccomp` matching, transfer/adjustment balances, GL balance.

This ticket records the seven items that are **not** money defects but that an owner or a follow-up wave
must rule on. None of them warrants a red test, so none carries a tripwire; the behaviours that are
launch-relevant are already pinned GREEN by the cases named below.

---

## 1. (P2) `/treasury/remittances/new` has no draft — one click creates, fills AND remits

`apps/web/src/features/treasury/RemittanceCreatePage.tsx:60-85`. The single
"Create and remit" button runs `POST /instrument-remittances` → a serial loop of
`POST …/lines` → `POST …/remit`, then navigates to the detail page. Consequences:

- **The bordereau total is never visible before the irreversible action.** `remit()` moves every
  line's instrument to `Deposited`, stamps `deposited_at`/`deposited_to_id` and fires
  `InstrumentDeposited` (`InstrumentRemittanceService::remit`). The only screen that renders a
  remittance total is `RemittanceDetailPage.tsx:78,93` — i.e. after the deposit has happened. A
  cashier cannot check the slip against the physical cheques they are about to hand the bank.
- **A mid-loop failure strands an orphan draft.** If line 3 of 5 fails, the slip and its first two
  lines persist as a `draft` with two instruments locked into it (`instrument.remittance_id` set),
  and the UI only shows a generic `remittances.createError`. Those instruments are then invisible
  to the next `/remittances/new` (the eligible list excludes instruments already on an active
  slip) with no screen that lists or resents draft slips.
- The API fully supports the draft workflow the plan assumes (compose → review → remit); only the
  UI collapses it.

**Campaign impact:** `MTP-TRE-28` and `MTP-TRE-30` (the two "displayed total" cases) had to compose
the draft over the API and read the total on the detail page. The figures themselves are correct —
`RemittanceDetailPage.tsx:2,78` uses `big.js`, so the plan's suspected client-side FLOAT reduce is
already fixed and is now regression-pinned.

**Recommendation:** split the button (Create draft / Remit), or at minimum render the running total
on the create page before submission.

---

## 2. (P2) Nothing guards a repository against being driven negative — owner ruling needed

`RepositoryTransferService::transfer` and `TreasuryMovementService::transfer`/`record` validate
currency, GL linkage, repository type, frozen state and self-transfer — **never the balance**.

**Recorded actual (`MTP-TRE-53`):** transferring `500.000` out of a repository holding exactly
`100.000` → **HTTP 201**, balance `-400.000`. The negative is recorded faithfully (signed, scale 3,
visible in the append-only movements ledger and on the repository header), so nothing is corrupted —
but a cash register cannot physically hold negative cash, so a mistyped transfer silently produces
an impossible till. `MTP-TRE-57`'s control leg shows the same for adjustments (`-12.500` from a
zero-balance repository, HTTP 201).

**`MTP-TRE-53` is now a GREEN TRIPWIRE** pinning this exact behaviour — `{status: 201, balance:
'-400.000'}`. (Its first revision asserted "refused OR negative" in an if/else, which would have
passed under every possible product behaviour forever; that is a recorded actual, not a tripwire —
corrected in fix round 1, I-5.) **If the ruling below ships, this case flips RED on purpose**: that
is the signal, not a flake. The case comment carries the verbatim replacement assertion
(`{status: 422, balance: '100.000'}`, and delete the restore-transfer).

**Owner ruling wanted before launch:** should `cash_register` / `safe` repositories refuse a
transfer or adjustment that would take the balance below zero (bank accounts legitimately can go
negative)?

---

## 3. (P3) Duplicate handling: a byte-identical re-upload is REFUSED, not "classified duplicate"

The plan's `MTP-TRE-37` wording expects re-uploading the same file to classify its rows as
duplicates. Actual (`StatementImportService::rejectDuplicateFile`, called in BOTH `preview()` and
`confirm()`): the file's SHA-256 is unique per repository, so the second upload returns **422
`DUPLICATE_STATEMENT_FILE`** carrying `errors.existing_statement_id`.

Row-level fingerprint dedupe does exist and does what the plan wants — it just needs a *different*
file: `MTP-TRE-37` proves a superset file (same 2 rows + 1 new row) previews
`accepted 1 / duplicate 2`. **The guarantee the plan cares about — no double-counted bank lines —
holds on both paths.** This entry exists only so the plan's wording gets corrected rather than
someone later filing the 422 as a defect.

---

## 4. (P3) Reconciliation permits a partial manual allocation that leaves a sub-millime residual

`MTP-TRE-42`. With a statement line of `500.000` and a repository movement of `500.001`:

- suggestions correctly offer **nothing** (matching is exact `bccomp`, no amount tolerance) ✔
- allocating the movement's full `500.001` to the line is **refused 422** ✔
- allocating `500.000` **succeeds**, resolving the line as `matched` while `0.001` of the movement
  stays unallocated.

That is *not* the "silently absorb the millime" failure the plan guards against — the residual stays
explicitly unreconciled on the movement (`remaining_allocatable_amount`) and completion re-checks
`Σ signed lines == closing − opening`. Recording it so the semantics are on the record: the
millime survives as visible unreconciled residue, it is neither written off nor hidden.

---

## 5. (P3) Fixture debt — two plan principals do not exist in `demo-pharmacy-tn`

`RolesAndPermissionsSeeder` has no seeded principal for either of these, so both cases are
recorded **PARTIAL** with an in-spec annotation:

| Plan case | Principal the plan names | Closest seeded principal used |
|---|---|---|
| `MTP-TRE-48` | `bank-statements.view` **only** | `accountant` (view+import+reconcile, no `.reopen`) and `manager` (no bank-statement permission at all → every route 403, including the list). The "list stays visible while writes are refused" half is unverifiable. |
| `MTP-TRE-59` | `treasury.view` **only** | `viewer` (`repositories.view` + `instruments.view`, no `treasury.*`). `treasury.view` is only ever granted together with `treasury.adjust` + `treasury.transfer`. |

The separation of `bank-statements.reconcile` from `bank-statements.reopen` IS fully proven
(`MTP-TRE-47`: the accountant completes a statement, then gets **403** on reopen; the owner reopens).

**Fix:** add a read-only role (or two) to `DemoPharmacySeeder::seedRoleCoverageUsers()` — campaign
fixture debt **C-3**.

---

## 6. (P3) Repository currency is not settable over HTTP → `MTP-TRE-51` is BLOCKED

Neither `PaymentRepositoryController::store()` nor `::update()` validates or accepts a `currency`
field; the value is inherited from the company. `demo-pharmacy-tn` is TND-only (asserted in the
case), so a cross-currency transfer cannot be constructed through the web at all.

The guard itself is real: `TreasuryMovementService::transfer` throws `CurrencyMismatchException`
(extends `DomainException` → 422) when either locked repository's currency differs from the intent,
and `TransferCashModal.tsx:99-101` filters the destination picker to the source currency. Exercising
it needs the multi-currency tenant of campaign fixture debt **C-9** (`demo-garage`, FR/EUR + TN/TND).

---

## 7. (P2) `statement-support.ts` provisions unbounded, undeletable `C2-STMT-*` repositories

**Test-infrastructure finding, same class as the C2-STMT terminal item in
`docs/superpowers/tickets/2026-08-02-w2-wave-minor-findings.md`.**

`discoverOrProvisionRepository()` returns the first `bank_account` repository that is active,
GL-linked, has no reconciliation checkpoint and holds no open statement — and **creates a new one**
when none qualifies. Every case that confirms a statement permanently disqualifies its repository
(an open statement, or a checkpoint once completed), so each subsequent call provisions another.

Consequences:

- `payment_repositories` has **no DELETE route**, so they can only be deactivated, never removed.
- While active they appear in every repository picker, the treasury dashboard and the cash-position
  widget — the same "pollutes a list a human reads" problem as the fixture terminals.
- Discovery is O(repositories) with a paginated statement fetch per candidate, so each run is slower
  than the last: by the end of the first W-5b pass a single `discoverOrProvisionRepository()` call
  took visibly longer than the case it was setting up.

**Fixed on the test side in fix round 1 (I-2):** all four W-5b specs now retire every profile and
every self-provisioned `C2-STMT-*`/`W5B-*` repository in an `afterEach`/`finally`, and a one-shot
sweep retired the accumulated backlog. **The product-side recommendation stands:** add a
`payment-repositories` DELETE (or an admin purge) for repositories with no movements — the retired
rows are undeletable forever.

---

## 8. Post-cleanup state of `demo-pharmacy-tn` (fix round 1, I-2 / M-10)

Measured live after the sweep, not estimated:

| Object | Before sweep | After sweep |
|---|---|---|
| Payment repositories (total) | 86 | 86 (no DELETE route exists) |
| — **active** | 42 | **8** — exactly the seeded set: `BANK-01/02/03`, `CASH-01/02`, `SAFE-01`, `VIRT-01`, `W2A-NOGL-01` |
| — `C2-STMT-*` active | 34 | **0** (52 total, all retired) |
| — `W5B-*` active | 0 | **0** (26 total, all retired) |
| Statement import profiles | 61 | **53**, all `is_active:false` — 8 deleted outright, 53 statement-bound and therefore undeletable by the API ("deactivate it instead") |
| Draft remittance slips holding instruments | 40 slips / 161 lines | **0 lines held** — every instrument released back to `received` |

**Verified durable, not one-off:** a full 34-case re-run AFTER the sweep provisioned 22 further
repositories and 6 further profiles and auto-retired **every one** — active repositories stayed at
exactly the 8 seeded codes and active profiles at **0**. Row TOTALS still grow per run (108
repositories / 67 profiles at time of writing) because neither object has a delete path once it is
referenced; that residue is the product-side recommendation above, and it is now inert (nothing
appears in a picker, and `discoverOrProvisionRepository` skips inactive rows — the combined suite
went from **5.9m to 3.4m** once the backlog was retired).

`CASH-01` is back at exactly `53143.660`, its pre-wave baseline (the `MTP-TRE-50` transfer was
reversed in-case). **What genuinely cannot be cleaned:** the 86 repository rows and 53 profile rows
themselves (no delete path), the 40 now-empty draft slip rows (no DELETE route for a slip), and the
retired fixture balances itemised in §9.

---

## 9. (I-3) Fabricated data left in the ledgers — W-6 MUST read this

**Do NOT unwind.** Every fixture adjustment posted a REAL balanced journal entry
(`RepositoryAdjustmentController.php:110-121`); reversing them would post more entries, not fewer,
and would double the noise W-6 has to reconcile. They are disclosed instead.

**Measured at WAVE CLOSE (2026-08-04), after the final full re-run.** Earlier drafts of this section
quoted mid-wave snapshots (87 JEs / 64 repos / 29 017.396 TND); those were stale the moment another
run happened. **These totals grow with every re-run of the W-5b specs — treat the EXCLUSION RULE in
§9.3 as the durable answer and re-measure if you need a number.**

### 9.1 Adjustment journal entries — the tolerance accounts

**127 posted `repository_adjustment` journal entries** whose description matches `W5b|W5B|C2-STMT`:

| Account | Name | Debit | Credit | Net | Lines |
|---|---|---|---|---|---|
| `6580` | Écart de règlement (charges) | **3 058.152** | 0.000 | **+3 058.152** | 24 |
| `7580` | Écart de règlement (produits) | 0.000 | **47 157.442** | **−47 157.442** | 103 |

The contra side sits on the repository GL accounts (`512` etc.).

### 9.2 Expense journal entries — MISSED by the original rule

The first version of this section only excluded `repository_adjustment` entries, which **silently
missed the fixture expenses**: `MTP-TRE-44`'s `create_expense` action and `MTP-TRE-41c`'s outbound
cheque both create real expense documents (`CreateExpenseHandler` / `issueTier3OutboundCheque`).

They post under **two different vendor patterns**, and the first measurement of this section caught
only one of them — so the figures below are the corrected, complete set (**15 entries**, not 9):

| Fixture | Vendor pattern | Entries | Accounts |
|---|---|---|---|
| `MTP-TRE-41c` outbound cheque (`37.125` each) | `C-2 fixture vendor *`, receipt `C2-RCPT-*` | 9 | `613` Dr **334.125** / `401` Cr **334.125** |
| `MTP-TRE-44` create-from-line (`42.750` each) | **`W5b TRE-44 vendor *`** | 6 | `613` Dr **256.500** / `512` Cr **256.500** |
| **Combined** | | **15** | **`613` Dr 590.625**, `401` Cr **334.125**, `512` Cr **256.500** |

Two things worth noting:

- The `W5b TRE-44 vendor *` entries carry **no `C-2` marker**, so a rule keyed only on
  `C-2 fixture vendor` / `C2-RCPT-` misses them entirely — that is exactly what happened here.
- The two fixtures hit **different credit sides**: TRE-41c's cheque expense stays **unpaid**, so it
  credits the supplier payable `401`; TRE-44's create-from-line expense is **paid on creation** (the
  handler requires a resulting repository movement), so it credits the bank account `512`.

So the fixtures inflate a P&L expense account (`613`), a supplier payable (`401`) **and** a bank GL
account (`512`) — not just the tolerance accounts.

### 9.3 The exclusion rule W-6 must apply

**Do not "subtract the totals above"** — they are a snapshot and go stale on the next run. Exclude by
predicate instead:

1. **Repositories** — the cleanest filter: every fixture repository is now `is_active = false` and
   every one of the 8 active repositories is seeded (`BANK-01/02/03`, `CASH-01/02`, `SAFE-01`,
   `VIRT-01`, `W2A-NOGL-01`). **Filter `payment_repositories.is_active = true`**, or exclude
   `code LIKE 'C2-STMT-%' OR code LIKE 'W5B-%'`.
2. **Adjustment JEs** — exclude `source_type = 'repository_adjustment'` whose `description` matches
   `W5b|W5B|C2-STMT`.
3. **Expense JEs** — exclude entries whose description/vendor matches `C-2 fixture vendor` or `W5b`
   (TRE-44's `create_expense` fixtures post under `W5b TRE-44 vendor *` at `42.750` each) or whose
   receipt matches `C2-RCPT-`.

A W-6 case that asserts an absolute `6580`, `7580`, `613`, `401` **or `512`** balance, or that
aggregates repository balances without an `is_active` filter, will fail for reasons that have
nothing to do with the product.

### 9.4 Wave-close counts

| Object | At wave close |
|---|---|
| Payment repositories | **130 rows**, **8 active** — exactly the seeded set; 122 fixture rows, **0 active** |
| Fixture repositories carrying a non-zero balance | **96**, totalling **43 634.290 TND** (inactive, but still summed by any unfiltered aggregate) |
| Statement import profiles | **81 rows**, **0 active** |
| Instruments held by draft remittance slips | **0** |

Row totals keep growing because neither repositories nor referenced profiles have a delete path —
that is the product-side recommendation in §7. The rows are inert (nothing appears in a picker) but
they are not removable.
