# Gate record — Session B lane Q-6 (pos_receipts trigger), fiscal-pos-reviewer r1

Commit `ad2c13325`. **Verdict: CHANGES-REQUESTED** (whitelist rewrite genuinely correct;
carry-forward proven character-identical; to_jsonb freeze right technique; red evidence
reproduces by revert-probe; census independently re-run — 0 rows all 10 local DBs).

**RULING (gate): FOLD residual-1 into this lane** — the `fiscalized → pending_seal` bypass via
the FK-cleanup branch is Critical (gate got the FULL round trip: walk back → rewrite total +
forge hash → re-seal, no exception at any step; pre-existing across 4 prior function bodies).
Two-line fix (`AND NEW.fiscal_status = OLD.fiscal_status AND NEW.is_voided IS NOT DISTINCT
FROM OLD.is_voided` on the FK-cleanup branch) proven safe + sufficient on a scratch DB.
Splitting would institutionalize the copy-forward ritual that let it survive.

- **F-1 [Critical, fold]**: as above + red-first test of the exact round trip; legitimate FK
  cleanup + void edge pinned green (companion test exists at `:537`).
- **F-2 [Important, decide+pin]**: UNDECLARED regression — frozen arm drops the FK-cleanup
  allowance, so PG's own `ON DELETE SET NULL` cascade (partners/contacts) ABORTS on a frozen
  receipt (proven: partner DELETE fails where old body succeeded). Latent (SoftDeletes
  everywhere, zero forceDelete in app/). **PARENT RULING: KEEP the freeze** — fail-closed on a
  fiscal archival row; a future hard-delete path must abort loudly, not silently mutate. Fix
  round: docblock paragraph naming the FK cascade as a deliberately-refused writer + a test
  pinning the refusal. Asymmetry vs the fiscalized branch = program residual.
- **F-3 [Minor→residual]**: backfill allowance accepts an unvalidated discriminator value
  (bogus algo → NF525 verification DoS via ValueError) — a `CHECK (sealed_hash_algorithm IN
  (...))` would close repo-wide; pre-existing gap widened, ticket it.
- **F-4 [Minor→residual]**: fiscalized backfill branch's 18-column list leaves chain-critical
  columns (e.g. `previous_hash`) rewritable on every pre-feature row; the frozen arm's
  `to_jsonb - keys` technique would close by construction — follow-up or fold if cheap.
- **F-5 [residual]**: no BEFORE TRUNCATE guard on pos_receipts (fiscal_events +
  repository_movements both have one).

Gate verified: branch diff mechanical; down() round-trip executed; INSERT unaffected; DELETE
raises pre-branch; to_jsonb correct on NULL/bytea/jsonb, numeric normalization pre-trigger, no
json/generated columns; ELSE RAISE proven with a 7th status; inherited ReceiptFinalization reds
confirmed unrelated; chain/Nf525 parity suites 25 pass/3 skip.
