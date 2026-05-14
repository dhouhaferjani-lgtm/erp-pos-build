# Lint-Warning Ratchet — 2026-05-14

**Task:** M4.2 (dev deferred-backlog session)
**Scope:** Stop the frontend lint-warning backlog from growing, without forcing a
big-bang cleanup.

## What changed

| File | Change |
|---|---|
| `scripts/lint-ratchet.mjs` | New — runs `eslint` per app, ratchets the warning count |
| `scripts/lint-warning-baseline.json` | New — frozen warning-count baseline |
| `package.json` | New `lint:ratchet` script |

## Baseline

Captured `2026-05-14` (after the `ajv` override fix that restored ESLint — see
`docs/security/pnpm-overrides-2026-05-13.md`):

| App | Errors | Warnings |
|---|---:|---:|
| `@autoerp/web` | 0 | 11337 |
| `@autoerp/pos` | 0 | 41 |

The 11337 web warnings are overwhelmingly the `no-restricted-syntax`
design-token rule (hardcoded Tailwind colour classes — CLAUDE.md rule 18).
Per the session scope, this backlog is **not** fixed here — it is burned down
incrementally, touch-a-file-fix-it.

## How the ratchet works — `scripts/lint-ratchet.mjs`

- Runs `pnpm --filter <app> lint` for each workspace app (`@autoerp/web`,
  `@autoerp/pos`).
- Parses ESLint's summary line for the error / warning counts.
- **Any error fails immediately** — errors are never ratcheted.
- The **warning count is ratcheted** against `scripts/lint-warning-baseline.json`:
  it may shrink (good) or hold, but never grow.
- When the count drops, it prints the `--update-baseline` command so the gain
  is locked in.

Implementation notes:
- ESLint exits 0 with only warnings, so the script parses the summary text
  rather than relying on the exit code.
- `maxBuffer` is raised to 64 MB — the web app emits ~2 MB of warning output
  and a truncated buffer would silently under-count.
- An ESLint crash (no parseable summary + a crash banner) is a hard failure,
  not a "0 warnings" pass.

## Usage

```bash
# Gate (CI): exit 0 = within baseline, exit 1 = regression
pnpm lint:ratchet

# Re-baseline after a real improvement (warning count dropped)
node scripts/lint-ratchet.mjs --update-baseline
```

### Wiring into CI

Add `pnpm lint:ratchet` to the frontend CI job. It supersedes a raw `pnpm lint`
gate, which against an 11k-warning backlog is either always-red or always-green.
When a developer clears warnings in a file they touched, they re-run
`--update-baseline` and commit the lowered count — the ratchet then holds the
new floor.

## Follow-up

- Burn down the `@autoerp/web` design-token warnings incrementally
  (CLAUDE.md rule 18 — migrate hardcoded Tailwind colours to `lib/designTokens.ts`
  in files already being touched). Each cleanup pass lowers the baseline.
