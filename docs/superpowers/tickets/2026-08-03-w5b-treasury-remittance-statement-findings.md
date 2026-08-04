# W-5b findings — remittances / statements / reconciliation / repositories (MTP-TRE-28..59)

**Raised:** 2026-08-04 · **Wave:** W-5b of the pre-launch full-E2E money campaign
**Specs:** `apps/web/e2e/money-campaign/treasury-remittances.spec.ts`,
`treasury-statements.spec.ts`, `treasury-reconciliation.spec.ts`, `treasury-repositories.spec.ts`
**Verdict summary:** 32 authored cases — **31 PASS, 0 FAIL, 1 BLOCKED**. **No money defect was
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

This is pinned GREEN by `MTP-TRE-53`, which asserts the exact resulting balance either way — if a
guard is added, the case flips to the refusal branch and still passes, so the pin is safe.

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
After W-5b (32 cases, some files re-run), `demo-pharmacy-tn` holds **60 payment repositories, 32 of
them `C2-STMT-*`**, all active.

Consequences:

- `payment_repositories` has **no DELETE route**, so they can only be deactivated, never removed.
- While active they appear in every repository picker, the treasury dashboard and the cash-position
  widget — the same "pollutes a list a human reads" problem as the fixture terminals.
- Discovery is O(repositories) with a paginated statement fetch per candidate, so each new run is
  slower than the last: by the end of this wave a single `discoverOrProvisionRepository()` call took
  visibly longer than the case it was setting up.

**Recommendations:** (a) have the fixture deactivate a repository once it has consumed it, (b) reuse
one dedicated fixture repository per run rather than per statement where the case allows it, and/or
(c) add a `payment-repositories` DELETE (or an admin purge) for repositories with no movements.

W-5b deactivated every repository IT provisioned directly (`W5B-*`, 20 of them, verified 0 active),
but deliberately did not touch `C2-STMT-*` repositories — they are `statement-support.ts`'s to
manage, and deactivating them from a case would race any sibling agent using the same fixture.
