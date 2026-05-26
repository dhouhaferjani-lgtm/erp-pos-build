<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE 0b PLACEHOLDER — intentionally skipped in Phase 0a.
 *
 * After the Stancl flip (Phase 0b), `personal_access_tokens` lives in the
 * CENTRAL database while each `tokenable` User lives in its tenant database.
 * Sanctum's `auth:sanctum` runs its own `PersonalAccessToken::findToken()`
 * AFTER tenancy is initialized, so on the default (now tenant) connection it
 * would miss the central PAT row and bearer auth would break.
 *
 * The fix (Phase 0b — needs the central connection from the flip):
 *   - Add `CentralPersonalAccessToken extends Laravel\Sanctum\PersonalAccessToken`
 *     with `protected $connection = 'central';`.
 *   - Register it via `Sanctum::usePersonalAccessTokenModel(CentralPersonalAccessToken::class)`
 *     in a service provider.
 *   - Then this test must assert that bearer auth reads the PAT row from the
 *     CENTRAL connection while the `tokenable` User resolves in TENANT context.
 *
 * It is skipped (not deleted) so the requirement is tracked and turns green the
 * moment Phase 0b lands the central connection. See
 * docs/sessions/2026-05-25-t6-phase0a-status.md (Phase 0b follow-ups).
 *
 * In Phase 0a the pre-auth ResolveTenancy middleware reads the tenant claim from
 * the (shared-connection) PAT, which works because the token table has not yet
 * moved — so nothing is broken today; this only matters post-flip.
 */
class CentralPersonalAccessTokenPhase0bTest extends TestCase
{
    use RefreshDatabase;

    public function test_bearer_auth_reads_pat_from_central_while_tokenable_resolves_in_tenant(): void
    {
        $this->markTestSkipped(
            'Phase 0b: requires the `central` DB connection + CentralPersonalAccessToken '
            .'(Sanctum::usePersonalAccessTokenModel). Not buildable in flip-agnostic Phase 0a.'
        );
    }
}
