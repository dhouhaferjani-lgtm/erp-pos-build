<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\PersonalAccessToken;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Sanctum personal access token pinned to the CENTRAL connection (T6 Phase 0b).
 *
 * Under database-per-tenant, `personal_access_tokens` lives in the central
 * database (topology contract §2). The pre-auth ResolveTenancy middleware reads
 * the bearer token to extract its `tenant:<uuid>` ability and initializes
 * tenancy — which swaps the DEFAULT connection to the tenant database. Sanctum's
 * `auth:sanctum` guard then runs its OWN `PersonalAccessToken::findToken()`
 * AFTER that swap, so on the default (now tenant) connection it would miss the
 * central token row and bearer auth would break.
 *
 * Pinning the model to the central connection fixes the TOKEN lookup. The
 * connection is resolved via the Stancl CentralConnection trait —
 * `config('tenancy.database.central_connection')` — exactly like the Tenant
 * model, rather than a hard-coded literal. In production that is `central`
 * (DB_CONNECTION=central); in the single-connection test suite it resolves to
 * the default connection, so RefreshDatabase transaction isolation is preserved
 * (a hard-coded second connection to the same physical DB would leak token rows
 * across tests).
 */
class CentralPersonalAccessToken extends PersonalAccessToken
{
    use CentralConnection;

    /**
     * Eloquent would derive "central_personal_access_tokens" from the class
     * name; keep Sanctum's real table.
     *
     * @var string
     */
    protected $table = 'personal_access_tokens';

    /**
     * The token row lives in central, but its `tokenable` (e.g. a User) lives in
     * the active TENANT database. Eloquent forces a related model onto THIS
     * model's (central) connection whenever the related model has no explicit
     * connection — which would make `$token->tokenable` look for the user in the
     * central database and fail.
     *
     * `newRelatedInstance` is the lazy-load path that Sanctum's guard uses when
     * it accesses `$accessToken->tokenable`. Overriding it so the related model
     * keeps its OWN default connection (the tenant database the resolver swapped
     * in) is what makes the tokenable resolve tenant-side.
     *
     * @template TRelatedModel of Model
     *
     * @param  class-string<TRelatedModel>  $class
     * @return TRelatedModel
     */
    protected function newRelatedInstance($class)
    {
        return new $class;
    }
}
