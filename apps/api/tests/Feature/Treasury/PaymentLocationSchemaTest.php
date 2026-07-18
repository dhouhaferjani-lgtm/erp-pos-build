<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PaymentLocationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_payments_and_instruments_have_nullable_location_id(): void
    {
        self::assertTrue(Schema::hasColumn('payments', 'location_id'));
        self::assertTrue(Schema::hasColumn('payment_instruments', 'location_id'));
    }

    public function test_documents_migration_absent_and_expense_metadata_untouched(): void
    {
        self::assertTrue(Schema::hasColumn('documents', 'location_id'));
        self::assertFalse(Schema::hasColumn('expense_metadata', 'location_id'));
    }
}
