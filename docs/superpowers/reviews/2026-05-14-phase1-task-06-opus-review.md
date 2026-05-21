# Phase 1 Task 6 — `FiscalIntegrityProvider` + `HashChainIntegrityProvider` (PHP + TS) + `SignatureProviderInterface` — Opus review

**Date:** 2026-05-15
**Reviewer:** Opus (headless review gate)
**Scope:** Task 6 from `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:488–564` — Phase-1 integrity provider interface + concrete `HashChainIntegrityProvider` (PHP and TS), the designed-for `SignatureProviderInterface`, and the `FiscalServiceProvider` binding.
**Base SHA:** `296d9d61583e91f68d0a2864d0de3bc58ba77835` (Task 5 head — `FiscalEventCanonicalEncoder` TS + golden vectors)
**Head SHA:** `3ad6fe49affb38fc67a22c3d13db79fc4e0fd899` (Task 6 head)
**Diff vs. base:** 5 new files + 1 edited file, 140 insertions, 0 deletions.

- New `apps/api/app/Shared/Contracts/Fiscal/FiscalIntegrityProvider.php` (+14)
- New `apps/api/app/Shared/Contracts/Fiscal/SignatureProviderInterface.php` (+20)
- New `apps/api/app/Modules/Fiscal/Application/Services/HashChainIntegrityProvider.php` (+25)
- Edited `apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php` (+7) — adds `register()` binding
- New `apps/api/tests/Unit/Fiscal/HashChainIntegrityProviderTest.php` (+34) — hash test + container-binding test
- New `apps/pos/src/lib/fiscal/HashChainIntegrityProvider.ts` (+23)
- New `apps/pos/src/lib/fiscal/__tests__/HashChainIntegrityProvider.test.ts` (+17)

**Verdict:** **APPROVE**

Implementation matches plan §488–564 and is consistent with spec §5.1 / §5.2 intent. The single concrete provider is correctly identified as **sequence integrity, not authorship** (`signature_version = 'hash-chain-integrity-v1'`), `SignatureProviderInterface` ships **designed-for, not built** (interface only, no concrete implementation), and `FiscalServiceProvider::register()` binds the interface to the hash-chain provider so downstream typehints (`OutboxIngestor`, `VerifyEventChainCommand` in later tasks) will resolve. Both tests pass locally and are non-tautological: the PHP test pins the `hash-chain-integrity-v1` version string, the lowercase-hex SHA-256 contract, and verifies provider wiring through the real Laravel container; the TS test pins the same version string, the deterministic 64-char lowercase hex shape, and the tamper-false path. No module-boundary violations (`HashChainIntegrityProvider` depends only on `App\Shared\Contracts\Fiscal\…`; TS provider only imports the local canonical encoder). No regressions — the change is purely additive plus a 4-line `register()` insertion into a previously-empty `register()`-less service provider. **No findings raised to BLOCKER, P1, or P2.** Three optional P3 observations are noted below for awareness only; none gates merge or Task 7.

---

## Verification summary

### Plan / spec conformance

