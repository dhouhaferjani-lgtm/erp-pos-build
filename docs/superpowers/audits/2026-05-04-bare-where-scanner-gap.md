# Bare-where scanner blind spot — Treasury cluster follow-up

Date: 2026-05-04
Branch: `feat/tenant-isolation-sweep-execution`
Trigger: Opus round-4 review — sub-finding 15a (INFORMATIONAL).

## What pattern do we miss today?

The current sweep scanners (Gate A `PhpAstExistsRuleScanner` for FormRequest /
inline `Rule::exists` validators, and Gate B `PhpAstFindScanner` for
`Model::find()` / `Model::findOrFail()` on guarded models) catch **scoped
calls that go through Eloquent's resolver shorthand**. They do not flag
the equivalent low-level chain that walks through `where(...)` directly:

```php
// Caught by Gate B (find() / findOrFail() on a guarded model)
$partner = Partner::query()
    ->where('company_id', $companyId)
    ->find($partnerId);

// NOT caught by either gate — semantically identical, written as a chain
$partner = Partner::where('company_id', $companyId)
    ->where('id', $partnerId)
    ->first();

$openInvoices = Document::where('company_id', $companyId)
    ->where('partner_id', $partnerId)
    ->where('type', DocumentType::Invoice)
    ->get();
```

Both invocations resolve a model row anchored on a route-supplied id and
both can leak cross-tenant rows when the chain is missing the cluster
invariant predicates (`tenant_id` AND `company_id`). But Gate B's AST
visitor (`FindCallVisitor`) only fires on `find` / `findOrFail` method
identifiers, so the bare-`where` chain is invisible to the scanner. The
inventory generator therefore never produces a callsite row for these
endpoints, and review has no pin to verify against.

## Why this is a real attack surface

Two findings in the Treasury cluster review walked into this gap:

- **Codex round-3 Finding 14** — `MultiPaymentController::getUnallocatedBalance`
  + `getPartnerAccountBalance` forwarded a raw `{partner}` route id into
  `MultiPaymentService` which ran `Payment::where('partner_id', $partnerId)`
  with no tenant scope. CRITICAL — exploitable cross-tenant payment-balance
  leak.
- **Opus round-4 Finding 15** — `SmartPaymentController::getOpenInvoices`
  + `PaymentAllocationService::getOpenInvoices` ran `Partner::where('company_id',
  ...)->where('id', ...)` and `Document::where('company_id', ...)->where('partner_id',
  ...)` with no tenant predicate. IMPORTANT — defense-in-depth gap;
  not exploitable today thanks to UUID uniqueness across `partners.id`
  / `documents.id`, but violates the cluster invariant Codex established
  in Finding 14.

Both findings were caught only by hostile human review on the
`/partners/{partner}/...` route family. The Treasury cluster sweep is
currently relying on this class of vulnerability being caught by manual
audit during a per-cluster review round, not by the inventory generator.

## What a future scanner pass would look like

A `PhpAstWhereChainScanner` (or an extension to the existing
`PhpAstFindScanner` + `FindCallVisitor`) would walk the AST looking for:

1. A `MethodCall` whose name is `where` (or `whereIn`) on a class-static
   `Model::query()` call, or directly `Model::where(...)`.
2. The first argument is a string literal in the set of route-anchor
   columns: `'id'`, `'partner_id'`, `'document_id'`, `'payment_id'`,
   `'repository_id'`, `'payment_method_id'`, `'instrument_id'`,
   `'reconciliation_id'`, etc.
3. The receiver class is in the guarded-model set.
4. The terminal call is `first()` / `firstOrFail()` / `get()` / `pluck()`
   / `lockForUpdate()->firstOrFail()` (read-side surface).
5. The `where(...)` chain does NOT contain a sibling
   `where('tenant_id', ...)` AND `where('company_id', ...)` predicate
   within the same expression.

The visitor needs to track the **chain context** for each MethodCall —
walk back to the receiver via `$node->var` and accumulate every prior
`where(...)` predicate before deciding the chain is complete. The chain
boundary is the receiver expression (the `Model::query()` or `Model::where(...)`
that starts the builder).

False positives to handle:
- Chains that filter further on a sibling Eloquent relation (e.g.
  `$payment->allocations()->where('document_id', ...)` are anchored on
  an already-scoped object). These should be flagged as
  `structurally_protected_by_upstream_guard` rather than IMPORTANT.
- Chains in domain-event reconstitution / read-replica tooling that
  intentionally cross tenants (super-admin paths). These should be
  annotated `#[CrossTenantRoute]` per master-plan Section 9.

## How to track this for the post-cluster sweep

A separate inventory pass after the Treasury cluster hard gate opens:

1. Add a stub class `App\Application\Sweep\Scanners\PhpAstWhereChainScanner`
   that mirrors `PhpAstFindScanner`'s structure (same Scanner interface,
   same CallsiteRow output, same cluster resolution). Initial implementation:
   `return [];` and a TODO pointing at this doc.
2. Register the stub in `SweepInventoryGenerateCommand` so the sweep
   runner picks it up and the architecture test can grow Gate C =
   `where-chain-scope-coverage` in lockstep.
3. Implement the visitor in a follow-up PR. Land the implementation
   alongside a re-grep of every `Treasury/`, `Document/`, `Partner/`,
   `Stock/` and POS module so the inventory absorbs the previously
   invisible callsites in one batched commit.
4. Open a sweep-progress Architecture test (Gate C) asserting
   `gate_c_where_chain_coverage >= scanned_chains_total`.

## Manual hostile-grep recipe (until the scanner lands)

Run from `apps/erp/apps/api/`:

```bash
/usr/bin/grep -rnE -- "->where\([\"'](id|partner_id|document_id|payment_id|repository_id|payment_method_id|user_id|instrument_id|account_id|cluster_id|tenant_id|company_id|allocation_id|reconciliation_id)[\"']" \
  app/Modules/<MODULE>/ | grep -v '/tests/'
```

For each match, inspect ±20 lines for sibling `where('tenant_id', ...)`
AND `where('company_id', ...)` predicates. Anything missing both
predicates is a NEW finding of the Codex Finding 14 / Opus Finding 15
class.

## Status

- Treasury cluster: bare-where audit performed on Opus round-4 (5 surfaces
  inspected, 2 NEW findings closed at commits `707284e6` (Finding 14) and
  `744e8155` (Finding 15)). Other Treasury matches are pre-existing
  tenant-only-scope NICE-TO-HAVE residuals — captured in the round-4
  review at `docs/superpowers/reviews/2026-05-04-treasury-cluster-opus-round4-review.md`,
  to be addressed in a separate sweep covering the `tenant-only scope,
  no company_id` anti-pattern across the cluster.
- Other clusters: not yet swept for this pattern. Document module reads,
  Partner reads, and POS reads should be re-scanned post-Treasury.

## References

- Round-3 Codex review: `docs/superpowers/reviews/2026-05-04-treasury-cluster-codex-round3-review.md` (Finding 14)
- Round-4 Opus review: `docs/superpowers/reviews/2026-05-04-treasury-cluster-opus-round4-review.md` (Finding 15 + sub-15a)
- Phase 1 Finding 15 fix commit: `744e8155`
- Round-3 Finding 14 fix commit: `707284e6`
- Existing Gate B scanner: `apps/api/app/Application/Sweep/Scanners/PhpAstFindScanner.php`
- Existing Gate B visitor: `apps/api/app/Application/Sweep/Visitors/FindCallVisitor.php`
