# Gate record — Session B lane Q-4 (coupon cap), treasury-reviewer r2

Fix commit `52e656136` on `f09a45b80`. **Verdict: ACCEPT.**

All code-level r1 findings closed and verified by execution: F-1 refusal-return path walked on
all six closure exits (no path returns a refusal with a committed usage row/increment; no
exception path loses a seal that was written — the two exception exits occur in states where no
seal exists); probe: seal persists even when a caller catches inside an enclosing txn. F-2
savepoint probe ported; sabotage-probe (savepoint removed) bites with 25P02 exactly as claimed.
F-3 test renamed honestly. F-4 caller-contract documented with the owner ruling flagged.
F-7 narrowed classifier proven: an unrelated unique violation escapes as QueryException,
use_count untouched; sqlite branch structurally unreachable (pgsql-guarded migration).
F-8 dual-table preflight verified live dirty-both-tables: one aggregated abort naming both
offenders, Phase-1-before-any-DDL, per-side remediation still enforced, up/down idempotent.
Exception type/message contract byte-identical.

Residuals → LEDGER C-12 (promotion recordUsage; owner ruling record-and-flag vs refuse;
conditional seal durability; pre-existing no-arg getScale). Record correction (F-10): the
sub-report HIGH's validate-time premise was overstated — `Coupon::isValid()` already refused a
capped coupon; new enforcement = under-lock re-check + DB index. Coupon lane parked ⇒ all
evidence local-only (S-17). Merge union arithmetic confirmed: gated 1147.
