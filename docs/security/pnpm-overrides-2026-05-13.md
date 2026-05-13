# pnpm Overrides Registry — 2026-05-13

> Closes Round 1 review P2-2 (pnpm overrides accumulate technical debt without a revisit policy).
> Companion to the `pnpm.overrides` block in the root `package.json`.

The root `package.json` carries pnpm transitive-dependency overrides as a result of `dev-remediation/M1.3`. Each row pins a minimum non-vulnerable version. Once the parent dependency ships a fix that pulls the same minimum on its own, the override row can be dropped.

This doc records the source advisory (or general reason), the parent dependency, and a revisit-by date.

## Overrides

| Override | Source | Parent dependency | Revisit by |
| --- | --- | --- | --- |
| `ajv@<6.14.0` → `>=6.14.0` | GHSA-v88g-cgmw-v5xw (prototype pollution) | various lint/build chains | 2026-Q3 |
| `brace-expansion@<1.1.13` → `>=1.1.13` | GHSA-v6h2-p8h4-qcjw (ReDoS) | glob, minimatch | 2026-Q3 |
| `brace-expansion@<2.0.3` → `>=2.0.3` | same CVE, 2.x branch | newer glob versions | 2026-Q3 |
| `flatted@<3.4.2` → `>=3.4.2` | GHSA-c95h-2pgw-cqxr (prototype pollution) | jest, eslint chains | 2026-Q3 |
| `minimatch@<3.1.4` → `>=3.1.4` | GHSA-f8q6-p94x-37v3 (ReDoS) | glob, rimraf | 2026-Q3 |
| `minimatch@<9.0.7` → `>=9.0.7` | same CVE, 9.x branch | newer glob | 2026-Q3 |
| `picomatch@<2.3.2` → `>=2.3.2` | transitive defense (followed by vitest/chokidar) | vitest, chokidar | 2026-Q3 |
| `picomatch@<4.0.4` → `>=4.0.4` | same CVE, 4.x branch | vite, vitest | 2026-Q3 |
| `postcss@<8.5.10` → `>=8.5.10` | GHSA-7fh5-64p2-3v2j (parser bug) | tailwindcss, vite | 2026-Q3 |
| `rollup@<4.59.0` → `>=4.59.0` | GHSA-... (build supply-chain) | vite | 2026-Q3 |
| `yaml@<2.8.3` → `>=2.8.3` | GHSA-... (parser DoS) | eslint, prettier | 2026-Q3 |

## Revisit procedure

On the revisit-by date (or whenever a dependency major version is updated):

```bash
pnpm outdated -r
pnpm audit --audit-level moderate
```

For each row above:

1. Check if the parent dependency has shipped a release that requires the override's minimum or higher.
2. If yes, drop the override row from `package.json` and run `pnpm install --frozen-lockfile`.
3. If `pnpm audit` re-flags the same CVE, the parent has NOT shipped a fix yet — extend the revisit-by date and leave the override in place.

## Process notes

- Never commit a new override without an entry in this doc.
- Never silently drop an override without confirming `pnpm audit` stays clean.
- If a parent dependency is itself end-of-life (no fix forthcoming), open a tracking issue to migrate off it; do not let the override remain indefinite.
