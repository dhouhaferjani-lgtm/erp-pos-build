<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class NotificationEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->otherUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        foreach ([$this->user, $this->otherUser] as $user) {
            UserCompanyMembership::create([
                'user_id' => $user->id,
                'company_id' => $this->company->id,
                'role' => 'admin',
            ]);
        }
    }

    public function test_database_channel_writes_a_notification_row(): void
    {
        $this->user->notify(new DatabaseSmokeNotification);

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => $this->user->getMorphClass(),
            'notifiable_id' => $this->user->id,
            'type' => DatabaseSmokeNotification::class,
        ]);
    }

    public function test_index_returns_only_own_notifications_paginated(): void
    {
        $ownIds = [];
        for ($index = 0; $index < 17; $index++) {
            $ownIds[] = $this->insertNotification($this->user, createdAt: Carbon::parse('2026-07-12 12:00:00')->addSeconds($index));
        }
        $foreignId = $this->insertNotification($this->otherUser, createdAt: Carbon::parse('2026-07-12 13:00:00'));

        $response = $this->getAsUser('/api/v1/notifications?filter=all&per_page=10&page=2')
            ->assertOk()
            ->assertJsonCount(7, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 17)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonStructure([
                'data' => [['id', 'type', 'data', 'read_at', 'created_at']],
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            ]);

        /** @var list<array{id: string}> $items */
        $items = $response->json('data');
        $returnedIds = array_column($items, 'id');

        $this->assertNotContains($foreignId, $returnedIds);
        $this->assertSame(array_slice(array_reverse($ownIds), 10), $returnedIds);
    }

    public function test_unread_filter_and_count_are_scoped_to_the_caller(): void
    {
        $firstUnreadId = $this->insertNotification($this->user);
        $secondUnreadId = $this->insertNotification($this->user);
        $readId = $this->insertNotification($this->user, readAt: Carbon::parse('2026-07-12 11:00:00'));
        $this->insertNotification($this->otherUser);

        $unreadResponse = $this->getAsUser('/api/v1/notifications?filter=unread')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        /** @var list<array{id: string}> $unreadItems */
        $unreadItems = $unreadResponse->json('data');
        $this->assertEqualsCanonicalizing([$firstUnreadId, $secondUnreadId], array_column($unreadItems, 'id'));

        $allResponse = $this->getAsUser('/api/v1/notifications?filter=all')
            ->assertOk()
            ->assertJsonCount(3, 'data');
        /** @var list<array{id: string}> $allItems */
        $allItems = $allResponse->json('data');
        $this->assertContains($readId, array_column($allItems, 'id'));

        $this->getAsUser('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertExactJson(['data' => ['count' => 2]]);
    }

    public function test_mark_read_is_idempotent_and_scoped(): void
    {
        $ownId = $this->insertNotification($this->user);
        $foreignId = $this->insertNotification($this->otherUser);

        $this->postAsUser("/api/v1/notifications/{$foreignId}/read")
            ->assertNotFound();

        $this->postAsUser("/api/v1/notifications/{$ownId}/read")
            ->assertOk()
            ->assertExactJson(['message' => 'ok']);

        $firstReadAt = DB::table('notifications')->where('id', $ownId)->value('read_at');
        $this->assertNotNull($firstReadAt);

        $this->postAsUser("/api/v1/notifications/{$ownId}/read")
            ->assertOk()
            ->assertExactJson(['message' => 'ok']);

        $this->assertSame($firstReadAt, DB::table('notifications')->where('id', $ownId)->value('read_at'));
        $this->assertNull(DB::table('notifications')->where('id', $foreignId)->value('read_at'));
    }

    public function test_malformed_id_is_404_not_500(): void
    {
        $this->postAsUser('/api/v1/notifications/not-a-uuid/read')
            ->assertNotFound();
    }

    public function test_read_all_marks_only_the_callers_unread_notifications(): void
    {
        $firstOwnId = $this->insertNotification($this->user);
        $secondOwnId = $this->insertNotification($this->user);
        $foreignId = $this->insertNotification($this->otherUser);

        $this->postAsUser('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertExactJson(['message' => 'ok']);

        $this->assertNotNull(DB::table('notifications')->where('id', $firstOwnId)->value('read_at'));
        $this->assertNotNull(DB::table('notifications')->where('id', $secondOwnId)->value('read_at'));
        $this->assertNull(DB::table('notifications')->where('id', $foreignId)->value('read_at'));
    }

    private function getAsUser(string $uri): TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson($uri);
    }

    private function postAsUser(string $uri): TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson($uri);
    }

    private function insertNotification(User $user, ?Carbon $readAt = null, ?Carbon $createdAt = null): string
    {
        $id = (string) Str::uuid();
        $timestamp = $createdAt ?? Carbon::parse('2026-07-12 12:00:00');

        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'test.alert',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => json_encode(['headline' => 'Test alert'], JSON_THROW_ON_ERROR),
            'read_at' => $readAt,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $id;
    }
}

final class DatabaseSmokeNotification extends Notification
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{headline: string}
     */
    public function toArray(object $notifiable): array
    {
        return ['headline' => 'Database channel smoke'];
    }
}
