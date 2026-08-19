# M3 — proposed verification contracts for the ES-16 / ES-17 stragglers

**Status: PROPOSAL (revision 2 — amended in M3 fix round 1). Nothing in this document is
implemented.** M3's bridge review approves,
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

**Revision 2 (M3 fix round 1)** carries exactly three edits, all inside ES-16, all in response to
the M3 round-1 register (`M3-round1.md`):

| Register finding | Edit |
|---|---|
| **F-2** | Clause **16-C** rewritten — the recovery-path clause is dropped (option (i) of the two the register offered) and the amendment reasoning is recorded under the clause table; falsifier **F16-7** added; defect-narrative item **5** corrected in place, because it carried the same false "recovery works" premise 16-C was built on. |
| **F-3** | Clause **16-F**'s citation corrected `:104` → `:106` (`:104` is `if ($projectorFilter === null) {`; the tenant filter is `:106`). |

ES-17 is **unchanged** — the register approved its contract as written.

**Revision 2a (M3b opening commit)** carries **one presentation-only edit**, recorded here rather
than made silently: the M3 round-2 register's finding **N-3** noted that revision 2 inserted
**F16-7** between F16-5 and F16-6, so the list did not read in sequence, and offered "renumber or
reorder" as a cosmetic rider. F16-7 is **moved to sit after F16-6**. No falsifier text changed, no
clause changed, no falsifier added or removed — ES-16 still has 6 clauses and 7 falsifiers, and the
contract M3b builds to is the one approved at M3 round 2.

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
5. **There is no safe recovery path today.** The one command that *would* act on the row is
   `fiscal:enqueue-resolved-event-projections`, whose only filters are
   `payload_parse_status = parsed` (`EnqueueResolvedEventProjectionsCommand.php:244-245`), an
   optional event id (`:247-250`) and `tenant_id` (`:254`) — which this row satisfies. But there is
   **no `integrity_status` / `integrity_exception_class` filter**, and
   `createMissingPendingRows()` (`:340-365`) inserts a pending row for every active projector
   without re-checking the suppression at `OutboxIngestor.php:922-924`. So running it on a
   `z_session_lifecycle` row does not recover anything — it **creates and dispatches precisely the
   projections the ingestor deliberately refused**, i.e. it writes the wrong Z aggregates that
   clause 16-D calls incorrect and that falsifier F16-2 forbids. It is F16-2 executed by hand.

   *(Amended in M3 fix round 1, register finding F-2. The original text of this item asserted
   "recovery works", and clause 16-C built an operator affordance on that assertion. Both were
   wrong in the same way and both are corrected here; the correction is recorded in place rather
   than by silent edit, per this program's honesty lane.)*

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
| **16-C** *(amended, M3 fix round 1 — see below)* | The surfaced row **names no recovery command at all.** ES-16 is a pure visibility contract: it reports the row and stops. | The response carries **no** remediation/recovery/next-action field naming `fiscal:enqueue-resolved-event-projections` or any other command, and no UI copy, help text or log line added by M3b does so either. Asserted as an absence, not assumed. |
| **16-D** | **No ingestion decision changes.** `OutboxIngestor` keeps classifying these as `sequence_gap` and keeps suppressing projections at `:922-924`. | `git diff` over `OutboxIngestor.php` shows **zero** behavioural lines, or every line is justified in the milestone report. Rationale: suppressing projections for a lifecycle-invalid Z session is *correct* — projecting it would write wrong aggregates. ES-16 is a visibility gap, not a suppression bug. |
| **16-E** | The existing two partitions keep their exact current contents. | The canonical-parse-failure partition and the dead-lettered-projection partition are each pinned with an unchanged-behaviour test, so ES-16's row is proven **additive** and not a re-partitioning that quietly moves other rows. |
| **16-F** | The new partition is **tenant-scoped** exactly like the existing two. | A second tenant's `z_session_lifecycle` row is absent from the first tenant's response. Both existing partitions filter on `$user->tenant_id` (`:106`, and partition 1's equivalent); the new one owes the same, asserted, not assumed. |

#### Amendment to 16-C — M3 fix round 1, register finding F-2

**Option (i) of the two the register offered is taken: the recovery-path clause is DROPPED and
ES-16 is a visibility contract only.** Recording the reasoning so M3b does not re-derive the
rejected version:

- **The original 16-C was self-contradicting.** It required the surfaced row to advertise
  `fiscal:enqueue-resolved-event-projections`, on the premise that the command "genuinely works on
  it". The premise is false in the only sense that matters. The command applies no
  `integrity_status` filter (`EnqueueResolvedEventProjectionsCommand.php:244-245`, `:247-250`,
  `:254`) and `createMissingPendingRows()` (`:340-365`) re-checks no suppression, so it re-creates
  exactly the projections `OutboxIngestor.php:922-924` refused. Clause **16-D** calls that outcome
  writing "wrong aggregates" and falsifier **F16-2** makes it a rejection trigger. A contract cannot
  forbid the implementation from producing those projections and simultaneously require it to
  advertise the command that produces them.
- **Option (ii) — "correct only after a human has adjudicated the lifecycle violation" — is
  explicitly NOT taken.** Adjudicating a suppressed fiscal row is a new decision on sealed data and
  is D-8-adjacent; it would owe its own STOP and its own owner gate, and neither is in this wave.
- **This is consistent with the contract's own closing sentence**: *"the strongest version of this
  contract is the one that adds no new decision anywhere."* Visibility alone already closes the
  register's blind spot — an operator who can see the row can escalate; an operator who is told to
  run the command will corrupt the Z aggregates.
- **What M3b must NOT do instead:** do not add the missing `integrity_status` precondition to
  `fiscal:enqueue-resolved-event-projections` as part of ES-16. That is a change to a recovery
  command's semantics, it is not asked for by the register, and it belongs to whatever lane
  eventually owns the lifecycle-adjudication decision. M3b's diff over
  `EnqueueResolvedEventProjectionsCommand.php` must be **zero lines**.

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
- **F16-7** *(added M3 fix round 1, finding F-2)* — the surfaced row, or any copy/doc/log line M3b
  adds around it, names `fiscal:enqueue-resolved-event-projections` (or any other command) as the
  action for a `z_session_lifecycle` row. Running that command on such a row is F16-2 performed by
  the operator instead of by the code, and the contract must not route anyone toward it. Equally
  falsifying: M3b makes the command safe by adding the missing `integrity_status` precondition —
  correct-looking, but it is a semantics change to a recovery command that ES-16 does not authorise.

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
