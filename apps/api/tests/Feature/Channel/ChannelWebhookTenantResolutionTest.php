<?php

declare(strict_types=1);

namespace Tests\Feature\Channel;

use App\Modules\Channel\Application\Contracts\ChannelAdapter;
use App\Modules\Channel\Application\Contracts\ChannelSignatureStrategy;
use App\Modules\Channel\Application\DTOs\ConnectionTestResult;
use App\Modules\Channel\Application\DTOs\OrderStatusUpdateDTO;
use App\Modules\Channel\Application\DTOs\PriceUpdateDTO;
use App\Modules\Channel\Application\DTOs\StockUpdateDTO;
use App\Modules\Channel\Application\DTOs\SyncResult;
use App\Modules\Channel\Application\Jobs\IngestChannelOrderJob;
use App\Modules\Channel\Application\Services\AdapterRegistry;
use App\Modules\Channel\Domain\Enums\ChannelConnectionStatus;
use App\Modules\Channel\Domain\Models\Channel;
use App\Modules\Channel\Domain\Models\ChannelProductMapping;
use App\Modules\Channel\Infrastructure\Directory\ChannelWebhookDirectoryEntry;
use App\Modules\Channel\Infrastructure\Directory\ChannelWebhookDirectoryRegistrar;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Tenant resolution for the unauthenticated channel webhook.
 *
 * `POST api/v1/webhooks/channels/{channelId}` runs `['api']` only — external
 * sales platforms call it directly, so there is no bearer token, no session and
 * no signed link, and `ResolveTenancy` binds nothing. The controller then did
 * `Channel::query()->findOrFail($channelId)` — and `channels` is a TENANT
 * table, so since the 2026-05-28 database-per-tenant flip every external
 * callback hit CENTRAL and 500'd with 42P01.
 *
 * The channel id in the URL is the ONLY identifier the request carries, and it
 * is unreadable without already knowing the tenant. Signature verification
 * cannot come first either — it needs the channel's adapter and credentials.
 * The tenant therefore has to be resolvable from CENTRAL, which is what
 * `channel_webhook_directory` is for.
 */
