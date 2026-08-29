# 10. Benchmark-First Specs — every user-facing flow spec opens with an industry-baseline gap matrix

> **The convention, in one sentence:**
> **A spec, design, or dispatch brief for a user-facing flow is not gate-ready until it carries an
> "Industry baseline" section stating what Odoo, ERPNext and Dolibarr guarantee for that flow, what AutoERP
> does today (cited `path:line`), and a per-guarantee decision — match / defer / deliberately diverge.**

Adopted 2026-08-29 (Session I). Exemplar to copy: `docs/superpowers/audits/2026-08-29-imports-hardening-gap-matrix.md`
(Session G, imports) — its §1 requirement↔exists↔gap matrix is the shape below with the owner's wording as the
baseline column; this convention replaces "owner wording" with the industry baseline as the *first* input, and
keeps the owner's requirements as the second.

---

## Why

The onboarding bugs of 2026-08-27/29 were, almost without exception, **things every mainstream ERP already
guarantees**: SKU unique per company, a fallback unit on import, an import that reports and skips duplicates
instead of doubling, a drawer bound to the till's location, a locked opening batch. None of them appeared in our
specs because the specs started from *our* code and *our* owner's wording — the industry baseline was never an
explicit input, so "we don't do X" never registered as a gap, only as an absence.

A gate cannot flag the absence of a requirement nobody wrote down. The baseline section writes it down.

---

## Scope

Mandatory for any spec, design doc, PRD slice, or Codex dispatch brief whose deliverable is a **user-facing flow**:
imports, onboarding/provisioning, POS sale/refund/close, purchasing (PO→GRN→invoice→payment), stock
transfers/counting, treasury (payments, allocation, count, deposit, reconciliation), documents lifecycle,
reports/declarations, settings the operator edits. Not required for pure infra/ratchet/refactor lanes — say
"no user-facing flow" in the spec header instead so round-0 can verify the claim.

---

## The section (copy this skeleton verbatim into the spec, directly after the summary)

```markdown
## Industry baseline (benchmark-first — convention 10)

Flow: <name>. Reference systems: Odoo <ver>, ERPNext <ver>, Dolibarr <ver> (cite the doc/page or the
module behaviour you checked; "from memory" is allowed but must be labelled so the gate can challenge it).

| # | Guarantee the baseline gives the user | Odoo | ERPNext | Dolibarr | AutoERP today (path:line) | Gap | Decision |
|---|---|---|---|---|---|---|---|
| B1 | Duplicate rows in an import are reported before write and the user chooses skip/update | ✅ | ✅ | ✅ | preview has no duplicate summary (`ImportPreview.tsx:…`) | MISSING | MATCH — lane G-4 |
| B2 | A code/SKU is unique per company, not per install | ✅ | ✅ | ✅ | `unique(['tenant_id','sku'])` (`2025_11_30_052910_…:37`) | WRONG | MATCH — lane G-3a |
| B3 | … | | | | | | |

Decision vocabulary: MATCH (we will do what they do — name the lane), DEFER (agree it is a gap, park it with a
ticket id), DIVERGE (we deliberately do otherwise — one sentence why, owner-ruled if user-visible),
ALREADY (we already match; cite it).

Second-of-everything (convention 09): <list the second-company / second-location / re-run tests this lane
adds, or "not in scope: no catalogue entity touched">.
```

Rules for filling it:
- **Guarantees, not features.** Write what the *user* can rely on ("a refund restores the lot it came from"),
  not UI ("has a refund button").
- **Cite AutoERP reality** with `path:line` per the round-0 precheck; never "we probably handle this".
- **Every row gets a decision.** An empty decision column is a round-0 failure.
- **Minimum rows:** the flow's create / duplicate / edit-after-use / delete-or-cancel / re-run / second-company
  / second-location / permissions / audit-trail guarantees, whichever apply. Fewer than five rows on a
  non-trivial flow is a smell the gate will probe.
- **Owner requirements come after.** Keep the owner's wording in the requirement↔exists↔gap matrix that follows
  (gap-matrix shape) and cross-reference baseline rows (`B2`) so a gate can see where the owner asked for less
  than the baseline and rule on it explicitly.

---

## Gate hooks

- **Round 0** (`docs/superpowers/SPEC-GATE-ROUND0-MECHANICAL-PRECHECK.md`, check 6): section present for a
  user-facing flow; every row has a decision; every AutoERP-today cell is a real `path:line`; "no user-facing
  flow" claims verified against the deliverable list.
- **Adversarial gate:** the reviewer's first question on a flow spec is "which baseline guarantee did you
  leave out?" — a guarantee missing from the table is a finding, same severity as a missing requirement.
- **Reviewer templates** carry the one-liner (see `.claude/agents/*-reviewer.md`, "Cross-cutting checks").

Related: [09-SECOND-OF-EVERYTHING.md](./09-SECOND-OF-EVERYTHING.md), [11-ONE-SURFACE-PER-CONCEPT.md](./11-ONE-SURFACE-PER-CONCEPT.md),
`docs/qa/MANUAL-TESTING-LOOP.md` (testers compare against the same baseline).
