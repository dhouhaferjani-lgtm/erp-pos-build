<?php

declare(strict_types=1);

namespace App\Modules\Channel\Infrastructure\Directory;

use App\Modules\Channel\Domain\Models\Channel;

/**
 * Mirrors channel lifecycle into the central webhook directory.
 *
 * An observer rather than a call inside ChannelService::create() on purpose:
 * channels are also minted by seeders, factories and tests, and a channel that
 * is missing from the directory has silently undeliverable webhooks. The
 * lifecycle hook is the only place that covers every creation path.
 *
 * A channel whose `company_id` moves to a different tenant would also need a
 * refresh, hence the `updated` hook — it is an updateOrCreate, so re-running it
 * is free.
 */
final class ChannelWebhookDirectoryObserver
{
    public function __construct(
        private readonly ChannelWebhookDirectoryRegistrar $registrar,
    ) {}

    public function created(Channel $channel): void
    {
        $this->registrar->register($channel);
    }

    public function updated(Channel $channel): void
    {
        if ($channel->wasChanged('company_id')) {
            $this->registrar->register($channel);
        }
    }

    public function deleted(Channel $channel): void
    {
        $this->registrar->forget($channel);
    }
}
