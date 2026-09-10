<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

require_once __DIR__.'/StockTransferReceiveTest.php';
use App\Modules\Inventory\Domain\StockTransferReceipt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

final class StockTransferReceiveValidationTest extends TransferReceiptFeatureTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function invalidQuantities(): iterable
    {
        yield 'negative' => ['-1.0000', '0.0000'];
        yield 'five decimals' => ['1.00001', '0.0000'];
        yield 'empty posting' => ['0.0000', '0.0000'];
        yield 'exponent' => ['1e0', '0.0000'];
        yield 'negative damaged' => ['1.0000', '-1.0000'];
    }

    #[DataProvider('invalidQuantities')]
    public function test_every_schema_mirror_rule_refuses_before_any_write(string $good, string $damaged): void
    {
        $before = $this->rowCounts();
        $this->postJson('/api/v1/stock-transfers/'.$this->transfer->id.'/receive', $this->receiptBody($good, $damaged))->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        self::assertSame($before, $this->rowCounts());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidStructures(): iterable
    {
        foreach (['no_lines', 'duplicate_line', 'no_id', 'bad_uuid', 'no_received', 'no_damaged', 'key_too_long', 'notes_too_long', 'reason_too_long', 'note_too_long', 'duplicate_lot', 'zero_lot', 'bad_batch', 'bad_lot_quantity'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('invalidStructures')]
    public function test_structural_rules_are_atomic(string $case): void
    {
        $body = $this->receiptBody();
        switch ($case) {
            case 'no_lines': $body['lines'] = [];
                break;
            case 'duplicate_line': $body['lines'][] = $body['lines'][0];
                break;
            case 'no_id': unset($body['lines'][0]['transfer_line_id']);
                break;
            case 'bad_uuid': $body['lines'][0]['transfer_line_id'] = 'not-a-uuid';
                break;
            case 'no_received': unset($body['lines'][0]['quantity_received']);
                break;
            case 'no_damaged': unset($body['lines'][0]['quantity_damaged']);
                break;
            case 'key_too_long': $body['idempotency_key'] = str_repeat('k', 129);
                break;
            case 'notes_too_long': $body['notes'] = str_repeat('n', 5001);
                break;
            case 'reason_too_long': $body['lines'][0]['discrepancy_reason'] = str_repeat('r', 33);
                break;
            case 'note_too_long': $body['lines'][0]['discrepancy_note'] = str_repeat('n', 1001);
                break;
            default:
                $body['lines'][0]['lots'] = [['batch_id' => 123, 'quantity_received' => '7.0000', 'quantity_damaged' => '0.0000']];
                if ($case === 'duplicate_lot') {
                    $body['lines'][0]['lots'][] = $body['lines'][0]['lots'][0];
                }
                if ($case === 'zero_lot') {
                    $body['lines'][0]['lots'][0]['quantity_received'] = '0.0000';
                }
                if ($case === 'bad_batch') {
                    $body['lines'][0]['lots'][0]['batch_id'] = 0;
                }
                if ($case === 'bad_lot_quantity') {
                    $body['lines'][0]['lots'][0]['quantity_damaged'] = '0.00001';
                }
        }
        $before = $this->rowCounts();
        $this->postJson('/api/v1/stock-transfers/'.$this->transfer->id.'/receive', $body)->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        self::assertSame($before, $this->rowCounts());
    }

    public function test_data_dependent_failures_are_atomic_for_every_submitted_line(): void
    {
        $body = $this->receiptBody();
        $other = $this->initiate('12.0000');
        $cases = [
            ['LINE_NOT_ON_TRANSFER', ['transfer_line_id' => $other->lines->sole()->id]],
            ['LOT_NOT_ALLOWED', ['lots' => [['batch_id' => 123, 'quantity_received' => '7.0000', 'quantity_damaged' => '0.0000']]]],
            ['DISCREPANCY_REASON_REQUIRED', ['quantity_damaged' => '1.0000']],
            ['DISCREPANCY_REASON_INVALID', ['discrepancy_reason' => 'other']],
            ['DISCREPANCY_REASON_INVALID', ['quantity_damaged' => '1.0000', 'discrepancy_reason' => 'lost_in_transit']],
        ];
        foreach ($cases as [$code, $overrides]) {
            $candidate = $body;
            $candidate['lines'][0] = array_replace($candidate['lines'][0], $overrides);
            $before = $this->rowCounts();
            $this->postJson('/api/v1/stock-transfers/'.$this->transfer->id.'/receive', $candidate)->assertStatus(422)->assertJsonPath('error.code', $code);
            self::assertSame($before, $this->rowCounts(), $code);
        }
        $this->product->update(['requires_batch_tracking' => true]);
        $before = $this->rowCounts();
        $this->receive()->assertStatus(422)->assertJsonPath('error.code', 'LOT_TRACKING_MISMATCH');
        self::assertSame($before, $this->rowCounts());
    }

    public function test_reserved_sys_namespace_is_refused_and_the_server_key_still_works(): void
    {
        $this->receive(key: 'SyS:complete:'.$this->transfer->id)->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR')->assertJsonValidationErrors('idempotency_key', 'error.errors');
        $this->postJson('/api/v1/stock-transfers/'.$this->transfer->id.'/complete')->assertOk();
        self::assertSame('sys:complete:'.$this->transfer->id, StockTransferReceipt::query()->where('transfer_id', $this->transfer->id)->sole()->idempotency_key);
        $this->postJson('/api/v1/stock-transfers/'.$this->transfer->id.'/complete')->assertOk();
        self::assertSame(1, StockTransferReceipt::query()->where('transfer_id', $this->transfer->id)->count());
    }

    public function test_no_error_body_carries_a_fixture_quantity(): void
    {
        $this->transfer = $this->initiate('13.5791');
        $response = $this->receive('17.4321')->assertStatus(422)->assertJsonPath('error.code', 'OVER_RECEIPT');
        foreach (['13.5791', '17.4321'] as $sentinel) {
            self::assertStringNotContainsString($sentinel, $response->getContent());
        }
    }

    public function test_key_reuse_requires_the_same_transfer_and_canonical_payload(): void
    {
        $this->receive()->assertCreated();
        $body = $this->receiptBody('7', '0');
        $body['notes'] = null;
        $body['lines'][0]['discrepancy_note'] = null;
        $this->postJson('/api/v1/stock-transfers/'.$this->transfer->id.'/receive', $body)->assertOk()->assertJsonPath('meta.replayed', true);
        $this->receive('6.0000')->assertStatus(422)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
        $this->transfer = $this->initiate('12.0000');
        $this->receive()->assertStatus(422)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    }

    /** @return array<string, int|string> */
    private function rowCounts(): array
    {
        $counts = [];
        foreach (['stock_transfer_receipts', 'stock_transfer_receipt_lines', 'stock_transfer_receipt_line_lots', 'stock_movements'] as $table) {
            $counts[$table] = DB::table($table)->where('company_id', $this->company->id)->count();
        }
        foreach (['stock_transfer_lines', 'stock_transfer_line_batch_allocations'] as $table) {
            $counts[$table] = DB::table($table)->where('company_id', $this->company->id)->orderBy('id')->get()->toJson();
        }
        $counts['stored_events'] = DB::table('stored_events')->count();

        return $counts;
    }
}
