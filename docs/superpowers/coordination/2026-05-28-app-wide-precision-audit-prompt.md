# Session Prompt — Repo-wide Precision & Scale-Drift Audit

You are starting a fresh session to **catalog** every precision-drift risk in the AutoERP application. Your output is a structured report. **You will not fix anything in this session** — fixes will be triaged separately based on severity and module ownership.

This prompt is your full brief. Read it top to bottom before touching anything.

---

## What "precision drift" means here

A precision-drift bug exists wherever:

1. **A column's storage precision is smaller than the precision the API or service layer accepts**, so writes silently round and reads return less than what was submitted. (Example: `stock_levels.quantity` is `decimal(15,2)` but `StoreStockTransferRequest` accepts `min:0.0001`.)
2. **Two columns that are linked by FK or by business logic disagree on scale**, so flowing data between them rounds at the boundary. (Example: a `stock_transfer_lines.quantity decimal(15,4)` value flowing into `stock_levels.quantity decimal(15,2)`.)
3. **A bcmath operation uses a hardcoded scale that doesn't match the column it's writing to.** (Example: `bcadd($a, $b, 2)` writing to a `decimal(15,4)` column truncates the result.)
4. **A `(float)` cast or `number_format` is used on a monetary/quantity value** — already flagged by `project_monetary_precision.md`, but every callsite needs verification.
5. **A `decimal:N` Eloquent cast disagrees with the migration column's `decimal(P, S)`.** PHP serializes/deserializes with the cast scale; the column stores at its own scale; a mismatch produces silent rounding at every read.
6. **JSONB payloads stringify decimals through PHP `float` intermediates.** `jsonb_object->'amount'` round-trips can lose precision.
7. **Frontend formatters truncate or round at display while storage holds higher precision** (e.g. `(value).toFixed(2)` on a quantity stored at 4 decimals). The display is wrong; the data is fine; but report it because users see misleading numbers.
8. **Validation rules that allow more precision than the column stores.** Mirror of #1 from the rule layer.
9. **Round-trip through `formatCurrency` / `formatQuantity` helpers that use a hardcoded scale not driven by the company's currency settings or product UoM.**

---

## What you produce

A single markdown report at `docs/superpowers/audits/2026-05-28-precision-drift-audit.md` with the following structure:

```markdown
# Precision & Scale-Drift Audit — AutoERP

**Date:** 2026-05-28
**Scope:** all modules under `apps/api/app/Modules/` and `apps/web/src/features/`
**Auditor:** [your session id]

## Executive summary

[2-3 paragraphs. How many findings, broken down by severity. Which modules are worst. Any pattern that crosses many modules (e.g. "every module that touches money uses CurrencyScale correctly; every module that touches quantity rolls its own and they all disagree").]

## Severity definitions

- **CRITICAL** — silently corrupts financial or fiscal data; user-facing precision contract is broken; regulatory-compliance risk.
- **HIGH** — silently rounds business data; mismatch between user-submitted and stored values; audit-trail inconsistency.
- **MEDIUM** — display truncates real data; user sees less precision than is stored; reports may misrepresent.
- **LOW** — internal inconsistency with no user-visible impact today (but a refactor could expose it).

## Findings

[One section per finding. Use the template below.]

## Cross-cutting patterns

[Section listing patterns that span multiple findings — e.g. "Pattern P1: bcmath with hardcoded scale 2 across 14 services" — so the triage session can fix a pattern in one PR rather than 14 PRs.]

## Recommended sequencing

[Suggest which findings should be fixed together; which need new spec; which are quick wins.]

## What I did not audit

[Be honest about coverage gaps. E.g. "I did not audit `data-acquisition` because it doesn't store decimals."]
```

### Finding template

For every finding:

```markdown
### F[N] — [Short title] ([SEVERITY])

**Module(s):** [Inventory, Treasury, etc.]
**Files cited (with path:line):**
- `apps/api/.../X.php:42` — [what this line does and why it's drift]
- `apps/api/database/migrations/...php:38` — [column declaration]
- `apps/api/.../Y.php:120` — [bcmath site]

**The drift:**
[2-4 sentences describing the mismatch. Use concrete values.]

**Worked example:**
[A two-step scenario showing what user input becomes what stored value vs what the user expected.]

**Recommended fix:**
[One or two sentences. Reference the canonical pattern that should apply. Don't write code unless the fix is one line.]

**Estimated effort:** [trivial / small / medium / large] — based on number of callsites and required migration.
```

---

## Where to look

### Backend (Laravel, `apps/api/`)

**Migrations** — all of `apps/api/database/migrations/`:
- Grep for `decimal(` and catalog every column with `(N, 2)`, `(N, 3)`, `(N, 4)`. For each, ask: what's the API max precision the column accepts? What's the Eloquent cast scale? Are they consistent?
- Pay extra attention to `quantity`, `unit_cost`, `unit_price`, `landed_unit_cost`, `tax_rate`, `discount_amount`, `freight`, anything money-shaped that isn't routed through `CurrencyScale`.

**Models** — all of `app/Modules/*/Domain/**.php`:
- Grep for `'decimal:'` casts.
- Cross-reference with the migration column. Mismatches go in the report.

