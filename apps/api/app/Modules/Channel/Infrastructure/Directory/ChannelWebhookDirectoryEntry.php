<?php

declare(strict_types=1);

namespace App\Modules\Channel\Infrastructure\Directory;

use App\Modules\Identity\Infrastructure\CentralPersonalAccessToken;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Central `channel_id -> tenant_id` pointer for the unauthenticated channel
 * webhook. See the migration for why it has to exist.
 *
 * Pinned to the central connection via Stancl's `CentralConnection` trait —
 * `config('tenancy.database.central_connection')` — exactly like
 * {@see CentralPersonalAccessToken}, rather
 * than a hard-coded literal. In production that resolves to `central`; in the
 * single-connection test suite it resolves to the default connection, so
 * RefreshDatabase transaction isolation is preserved (a hard-coded second
 * connection to the same physical database would leak rows across tests).
 *
 * Deliberately minimal: no channel name, adapter type, credentials or active
 * flag. Everything about a channel except "which tenant owns it" stays inside
 * the tenant database.
 *
 * @property string $channel_id
 * @property string $tenant_id
 */
final class ChannelWebhookDirectoryEntry extends Model
{
    use CentralConnection;

    protected $table = 'channel_webhook_directory';

    protected $primaryKey = 'channel_id';

    /** @var string */
    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'channel_id',
        'tenant_id',
    ];
}
