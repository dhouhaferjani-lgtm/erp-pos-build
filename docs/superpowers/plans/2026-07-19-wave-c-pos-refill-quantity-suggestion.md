# Wave C — POS Refill Quantity Suggestion Implementation Plan

> Execute from branch `feat/pos-replenishment-suggested-qty`, based on local
> `dev` at `28c422cc8`. Use strict TDD and commit after every green cycle.

**Goal:** Compute the approved order-up-to quantity on the server, carry it
through the snake_case POS feed and SQLite cache, and prefill the optional,
editable refill quantity input.

**Design:**
`docs/superpowers/specs/2026-07-19-wave-c-pos-refill-quantity-suggestion-design.md`

## Cycle 1 — Backend feed enrichment

**Tests first:**

- Extend `apps/api/tests/Feature/POS/PosReplenishmentControllerTest.php` with
  exact-grain cases for max-minus-available, min fallback, floor-one, no stock
  row, variant isolation, and closed-row null.
- Extend the wire-structure assertion to require `suggested_qty`.
- Run only that test file and preserve the failing output.

**Implementation:**

- Add an Inventory public application service that bulk-loads stock rows for
  one location and a bounded set of product/variant grains, calculates scale-4
  suggestions, and returns a typed key-to-numeric-string map.
- Constructor-inject it into `ReplenishmentQueryService`; enrich open feed rows
  after the capped query, leaving closed rows null.
- Emit `suggested_qty` from `ReplenishmentRequestResource`.
- Run the targeted PHPUnit file, Pint on touched PHP files, and PHPStan on the
  touched backend seams.
- Commit the green cycle.

## Cycle 2 — POS wire, migration, and cache

**Tests first:**

- Add a v61 replay test under `apps/pos/src/lib/db/__tests__/` that proves the
  new nullable TEXT column, pre-existing-row preservation, and idempotence.
- Extend the replenishment repository round-trip test to pin
  `suggested_qty` persistence and update behavior.
- Extend `replenishmentSyncService.test.ts` with a valid suggestion round trip
  and invalid/missing `suggested_qty` envelope cases.
- Run only those POS test files and preserve the failing output.

**Implementation:**

- Append SQLite migration v61; do not edit v60.
- Add `suggested_qty: string | null` to `ServerReplenishmentRow`, repository
  input/cache types, INSERT/UPSERT, and the sync runtime guard.
- Run the targeted Vitest files and POS typecheck.
- Commit the green cycle.

## Cycle 3 — Editable sheet prefill

**Tests first:**

- Extend `RequestRefillSheet.test.tsx` to prove a cached suggestion prefills
  the quantity, is submitted when unchanged, remains editable, blank-falls
  back when null, and does not overwrite an edit made before the cache promise
  resolves.
- Run that component test file and preserve the failing output.

**Implementation:**

- Initialize the sheet quantity from the matching cache row only when a
  suggestion exists and the user has not edited the field during the lookup.
- Keep the existing optional validation and submission path unchanged.
- Run the targeted component test, POS typecheck, touched-file lint, and React
  Doctor against base `28c422cc8`.
- Commit the green cycle.

## Wave C gate

- Re-run all Wave C backend/POS test files together.
- Run targeted PHPStan/Pint, POS typecheck, and touched-file lint.
- Run a scope-diff audit from `28c422cc8`, check for generated/build artifacts,
  and confirm the worktree is clean.
- Squash the green-cycle commits into one repository-style `Phase` commit
  without changing the tree, then re-run the critical verification.
- Hard-stop with the gate report: commits, files, behavior/contract proof,
  commands and actual results, deviations, deploy note, and review focus.
- Deploy note: run the brief's standard `php artisan tenants:migrate`, then
  deliver the POS device update for SQLite v61; no permission seeder/cache
  action is introduced by Wave C.
- Do not merge, push, or begin Wave D before owner review.
