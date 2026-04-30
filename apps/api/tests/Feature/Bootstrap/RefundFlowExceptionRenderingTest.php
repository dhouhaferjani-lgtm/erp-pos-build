<?php

declare(strict_types=1);

namespace Tests\Feature\Bootstrap;

use App\Modules\POS\Domain\Enums\RefundDestination;
use App\Modules\POS\Domain\Exceptions\DailyRefundCapExceededException;
use App\Modules\POS\Domain\Exceptions\ManagerOverrideRequiredException;
use App\Modules\POS\Domain\Exceptions\RefundDestinationNotAllowedException;
use App\Modules\POS\Domain\Exceptions\RefundWindowClosedException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Verifies that bootstrap/app.php maps the four POS refund-flow exceptions to
 * HTTP 422 with typed error.code strings.
 *
 * Each test registers a tiny test-only route that throws one exception, hits it
 * as a JSON request, and asserts the exact response shape expected by the POS
 * frontend (apps/pos/src/lib/refundFlow/refundConfirmation.ts).
 *
 * Route URLs start with /api/ so the $request->is('api/*') gate fires even when
 * the Accept header is not application/json.
 */
final class RefundFlowExceptionRenderingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->get('/api/__test/refund-exceptions/manager-override', static function (): never {
            throw new ManagerOverrideRequiredException('150.00', '100.00');
        });

        Route::middleware('api')->get('/api/__test/refund-exceptions/daily-cap', static function (): never {
            throw new DailyRefundCapExceededException('500.00', '620.00', 'cashier-uuid-001');
        });

        Route::middleware('api')->get('/api/__test/refund-exceptions/window-closed', static function (): never {
            throw new RefundWindowClosedException(30, '2026-01-01T00:00:00Z');
        });

        Route::middleware('api')->get('/api/__test/refund-exceptions/destination-not-allowed', static function (): never {
            throw new RefundDestinationNotAllowedException(RefundDestination::Cash, 'policy prohibits cash refunds after 30 days');
        });
    }

    public function test_manager_override_required_returns_422_with_typed_code(): void
    {
        $response = $this->getJson('/api/__test/refund-exceptions/manager-override');

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'MANAGER_OVERRIDE_REQUIRED');
        $response->assertJsonPath('error.message', static fn (string $message): bool => strlen($message) > 0);
    }

    public function test_daily_refund_cap_exceeded_returns_422_with_typed_code(): void
    {
        $response = $this->getJson('/api/__test/refund-exceptions/daily-cap');

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DAILY_REFUND_CAP_EXCEEDED');
        $response->assertJsonPath('error.message', static fn (string $message): bool => strlen($message) > 0);
    }

    public function test_refund_window_closed_returns_422_with_typed_code(): void
    {
        $response = $this->getJson('/api/__test/refund-exceptions/window-closed');

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'REFUND_WINDOW_CLOSED');
        $response->assertJsonPath('error.message', static fn (string $message): bool => strlen($message) > 0);
    }

    public function test_refund_destination_not_allowed_returns_422_not_500(): void
    {
        $response = $this->getJson('/api/__test/refund-exceptions/destination-not-allowed');

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'REFUND_DESTINATION_NOT_ALLOWED');
        $response->assertJsonPath('error.message', static fn (string $message): bool => strlen($message) > 0);
    }

    public function test_exception_message_is_preserved_in_response(): void
    {
        $response = $this->getJson('/api/__test/refund-exceptions/manager-override');

        $response->assertStatus(422);
        $response->assertJsonPath(
            'error.message',
            'Refund amount 150.00 exceeds the manager-override threshold 100.00. An authorized_by_user_id (manager PIN approval) is required.'
        );
    }
}
