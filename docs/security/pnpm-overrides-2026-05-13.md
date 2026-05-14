# pnpm Overrides Registry — 2026-05-13 (revisited 2026-05-14)

> Originally closed Round 1 review P2-2 (pnpm overrides accumulate technical debt
> without a revisit policy). Companion to the `pnpm.overrides` block in the root
> `package.json`.

## Current state — NO overrides

As of the **2026-05-14 revisit** (T6, dev deferred-backlog session) the root
`package.json` carries **no `pnpm.overrides` block**. All 11 overrides added by
`dev-remediation/M1.3` were found redundant and removed — every parent
dependency now resolves a safe version on its own.

## 2026-05-14 revisit log

`dev-remediation/M1.3` (2026-05-13) added 11 transitive-dependency overrides,
each pinning a minimum non-vulnerable version. The revisit ran the documented
procedure: `pnpm outdated -r`, `pnpm audit`, and — for each override — removed
it, reinstalled, and checked the naturally-resolved version + audit status.

### The `ajv` override was malformed

`ajv@<6.14.0` → `>=6.14.0` was **broken**: `ajv@6.x` tops out short of 6.14.0,
so `>=6.14.0` with no upper bound resolved to `ajv@8.20.0`, incompatible with
`@eslint/eslintrc@3.3.3` (needs `ajv@^6.12.4`). ESLint crashed on load across
`apps/web` + `apps/pos` — `pnpm lint` was dead on `origin/dev`. Fixed first
(commit `aab17b2d`) to `ajv@<6.12.3` → `6.12.6` to unblock the lint work, then
found redundant and removed with the rest (see below).

### All 11 overrides confirmed redundant and removed

With every override removed, `pnpm install` resolves these versions, all at or
above the override minimums, and `pnpm audit` (unfiltered) reports **no known
vulnerabilities**:

| Override (removed) | Source advisory | Naturally resolves to | Redundant? |
| --- | --- | --- | --- |
| `ajv@<6.14.0` → `>=6.14.0` (malformed; → `<6.12.3`→`6.12.6`) | GHSA-v88g-cgmw-v5xw (prototype pollution, fixed in ajv 6.12.3) | `ajv@6.15.0` | ✅ yes |
| `brace-expansion@<1.1.13` → `>=1.1.13` | GHSA-v6h2-p8h4-qcjw (ReDoS) | `brace-expansion@1.1.14` | ✅ yes |
| `brace-expansion@<2.0.3` → `>=2.0.3` | same CVE, 2.x branch | `brace-expansion@2.1.0` | ✅ yes |
| `flatted@<3.4.2` → `>=3.4.2` | GHSA-c95h-2pgw-cqxr (prototype pollution) | `flatted@3.4.2` | ✅ yes |
| `minimatch@<3.1.4` → `>=3.1.4` | GHSA-f8q6-p94x-37v3 (ReDoS) | `minimatch@3.1.5` | ✅ yes |
| `minimatch@<9.0.7` → `>=9.0.7` | same CVE, 9.x branch | `minimatch@9.0.9` | ✅ yes |
| `picomatch@<2.3.2` → `>=2.3.2` | transitive defense | `picomatch@2.3.2` | ✅ yes |
| `picomatch@<4.0.4` → `>=4.0.4` | same CVE, 4.x branch | `picomatch@4.0.4` | ✅ yes |
| `postcss@<8.5.10` → `>=8.5.10` | GHSA-7fh5-64p2-3v2j (parser bug) | `postcss@8.5.14` | ✅ yes |
| `rollup@<4.59.0` → `>=4.59.0` | build supply-chain advisory | `rollup@4.60.3` | ✅ yes |
| `yaml@<2.8.3` → `>=2.8.3` | parser DoS advisory | (no vulnerable version resolved) | ✅ yes |

Verification after removal:
- `pnpm audit` and `pnpm audit --audit-level moderate` — no known vulnerabilities.
- ESLint runs again (`@eslint/eslintrc` links `ajv@6.15.0`).
- `pnpm-lock.yaml` regenerated and committed alongside `package.json`.

## Revisit procedure (for any future overrides)

When an override is added again, record it here with its source advisory,
parent dependency, and a revisit-by date. On the revisit date (or whenever a
dependency major is updated):

```bash
pnpm outdated -r
pnpm audit --audit-level moderate
```

For each override:

1. Remove the override row, run `pnpm install`.
2. Check the naturally-resolved version against the override minimum, and
   re-run `pnpm audit`.
3. If audit stays clean and the resolved version meets the minimum, the parent
   has shipped the fix — keep it removed.
4. If `pnpm audit` re-flags the CVE, the parent has NOT shipped a fix — restore
   the override and extend the revisit-by date.

## Process notes

- Never commit a new override without an entry in this doc.
- Never silently drop an override without confirming `pnpm audit` stays clean.
- An override target must be a real, installable version on the intended major
  line — the malformed `ajv@<6.14.0` → `>=6.14.0` row above is the cautionary
  example (`6.14.0` did not exist on the 6.x line, so the range jumped to 8.x).
- If a parent dependency is itself end-of-life (no fix forthcoming), open a
  tracking issue to migrate off it; do not let the override remain indefinite.
