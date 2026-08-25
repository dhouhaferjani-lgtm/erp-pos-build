<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Document\Domain\Enums\DocumentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * N-6 fix round r1 / treasury gate M-3 — the `chk_documents_status_enum` CHECK
 * is materialised into SQL when its migration RUNS, so it does not track
 * `DocumentStatus`. Adding an enum case without a widening migration leaves
 * every already-migrated tenant rejecting the new value at INSERT time, per
 * tenant, at runtime — while the application happily accepts it.
 *
 * This test turns that from a documented obligation into an enforced one.
 *
 * PostgreSQL only: the constraint migration is `pgsql`-guarded, as 95 of the
 * 110 CHECK-bearing tenant migrations are, so the SQLite driver never has one.
 */
final class DocumentStatusCheckConstraintParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_live_constraint_admits_exactly_the_enum_cases(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('chk_documents_status_enum is a pgsql-only constraint.');
        }

        /** @var object{definition: string}|null $row */
        $row = DB::selectOne(
            "SELECT pg_get_constraintdef(oid) AS definition
               FROM pg_constraint
              WHERE conname = 'chk_documents_status_enum'"
        );

        $this->assertNotNull($row, 'the CHECK must exist on a migrated tenant database');

        $definition = $row->definition;

        foreach (DocumentStatus::cases() as $case) {
            $this->assertStringContainsString(
                "'".$case->value."'",
                $definition,
                "DocumentStatus::{$case->name} is not admitted by the live CHECK — it needs a widening migration",
            );
        }

        // And nothing BEYOND the enum: count the quoted literals in the
        // definition and compare. A stale extra value is drift in the other
        // direction and is just as much a lie about the value domain.
        preg_match_all("/'([a-z_]+)'/", $definition, $matches);
        $admitted = array_values(array_unique($matches[1]));
        sort($admitted);

        $expected = array_map(static fn (DocumentStatus $s): string => $s->value, DocumentStatus::cases());
        sort($expected);

        $this->assertSame($expected, $admitted, 'the CHECK and the enum must describe the same value domain');
    }
}
