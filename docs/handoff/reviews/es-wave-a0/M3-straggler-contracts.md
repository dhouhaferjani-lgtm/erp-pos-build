# M3 — proposed verification contracts for the ES-16 / ES-17 stragglers

**Status: PROPOSAL. Nothing in this document is implemented.** M3's bridge review approves,
amends or rejects each contract below; **M3b** then implements *only* what was approved, as
amended there. Implementing a straggler before its contract is approved is a milestone failure
(brief R-7, `docs/handoff/CODEX-DISPATCH-es-wave-a0-2026-08-11.md:325-347`).

Handover §5's footnote is the authority these two rows fall under: a register row that fits none
of the six verification classes *"must state its verification contract explicitly in its own
milestone review and get it approved there"*. ES-16 and ES-17 are named there. Both are operator
**workflow / read-surface** gaps: no event to emit, no verifier to correct, no guard to add, and
**no corrupted data to repair**. The standard six-class shapes (verifier-class red/green,
refusal-class 403-and-persist-nothing, correctness-class before-RED/after-GREEN) do not fit, which
is exactly why they are proposed here rather than assumed.

**Do not improvise a seventh class.** Each contract below states its own falsifier so the reviewer
can reject it on its own terms.

Every `file:line` in this document was re-derived against the tree **as this milestone leaves it**
(M3's own tip), not against an earlier commit — the reviewer inspects the committed range, so the
addresses have to resolve there. M3 changes only `VerifyEventChainCommand.php`,
`VerifyPosChainCommand.php` and `ReceiptHashService.php`; every citation below in those files was
re-checked after the edits.

---

## Shared ground: what an operator can and cannot see today

Two partitions exist in `DeadLetteredProjectionsController::index()`:

| Partition | Selects | `file:line` |
|---|---|---|
| 1 — failed projections | `fiscal_event_projections` rows that dead-lettered | `DeadLetteredProjectionsController.php:66-99` |
| 2 — ingress quarantine | `fiscal_events` where `integrity_exception_class = 'canonical_parse_failure'` **and** no `fiscal_event_projections` row exists | `DeadLetteredProjectionsController.php:103-121` |

Everything an operator is expected to act on has to land in one of those two buckets. ES-16 is a
row that lands in **neither**. ES-17 is a row in a **different table entirely** that neither
partition looks at.

One resolution service exists — `ParseFailureResolutionService::resolve()`
(`ParseFailureResolutionService.php:120-234`) — and its preconditions
(`:241-270`) restrict it to `fiscal_events` rows that are simultaneously
`payload_parse_status = failed`, `payload IS NULL`, `integrity_status = quarantined` and
`integrity_exception_class = canonical_parse_failure`. Neither straggler satisfies those.

---

## ES-16 — the `z_session_lifecycle` quarantine blind spot

### The defect, restated from code (not from the register)

`OutboxIngestor::verifyZSessionLifecycle()` (`OutboxIngestor.php:510-556`) returns a
`z_session_lifecycle:*` verdict string for seven distinct lifecycle violations
(`missing_session_id`, `session_open_source_mismatch`, `duplicate_session_open`,
`missing_session_open`, `session_already_z_reported`, `missing_session_close_reference`,
`missing_session_close`). At `:182-186` that verdict is folded into `$linkageVerdict`, so
`deriveIntegrity()` (`:730-776`) classifies the row as **`sequence_gap`** —
*not* a class of its own — with the `z_session_lifecycle:*` string surviving only inside
`integrity_exception_reason`.

Then `dispatchProjections()` suppresses projection creation for it:

```php
// OutboxIngestor.php:922-924
if ($exceptionReason !== null && str_contains($exceptionReason, 'z_session_lifecycle:')) {
    return;
}
```

The consequences compose into the blind spot:

1. The `fiscal_events` row **exists** and is `quarantined`.
2. **Zero** `fiscal_event_projections` rows are created — so partition 1 is empty for it.
3. Its class is `sequence_gap`, not `canonical_parse_failure` — so partition 2 skips it
   (`DeadLetteredProjectionsController.php:107`).
4. `ParseFailureResolutionService` refuses it on two independent preconditions: the class is wrong
   (`:263-269`) and its `payload` is not NULL (`:250-255`) — the envelope **parsed fine**; only the
   lifecycle was wrong.
5. The one path that *would* recover it is
   `fiscal:enqueue-resolved-event-projections`, whose only event filter is
   `payload_parse_status = parsed` (`EnqueueResolvedEventProjectionsCommand.php:245`) — which this
   row satisfies. So recovery works, **but only for an operator who already knows the row exists
   and already knows to run that command.** Nothing surfaces either fact.

The money-relevant edge: a suppressed `SESSION_CLOSE` or `Z_REPORT` lifecycle event means the Z
session never projects, so the day's Z aggregates silently do not exist, with no operator-visible
signal anywhere.

### PROPOSED CONTRACT — ES-16

> **The fix must make a `z_session_lifecycle`-quarantined `fiscal_events` row visible to the
> operator on the existing dead-letter read surface, carrying enough information to act on it,
> without changing what the ingestor decides.**

Clause by clause — what M3b must **demonstrate**:

| # | Clause | Demonstration required |
|---|---|---|
| **16-A** | A `z_session_lifecycle` quarantine appears in `DeadLetteredProjectionsController::index()`'s response. | Red-first: a test that seeds the row through the **real ingestion path** (`OutboxIngestor`, one of the seven lifecycle violations) and asserts the row's id appears in the endpoint's payload. That test must be shown RED before the change. A hand-inserted `fiscal_events` row is weaker evidence and must be labelled as such if the production path cannot produce the shape. |
| **16-B** | The surfaced row names **which** lifecycle violation fired. | The response carries the `z_session_lifecycle:<reason>` discriminator, not just "quarantined". Asserted for at least two *different* violations so the field is proven to vary rather than being a constant. |
| **16-C** | The surfaced row names its **recovery path**. | The response tells the operator that `fiscal:enqueue-resolved-event-projections` is the action for this row — because that command genuinely works on it (`EnqueueResolvedEventProjectionsCommand.php:245`) and the ONLY thing missing today is that nobody is told. |
| **16-D** | **No ingestion decision changes.** `OutboxIngestor` keeps classifying these as `sequence_gap` and keeps suppressing projections at `:922-924`. | `git diff` over `OutboxIngestor.php` shows **zero** behavioural lines, or every line is justified in the milestone report. Rationale: suppressing projections for a lifecycle-invalid Z session is *correct* — projecting it would write wrong aggregates. ES-16 is a visibility gap, not a suppression bug. |
| **16-E** | The existing two partitions keep their exact current contents. | The canonical-parse-failure partition and the dead-lettered-projection partition are each pinned with an unchanged-behaviour test, so ES-16's row is proven **additive** and not a re-partitioning that quietly moves other rows. |
| **16-F** | The new partition is **tenant-scoped** exactly like the existing two. | A second tenant's `z_session_lifecycle` row is absent from the first tenant's response. Both existing partitions filter on `$user->tenant_id` (`:104`, and partition 1's equivalent); the new one owes the same, asserted, not assumed. |

**What would FALSIFY this contract** (the reviewer should reject the implementation if any holds):

- **F16-1** — the row becomes visible by **weakening** partition 2's `canonical_parse_failure`
  predicate (e.g. dropping it, or widening to "any quarantined class"). That drags
  `canonical_hash_mismatch` / `time_anomaly` / `sequence_gap` rows that are *not* ES-16 into a
  surface built for parse failures, and the register does not authorise it.
- **F16-2** — the implementation makes the suppressed projections fire after all (unsuppressing
  `:922-924`). That writes Z aggregates the ingestor deliberately refused to write; it is a
  different, larger change and it is not ES-16.
- **F16-3** — the "red-first" is a test written against a hand-inserted row whose shape the real
  ingestor never produces (e.g. exception class `canonical_parse_failure` with a
  `z_session_lifecycle:` reason — an impossible combination given `:745-752`).
- **F16-4** — clause 16-B is satisfied by a hard-coded string rather than a value read from
  `integrity_exception_reason`; provable by the two-violation assertion in 16-B.
- **F16-5** — the response shape changes for rows the two existing partitions already return,
  breaking the consumer contract, without that being called out and re-reviewed.
- **F16-6** — `ParseFailureResolutionService` is extended to accept these rows. It cannot resolve
  them (there is no bad payload to correct — the payload parsed) and doing so would grant an
  operator a payload-rewrite on a sealed row for a reason unrelated to parsing. That is squarely
  the **D-8** surface and is out of this wave entirely.

**Explicitly OUT of ES-16's contract:** any change to the seven lifecycle rules themselves; any
new `IntegrityExceptionClass` case (that is an enum + CHECK-constraint + migration change, and the
register does not ask for it); any automatic remediation. ES-16 asks for **visibility**, and the
strongest version of this contract is the one that adds no new decision anywhere.

---

## ES-17 — `fiscal_event_quarantine` has no resolution path at all

### The defect, restated from code

`fiscal_event_quarantine` holds envelopes that could not enter `fiscal_events` at all —
`sequence_conflict` (`OutboxIngestor.php:1114-1119`) and `malformed_envelope`
(`OutboxIngestor.php:335-341`). The table is documented as **mutable**, precisely so a resolution
flow can stamp it:

> *"Unlike `FiscalEvent`, this table is MUTABLE — the resolution flow writes `resolved_at` /
> `resolved_by` when an admin clears the incident."* — `FiscalEventQuarantine.php:20-22`

That resolution flow does not exist. Both columns are declared (`:57-58`), cast (`:151`),
deliberately non-fillable (`:84-90`) — and **only ever read**:

| Reader | `file:line` | What it does with it |
|---|---|---|
| `VerifyEventChainCommand` | `:843`, `:856` | reports every `resolved_at IS NULL` row as a chain incident — so an unresolvable row makes the chain verifier permanently non-green |
| `Nf525DataProvider` | `:1571`, `:1603-1605` | exports the stamp into the §8 audit section |

A repository-wide sweep for a writer (`grep -rn "resolved_at\|resolved_by" app/`, excluding the
unrelated `integrity_resolved_*` columns on `fiscal_events` and the unrelated
`FraudAlert::resolved_at`) finds **none**. Consequence, and this is the part that matters for the
A0 exit statement: **one `sequence_conflict` envelope makes `fiscal:verify-event-chain` return
exit 1 for that terminal forever**, because the only condition that clears the incident is a column
nothing writes. The command this whole wave exists to make trustworthy has a permanently-red state
with no exit.

### PROPOSED CONTRACT — ES-17

> **The fix must give an authorised operator a way to record that a `fiscal_event_quarantine`
> incident has been adjudicated — stamping `resolved_at` / `resolved_by` — such that the chain
> verifier stops reporting it, WITHOUT the stamp implying anything about the envelope's contents
> and WITHOUT any envelope ever entering `fiscal_events` as a side effect.**

Clause by clause — what M3b must **demonstrate**:

| # | Clause | Demonstration required |
|---|---|---|
| **17-A** | An authorised operator can stamp `resolved_at` + `resolved_by` on a quarantine row, and both land together. | Red-first: a test proving no path exists today, then the stamp asserted on the row. Both columns non-null in the same write, or neither — a half-stamped row is an incident state nothing describes. |
| **17-B** | The stamp is **explicit lifecycle code, never mass assignment.** | Asserted against `FiscalEventQuarantine.php:84-90`, which deliberately keeps both columns out of `$fillable`. An implementation that adds them to `$fillable` violates the model's stated boundary discipline and fails this clause. |
| **17-C** | After the stamp, `fiscal:verify-event-chain` stops reporting that row. | End-to-end: the command exits **1** with the quarantine incident before, and **0** after (all other checks clean), on the same fixture. This is the clause that closes the permanently-red state; the `whereNull('resolved_at')` predicate at `VerifyEventChainCommand.php:856` is the seam. |
| **17-D** | The stamp changes **nothing else**. | The quarantined envelope is NOT copied into `fiscal_events`; `canonical_bytes`, `current_hash`, `claimed_sequence_number`, `raw_envelope` and both classification columns are byte-identical before and after; the conflicting event that occupies the slot is untouched. Asserted column by column. |
| **17-E** | The action is **permission-gated**, reusing an existing seeded permission. | Named candidate: `fiscal.events.resolve_quarantine`, which already exists and already gates the sibling parse-failure resolver (`ParseFailureResumeTest.php:138`). An unauthorised principal is refused **and persists nothing** (row unstamped after the refused call). **If the fixture shows the intended principal does not hold it, STOP `blocked_owner` rather than inventing a permission** — the same rule M4 carries for ES-42. |
| **17-F** | Tenant-scoped. | A resolver in tenant A cannot stamp tenant B's quarantine row. Asserted, not assumed. |
| **17-G** | Idempotent / non-destructive on an already-resolved row. | Re-stamping either no-ops or refuses; it must not silently overwrite the original `resolved_by`, which is the audit fact the §8 export publishes (`Nf525DataProvider.php:1603-1605`). |

**What would FALSIFY this contract:**

- **F17-1** — the resolution **admits the quarantined envelope into `fiscal_events`**. That is a
  chain write, it would need a sequence slot that is by definition already occupied, and nothing in
  the register asks for it. ES-17 asks for *adjudication*, not *ingestion*.
- **F17-2** — the stamp is presented as a correctness claim about the envelope ("verified",
  "accepted"). It is not one: `sequence_conflict` means two different events claimed one slot, and
  recording that a human looked at it says nothing about which was right. Any naming, response
  field, or audit text asserting correctness fails this clause.
- **F17-3** — `resolved_at` / `resolved_by` are added to `$fillable` (fails 17-B) or written
  through a request-bound mass assignment.
- **F17-4** — 17-C is demonstrated by making the **verifier** ignore quarantine rows instead of by
  stamping them. That deletes a control this wave just finished hardening and would be the exact
  "verification theater" theme T5 exists to close.
- **F17-5** — the refusal half of 17-E is asserted only on the HTTP status and not on persistence.
  A 403 that still stamped the row is a failed contract, and the register's own framing of refusal
  contracts (brief R-4) is two-sided.
- **F17-6** — a new permission, role seeder change, or `permission:cache-reset` is introduced
  without an owner ruling. Reuse or STOP.
- **F17-7** — the implementation also builds an approval / second-approver flow. That is **D-8**,
  owner-gated, and out of this wave. ES-17's stamp is a single-actor adjudication record; if M3b's
  work turns on whether a second approver is required, it must STOP `blocked_owner` naming D-8
  rather than design it provisionally.

**Explicitly OUT of ES-17's contract:** any front-end surface (the register describes a backend
gap and no FE lens is loaded on this wave); resolving the *underlying* sequence conflict; any
change to what `OutboxIngestor` routes into the table; the `malformed_envelope` vs
`sequence_conflict` classification itself.

---

## Sequencing note for the reviewer

Both contracts are deliberately scoped so that **neither requires touching `ZReportHashService`**
(R-1, zero-diff for this wave), neither requires a migration, neither adds a queue (rule 20),
and neither touches money or quantity arithmetic (rule 19 stays N/A). ES-17 is the one with a
real chance of colliding with **D-8**: if its adjudication turns out to require a second approver,
the correct outcome is a STOP, not a provisional design.

If the reviewer amends a clause, M3b implements the **amended** clause and cites it verbatim in
its clause-to-code mapping (brief line 594).
