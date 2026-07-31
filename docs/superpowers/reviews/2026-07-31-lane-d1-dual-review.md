# Lane D1 review record — feat/first-tenant-provisioning (first-tenant program)

**Reviewers:** tenancy-authz-reviewer + fiscal-pos-reviewer (both Opus). **Target:**
`feat/first-tenant-provisioning` vs `origin/dev @ 711f3d79f`. **Merged to local dev** 2026-07-31
(merge of `e29913a7b`).

## Round 1: both APPROVE-WITH-FIXES
Tenancy: code correct (no ?Company trap; call order + tenant context right; idempotency/operator-state
contract holds; migration row/attribute-safe; authz surface intact; fail-closed paths confirmed) but
evidence thin — seeder wiring had ZERO test coverage; launch-contract test never ran on PG/db-per-tenant.
Fiscal: [Imp] getOrCreateWebTerminal stamped v3 would dead-end the web shifts dashboard (no device to
author SESSION_OPEN) — later self-corrected in round 2: blast radius was DEMO tenants (web POS routes
are demo-gated), not tenant #1; [Imp] green suite proved nothing (TerminalFactory pins 2 + Eloquent
doesn't hydrate DB defaults); consumer enumeration: NO consumer needs a cutover marker — v3-from-birth
is safe. **RULING: ship D1 in the batch, do NOT hold for Lane C; procedural no-refunds gate instead
of a /return 409** (an interim guard would make refunds impossible rather than divergent) — executed
as the E-7 interim prohibition + tickets (preflight-gate predicate, NF525 JET header).

## Fix round (@ e29913a7b) → Round 2: APPROVE + APPROVE
Web terminal explicit v2 with load-bearing comment (idempotent branch covered); behavioural 409
SHIFT_DEVICE_AUTHORITY_REQUIRED test through the full middleware stack; factory divergence recorded;
migration hasColumn guards both directions; seeder wiring genuinely pinned (CoffeeShop = only
possible row source; DemoPharmacy rerun isolated by row deletion — RED vs pre-lane seeder);
ci.yml pgsql filter entry; date + overclaim corrections. 2 pre-existing DemoPharmacySeederTest
failures verified on base in a throwaway worktree (one is a calendar flake failing on days >= 29).

## Residual (non-blocking, recorded)
- 🎫 Web X-report button ungated on isDeviceAuthoritative (moot while web=2; resurfaces if a v3 web
  terminal ever exists). 🎫 DemoPharmacySeederTest calendar flake (subDays(28) vs before-current-month
  assertion — fails on any day >= 29). Docblock "server-authoritative ⇒ stays at 2" has one
  pre-existing exception: VirtualAdminTerminalResolver sets 3.
- ParapharmacySeeder/DatabaseSeeder wirings remain unpinned by tests (low risk).
- First backend-test-pgsql CI run of TenantLaunchContractTest must be watched.
- Mixed device-3/web-2 fleet is deliberate; every reader verified per-terminal.
