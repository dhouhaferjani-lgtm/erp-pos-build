# Codex adversarial review — burn-down Task 1 (SQL-bounded suggestion loading), round 1

Diff: 11beef2f7..34ae4ceba · Reviewer: Codex CLI (via codex-rescue agent) · 2026-07-28

## Verdict: REJECT

Note: Codex's sandbox could not write into this worktree (writes restricted to the main
repo path); verdict transcribed by the orchestrator from Codex's inline output.

## Findings

- **[Important] False Tier-2 uniqueness / omitted candidate under cap.** The
  pre-allocation 500-row cap can truncate the in-window candidate set, so the service
  can judge an amount "unique in window" when the duplicate was beyond the cap, or omit
  the correct candidate entirely. A bounding cap must never CHANGE suggestion outcomes.
- **[Important] Out-of-window query keeps the oldest 500 broad candidates.** Ordered by
  occurred_at with a cap, later valid Tier-1 reference matches are silently dropped.
- **[Important] Cap applied per query, not overall** — up to 1,000 hydrated movements
  across the two queries, defeating the stated bound.
- **[Important] Cap test is vacuous** — inserts one movement and asserts SQL text; it
  never exercises overflow or the second (out-of-window) query.
- **[Minor] Out-of-window scan not demonstrably bounded at the database level** even
  when the result set is capped.

## Evidence noted by Codex

Focused tests 11/69 assertions green; Pint/PHPStan clean. No files modified by review.

---

# Round 2 (diff 11beef2f7..abaae1a8b): REJECT

- **[Important]** Tier-1 remains cap-sensitive: short/repeated references satisfy the SQL
  predicate and 500 earlier gross-amount candidates can fill the ordered limit, dropping a
  later exact-remaining reference match (`StatementSuggestionService.php:288,415`).
- **[Important]** Documented hydration bound not real: reference-source `pluck()` calls and
  allocation hydration are uncapped (`StatementSuggestionService.php:231,433`).
- **[Important]** None of the three new tests fail against round-1 code — fixtures don't
  pressure the caps/paths they claim to prove (`StatementMatchingHttpTest.php:201,220`,
  `StatementSuggestionServiceTest.php:123`).
- **[Important]** SQL trim/lower does not collapse INTERNAL whitespace unlike PHP
  `normalize()` — references like `AB  123` previously matched, now rejected before PHP
  verification: a real Tier-1 regression (`StatementSuggestionService.php:415,698`).
- **[Cleared]** sqlite `:memory:` vs PostgreSQL decimal equality: both map `decimal(15,3)`
  to numeric comparison; no cross-engine divergence.

Round-1 findings: 1 CLOSED, 2 NOT CLOSED, 3 NOT CLOSED, 4 NOT CLOSED, 5 CLOSED.

---

# Round 3 (fix commit c3caeee91, 2026-07-29): REJECT — one residual finding

Verified closed: displacement/broad-noise (source-side pluck + source_id load), hydration
bounds (5000 source cap w/ skip-Tier-1; 500/movement allocation ceiling w/ exclusion),
internal-whitespace regression (normalize() restored on both sides), discriminating tests
(600-row displacement + `AB  123` whitespace fixtures confirmed non-vacuous), cap boundary
off-by-one correct, notes path intact.

- **[Important — REMAINING]** Final movement load (`StatementSuggestionService.php:358,360`)
  still orders + `limit(500)` silently: >500 movements matching the selected sources/notes
  can truncate Tier-1 candidates without degradation. Required: fetch cap+1, on overflow
  skip Tier-1 explicitly (same pattern as the source/allocation bounds). Also noted:
  `(source_type, source_id)` is indexed, not unique, so multi-movement sources legitimately
  fan out (`...create_repository_movements_table.php:37,40`).

---

# Round 4 (fix commit 446041fc3, 2026-07-29): APPROVE — TASK 1 CLOSED

- Every Tier-1 limit now fetches cap+1 with explicit overflow degradation; no silent
  truncation path remains (`StatementSuggestionService.php:261-320,331-369,497-531`).
- Fan-out overflow test confirmed non-vacuous: pre-fix code returns the first 500; the
  swap-protocol red run is recorded in the task report.
- Committed fix verified coherent (overflow logic + docblock + test all in 446041fc3).

Final state over 4 rounds: Tier-2 uniqueness exact-SQL (cap-independent), Tier-1
source-side matching with original normalize() semantics, all hydration paths bounded
with fail-safe degradation, discriminating tests proven against both prior
implementations. 20 focused tests / 104 assertions; phpstan + pint clean.