| Check | Result | Evidence |
|---|---|---|
| Files exist at the plan-specified paths | ✓ | All five new file paths from plan §492–497 are present verbatim; `FiscalServiceProvider.php` edit lands as plan §535 prescribed. |
| `version()` returns `'hash-chain-integrity-v1'` | ✓ | `apps/api/app/Modules/Fiscal/Application/Services/HashChainIntegrityProvider.php:11–14`; `apps/pos/src/lib/fiscal/HashChainIntegrityProvider.ts:12–14`. Matches spec §5.1 line 273 and the embedded JSON sample at spec §3 line 107 (`'hash-chain-integrity-v1' in Phase 1`). |
| `computeHash` = lowercase-hex SHA-256 over UTF-8 canonical bytes | ✓ | PHP `:16–19` delegates to `hash('sha256', $canonicalBytes)` (lowercase hex by default). TS `:16–18` delegates to `FiscalEventCanonicalEncoder.sha256Hex()` which uses `new TextEncoder().encode(...)` + `byte.toString(16).padStart(2, '0')` (`apps/pos/src/lib/fiscal/FiscalEventCanonicalEncoder.ts:73–77`). Both produce the 64-char `/^[0-9a-f]{64}$/` shape asserted by tests. |
| `verify(canonicalBytes, currentHash)` recomputes and compares | ✓ | PHP `:21–24` uses `hash_equals($this->computeHash($canonicalBytes), $currentHash)` (constant-time). TS `:20–22` uses strict equality on the recomputed hash. Hash is non-secret integrity data — see P3 #2 below. |
| `signature_version` value flows through unchanged | ✓ | Identical literal string `'hash-chain-integrity-v1'` in PHP and TS provider; matches the `signature_version` column type spec §3 line 107 (`TEXT NOT NULL — 'hash-chain-integrity-v1' in Phase 1`) and line 152 (`VARCHAR(64) NOT NULL`). Length 25 fits the upcoming `VARCHAR(64)` column. |
| `FiscalIntegrityProvider` interface is the shared contract | ✓ | `apps/api/app/Shared/Contracts/Fiscal/FiscalIntegrityProvider.php:7–14` declares the three methods. Lives in `App\Shared\Contracts\Fiscal\` (the cross-module contracts namespace, per spec §1.1 / plan §41), not inside the Fiscal module — so downstream consumers (Task 8 `OutboxIngestor`, Task 18 `VerifyEventChainCommand`) can typehint without taking a Fiscal-module dependency. |
| `SignatureProviderInterface` is designed-for, not built | ✓ | `apps/api/app/Shared/Contracts/Fiscal/SignatureProviderInterface.php:7–20` declares `sign(string $canonicalBytes, array $context = []): array{status: 'pending'\|'signed'\|'failed', signature?: string, transaction_id?: string, reason?: string}` plus the three capability flags (`requiresConnectivity()`, `signsSynchronously()`, `assignsTransactionId()`). No `implements SignatureProviderInterface` exists anywhere in the repo (`Grep` over `apps/erp` returns zero implementers — only the interface file, the provider file's import, and docs). Matches spec §5.2 exactly. |
| Async-capable / capability-flag shape | ✓ | Return-array discriminator `status: 'pending'\|'signed'\|'failed'` captures the spec §5.2 "sign() may return pending" requirement; the three boolean capability methods map 1:1 to spec §5.2 lines 286 (`requires_connectivity, signs_synchronously, assigns_transaction_id`). `signature` / `transaction_id` / `reason` are correctly optional via the PHPDoc `?` markers. |
| `FiscalServiceProvider` binds the interface | ✓ | `apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php:14–17` adds `register()` with `$this->app->bind(FiscalIntegrityProvider::class, HashChainIntegrityProvider::class);`. The `boot()` method that Task 2 added is preserved verbatim. |
| Provider is loaded by Laravel | ✓ | `apps/api/bootstrap/providers.php:15` imports `FiscalServiceProvider`; `:74` lists it in the providers array. Phase-1 register() runs on every container boot, so `app()->make(FiscalIntegrityProvider::class)` resolves to `HashChainIntegrityProvider` in console, HTTP, and test contexts. |
| Tests are real red/green and non-tautological | ✓ | **PHP** (`apps/api/tests/Unit/Fiscal/HashChainIntegrityProviderTest.php:13–25`): asserts `version() === 'hash-chain-integrity-v1'` (independent literal — would catch a typo or wrong version), `computeHash` equals the language built-in `hash('sha256', $bytes)` for a non-trivial 30-byte input (would catch a wrong algorithm or encoding), the regex `/^[0-9a-f]{64}$/` (would catch case or length drift), `verify(bytes, hash) === true`, and `verify(bytes, '0'*64) === false` (tamper case). **PHP container** (`:27–33`): `$this->app->make(FiscalIntegrityProvider::class)` returns a `HashChainIntegrityProvider` instance — exercises the real Laravel container, not a manual `new`, so it would catch a missing `register()` binding, a wrong target class, or a forgotten provider registration. **TS** (`apps/pos/src/lib/fiscal/__tests__/HashChainIntegrityProvider.test.ts:7–16`): same five contract assertions on the device-side provider. None of the assertions tautologically restate the implementation — every test could fail under a plausible bug. |
| PHP test extends Laravel `Tests\TestCase`, not plain PHPUnit | ✓ | `:9` `use Tests\TestCase;` and `:11` `extends TestCase`. `Tests\TestCase` (`apps/api/tests/TestCase.php`) extends `Illuminate\Foundation\Testing\TestCase`, which boots the Laravel app and exposes `$this->app`. The plan's example test at §502–524 incorrectly extended `PHPUnit\Framework\TestCase` (which has no `$this->app`); the implementer correctly upgraded to Laravel's base — a real fix, not drift. |
| Both tests actually pass | ✓ | `cd apps/api && ./vendor/bin/phpunit tests/Unit/Fiscal/HashChainIntegrityProviderTest.php` → **OK (2 tests, 6 assertions)**, 0.639 s. `cd apps/pos && pnpm vitest run src/lib/fiscal/__tests__/HashChainIntegrityProvider.test.ts` → **1 passed**, 819 ms. Run live by reviewer on head SHA `3ad6fe49`. |
| Module-boundary cleanliness | ✓ | `HashChainIntegrityProvider.php:6` imports only `App\Shared\Contracts\Fiscal\FiscalIntegrityProvider` — zero cross-module imports. `FiscalServiceProvider.php:7–9` imports the local module's `HashChainIntegrityProvider` + the `Shared\Contracts\Fiscal\FiscalIntegrityProvider` interface — clean. `HashChainIntegrityProvider.ts:1` imports only the sibling `./FiscalEventCanonicalEncoder` (same `apps/pos/src/lib/fiscal/` directory). Interface files have zero imports. CLAUDE.md rule #6 (`Cross-module communication only via Shared/Contracts/`) is honored. |
| No name/type drift from earlier tasks | ✓ | Class names `HashChainIntegrityProvider`, `FiscalIntegrityProvider`, `SignatureProviderInterface` and the version string `hash-chain-integrity-v1` match plan §38–45 / §75 / §493–496 verbatim. `signature_version` column type expectations from spec §3 (TEXT/VARCHAR(64)) accommodate the 25-char literal. The `SignatureStatus` enum from Task 4 (`apps/api/app/Modules/Fiscal/Domain/Enums/SignatureStatus.php`) defines `not_required\|pending\|signed\|failed`; the `SignatureProviderInterface::sign()` return omits `not_required` correctly (a provider would never *return* "not required" — that status is assigned when no provider runs, per spec §5.2 line 286). |
| Diff scope | ✓ | `git diff --stat 296d9d61..3ad6fe49` shows exactly the five new files plus the seven-line `FiscalServiceProvider` edit — no incidental file edits, no test-fixture changes, no migration files (Task 7+), no consumer wiring. Plan §561–563's `git add` line includes exactly these paths. |
| Regression check | ✓ | The only edit to a pre-existing file is `FiscalServiceProvider.php` adding a `register()` method. The previous `boot()` block (`Task 2` head) is preserved verbatim. No other Phase-1 tests touch `FiscalIntegrityProvider` resolution today, so no existing test could newly fail; the canonical-encoder test from Task 5 (10 vectors, runs in `apps/pos`) is untouched. |
| Source-of-truth alignment | ✓ | `docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md` §4.1 ("integrity, not authorship") and §4.2 (designed-for signature provider) reproduce the same separation. `/Users/houssamr/Downloads/fiscal_chain_architecture_strategy.md` and `…/pos_printable_documents_architecture.md` are background reference for later phases — Task 6 introduces no decision in conflict with them. |

### Comparison to spec §5.1's interface sketch

Spec §5.1 line 276–281 sketches a slightly different interface:
```
interface FiscalIntegrityProvider {
    version(): string
    canonicalBytes(event): bytes
    computeHash(canonicalBytes): hex64
    verify(event): bool
}
```

The implementation deliberately decouples `canonicalBytes` and `verify`:
- `canonicalBytes` is **not** on `FiscalIntegrityProvider`; canonical-byte construction is delegated to `FiscalEventCanonicalEncoder` (Tasks 4 & 5).
- `verify` takes raw bytes + hash, not an event.

This matches plan §531–534 verbatim and is the correct call: it isolates the SHA-256 primitive from the JCS encoder, keeps the integrity provider stateless and free of event-DTO knowledge, and matches the downstream usage at plan §1147 (`canonical_bytes = encoder.encode(...); current_hash = HashChainIntegrityProvider.computeHash(canonical_bytes)`). The spec's interface sketch was a rough description; the plan refined it. No real divergence.

---

## Findings

**None at BLOCKER, P1, or P2.** Three P3 observations follow — informational only, not gating Task 7.

### P3 — Constant-time comparison only on PHP side

`HashChainIntegrityProvider.php:23` uses `hash_equals()`; the TS provider at `HashChainIntegrityProvider.ts:21` uses plain `===`. This is correct for this use case (the hash is a public integrity tag, not a secret — timing leakage reveals nothing useful), and `hash_equals` is the right idiom on PHP regardless. Calling it out only because the asymmetry is the kind of thing a reader might assume is a bug. No fix needed. If a future signature-style provider lands and starts comparing MACs or signatures on the device, the TS side should switch to a constant-time comparator at that point.

### P3 — `SignatureProviderInterface::sign()` return-status duplicates the `SignatureStatus` enum value list

`SignatureProviderInterface.php:11` types the return array's `status` field as the literal union `'pending'|'signed'|'failed'`. The `SignatureStatus` enum (`apps/api/app/Modules/Fiscal/Domain/Enums/SignatureStatus.php:7–13`, added in Task 4) lists `not_required`, `pending`, `signed`, `failed`. Substituting the enum here would be wrong by layering — the enum lives in the Fiscal module's `Domain\Enums\` and a `Shared\Contracts\` interface must not back-depend on a specific module's domain layer. The string-literal union is the architecturally correct choice. The cost is a small one-time maintenance burden: if `SignatureStatus` ever gains a new case (e.g. `retrying`), the union must be updated by hand. Worth a comment-line note here, but not in this task — leave it for whenever a concrete signature provider lands and the union starts mattering operationally.

### P3 — Carry-over deferred items from Task 5 still apply

Task 5's Opus review explicitly deferred two P2s to "before Task 15":
1. Hand-rolled SHA-256 in `FiscalEventCanonicalEncoder.ts:73–186` (no vetted library, no boundary-length test vectors at 55/56/57/63/64/65/119/120 bytes).
2. The TS encoder duplicates the JCS core from `apps/pos/src/lib/fiscal/v3/canonicalJson.ts` instead of sharing one.

The Task-6 TS provider routes `computeHash` through the encoder's `sha256Hex`, so item 1 is now *reused* by the integrity provider as well as the encoder. This does not make item 1 worse (the implementation is unchanged; only one more caller is wired to it), but it does mean a future swap to a vetted SHA-256 library has to touch the encoder, which both the encoder tests and the integrity provider tests will exercise — i.e. when the swap happens, the same single change will revalidate both call sites. Reaffirmed as a tracked follow-up before Task 15; **not** a Task-6 finding.

---

## Sign-off

The change is small, additive, well-tested, and matches plan and spec intent. The provider binding correctly threads through the real Laravel container in the wiring test, so Task 8 (`OutboxIngestor`) can rely on `FiscalIntegrityProvider` resolving when it ships. Nothing on this commit blocks proceeding to Task 7 (`create_fiscal_events_table` migration).

**Verdict: APPROVE — proceed to Task 7.**
