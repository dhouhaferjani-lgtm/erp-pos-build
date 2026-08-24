# Gate record — Session B lane Q-6 (pos_receipts trigger), fiscal-pos-reviewer r2

Commits `ad2c13325` (r1 target) + `c85875c73` (fix round). Base = local `dev` at `83448e0bd`;
`dev` tip has since moved to `850e86217` (Q-2 merge). Worktree
`.worktrees/sb-q6-receipt-trigger`, branch `fix/sb-q6-voided-receipt-trigger`. Diff = 3 files:
one tenant migration, one PG-only test class, the lane manifest.

**Verdict: spec ✅ + quality APPROVED (ACCEPT-with-conditions).** Every r1 fix-round claim was
independently re-executed against a throwaway PG 16 DB and holds. Two NEW findings (F-6, F-7),
neither a regression from this lane; both are reported for parent ruling, not merge blockers.

## Per-finding disposition (r1 F-1..F-5)

- **F-1 [Critical, folded] — CLOSED, independently re-proven.**
  Fix is `2026_08_23_160000_harden_pos_receipt_immutability_trigger_whitelist.php:235-236`
  (`AND NEW.fiscal_status = OLD.fiscal_status` / `AND NEW.is_voided IS NOT DISTINCT FROM
  OLD.is_voided` on the FK-cleanup branch).
  - RED re-executed on the PRE-lane body (installed from `2026_07_31_940000`'s up()): the full
    round trip succeeded with no exception at any step — STEP 1 `partner_id=NULL, contact_id=NULL,
    fiscal_status='pending_seal'` → STEP 2 `total=9999.000, fiscal_hash=repeat('e',64)` → STEP 3
    `fiscal_status='fiscalized'`; final row read back `fiscalized | 9999.000 | eeee`.
  - GREEN on the lane body: STEP 1 now raises `Receipt GATE-201 is fiscally sealed and cannot be
    modified…` at plpgsql line 124, so STEPs 2/3 are unreachable. The `is_voided`-flip variant
    raises identically.
  - Legitimate edges still green: FK-cleanup-only UPDATE on a fiscalized row succeeds
    (`partner_nulled=t, fiscal_status=fiscalized`); PG's own `ON DELETE SET NULL` cascade from a
    `partners` hard DELETE still reaches a fiscalized receipt and nulls `partner_id`.
  - Tests: `PosReceiptFrozenStateImmutabilityTriggerTest:571` (status downgrade) and `:603`
    (`is_voided` flip), companion legit pin at `:537`, cascade pin at `:679`.
  - Accuracy note (no action): the test at `:571` executes STEP 1 only; STEPs 2/3 live in comments
    at `:585-589` because STEP 1 now raises. That is the correct design, but the commit message's
    "red-first test of the exact round trip" overstates what the test executes — the gate executed
    the full round trip out-of-band (above).

- **F-2 [Important, parent-RULED keep-freeze + pin] — CLOSED.**
  Docblock paragraph "PG's own FK cascade is a DELIBERATELY-REFUSED writer on frozen rows" at
  migration `:97-122`; refusal pinned by test `:642`, asymmetry pinned by test `:679`.
  - FK shape verified at the DB level: `pos_receipts_partner_id_foreign … REFERENCES partners(id)
    ON DELETE SET NULL` and `pos_receipts_contact_id_foreign … REFERENCES contacts(id) ON DELETE
    SET NULL`.
  - Re-executed: `DELETE FROM partners` against a VOIDED referencing receipt aborts with
    `Receipt GATE-202 is frozen in fiscal_status 'voided'…` (PG reports the cascade statement
    `UPDATE ONLY "public"."pos_receipts" SET "partner_id" = NULL …`); the row keeps its
    `partner_id`. Same DELETE against a FISCALIZED receipt succeeds. Under the pre-lane body the
    same DELETE silently succeeded and nulled `partner_id` on the voided row.
  - Latency claim re-verified: no `fiscal_status='voided'` / `is_voided=true` writer exists on
    `pos_receipts` anywhere in `app/` (the only `FiscalStatus::Voided` write is
    `DocumentPostingService.php:240`, on the `documents` table, not `pos_receipts`).
  - Minor residual: the docblock and both tests pin `partners` only; `contacts` carries the
    identical `ON DELETE SET NULL` and is unpinned. One-line test gap, not a defect.

- **F-3 [Minor → residual] — STILL OPEN, correctly untouched.** No CHECK constrains
  `sealed_hash_algorithm`; the gate wrote `'forged_v9'` through the allowance on a probe row. Carry
  as the program ticket r1 opened (`CHECK (sealed_hash_algorithm IN (…))` closes it repo-wide).

- **F-4 [Minor → FOLDED] — CLOSED, independently re-proven.**
  Migration `:277-283`: the 18-column enumeration is replaced by
  `(to_jsonb(NEW) - 'sealed_hash_algorithm' - 'updated_at') IS NOT DISTINCT FROM (to_jsonb(OLD) - …)`.
  - RED on the pre-lane body: `UPDATE … SET sealed_hash_algorithm='legacy_pipe_v1',
    previous_hash=repeat('9',64)` SUCCEEDED — row read back `legacy_pipe_v1 | 999`.
  - GREEN on the lane body: the same statement raises `… is fiscally sealed and cannot be
    modified…`, and `previous_hash` is unchanged (`111`).
  - The transition it exists to permit still passes: `sealed_hash_algorithm` + `updated_at` on a
    row carrying a non-null `previous_hash` succeeds. Tests `:721` / `:741`.
  - Claim (4) verified in code: `BackfillSealedHashAlgorithmCommand.php:233-234` sets
    `sealed_hash_algorithm` and calls `save()` — i.e. exactly `sealed_hash_algorithm` +
    `updated_at`. `Receipt` has no observer and no `saving`/`updating` hook.

- **F-5 [residual] — STILL OPEN, correctly untouched.** `pos_receipts` still carries only
  `enforce_receipt_immutability` (`BEFORE DELETE OR UPDATE`); `fiscal_events` and
  `repository_movements` each additionally carry a `BEFORE TRUNCATE … FOR EACH STATEMENT` guard.
  Program residual.

## New findings

- **[Important] F-6 — migration `:198-207` (carried forward verbatim from the pre-lane body,
  PRE-EXISTING, not a regression): the `fiscalized → voided` edge's immutable-field check guards
  only 7 columns** (`fiscal_hash`, `receipt_number`, `total`, `subtotal`, `tax_amount`,
  `chain_sequence`, `posted_at`). Everything else may be rewritten ON that edge, and the row is
  then frozen forever with the rewritten bytes. Gate probe P20, executed on the lane body:
  a single UPDATE moved a fiscalized receipt to `voided` while rewriting `previous_hash` →`999…`,
  `canonical_bytes` →`\xdeadbeef`, `sealed_hash_algorithm` →`forged_v9`, `vat_breakdown_hash`
  →`ddd…` — accepted, and the row is now immutable in that forged state. Why it matters: after
  F-1 and F-4 were tightened, this is the widest remaining hole on the `fiscalized` arm, and it
  touches the chain link (`previous_hash`) and the NF525 component hashes. Suggested fix (follow-up
  lane, red-first + re-gate — do NOT fold here): apply the same `to_jsonb - keys` technique with
  the void bookkeeping columns subtracted (`is_voided`, `voided_at`, `voided_by`, `void_reason`,
  `void_receipt_id`, `fiscal_status`, `updated_at`). The brief explicitly required the void edge be
  carried byte-for-byte, so the lane is compliant; this is a program residual.

- **[Important] F-7 — CI reachability: the finisher's residual is CONFIRMED.**
  `grep -c PosReceiptFrozenStateImmutabilityTriggerTest .github/workflows/ci.yml` = **0**, while
  its sibling `PosReceiptImmutabilityTriggerSealedHashAlgorithmTest` IS named in the live
  `backend-test-pgsql` `--filter` allowlist (`.github/workflows/ci.yml:958`). Its declared lane
  `feature-lane-fiscal-finance` is PARKED (`ci.yml:2577` `ALLOW_SKIPPED_JOBS`). Net: the ONLY
  regression pin on the receipt trigger's whitelist — including the F-1 Critical bypass pin —
  executes in no live CI job. Per S-14 the gate did not touch `.github/**`; reporting only. Parent
  ruling wanted: name the class in the pgsql allowlist, or accept a locally-verified-only pin.

- **[Minor] F-8 — migration `:146-147` says the local fleet is "10 databases (`autoerp` + 9
  `tenant<uuid>` DBs)".** The local fleet on 5433 is now **11** (`autoerp` + 10 `tenant…` DBs); a
  tenant DB appeared since r1. Result is unchanged (zero rows in all four states). Stale count in a
  docblock, no behavioural impact.

