<?php

declare(strict_types=1);

namespace App\Modules\Channel\Infrastructure\Directory;

use App\Modules\Channel\Domain\Models\Channel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
    /**
     * Register (or refresh) the central pointer for a channel.
     *
     * Returns false when the channel's company — and therefore its tenant —
     * cannot be resolved. That is a data defect, not a routine outcome: it is
     * logged rather than thrown, because it must never be the reason a channel
     * cannot be created or a nightly sweep aborts. The consequence is confined
     * to that one channel's webhooks being unroutable, which the fail-closed
     * 404 in ChannelWebhookController makes visible.
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

        ChannelWebhookDirectoryEntry::query()->updateOrCreate(
            ['channel_id' => $channel->id],
            ['tenant_id' => $tenantId],
        );

        return true;
    }

    public function forget(Channel $channel): void
    {
        ChannelWebhookDirectoryEntry::query()->whereKey($channel->id)->delete();
    }

    /**
     * Resolve the tenant that owns a channel id, from CENTRAL context.
     */
    public function resolveTenantId(string $channelId): ?string
    {
        $tenantId = ChannelWebhookDirectoryEntry::query()
            ->whereKey($channelId)
            ->value('tenant_id');

        return is_string($tenantId) && $tenantId !== '' ? $tenantId : null;
    }
}
