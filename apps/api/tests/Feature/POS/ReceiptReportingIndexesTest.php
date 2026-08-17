<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ReceiptReportingIndexesTest extends TestCase
{
    use RefreshDatabase;

    public function test_reporting_indexes_have_the_frozen_structure(): void
    {
        $definitions = collect(DB::select(
            "SELECT name, sql FROM sqlite_master WHERE type = 'index' AND name IN (?, ?)",
            [
                'pos_receipts_company_location_posted_at_idx',
                'pos_receipts_company_posted_at_production_idx',
            ],
        ))->keyBy('name');

        $this->assertSame(
            ['pos_receipts_company_location_posted_at_idx', 'pos_receipts_company_posted_at_production_idx'],
            $definitions->keys()->sort()->values()->all(),
        );
        $this->assertStringContainsString(
            '(company_id, location_id, posted_at DESC)',
            $definitions['pos_receipts_company_location_posted_at_idx']->sql,
        );
        $this->assertStringContainsString(
            '(company_id, posted_at DESC)',
            $definitions['pos_receipts_company_posted_at_production_idx']->sql,
        );
        $this->assertStringContainsString(
            'WHERE training_flag = false',
            $definitions['pos_receipts_company_posted_at_production_idx']->sql,
        );
    }
}