- **[Minor] F-9 — the fix-round claim "down()/up() round trip byte-identical" is not literally
  true.** After `up()` then `down()`, `md5(pg_get_functiondef(...))` differs from the pre-lane
  installed definition (`a4fc49c7…` → `7b110cc5…`). The delta is EXCLUSIVELY the 22-line SQL
  comment block from `2026_07_31_940000`'s up() (`-- v3-refund-chain-integration spec §6.2 …
  FINAL-REVIEW CONDITION 1 (I-1) …`), which `down()` does not reproduce. Executable body verified
  line-by-line identical with comments and whitespace normalised. No fix required; restate the
  claim as "executable body identical, SQL comments not reproduced".

## Gate verified

Branch-by-branch account of the r1→r2 function body (every branch traced):
`pending_seal` arm — carried; `fiscalized` (1) void edge — carried verbatim; `fiscalized`
(2) FK-cleanup — TIGHTENED (F-1, +2 lines at `:235-236`); `fiscalized` (3) discriminator —
FOLDED (F-4, 18-column list → `to_jsonb - keys` at `:279-281`); `fiscalized` ELSE raise —
carried; frozen arm (voided/pending_sync/synced/sync_failed) — new, strict no-op + carried
discriminator allowance; `ELSE RAISE` — new; DELETE arm and trailing `RETURN NEW` — carried.
The r1→r2 diff touches nothing else: `git diff ad2c13325 c85875c73` = docblock + those two
condition edits + 5 new tests + manifest.

Out-of-band probe battery on throwaway DB `autoerp_gate_q6r2` (PG 16.10 on 5433, schema cloned
from `autoerp`, non-partner FKs dropped so the partner/contact `ON DELETE SET NULL` cascade stays
live; DB DROPPED at end of gate). 20 probes, all as expected:
RED on pre-lane body — F-1 full round trip succeeds, F-4 `previous_hash` smuggle succeeds, F-2
cascade silently nulls a voided row, `touch()`-only write on a voided row succeeds.
GREEN on lane body — P1/P2 F-1 raise · P3 legit FK cleanup succeeds · P4 F-2 refusal · P5
fiscalized cascade succeeds · P6 7th status `quarantined` hits `no UPDATE policy` ELSE RAISE ·
P7 `pending_seal → fiscalized` succeeds · P8 `pending_seal` self-edit unrestricted succeeds ·
P8b `pending_seal → voided` raises with its own message · P9 `fiscalized → voided` succeeds ·
P10 void + immutable-field rewrite raises · P11 fiscalized backfill (algo + updated_at) succeeds ·
P12 F-4 smuggle raises · P13 voided strict no-op succeeds · P14 voided `updated_at`-only raises
(the pinned `touch()` decision, migration `:62-68`, test `:272`) · P15 voided backfill succeeds ·
P16 second discriminator write raises · P17 DELETE on voided raises · P18 INSERT in a frozen
state untouched · P19 `pending_sync`/`sync_failed` reject a total rewrite ·
P20 the void-edge hole (F-6, above).

Test runs BY PATH on PG 5433 (throwaway DB, `phpunit-pgsql.xml`):
- `tests/Feature/Fiscal/PosReceiptFrozenStateImmutabilityTriggerTest.php` — **41 passed
  (99 assertions)**, 0 failed. Confirms the fix-round's "3 reds → 41/41". `setUp():74` does
  `app(CompanyContext::class)->clear()` (rule 20 satisfied).
- `PosReceiptImmutabilityTriggerSealedHashAlgorithmTest.php` + `BackfillSealedHashAlgorithmCommandTest.php`
  + `ImmutabilityTriggerPresenceTest.php` — **25 passed (77 assertions)**, 0 failed. I-1 holds a
  fortiori under the F-4 swap, including `a voided row interspersed does not break discrimination`.
- `Nf525VerifyChainParityTest.php` + `ReceiptChainRebuildTest.php` + `ReceiptFinalizationServiceTest.php`
  — 27 passed, 3 skipped, **3 failed**, all three in `ReceiptFinalizationServiceTest`
  (`finalize is idempotent on already fiscalized receipt`, `finalize throws on voided receipt`
  [`pos_receipts_void_logic` CHECK violation], `finalize v3 produces canonical hash from fixture 01`).
  **INHERITED — independently re-proven**: the identical 3 failures / 2 passes reproduce on the
  dev checkout at `850e86217` against its own scratch DB, with the lane's migration absent. Parity
  + rebuild alone = 25 pass / 3 skip, matching r1.
- PHPStan level 8 on both changed backend files: `[OK] No errors`.
- Pint `--test` on both changed backend files: `{"result":"pass"}`.

Census (docblock SQL, re-run by the gate on every local DB carrying `pos_receipts`, PG 5433,
2026-08-24) — `SELECT fiscal_status, COUNT(*) FROM pos_receipts WHERE fiscal_status IN
('voided','pending_sync','synced','sync_failed') GROUP BY 1;`

| database | pos_receipts rows | rows in the four frozen states |
|---|---|---|
| autoerp | 0 | 0 |
| tenant019fbe86-944a-7252-8a3b-8c341dfa9de9 | 5 | 0 |
| tenant019fcf48-49a3-7230-aaf5-1c5daa44b3b3 | 0 | 0 |
| tenant019fe276-750a-709d-8968-d1364e3459b6 | 0 | 0 |
| tenant01a01b77-21a0-73f7-a893-96f689fbe815 | 1 | 0 |
| tenant01a03028-9470-70e6-83ca-cdc354f17cf1 | 0 | 0 |
| tenant01a033c6-3f61-73ce-b7f4-026b75656b71 | 0 | 0 |
| tenant3f16ac36-1cc6-4a5d-82bd-4f14831be040 | 0 | 0 |
| tenant4c3a1260-ed30-4ee6-8755-a9d823d61403 | 0 | 0 |
| tenantbe3cd47a-e4a1-4941-8b77-dde33f6ca4ce | 0 | 0 |
| tenantf6c592ac-2199-4095-96b4-e4244442dd80 | 0 | 0 |

**11 databases, 6 receipt rows total, ZERO rows in all four frozen states.** No local row changes
behaviour under this migration. (F-8: the docblock still says 10 databases.)

Live-writer re-verification (docblock claim): the only server writes of `pos_receipts.fiscal_status`
are `PosCoreReceiptProjection.php:398` (`Fiscalized`), `ReceiptCreationService.php:572-584`
(`PendingSeal`, or `Fiscalized` when training), `ReceiptFinalizationService.php:117` (`Fiscalized`),
`ReceiptReturnService.php:821` (`PendingSeal`). Nothing writes any of the four frozen values, and
nothing writes `pos_receipts.is_voided = true`. No `void_receipt_id` writer exists in `app/`
(only the fillable entry `Receipt.php:182` and the relation at `:351`).

## Manifest union (dev tip has moved)

- dev `850e86217`: `gated_ceiling` **1151** · Fiscal **79** · POS **150** · Inventory **111**.
- branch (base `83448e0bd`): `gated_ceiling` **1146** · Fiscal **80** · POS **149** · Inventory **108**.
- The branch's only manifest delta vs its own base is `gated_ceiling` 1145→1146 and Fiscal 79→80
  (+ the Fiscal `note` attribution paragraph). POS 149→150 and Inventory 108→111 are dev-side
  moves (Q-1, Q-2) the branch has not seen.
- **Post-merge values MUST be: `gated_ceiling` = 1152, Fiscal.classes = 80, POS = 150,
  Inventory = 111.** The manifest WILL conflict on merge (both sides edited `gated_ceiling` and the
  `Fiscal` block); resolve by taking dev's numbers everywhere, then applying +1 to `gated_ceiling`
  (1151→1152) and Fiscal 79→80, and keeping the branch's Fiscal `note` text.
- Checker in-branch: `php apps/api/tools/feature-lane-manifest-check.php` → **EXIT=0**
  ("tests/Feature lane manifest OK — 1380 Feature classes in 74 groups"). It MUST be re-run after
  the merge resolution — the gate cannot vouch for the post-merge file.

## Scope

Migration-only lane. Three files, all listed above. Nothing on Session A's collision matrix:
no X/Z report surface, no VAT resolution, no `StockLevel`, no PIN/`has_pins`, no
`apps/web` document pages, no `.github/**`, no `apps/pos`. No PHP application code changed.

## MIGRATION-BEARING — promotion obligations (verbatim)

1. **Per-tenant census before promotion.** Run, on EVERY staging and production tenant database:
   `SELECT fiscal_status, COUNT(*) FROM pos_receipts WHERE fiscal_status IN
   ('voided','pending_sync','synced','sync_failed') GROUP BY 1;`
   A non-zero count is NOT an abort — it is the population that becomes frozen, and (for rows with
   `sealed_hash_algorithm IS NULL AND fiscal_event_id IS NULL`) the population the carried-forward
   backfill allowance keeps writable. Record the counts alongside the deploy.
2. **The DDL itself cannot fail on data.** `CREATE OR REPLACE FUNCTION` only; no constraint, no
   index, no data validation, no row touched. Safe on non-zero counts.
3. **`down()` re-opens every hole this migration closes** (the four unguarded states, the F-1
   FK-cleanup bypass, the F-4 18-column enumeration). A rollback is a fiscal-integrity regression,
   never a routine step. Do not schedule it as a standard back-out.
4. **Push to `origin/dev` auto-deploys staging including `tenants:migrate`** — this migration is
   self-guarding (`getDriverName() !== 'pgsql'` early return) and has no manual prerequisite, so no
   pre-push step is owed. Confirm the per-tenant migrate log after the deploy.
5. **Re-run `php apps/api/tools/feature-lane-manifest-check.php` after the merge conflict
   resolution** and confirm EXIT=0 with `gated_ceiling` 1152 / Fiscal 80.
6. **CI-UNVERIFIED (S-17)** and, additionally, **CI-UNREACHABLE (F-7)**: the lane's regression pin
   runs in no live CI job. All test evidence in this record is local, on PG 16.10 / port 5433.