final class ChannelWebhookTenantResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_creating_a_channel_registers_it_in_the_central_directory(): void
    {
        $tenant = $this->createTenant('channel-dir-create');
        $channel = $this->createChannel($this->createCompany($tenant, 'CREATE'));

        $entry = ChannelWebhookDirectoryEntry::query()->find($channel->id);

        $this->assertNotNull($entry, 'Every channel must be resolvable from central, or its webhook is undeliverable.');
        $this->assertSame($tenant->id, $entry->tenant_id);
    }

    public function test_deleting_a_channel_removes_its_directory_entry(): void
    {
        $tenant = $this->createTenant('channel-dir-delete');
        $channel = $this->createChannel($this->createCompany($tenant, 'DELETE'));

        $channel->delete();

        $this->assertNull(ChannelWebhookDirectoryEntry::query()->find($channel->id));
    }

    public function test_a_webhook_for_an_unknown_channel_fails_closed(): void
    {
        Queue::fake();

        $response = $this->postWebhook((string) Str::uuid(), ['external_order_id' => 'EXT-1']);

        $response->assertNotFound();
        Queue::assertNothingPushed();
    }

    /**
     * Fail-closed, not fan-out: an unresolvable channel id must NOT trigger a
     * search across every tenant database. This endpoint is unauthenticated, so
     * a per-tenant scan would be a free amplification vector — one forged
     * request costing N database switches.
     */
    public function test_an_unknown_channel_never_reaches_the_adapter_registry(): void
    {
        Queue::fake();

        $registry = app(AdapterRegistry::class);
        $adapter = new RecordingChannelAdapter;
        $registry->register('shopify', $adapter);

        $this->postWebhook((string) Str::uuid(), ['external_order_id' => 'EXT-2'])->assertNotFound();

        $this->assertSame(0, $adapter->verifyCalls);
    }

    /**
     * B1 (2026-08-05 adversarial review, Critical). `channel_webhook_directory
     * .channel_id` is a PostgreSQL `uuid` column, so `where channel_id =
     * 'garbage'` raises SQLSTATE 22P02 — and nothing renders `QueryException`,
     * so an anonymous caller could drive unbounded 500s / Sentry events on an
     * UNAUTHENTICATED route with a one-line curl.
     *
     * The suite runs on SQLite, which happily compares a TEXT primary key to
     * 'garbage' and returns no rows — so a behavioural "assert 404" test alone
     * is green on the broken code. The assertion that actually goes red is that
     * the malformed id NEVER REACHES THE QUERY.
     */
    public function test_a_non_uuid_channel_id_never_reaches_the_directory_query(): void
    {
        Queue::fake();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $response = $this->postWebhook('not-a-uuid', ['external_order_id' => 'EXT-BAD']);

        $queries = DB::getRawQueryLog();
        DB::disableQueryLog();

        $response->assertNotFound();

        $directoryQueries = array_values(array_filter(
            $queries,
            static fn (array $entry): bool => str_contains((string) $entry['raw_query'], 'channel_webhook_directory'),
        ));

        $this->assertSame(
            [],
            $directoryQueries,
            'A non-UUID channel id must be rejected BEFORE the central directory lookup — on PostgreSQL that '
            .'lookup raises 22P02 and the unauthenticated route answers 500.',
        );

        Queue::assertNothingPushed();
    }

    /**
     * Layer 1 of the B1 fix: the route itself refuses a non-UUID segment, so a
     * malformed id never even enters the middleware stack.
     */
    public function test_the_webhook_route_constrains_the_channel_id_to_a_uuid(): void
    {
        $route = Route::getRoutes()->getByName('channels.webhooks.ingest');

        $this->assertNotNull($route);

        $pattern = $route->wheres['channelId'] ?? null;

        $this->assertIsString(
            $pattern,
            'The unauthenticated webhook route must constrain {channelId} to a UUID.',
        );
        $this->assertSame(1, preg_match('~^'.$pattern.'$~', (string) Str::uuid()));
        $this->assertSame(0, preg_match('~^'.$pattern.'$~', 'not-a-uuid'));
    }

    /**
     * Layer 2 of the B1 fix: the registrar is the last line of defence for any
     * caller that reaches it without the route constraint (a direct call, a
     * future route, a copy of this endpoint). Same fail-closed answer, no query.
     */
    public function test_the_directory_lookup_rejects_a_non_uuid_without_querying(): void
    {
        $registrar = app(ChannelWebhookDirectoryRegistrar::class);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $resolved = $registrar->resolveTenantId('not-a-uuid');

        $queries = DB::getRawQueryLog();
        DB::disableQueryLog();

        $this->assertNull($resolved);
        $this->assertSame([], $queries, 'A non-UUID id must not reach the uuid-typed primary key at all.');
    }

    /**
     * Layer 3 of the B1 fix: the route is unauthenticated by design, so without
     * a limiter any caller gets an unbounded free channel into the central
     * directory (and, for a real channel id, into a tenant database switch).
     */
    public function test_the_webhook_route_is_rate_limited(): void
    {
        Queue::fake();

        $this->assertContains(
            'throttle:channel-webhook',
            Route::getRoutes()->getByName('channels.webhooks.ingest')?->gatherMiddleware() ?? [],
        );

        $this->assertNotNull(
            RateLimiter::limiter('channel-webhook'),
            'The named limiter the route references must be registered, or the throttle middleware throws.',
        );

        $unknown = (string) Str::uuid();

        for ($i = 0; $i < 60; $i++) {
            $this->postWebhook($unknown, ['external_order_id' => 'EXT-RL'])->assertNotFound();
        }

        $this->postWebhook($unknown, ['external_order_id' => 'EXT-RL'])->assertStatus(429);
    }

    /**
     * M1 (2026-08-05 review): the three fail-closed 404s must be
     * INDISTINGUISHABLE. `Channel::findOrFail()` rendered a DIFFERENT body
     * (`{"error":{"code":"NOT_FOUND","message":"… Channel …"}}`) via the
     * ModelNotFoundException handler, so a directory row pointing at a live
     * tenant with no channel row was tellable apart from an unknown id.
     */
    public function test_every_fail_closed_404_returns_the_same_body(): void
    {
        Queue::fake();

        $unknownId = $this->postWebhook((string) Str::uuid(), [])->assertNotFound();

        // Directory row whose tenant no longer exists.
        $deadTenantChannelId = (string) Str::uuid();
        ChannelWebhookDirectoryEntry::query()->create([
            'channel_id' => $deadTenantChannelId,
            'tenant_id' => (string) Str::uuid(),
        ]);
        $deadTenant = $this->postWebhook($deadTenantChannelId, [])->assertNotFound();

        // Directory row + live tenant, but the channel row itself is gone
        // (deleted with raw SQL / restored snapshot — the observer never fired).
        $tenant = $this->createTenant('channel-dir-oracle');
        $ghostChannelId = (string) Str::uuid();
        ChannelWebhookDirectoryEntry::query()->create([
            'channel_id' => $ghostChannelId,
            'tenant_id' => $tenant->id,
        ]);
        $ghostChannel = $this->postWebhook($ghostChannelId, [])->assertNotFound();

        $this->assertSame($unknownId->json(), $deadTenant->json());
        $this->assertSame(
            $unknownId->json(),
            $ghostChannel->json(),
            'A directory row whose channel row is missing must not be distinguishable from an unknown channel id.',
        );
    }

    public function test_a_webhook_resolves_the_tenant_and_dispatches_the_ingest_job_with_that_anchor(): void
    {
        Queue::fake();

        $tenant = $this->createTenant('channel-dir-webhook');
        $channel = $this->createChannel($this->createCompany($tenant, 'HOOK'));

        $registry = app(AdapterRegistry::class);
        $adapter = new RecordingChannelAdapter;
        $registry->register('shopify', $adapter);

        $this->postWebhook($channel->id, ['external_order_id' => 'EXT-3'])->assertStatus(202);

        $this->assertSame(1, $adapter->verifyCalls);

        Queue::assertPushed(IngestChannelOrderJob::class, 1);
        $pushed = Queue::pushed(IngestChannelOrderJob::class)->first();
        $this->assertNotNull($pushed);
        $this->assertSame(
            $tenant->id,
            (string) (new ReflectionProperty($pushed, 'tenantId'))->getValue($pushed),
        );
    }

    /**
     * Self-heal: channels created BEFORE the directory existed have no entry,
     * and there is no central backfill possible from a central migration. The
     * nightly per-tenant reconciliation sweep already visits every channel of
     * every tenant, so it re-registers what is missing.
     */
    public function test_the_nightly_reconcile_sweep_backfills_a_missing_directory_entry(): void
    {
        Queue::fake();

        $tenant = $this->createTenant('channel-dir-heal');
        $channel = $this->createChannel($this->createCompany($tenant, 'HEAL'));

        ChannelWebhookDirectoryEntry::query()->whereKey($channel->id)->delete();
        $this->assertNull(ChannelWebhookDirectoryEntry::query()->find($channel->id));

        $this->assertSame(0, Artisan::call('channels:reconcile'));

        $entry = ChannelWebhookDirectoryEntry::query()->find($channel->id);
        $this->assertNotNull($entry);
        $this->assertSame($tenant->id, $entry->tenant_id);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function postWebhook(string $channelId, array $body): TestResponse
    {
        return $this->postJson(
            "/api/v1/webhooks/channels/{$channelId}",
            $body,
            ['X-Channel-Timestamp' => (string) time()],
        );
    }

    private function createTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => str_replace('-', ' ', ucfirst($slug)),
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    private function createCompany(Tenant $tenant, string $suffix): Company
    {
        return Company::create([
            'tenant_id' => $tenant->id,
            'name' => "Channel Company {$suffix}",
            'legal_name' => "Channel Company {$suffix} LLC",
            'tax_id' => "TAX-CHW-{$suffix}",
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
    }

    private function createChannel(Company $company): Channel
    {
        return Channel::create([
            'company_id' => $company->id,
            'name' => 'Webhook Channel',
            'adapter_type' => 'shopify',
            'is_active' => true,
            'connection_status' => ChannelConnectionStatus::Connected,
        ]);
    }
}

/**
 * Minimal in-memory adapter: the module ships no concrete adapters yet
 * (AdapterRegistry::resolve() says "Concrete adapters ship in a follow-up
 * sprint"), so the webhook path can only be exercised with a test double.
 */
final class RecordingChannelAdapter implements ChannelAdapter, ChannelSignatureStrategy
{
    public int $verifyCalls = 0;

    public function adapterType(): string
    {
        return 'shopify';
    }

    public function signatureStrategy(): ChannelSignatureStrategy
    {
        return $this;
    }

    public function verify(Request $request, Channel $channel): bool
    {
        $this->verifyCalls++;

        return true;
    }

    public function pushProduct(Product $product, ?object $variant, ChannelProductMapping $mapping): SyncResult
    {
        throw new \RuntimeException('not used');
    }

    public function pushStock(StockUpdateDTO $update): SyncResult
    {
        throw new \RuntimeException('not used');
    }

    public function pushPriceUpdate(PriceUpdateDTO $update): SyncResult
    {
        throw new \RuntimeException('not used');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function pullOrders(Channel $channel): Collection
    {
        return new Collection;
    }

    public function updateOrderStatus(string $externalOrderId, OrderStatusUpdateDTO $update): SyncResult
    {
        throw new \RuntimeException('not used');
    }

    public function testConnection(Channel $channel): ConnectionTestResult
    {
        throw new \RuntimeException('not used');
    }
}
