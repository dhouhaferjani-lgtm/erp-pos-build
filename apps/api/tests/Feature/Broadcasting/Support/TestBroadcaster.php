<?php

declare(strict_types=1);

namespace Tests\Feature\Broadcasting\Support;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Illuminate\Http\Response;

/**
 * Minimal `Broadcaster` driver for behavioral integration tests of the
 * broadcast-channel auth callbacks.
 *
 * The default `null` driver (set in phpunit.xml) DOES NOT run the
 * channel-auth closures — its `auth()` method is a no-op, so every
 * /broadcasting/auth call returns 200 with an empty body regardless
 * of the closure's return value. That defeats the purpose of an
 * integration test against the closures.
 *
 * This driver implements `auth()` by delegating to the abstract
 * Broadcaster's `verifyUserCanAccessChannel()`, which:
 *   - Strips `private-` / `presence-` prefix from the channel name.
 *   - Matches the name against registered channel patterns.
 *   - Invokes the matching closure with the authenticated user +
 *     resolved wildcard parameters.
 *   - Throws AccessDeniedHttpException (→ 403) if the closure returns
 *     `false` (or no pattern matches).
 *   - Returns a truthy auth-response on success (→ 200 from
 *     validAuthenticationResponse below).
 *
 * `validAuthenticationResponse()` returns an empty array, which
 * Laravel's broadcasting controller serializes to a 200 JSON response.
 *
 * `broadcast()` is a no-op — these tests don't exercise event
 * broadcasting, only auth.
 */
final class TestBroadcaster extends Broadcaster
{
    use UsePusherChannelConventions;

    public function auth($request)
    {
        $channelName = $this->normalizeChannelName($request->channel_name);

        return $this->verifyUserCanAccessChannel($request, $channelName);
    }

    public function validAuthenticationResponse($request, $result)
    {
        // Return a small, predictable auth payload. The HTTP layer
        // serializes this to a 200 JSON response.
        return new Response(json_encode(['auth' => 'ok']), 200, [
            'Content-Type' => 'application/json',
        ]);
    }

    public function broadcast(array $channels, $event, array $payload = []): void
    {
        // No-op: integration tests don't exercise broadcasting itself.
    }
}
