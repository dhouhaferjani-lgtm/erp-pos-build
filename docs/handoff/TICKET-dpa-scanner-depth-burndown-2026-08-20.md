# Ticket — DPA scanner depth burn-down

**Opened:** 2026-08-20, at the close of enforcement package P1 (document-per-action cementing guard).
**Owner:** alongside the baseline burn-down — the same programme that shrinks
`apps/api/tests/Architecture/baselines/document-per-action-baseline.json` from its 34 seed entries.
**Raised by:** the P1 final-gate rounds 9–10 (parent bridge) and the Codex second reviewer.

**Why this file lives in `docs/handoff/` and not in the house ticket directory:** it was first written to
`docs/superpowers/tickets/2026-08-20-dpa-scanner-depth-burndown.md`, which is the repo's normal home for
tickets but sits OUTSIDE the P1 dispatch brief's binding path allowlist (`apps/api/tests/Architecture/**` ·
`.github/workflows/ci.yml` · `docs/handoff/**`), where any other path is a scope FAIL rather than a footnote.
Final gate round 11 caught the widening; the owner ruled the out-of-allowlist path a PARENT instruction error
and directed the file be moved inside the allowlist rather than the allowlist be widened for it. When the
burn-down is picked up, relocating this ticket to `docs/superpowers/tickets/` is a free move outside the P1
package's scope.

## Why this exists

P1 shipped a static guard whose blind spots are **named in the scanner docblock (A–J)** rather than argued
away. Four of them are genuine analysis projects rather than oversights: closing them means adding real
capability to the scanner, not tightening a predicate. None has a live instance in `app/` at the closing tip —
each was verified zero — so none blocks the ratchet today, and each is a way a *future* contributor could land
an unlinked write that the guard reports as out of contract.

This ticket is the backlog for that depth. It is deliberately separate from the baseline burn-down: that one
removes known violations, this one removes ways to hide new ones.

## Items

### 1. Blind spot I — unpersisted-model paths not recognised as creates (Codex #2)

The create-by-save rule recognises `new <Model>` and `<Model>::make(...)`, directly, chained, or through a
copy alias. It does **not** recognise:

- `$entry->replicate()` and `clone $entry` — both yield an unpersisted copy whose `save()` is an INSERT;
  `clone` currently emits no site at all.
- `<Model>::firstOrNew(...)` — returns an unpersisted model when nothing matches, so the following `save()`
  is an INSERT.
- Relation-mediated creates that pass the model as an **argument** rather than a receiver:
  `$owner->journalEntries()->save(new JournalEntry)`, `->saveMany([...])`, `->make(...)`.

**Why it is not a one-liner:** the first two need clone/replicate provenance tracking; the third needs the
scanner to classify a write by an **argument's** type rather than the receiver's, which is a different
resolution axis from everything it does today.

**Exposure:** `journal_entries` only — `stock_movements` MUTATE is unconditionally a violation and the level
tables are governed by the pairing predicate regardless of write class. Verified zero live instances.

### 2. Blind spot J — pairing reachability across closures and deferred bodies (Codex #4)

The pairing predicate's scope is the whole function body, **including closures that are never invoked and
callbacks that run later**. A linked `StockMovement::create(...)` inside an uncalled closure — or inside a
`DB::afterCommit(...)` callback — credits a `stock_levels` write that sits outside that closure. The movement
may never be written, or may be written after the level change.

**Why it is not a one-liner:** proving reachability needs a call graph. Same-file, same-function scope is the
honest limit of the current analysis.

**Exposure:** both level tables. Verified zero live instances of the exploit shape.

### 3. Blind spot D residue — raw SQL through an injected connection

`$this->db->update('UPDATE …')` where `$db` is a constructor-injected `ConnectionInterface` is not matched;
only the `DB` facade is. Live in the tree at `DeliveryNoteBillingClaimService`, but targeting `documents` /
`delivery_note_billing_marks`, **not** a contract table — so it changes no classification today. An
injected-connection raw write against one of the four tables would be invisible.

### 4. CI asserts "nonzero tests", not an expected count (Codex #6, Medium)

`backend-dpa-guard` asserts `grep -qE 'OK \([1-9][0-9]* test'` on each step. That closes the
empty-selection hole (`phpunit.xml` sets no `failOnEmptyTestSuite`), but it does **not** notice a test being
deleted: removing `journal_entries_save_pins_both_semantics()` drops the guard from 6 tests to 5 and the regex
still matches.

**Suggested fix:** assert an expected minimum count in the CI step, sourced from one place so it cannot go
stale — or add a meta-test asserting the guard class declares its expected number of `#[Test]` methods. The
count is currently recorded in `enforcement-p1.progress.yaml` `m3_evidence` and in the handback, and those
records have already gone stale once (round 10 found `4` recorded while the tip ran `5`).

## Standing note

Every item above is disclosed in the shipped artifacts — items 1–3 as blind spots I, J and D in
`DocumentPerActionWriteScanner`'s class docblock, item 4 in the P1 handback. Nothing here is a surprise
discovered later; this ticket exists so the disclosures have an owner and a burn-down path rather than living
only as comments.
