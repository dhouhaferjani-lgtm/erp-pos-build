# `no-parsefloat-on-money` ESLint rule — two independent holes (P2, guard hardening)

Source: R2-G lane m-6 sweep (2026-08-07). The vat-reporting feature carried 4 live
parseFloat-on-money violations that the rule failed to gate, for two independent reasons:

1. **Severity**: the rule is registered `'warn'` in `eslint.config.js`, and `pnpm lint:eslint`
   runs plain `eslint .` with no `--max-warnings 0`, so warnings never fail lint/preflight/CI.
   Fix: promote to `'error'` (with a one-time baseline/ratchet pass if existing warnings
   remain elsewhere), or add `--max-warnings` to the lint script.
2. **AST blind spot**: the rule's `argName()` inspects only bare `Identifier` /
   `MemberExpression` arguments. `parseFloat(period.total_output_vat ?? '0')` wraps the
   member access in a `LogicalExpression` and silently evades even the warning despite the
   money-name regex matching. Fix: unwrap LogicalExpression / ConditionalExpression /
   TSAsExpression / ChainExpression before name extraction; add RuleTester cases for each.

Land as ONE tooling change AFTER the current remediation-round PHP/FE lanes rebase (same
sequencing rule as the PHPStan bcmath rule in plan v2 — repo-wide guard changes go last).
Sweep for new hits the moment the rule is hardened; fix or baseline them explicitly.