**Services** — all of `app/Modules/*/{Application,Domain}/Services/**.php`:
- Grep for `bcadd|bcsub|bcmul|bcdiv|bcpow|bccomp` calls.
- For each, identify the scale argument. Look for hardcoded numeric literals (`2`, `3`, `4`, `6`). Compare against the columns the result eventually flows to.
- Grep for `(float)` casts on properties that look monetary or quantity-shaped.
- Grep for `number_format(` — per `project_monetary_precision.md`, this is forbidden on monetary/quantity values. Every site is a finding.
- Grep for `round(` and `floor(` and `ceil(` — for each, identify the scale and what it writes to.
- Grep for `CurrencyScale::bcformat` and verify it's used everywhere it should be (per the memory rule).

**Form requests** — all of `app/Modules/*/Presentation/Requests/**.php`:
- Grep for `min:` and `max:` rules with sub-cent values (`0.0001`, `0.001`). For each, check the storage column's actual scale.
- Grep for `'numeric'` rules — for each, check the destination scale.

**Resources / API responses** — all of `app/Modules/*/Presentation/Resources/**.php` and controllers that return JSON:
- Grep for explicit precision formatting (`number_format`, `(string) round(...)`).

### Frontend (React, `apps/web/`)

**Formatters** — `apps/web/src/lib/format.ts`, `formatCurrency.ts`, any `format*` helpers:
- For each helper, identify the precision it uses. Document whether it's currency-driven (correct) or hardcoded (suspect).

**Form inputs** — search `step=` on `<input type="number">` across `apps/web/src/features/`:
- For each, identify what column the value flows to. Catalog mismatches.

**Display** — search `.toFixed(`, `Number(...).` patterns:
- For each, what's the storage precision of the underlying data?

### POS (Tauri, `apps/pos/`)

- Same patterns as web. POS often diverges from the web app on precision because of receipt-printing constraints. Catalog any local rounding that doesn't match the backend.

### Shared package (`packages/shared/`)

- Generated types — verify the auto-generated TypeScript matches the DTO precision contracts.

---

## Search recipes

```bash
# Decimal column declarations
rg -n "decimal\(\s*\d+\s*,\s*\d+\s*\)" apps/api/database/migrations/

# Eloquent decimal casts
rg -n "'decimal:\d+'" apps/api/app/

# Hardcoded bcmath scale
rg -n "bc(add|sub|mul|div|comp|pow)\([^)]*,\s*\d+\s*\)" apps/api/app/

# Float casts on suspect properties
rg -n "\(float\)\s*\\\$\w*(price|cost|amount|quantity|qty|total|subtotal|tax|fee)" apps/api/app/

# number_format usage (forbidden per memory)
rg -n "number_format\(" apps/api/app/

# Validation rules with sub-cent precision
rg -n "min:0\.000\d|max:0\.000\d" apps/api/app/

# CurrencyScale call sites (should be everywhere money-shaped values are formatted)
rg -n "CurrencyScale::|CurrencyScaleResolverInterface" apps/api/

# Frontend toFixed
rg -n "\.toFixed\(\d+\)" apps/web/src/ apps/pos/src/

# Frontend numeric step
rg -n 'step="0\.\d+"' apps/web/src/ apps/pos/src/
```

Adapt as needed. Run each pattern in the entire `apps/` tree, not just `apps/api/` — frontend has its own precision contract.

---

## Required reading before you start

- `apps/erp/CLAUDE.md` — agent operational rules.
- Memory file `project_monetary_precision.md` — the existing rule that `(float) … number_format` is forbidden for monetary values; `CurrencyScale::bcformat` is the canonical helper. Use this as your benchmark for "what good looks like" on the money side; the quantity side has no equivalent helper today (one of your findings will be: there should be a `QuantityScale` analog).
- The pre-existing tech-debt note in `docs/superpowers/reviews/2026-05-28-inventory-transfer-opus-review.md` — Opus's P2-5 finding. Use it as a Rosetta-stone example of what a precision-drift finding looks like in this audit.
- `docs/superpowers/coordination/2026-05-28-inventory-precision-fix-prompt.md` — the parallel session that fixes the Inventory module. Your audit may find similar drift in other modules; those are separate fixes, but you should still catalog them.

---

## What to NOT do

- **Don't fix anything.** Even one-line "obvious" fixes go in the report, not in code. The triage session decides which fix lands where.
- **Don't audit currency-handling sites that route through `CurrencyScale` and look correct.** If a site already follows the documented pattern, it's not drift; it's correct. (But verify the pattern is actually followed — the memory note exists precisely because it isn't followed consistently.)
- **Don't make it a list of `grep` output dumps.** Every finding needs the worked example showing concrete user-visible consequences. If you can't construct an example, the finding probably isn't real and shouldn't be in the report.
- **Don't dilute severity.** CRITICAL is for "this currently silently corrupts fiscal data and we should ship the fix this week." LOW is "we'd fix this in a code-style sweep." Most findings will be MEDIUM or HIGH; if everything is CRITICAL the report is useless.

---

## Coordination notes

- The Inventory module is already being fixed in a parallel session (the inventory-precision-fix prompt above). Catalog inventory findings as a sanity-check on that session's scope, but don't duplicate its work. If you find inventory drift NOT in that prompt's scope, flag it.
- The active T6 work (`feat/t6-phase0b`, `feat/t6-preflip-ops`) doesn't change precision contracts; you don't need to coordinate with it.

---

## Output

- Save the report to `docs/superpowers/audits/2026-05-28-precision-drift-audit.md`. **Save by writing the file — do not return it inline.**
- Reply with just the file path and a one-line summary: `[N CRITICAL, N HIGH, N MEDIUM, N LOW findings across [N] modules]`.
- Do not commit the report unless explicitly asked. The orchestrator will read it from disk and decide how to triage.
