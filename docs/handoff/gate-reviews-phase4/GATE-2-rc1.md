# Treasury Phase 4 — Gate 2 RC1 adversarial review

- Scope: Wave 2 / Tasks 7–12
- Diff reviewed: `phase4-gate-1..phase4-gate-2-rc1`
- Reviewed HEAD: `d1a37439e`
- Model tier: Opus (`claude-opus-4-8`)

## Verdict

**APPROVE — no BLOCKER, HIGH, or MEDIUM findings.** Fable escalation is not required because no money-path BLOCKER/HIGH remains.

## Guard checks

- `TreasuryMovementService` is byte-untouched; its `origin/dev..HEAD` diff is empty.
- The generation command and `ExpenseService::create()` do not read `CompanyContext`; create resolves the company from explicit `company_id`.
- No no-argument scale resolution or literal bcmath scales were found; scale lookup uses explicit currency.
- No float money operations were found. Template decimals remain strings and forecast aggregation uses bcmath/string formatting.

## Verified contracts

- Cursor math is origin-anchored and overflow-safe, including Jan-31 clamping/restoration and leap-year behavior. `next()` remains strictly monotonic and `firstOnOrAfter()` does not skip or overshoot.
- Generation atomically creates/links a draft and advances the cursor. The `wasRecentlyCreated` replay gate prevents repeat notification while preserving correct cursor behavior. Tenant/company/actor stamps, tenant Spatie team ID, company-timezone lead gate, shared `MAX_LEAD_DAYS`, inclusive end bound, and the in-process 05:30 schedule are correct.
- CRUD queries are company-scoped. Edit recomputation, resume roll-forward without backfill, terminal Ended state, and pause/resume source-state guards match the plan. The VAT tuple is validated at the owning company currency grid before it can flow into generated drafts. All seven route verbs are present.
- Forecast feeds partition materialized Drafts, cursor-forward projections, and posted-unpaid expenses without overlap. Reads are company-scoped and totals use exact string/bcmath aggregation.
- Backend seeder grants and frontend permission mappings match spec §8.5: manager/accountant full recurrence access plus expense export, while cashier/operator/viewer are view-only.
- The frontend route precedes `:id`; notification keys are nested in en/fr/ar and interpolate snake-case payload fields into formatted values; query keys and invalidations match tenant/company scoping; the recurrence-origin chip is backed by real serialization; no hardcoded color regression was found.
- Prior Task 7/12 findings are closed: recurrence metadata is fillable, VAT off-grid and tuple errors are rejected, origin metadata is serialized, rejected mutation promises are consumed, and delete invalidation is tenant/company/ID precise.
- Pinned replay/no-double-notify, two-companies isolation, forecast partition, deny-path, and grant-matrix tests are present.

## Non-blocking observations

1. **LOW:** Forecast invalidation uses the bare `['upcoming-payments']` prefix. It correctly matches the cache but may over-refetch other tenant-scoped entries in the same client.
2. **INFO:** CRUD lifecycle calculation uses application-timezone `today()` while the command uses company time. The command re-gates generation in company time, so this is not material to the money path.
3. **INFO:** The notification panel's EUR fallback is dead in the planned path because the generation command always emits currency.

## Review limitation

The reviewer could inspect the full diff and code paths but its sandbox required an unavailable permission grant to write this artifact. The controller persisted the emitted review without changing its substance.

VERDICT: APPROVE
