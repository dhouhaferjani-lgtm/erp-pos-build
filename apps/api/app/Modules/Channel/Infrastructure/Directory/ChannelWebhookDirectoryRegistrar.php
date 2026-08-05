<?php

declare(strict_types=1);

namespace App\Modules\Channel\Infrastructure\Directory;

use App\Modules\Channel\Domain\Models\Channel;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Keeps `channel_webhook_directory` in step with the tenant-side `channels`
 * table.
 *
 * The owning tenant is read from the channel's COMPANY rather than from
 * `tenant('id')`: the company row is the authoritative anchor, and it is the
 * only source that works in both database-per-tenant mode (where the company
 * lives in the bound tenant database) and legacy row-level mode (where nothing
 * is bound at all — the test suite's mode).
 */
final class ChannelWebhookDirectoryRegistrar
{
    /** Cap on ids echoed into a log line — the pruned set can be large. */
    private const MAX_LOGGED_IDS = 20;

    /**
     * Register (or refresh) the central pointer for a channel.
     *
     * Returns false — never throws — when the pointer could not be written,
     * for either reason: the channel's company (and therefore its tenant) could
     * not be resolved, or the central write itself failed. Both are logged.
     *
     * **Why it swallows** (M2, 2026-08-05 review — the docblock previously
     * promised this and only delivered it for the first branch): this runs
     * inside a model observer on `Channel::created`, so a propagating exception
     * would abort the caller's transaction and make a central-connection blip
     * the reason a tenant cannot create a channel. The consequence of the
     * swallow is confined to that one channel's webhooks being unroutable,
     * which the fail-closed 404 in ChannelWebhookController makes visible and
     * the nightly `channels:reconcile` sweep self-heals.
     */
    public function register(Channel $channel): bool
    {
        // A raw table read, not the Company MODEL, on purpose: ownership is what
        // this pointer records, and ownership does not change when a company is
        // soft-deleted. Going through the model would apply the SoftDeletes
        // global scope and silently drop the entry for a soft-deleted company,
        // turning that channel's webhooks into a 404 the operator cannot explain.
        $tenantId = DB::table('companies')
            ->where('id', $channel->company_id)
            ->value('tenant_id');

        if (! is_string($tenantId) || $tenantId === '') {
            Log::warning('Channel webhook directory: could not resolve the owning tenant for a channel; its webhooks will not be routable.', [
                'channel_id' => $channel->id,
                'company_id' => $channel->company_id,
            ]);

            return false;
        }

        try {
            ChannelWebhookDirectoryEntry::query()->updateOrCreate(
                ['channel_id' => $channel->id],
                ['tenant_id' => $tenantId],
            );
        } catch (Throwable $e) {
            Log::error('Channel webhook directory: the central pointer could not be written; this channel\'s webhooks will not be routable until the next channels:reconcile sweep.', [
                'channel_id' => $channel->id,
                'tenant_id' => $tenantId,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    public function forget(Channel $channel): void
    {
        ChannelWebhookDirectoryEntry::query()->whereKey($channel->id)->delete();
    }

    /**
     * Drop directory rows for a tenant whose channel no longer exists.
     *
     * R2 (2026-08-05 review): {@see self::forget()} is wired to the Eloquent
     * `deleted` event ONLY, so a channel removed by raw SQL, a `truncate`, or a
     * tenant database restored to a pre-channel snapshot leaves its pointer
     * behind forever. A stale pointer is not inert: the webhook controller
     * binds tenancy (a full database switch) BEFORE it discovers the channel
     * row is gone, so each one buys an anonymous caller the exact cost the
     * fail-closed design exists to avoid.
     *
     * Called from `channels:reconcile` inside each tenant's iteration slot,
     * with `$liveChannelIds` read from that tenant's own database. A tenant the
     * sweep never reached (absent database, failed probe) is never passed here,
     * so an unreachable tenant's pointers are preserved rather than pruned.
     *
     * @param  list<string>  $liveChannelIds
     * @return list<string> the channel ids whose pointers were removed
     */
    public function pruneTenant(string $tenantId, array $liveChannelIds): array
    {
        $stale = ChannelWebhookDirectoryEntry::query()
            ->where('tenant_id', $tenantId)
            ->when($liveChannelIds !== [], static fn ($query) => $query->whereNotIn('channel_id', $liveChannelIds))
            ->pluck('channel_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        if ($stale === []) {
            return [];
        }

        ChannelWebhookDirectoryEntry::query()->whereIn('channel_id', $stale)->delete();

        Log::warning('Channel webhook directory: pruned pointers whose channel no longer exists in the owning tenant.', [
            'tenant_id' => $tenantId,
            'pruned_count' => count($stale),
            'channel_ids' => array_slice($stale, 0, self::MAX_LOGGED_IDS),
        ]);

        return array_values($stale);
    }

    /**
     * Drop directory rows whose tenant is gone from the CENTRAL directory.
     *
     * The deprovisioning half of R2: {@see self::pruneTenant()} can only ever
     * run for a tenant that still has a directory row to iterate, so a
     * deprovisioned tenant's pointers are unreachable by it. Expressed as a
     * subquery on purpose — an empty `tenants` table then legitimately prunes
     * everything, while a central read that FAILS raises instead of deleting.
     *
     * @return int the number of pointers removed
     */
    public function pruneDeprovisionedTenants(): int
    {
        $orphans = ChannelWebhookDirectoryEntry::query()
            ->whereNotIn('tenant_id', Tenant::query()->select('id'))
            ->pluck('channel_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($orphans === []) {
            return 0;
        }

        ChannelWebhookDirectoryEntry::query()->whereIn('channel_id', $orphans)->delete();

        Log::warning('Channel webhook directory: pruned pointers whose tenant is no longer in the central directory.', [
            'pruned_count' => count($orphans),
            'channel_ids' => array_slice($orphans, 0, self::MAX_LOGGED_IDS),
        ]);

        return count($orphans);
    }

    /**
     * Resolve the tenant that owns a channel id, from CENTRAL context.
     *
     * B1 (2026-08-05 review, Critical): `channel_webhook_directory.channel_id`
     * is a PostgreSQL `uuid` column, so binding a non-UUID here raises
     * SQLSTATE 22P02 — and `bootstrap/app.php` registers no `QueryException`
     * render callback, so the UNAUTHENTICATED webhook route answered 500 for
     * `POST /api/v1/webhooks/channels/garbage`. The route constrains the
     * segment too; this guard is what makes the rule hold for every caller
     * rather than for one route declaration. A malformed id is answered exactly
     * like an unknown one — null here, 404 upstream — so nothing about channel
     * existence leaks either way.
     */
    public function resolveTenantId(string $channelId): ?string
    {
        if (! Str::isUuid($channelId)) {
            return null;
        }

        $tenantId = ChannelWebhookDirectoryEntry::query()
            ->whereKey($channelId)
            ->value('tenant_id');

        return is_string($tenantId) && $tenantId !== '' ? $tenantId : null;
    }
}
