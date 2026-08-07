<?php

declare(strict_types=1);

namespace Tests\Traits;

/**
 * The shape every tenant migration file honors by Laravel convention
 * (`return new class extends Migration { public function up(): void {…}
 * public function down(): void {…} };`) — but the base
 * `Illuminate\Database\Migrations\Migration` class does NOT declare `up()`
 * or `down()` at all (they're a convention the migration runner calls via
 * duck typing, not an interface). {@see ProvesTenantMigrationRoundTrip}
 * asserts a required migration file against this interface via a `@var`
 * docblock so static analysis can call `up()`/`down()` without `mixed`.
 */
interface ReversibleTenantMigration
{
    public function up(): void;

    public function down(): void;
}
