# Ticket: hardcodedColorSelector ESLint guard is inert app-wide

Found during Lane B round-1 review (2026-07-31), pre-existing, unchanged by Lane B.
`apps/pos/eslint.config.js`: `hardcodedColorSelector` is applied only in the `tokenMigratedGlobs`
block, but the later `src/**/*.{ts,tsx}` `no-restricted-syntax` block REPLACES the rule for every
token-migrated file (flat-config replace semantics — same mechanism as the B4 cart-mutator bug).
Verified: `--print-config src/components/Header.tsx` shows no color selector, before AND after B4.
Fix shape: same pattern as B4 — spread the color selector into the third block for
tokenMigratedGlobs, or a dedicated trailing block. Post-launch hygiene; not launch-blocking.
