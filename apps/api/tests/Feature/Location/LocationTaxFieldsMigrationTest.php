<?php

declare(strict_types=1);

namespace Tests\Feature\Location;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LocationTaxFieldsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_locations_table_has_nullable_tax_identity_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('locations', 'tax_id'));
        $this->assertTrue(Schema::hasColumn('locations', 'vat_number'));
        $this->assertTrue(Schema::hasColumn('locations', 'legal_identifiers'));
    }
}
