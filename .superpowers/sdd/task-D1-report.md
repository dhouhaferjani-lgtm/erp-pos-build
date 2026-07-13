# Task D1 Report — FE permission registration

## Outcome

Registered `treasury.transfer` as a frontend `Permission` with the exact backend-privileged role fallback: `admin`, `manager`, and `accountant`. The permission is not server-authoritative because no treasury/adjust-class peer is present in `SERVER_AUTHORITATIVE_PERMISSIONS`.

## TDD evidence

- RED: added a token-bearing `hasPermission('treasury.transfer')` regression to the existing hook test. Focused Vitest executed the behavior successfully, while `pnpm typecheck` failed with TS2345 because the token was not yet part of `Permission`. The fixture now has `roles: []`, proving runtime success comes from the server permission token rather than role fallback.
- GREEN: added the single `PERMISSIONS` map entry. Focused Vitest passed 3/3 and `pnpm typecheck` exited 0.

## Verification

- `pnpm vitest run src/hooks/__tests__/usePermissions.authPayload.test.tsx` — 3 tests passed.
- `pnpm typecheck` — exit 0.
- `pnpm exec eslint src/hooks/usePermissions.ts src/hooks/__tests__/usePermissions.authPayload.test.tsx` — exit 0.
- `pnpm lint` — exit 0.
- `npx react-doctor@latest --verbose --scope changed --base 9218efcea` — exit 0; 100/100, `No issues found!`. This pinned command is the React Doctor evidence; the earlier deprecated `--diff` invocation only emitted a scope banner and is not relied upon as verification.

## Scope

Production and test changes are limited to `usePermissions.ts` and its existing auth-payload test. No server-authoritative membership or unrelated behavior was changed.
