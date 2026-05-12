# PR #102 — Per-token ability scoping for POS Sanctum tokens — Codex round-1 review

**Date:** 2026-05-10
**PR:** https://github.com/otospexsolutions/erp/pull/102
**Branch:** `fix/pos-token-ability-scoping`
**Base:** `dev` (tip `45722ccb` post-PR-#101)
**Review tool:** `codex review --base dev --title "..."` (codex-cli 0.128.0)

## Codex actions executed (excerpt)

- `nl -ba apps/api/app/.../AuthController.php | sed -n '145,165p'` — confirmed `tokenAbilities` extraction.
- `cd apps/api && php artisan test tests/Feature/Identity/AuthenticationTest.php --filter "t14.*abilities|default_login_keeps_catchall|pos_client_header_issues_pos_scoped"` →
  - `t14 pos client header issues pos scoped abilities token` ✅ 1.78s
  - `t14 default login keeps catchall abilities for web backoffice` ✅ 0.27s
  - `t14 register with pos client header issues pos scoped abilities` ✅ 0.61s
  - **3 passed (16 assertions) in 2.70s**

## Findings

**None.** No P1/P2/P3 issues raised.

## Verbatim conclusion

> The changes consistently scope POS-issued tokens while preserving existing web token behavior, and no current routes appear to depend on Sanctum token abilities. The added regression tests cover the modified login and registration paths.

## Acknowledged adversarial vectors (mitigations)

The codex CLI's `--base BRANCH` mode does not accept a custom prompt, so the structured adversarial prompt drafted for this round was not passed verbatim. The default review prompt did exercise:

1. **Sanctum `can()` semantics** — verified by both the inline test assertions (`can('*')` true on catch-all, `can('pos:*')` true on both, `can('*')` false on `['pos:*']`) and by Sanctum's `PersonalAccessToken::can` implementation (`in_array('*', abilities) || in_array($ability, abilities)`).
2. **Backwards compat for in-flight tokens** — POS tokens issued before this PR carry `['*']` and continue to work because no consumer route enforces a non-`pos:*` ability check today. Only newly-issued POS tokens carry the narrower `['pos:*']` scope.
3. **No accidental narrowing of web back-office** — verified by the `test_t14_default_login_keeps_catchall_abilities_for_web_backoffice` regression guard.
4. **Refactor correctness** — `tokenExpiresAt` was inlined to call `isPosClient`; the existing 35 AuthenticationTest cases pass unchanged (38/38 total). Full Identity suite: 157/157 (546 assertions).

## Verdict

APPROVE
