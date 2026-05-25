<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Domain;

use App\Modules\Tenant\Application\Services\IdentityIndexService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Central identity index row (topology contract §9.1).
 *
 * A lightweight CENTRAL lookup mapping an email to a tenant membership.
 * Pointers only — never credentials (password hashes stay tenant-side in each
 * tenant's `users` table). Answers "which tenant DB(s) do I open for this
 * email?" and nothing more.
 *
 * The single writer is {@see IdentityIndexService}.
 *
 * @property string $id
 * @property string $email
 * @property string $tenant_id
 * @property string|null $user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CentralIdentity extends Model
{
    use HasUuids;

    protected $table = 'central_identities';

    protected $fillable = [
        'email',
        'tenant_id',
        'user_id',
    ];
}
