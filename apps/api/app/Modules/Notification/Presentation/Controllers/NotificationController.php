<?php

declare(strict_types=1);

namespace App\Modules\Notification\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

final class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', '15')));
        $user = $this->user($request);
        $query = $user->notifications()->orderByDesc('created_at');

        if ($request->query('filter') === 'unread') {
            $query->whereNull('read_at');
        }

        $page = $query->paginate($perPage);

        return response()->json([
            'data' => array_map(
                static fn (DatabaseNotification $notification): array => [
                    'id' => $notification->id,
                    'type' => $notification->type,
                    'data' => $notification->data,
                    'read_at' => $notification->read_at?->toIso8601String(),
                    'created_at' => $notification->created_at?->toIso8601String(),
                ],
                $page->items(),
            ),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['count' => $this->user($request)->unreadNotifications()->count()],
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $this->user($request)->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json(['message' => 'ok']);
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->user($request)->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['message' => 'ok']);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
