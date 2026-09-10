<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

require_once __DIR__.'/StockTransferReceiveTest.php';
use App\Modules\Inventory\Application\Services\TransferReconciliationService;
use App\Modules\Inventory\Domain\Events\StockTransferReceiptLineRecordedV1;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TransferReceiptPatternQueryPostgresTest extends TransferReceiptFeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('Pattern query requires PostgreSQL jsonb, FILTER and bool_or.');
        }
    }

    public function test_line_grain_and_both_dispositions_are_counted(): void
    {
        $lineA = (string) Str::uuid();
        $lineB = (string) Str::uuid();
        $this->posting('receiver-b', $lineA, 'receiver');
        $this->posting('receiver-b', $lineB, 'receiver');
        $this->posting('supervisor', $lineA, 'closer', writtenOff: '3.0000');
        $this->posting('supervisor', $lineB, 'closer', returned: '2.0000');
        $rows = DB::select(TransferReconciliationService::PATTERN_DETECTION_SQL, ['company_id' => $this->company->id]);
        self::assertCount(1, $rows);
        self::assertSame(2, (int) $rows[0]->lines_later_confirmed_short);
        self::assertSame('receiver-b', $rows[0]->user_id);
        $mutated = str_replace(" OR (p->>'quantityReturned')::numeric > 0", '', TransferReconciliationService::PATTERN_DETECTION_SQL);
        self::assertNotSame($mutated, TransferReconciliationService::PATTERN_DETECTION_SQL);
        self::assertSame(1, (int) DB::select($mutated, ['company_id' => $this->company->id])[0]->lines_later_confirmed_short);
    }

    public function test_repeated_partial_receipts_collapse_to_one_line_per_receiver(): void
    {
        $lineA = (string) Str::uuid();
        $lineB = (string) Str::uuid();
        $this->posting('receiver-c', $lineA, 'receiver', damaged: '1.0000');
        $this->posting('receiver-c', $lineA, 'receiver');
        $this->posting('receiver-c', $lineB, 'receiver');
        $this->posting('receiver-d', $lineA, 'receiver');
        $rows = collect(DB::select(TransferReconciliationService::PATTERN_DETECTION_SQL, ['company_id' => $this->company->id]))->keyBy('user_id');
        self::assertSame('0.5000', bcadd((string) $rows['receiver-c']->damage_rate, '0', 4));
        self::assertSame(2, (int) $rows['receiver-c']->lines_posted);
        self::assertSame(3, (int) $rows['receiver-c']->postings);
        self::assertTrue($rows['receiver-c']->shared_line);
        self::assertSame([], DB::select(TransferReconciliationService::PATTERN_DETECTION_SQL, ['company_id' => (string) Str::uuid()]));
    }

    private function posting(string $actor, string $line, string $role, string $damaged = '0.0000', string $writtenOff = '0.0000', string $returned = '0.0000'): void
    {
        DB::table('stored_events')->insert(['aggregate_uuid' => (string) Str::uuid(), 'aggregate_version' => 2, 'event_version' => 1,
            'event_class' => StockTransferReceiptLineRecordedV1::class, 'meta_data' => '{}', 'created_at' => now(),
            'event_properties' => json_encode(['companyId' => $this->company->id, 'actorUserId' => $actor, 'transferLineId' => $line, 'actorRole' => $role, 'quantityDamaged' => $damaged, 'quantityWrittenOff' => $writtenOff, 'quantityReturned' => $returned], JSON_THROW_ON_ERROR),
        ]);
    }
}
