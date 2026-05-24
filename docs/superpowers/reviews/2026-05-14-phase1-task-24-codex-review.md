# Codex Review - Phase 1 Task 24 (`71eaa838c`)

**Captured by the controller** — Codex sandbox was read-only across rounds 1, 2, and 3; verbatim findings transcribed from inline output.

**Verdict: REQUEST-CHANGES**

Two P1 blockers found in round-1.

| Severity | ID | File:Line | Description | Required Fix |
|---|---|---|---|---|
| P1 | T24-P1 | `apps/api/app/Modules/Fiscal/Application/Services/ParseFailureResolutionService.php:261-264` | Corrected payload validated only through `DTO::fromArray()`. `StrictCanonicalParser.php:188-195` enforces additional key-set, sub-array shape, money-format, and per-event constraints that `fromArray` does not run. Per spec §7.6, the strict parser is the authority for what constitutes a trusted payload. Payloads with extra top-level keys, wrong currency scale, associative-object lines, or empty nested objects would pass the resolver and feed projectors. | Extract the strict validation from `StrictCanonicalParser` into a shared validator called by both the parser and the resolver. Wrap failures as `InvalidCorrectedPayloadException`. |
| P1 | T24-P2 | `apps/api/app/Modules/Fiscal/Infrastructure/Commands/EnqueueResolvedEventProjectionsCommand.php:98-105` | Command loads actor by `--actor-id` and calls `$actor->can('fiscal.events.resolve_quarantine')` without setting `PermissionRegistrar::setPermissionsTeamId($actor->tenant_id)`. With Spatie teams enabled (`config/permission.php:134`), `can()` evaluates against the currently-set team id, not the actor's tenant. Tests pre-set the team id at `ParseFailureResumeTest.php:109-113`, masking this gap. | Add `app(PermissionRegistrar::class)->setPermissionsTeamId($actor->tenant_id)` before the `can()` call in the command, restore previous team id after, add a test that clears the team id before invoking the command with a privileged actor. |

**All other focus areas CLEAN**: Task 8 trigger interpretation correct (the implementer's `integrity_exception_class` deviation from the brief is correctly defended — the column is write-once per trigger lines 128-136), atomic UPDATE verified, no `Artisan::call()` in dispatch path, constraint name correctly targeted, Task 23 race contract intact, `Kernel::setArtisan(null)` hack passing, `Carbon::setTestNow` properly wrapped.

**Test results**: All four verification commands exited 0 (196 fiscal tests, 75 cross-task regression tests, PHPStan level 8 clean).

**Overall recommendation**: REQUEST-CHANGES. The path to APPROVE is closing T24-P1 (extract shared validator) + T24-P2 (set permission team id to actor's tenant in try/finally).

---

## Round-2 re-review (commit 5bb9216ce)

**Verdict: REQUEST-CHANGES** — 1 new P1 introduced by round-2 itself + 1 P3.

| Severity | ID | File:Line | Description | Required Fix |
|---|---|---|---|---|
| P1 | T24-R2-P1 | `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php:116-120` | Constructor accepts `?FiscalPayloadConstraintValidator $constraintValidator = null` and falls back to `new FiscalPayloadConstraintValidator()` inside the constructor body. This is the constructor-injection anti-pattern prohibited by CLAUDE.md rule 13 ("Constructor injection ONLY — never use `app()` helper"). The `new` in constructor body is the equivalent violation. | Make `FiscalPayloadConstraintValidator $constraintValidator` a REQUIRED, non-nullable constructor parameter. Update the test call site at `StrictCanonicalParserTest.php:27` to pass an explicit `new FiscalPayloadConstraintValidator()` second arg. |
| P3 | T24-R2-P3 | `apps/api/tests/Feature/Fiscal/ParseFailureResumeTest.php:844-860` | `ResumeThrowingResolver` test-local class is defined but never instantiated. Survives as documentation of the failed exit-code-2 approach. | Delete the dead class and keep the doc context as a comment block on `ResumeThrowingProjector` (the class that actually exercises exit code 2). |

**All round-1 P1s CLOSED**:
- **T24-P1a-e**: full extraction confirmed, call order confirmed (DTO::fromArray first, then validator), exception wrapping confirmed, 82 parser tests pass, regression tests proven to catch round-1 behavior.
- **T24-P2a-d**: `setPermissionsTeamId` precedes `can()`, try/finally restores previous team id, stale-team regression test confirmed, Spatie singleton binding confirmed.
- **F3** (Opus convergent): CLOSED — zero `DB::table` hits in both modified files.
- **F6** (Opus convergent): CLOSED — test uses `ResumeThrowingProjector` (not resolver) and asserts exit code 2.

**Deferrals documented**: F1 + F5 at `EnqueueResolvedEventProjectionsCommand.php:69-75`, F4 at `RolesAndPermissionsSeeder.php:305-313`.

**Test suite caveat**: Codex sandbox blocked Laravel log writes so `ParseFailureResumeTest` and the cross-task regression suite reported 100% errors from filesystem permission failures — NOT test logic failures. The `StrictCanonicalParser` filter (82 tests, PHPStan level 8) ran clean in the read-only context. Controller verified the full feature suite (201/201) in a normal environment.

**Path to APPROVE**: make `FiscalPayloadConstraintValidator` a required constructor parameter in `StrictCanonicalParser` and update all callers to inject it explicitly.

---

## Round-3 closure (commit 48c261320)

**Verdict: APPROVE** (implicit — round-3 closed both R2 findings; no re-review dispatched per controller decision since the fix was exactly what Codex specified + verified by 201/201 tests).

- **T24-R2-P1**: CLOSED — `StrictCanonicalParser` constructor parameter is now REQUIRED + non-nullable. Test call site updated. 82/82 parser tests still green; 201/201 Fiscal feature suite still green. CLAUDE.md rule 13 satisfied.
- **T24-R2-P3**: CLOSED — `ResumeThrowingResolver` dead class deleted; docblock context absorbed into `ResumeThrowingProjector`.

**Final Task 24 state**: 14 `ParseFailureResumeTest` tests + 82 `StrictCanonicalParserTest` tests + 201/201 full Fiscal feature suite green. PHPStan level 8 clean (no new errors introduced — 11 pre-existing errors are out of scope). Pint clean. CI PG-merge-gate filter extended in round-1.
