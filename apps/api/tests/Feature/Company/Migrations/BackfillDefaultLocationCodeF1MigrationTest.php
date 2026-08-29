<?php

declare(strict_types=1);

namespace Tests\Feature\Company\Migrations;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class BackfillDefaultLocationCodeF1MigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_08_29_100000_backfill_default_location_code_f1.php';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    public function test_it_backfills_null_and_empty_default_location_codes_idempotently(): void
    {
        $nullCodeLocation = $this->locationWithCode(null, true);
        $emptyCodeLocation = $this->locationWithCode('', true);
        $nonDefaultLocation = $this->locationWithCode(null, false);
        DB::table('locations')
            ->whereIn('id', [$nullCodeLocation->id, $emptyCodeLocation->id, $nonDefaultLocation->id])
            ->update(['updated_at' => now()->subYear()]);
        $timestampBeforeBackfill = DB::table('locations')
            ->where('id', $nullCodeLocation->id)
            ->value('updated_at');
        $nonDefaultTimestamp = DB::table('locations')
            ->where('id', $nonDefaultLocation->id)
            ->value('updated_at');

        $this->runMigration();

        self::assertSame('MAIN', $nullCodeLocation->refresh()->code);
        self::assertSame('MAIN', $emptyCodeLocation->refresh()->code);
        self::assertNull($nonDefaultLocation->refresh()->code);
        self::assertNotSame(
            $timestampBeforeBackfill,
            DB::table('locations')->where('id', $nullCodeLocation->id)->value('updated_at'),
        );
        self::assertSame(
            $nonDefaultTimestamp,
            DB::table('locations')->where('id', $nonDefaultLocation->id)->value('updated_at'),
        );

        DB::table('locations')
            ->whereIn('id', [$nullCodeLocation->id, $emptyCodeLocation->id])
            ->update(['updated_at' => now()->subYear()]);
        $timestampsBeforeRerun = DB::table('locations')
            ->whereIn('id', [$nullCodeLocation->id, $emptyCodeLocation->id])
            ->orderBy('id')
            ->pluck('updated_at', 'id')
            ->all();

        $this->runMigration();

        self::assertSame(
            $timestampsBeforeRerun,
            DB::table('locations')
                ->whereIn('id', [$nullCodeLocation->id, $emptyCodeLocation->id])
                ->orderBy('id')
                ->pluck('updated_at', 'id')
                ->all(),
        );
    }

    public function test_it_leaves_a_codeless_default_untouched_and_censuses_a_main_collision(): void
    {
        $company = $this->company();
        Location::factory()->for($company)->create([
            'code' => 'MAIN',
            'is_default' => false,
        ]);
        $defaultLocation = Location::factory()->for($company)->create([
            'code' => null,
            'is_default' => true,
        ]);
        $expectedLine = "default-location-code-collision company_id={$company->id} location_id={$defaultLocation->id}";

        $infoMessages = [];
        Log::listen(static function (MessageLogged $message) use (&$infoMessages): void {
            if ($message->level === 'info') {
                $infoMessages[] = $message->message;
            }
        });
        $output = $this->runMigration();

        self::assertNull($defaultLocation->refresh()->code);
        self::assertSame('', $output);
        self::assertSame([$expectedLine], $infoMessages);
    }

    private function locationWithCode(?string $code, bool $isDefault): Location
    {
        $company = $this->company();

        return Location::factory()->for($company)->create([
            'code' => $code,
            'is_default' => $isDefault,
        ]);
    }

    private function company(): Company
    {
        return Company::factory()->for($this->tenant)->create();
    }

    private function runMigration(): string
    {
        $path = database_path('migrations/tenant/'.self::MIGRATION);
        self::assertFileExists($path);

        $migration = require $path;
        if (! is_object($migration)) {
            self::fail('The tenant migration must return an object.');
        }

        $up = [$migration, 'up'];
        if (! is_callable($up)) {
            self::fail('The tenant migration must expose up().');
        }

        ob_start();
        $up();
        $output = ob_get_clean();

        self::assertIsString($output);

        return $output;
    }
}
