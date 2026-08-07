<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\BankStatement;
use App\Modules\Treasury\Domain\BankStatementLine;
use App\Modules\Treasury\Domain\BankStatementLineAllocation;
use App\Modules\Treasury\Domain\BankStatementMatchExecution;
use App\Modules\Treasury\Domain\Enums\BankStatementStatus;
use App\Modules\Treasury\Domain\Enums\MatchActionType;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\StatementDirectionConvention;
use App\Modules\Treasury\Domain\Enums\StatementLineMatchStatus;
use App\Modules\Treasury\Domain\Enums\StatementMatchType;
use App\Modules\Treasury\Domain\Enums\StatementParserKey;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\StatementImportProfile;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\ProvesTenantMigrationRoundTrip;

final class BankStatementAggregateSchemaTest extends TestCase
{
    use ProvesTenantMigrationRoundTrip;
    use RefreshDatabase;

    /**
     * The bank-statement aggregate's own migrations, forward dependency
     * order — by NAME, not by a step count. A fixed `--step` count breaks
     * the instant any other tenant migration lands anywhere after these
     * (it already did: the 2026-08-06 `allow_negative` migration shifted
     * the rollback window, and every later lane's migrations will too).
     * Naming the files makes the test immune to that. `110006` is included
     * because it alters `statement_import_profiles`, one of this
     * aggregate's own tables; `110007` (`add_checkpoint_flag_to_
     * repository_movements`) is deliberately excluded — it touches
     * `repository_movements`, a table this aggregate references but does
     * not own, and isn't part of what this test asserts.
     */
    private const AGGREGATE_MIGRATIONS = [
        '2026_07_19_110000_create_statement_import_profiles.php',
        '2026_07_19_110001_create_bank_statements.php',
        '2026_07_19_110002_create_bank_statement_lines.php',
        '2026_07_19_110003_create_bank_statement_line_allocations.php',
        '2026_07_19_110004_create_bank_statement_match_executions.php',
        '2026_07_19_110005_make_statement_deduplication_void_aware.php',
        '2026_07_19_110006_add_matching_window_to_statement_profiles.php',
    ];

    /** @var list<string> */
    private const AGGREGATE_TABLES = [
        'statement_import_profiles',
        'bank_statements',
        'bank_statement_lines',
        'bank_statement_line_allocations',
        'bank_statement_match_executions',
    ];

    public function test_models_relations_enum_casts_and_state_helpers_round_trip(): void
    {
        [$profile, $statement, $line] = $this->aggregate();

        $this->assertTrue(Schema::hasTable('statement_import_profiles'));
        $this->assertTrue(Schema::hasTable('bank_statements'));
        $this->assertTrue(Schema::hasTable('bank_statement_lines'));
        $this->assertTrue(Schema::hasTable('bank_statement_line_allocations'));
        $this->assertTrue(Schema::hasTable('bank_statement_match_executions'));
        $this->assertSame(StatementParserKey::Csv, $profile->parser_key);
        $this->assertSame(StatementDirectionConvention::SignedAmount, $profile->direction_convention);
        $this->assertSame(BankStatementStatus::Imported, $statement->status);
        $this->assertSame(MovementDirection::In, $line->direction);
        $this->assertSame(StatementLineMatchStatus::Unmatched, $line->match_status);
        $this->assertTrue($line->dedupe_active);
        $this->assertSame($profile->id, $statement->parserProfile?->id);
        $this->assertSame($statement->id, $line->statement->id);
        $this->assertCount(1, $statement->lines);

        $this->assertTrue(BankStatementStatus::Imported->canTransitionTo(BankStatementStatus::Reconciling));
        $this->assertTrue(BankStatementStatus::Imported->canTransitionTo(BankStatementStatus::Voided));
        $this->assertTrue(BankStatementStatus::Reconciling->canTransitionTo(BankStatementStatus::Reconciled));
        $this->assertTrue(BankStatementStatus::Reconciled->canTransitionTo(BankStatementStatus::Reconciling));
        $this->assertFalse(BankStatementStatus::Reconciled->canTransitionTo(BankStatementStatus::Voided));
        $this->assertFalse(BankStatementStatus::Voided->canTransitionTo(BankStatementStatus::Imported));

        $this->assertSame(
            StatementLineMatchStatus::Unmatched,
            StatementLineMatchStatus::derive(false, false, '0.000', '100.000', 3),
        );
        $this->assertSame(
            StatementLineMatchStatus::Partial,
            StatementLineMatchStatus::derive(false, false, '40.000', '100.000', 3),
        );
        $this->assertSame(
            StatementLineMatchStatus::Matched,
            StatementLineMatchStatus::derive(false, false, '100.000', '100.000', 3),
        );
        $this->assertSame(
            StatementLineMatchStatus::ResolvedByCreation,
            StatementLineMatchStatus::derive(false, true, '100.000', '100.000', 3),
        );
        $this->assertSame(
            StatementLineMatchStatus::Ignored,
            StatementLineMatchStatus::derive(true, false, '0.000', '100.000', 3),
        );
    }

    public function test_statement_file_hash_is_unique_per_repository_not_globally(): void
    {
        [$profile, $statement, , $secondRepository] = $this->aggregate();
        $otherProfile = $this->createProfile(
            Tenant::query()->findOrFail($statement->tenant_id),
            Company::query()->findOrFail($statement->company_id),
            $secondRepository,
        );

        $other = $this->createStatement($otherProfile, $secondRepository, $statement->source_file_sha256);
        $this->assertNotSame($statement->id, $other->id);

        $this->expectException(QueryException::class);
        $this->createStatement($profile, $statement->repository, $statement->source_file_sha256);
    }

    public function test_derived_line_status_rejects_overallocation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot exceed');

        StatementLineMatchStatus::derive(false, false, '100.001', '100.000', 3);
    }

    public function test_profile_parser_key_check_rejects_unknown_values_on_postgres(): void
    {
        $this->requirePostgres();
        [$profile] = $this->aggregate();

        $this->expectException(QueryException::class);
        DB::table('statement_import_profiles')->where('id', $profile->id)->update(['parser_key' => 'xml']);
    }

    public function test_profile_direction_check_rejects_unknown_values_on_postgres(): void
    {
        $this->requirePostgres();
        [$profile] = $this->aggregate();

        $this->expectException(QueryException::class);
        DB::table('statement_import_profiles')->where('id', $profile->id)->update(['direction_convention' => 'guess']);
    }

    public function test_statement_status_check_rejects_unknown_values_on_postgres(): void
    {
        $this->requirePostgres();
        [, $statement] = $this->aggregate();

        $this->expectException(QueryException::class);
        DB::table('bank_statements')->where('id', $statement->id)->update(['status' => 'closed']);
    }

    public function test_statement_period_check_rejects_an_inverted_period_on_postgres(): void
    {
        $this->requirePostgres();
        [, $statement] = $this->aggregate();

        $this->expectException(QueryException::class);
        DB::table('bank_statements')->where('id', $statement->id)->update(['period_end' => '2026-06-30']);
    }

    public function test_line_number_is_unique_per_statement(): void
    {
        [, $statement, $line] = $this->aggregate();

        $this->expectException(QueryException::class);
        BankStatementLine::query()->create([
            ...$line->only([
                'bank_statement_id',
                'payment_repository_id',
                'line_number',
                'value_date',
                'direction',
                'amount',
                'label',
                'match_status',
            ]),
            'id' => Str::uuid()->toString(),
            'fingerprint' => hash('sha256', 'different-fingerprint'),
        ]);
    }

    public function test_line_fingerprint_is_unique_per_repository(): void
    {
        [, , $line] = $this->aggregate();

        $this->expectException(QueryException::class);
        BankStatementLine::query()->create([
            ...$line->only([
                'bank_statement_id',
                'payment_repository_id',
                'value_date',
                'direction',
                'amount',
                'label',
                'match_status',
                'fingerprint',
            ]),
            'id' => Str::uuid()->toString(),
            'line_number' => 2,
        ]);
    }

    public function test_voided_statement_and_line_identities_can_coexist_with_one_active_copy(): void
    {
        [$profile, $statement, $line] = $this->aggregate();
        $statement->update(['status' => BankStatementStatus::Voided]);
        $line->update(['dedupe_active' => false]);
        $repository = PaymentRepository::query()->findOrFail($statement->payment_repository_id);

        $active = $this->createStatement($profile, $repository, $statement->source_file_sha256);
        $activeLine = BankStatementLine::query()->create([
            ...$line->only([
                'payment_repository_id',
                'line_number',
                'value_date',
                'booking_date',
                'direction',
                'amount',
                'reference',
                'bank_transaction_id',
                'label',
                'counterparty_hint',
                'match_status',
                'location_id',
                'fingerprint',
            ]),
            'bank_statement_id' => $active->id,
            'dedupe_active' => true,
        ]);

        $this->assertNotSame($statement->id, $active->id);
        $this->assertNotSame($line->id, $activeLine->id);
        $this->assertDatabaseCount('bank_statement_lines', 2);
    }

    public function test_void_aware_indexes_have_partial_predicates_on_postgres(): void
    {
        $this->requirePostgres();

        $definitions = DB::table('pg_indexes')
            ->whereIn('indexname', [
                'bank_statements_repository_file_unique',
                'bank_statement_lines_repository_fingerprint_unique',
            ])
            ->pluck('indexdef', 'indexname');
        $statementIndex = strtolower((string) $definitions->get('bank_statements_repository_file_unique'));
        $lineIndex = strtolower((string) $definitions->get('bank_statement_lines_repository_fingerprint_unique'));

        $this->assertStringContainsString('where', $statementIndex);
        $this->assertStringContainsString('status', $statementIndex);
        $this->assertStringContainsString('voided', $statementIndex);
        $this->assertStringContainsString('where', $lineIndex);
        $this->assertStringContainsString('dedupe_active', $lineIndex);
    }

    public function test_corrective_migration_upgrades_stale_indexes_idempotently_on_postgres(): void
    {
        $this->requirePostgres();
        [, $voidedStatement, $voidedLine] = $this->aggregate();
        $voidedStatement->update(['status' => BankStatementStatus::Voided]);
        DB::statement('DROP INDEX bank_statements_repository_file_unique');
        DB::statement('DROP INDEX bank_statement_lines_repository_fingerprint_unique');
        Schema::table('bank_statement_lines', function (Blueprint $table): void {
            $table->dropColumn('dedupe_active');
        });
        DB::statement('CREATE UNIQUE INDEX bank_statements_repository_file_unique ON bank_statements (payment_repository_id, source_file_sha256)');
        DB::statement('CREATE UNIQUE INDEX bank_statement_lines_repository_fingerprint_unique ON bank_statement_lines (payment_repository_id, fingerprint)');

        $migrationPath = database_path('migrations/tenant/2026_07_19_110005_make_statement_deduplication_void_aware.php');
        $migration = require $migrationPath;
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('bank_statement_lines', 'dedupe_active'));
        $this->assertDatabaseHas('bank_statement_lines', [
            'id' => $voidedLine->id,
            'dedupe_active' => false,
        ]);
        $definitions = DB::table('pg_indexes')
            ->whereIn('indexname', [
                'bank_statements_repository_file_unique',
                'bank_statement_lines_repository_fingerprint_unique',
            ])
            ->pluck('indexdef', 'indexname');
        $this->assertStringContainsString('voided', strtolower((string) $definitions->get('bank_statements_repository_file_unique')));
        $this->assertStringContainsString('dedupe_active', strtolower((string) $definitions->get('bank_statement_lines_repository_fingerprint_unique')));
    }

    public function test_composite_fk_rejects_a_line_with_a_different_repository_than_its_statement(): void
    {
        [, $statement, , $secondRepository] = $this->aggregate();

        $this->expectException(QueryException::class);
        BankStatementLine::query()->create([
            'bank_statement_id' => $statement->id,
            'payment_repository_id' => $secondRepository->id,
            'line_number' => 2,
            'value_date' => '2026-07-02',
            'direction' => MovementDirection::In,
            'amount' => '50.000',
            'label' => 'Wrong repository',
            'match_status' => StatementLineMatchStatus::Unmatched,
            'fingerprint' => hash('sha256', 'wrong-repository'),
        ]);
    }

    public function test_line_amount_must_be_positive(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraints are exercised on PostgreSQL.');
        }

        [, $statement] = $this->aggregate();

        $this->expectException(QueryException::class);
        BankStatementLine::query()->create([
            'bank_statement_id' => $statement->id,
            'payment_repository_id' => $statement->payment_repository_id,
            'line_number' => 2,
            'value_date' => '2026-07-02',
            'direction' => MovementDirection::Out,
            'amount' => '0.000',
            'label' => 'Zero informational row',
            'match_status' => StatementLineMatchStatus::Unmatched,
            'fingerprint' => hash('sha256', 'zero-row'),
        ]);
    }

    public function test_line_number_check_rejects_zero_on_postgres(): void
    {
        $this->requirePostgres();
        [, , $line] = $this->aggregate();

        $this->expectException(QueryException::class);
        DB::table('bank_statement_lines')->where('id', $line->id)->update(['line_number' => 0]);
    }

    public function test_line_direction_check_rejects_unknown_values_on_postgres(): void
    {
        $this->requirePostgres();
        [, , $line] = $this->aggregate();

        $this->expectException(QueryException::class);
        DB::table('bank_statement_lines')->where('id', $line->id)->update(['direction' => 'sideways']);
    }

    public function test_line_status_check_rejects_unknown_values_on_postgres(): void
    {
        $this->requirePostgres();
        [, , $line] = $this->aggregate();

        $this->expectException(QueryException::class);
        DB::table('bank_statement_lines')->where('id', $line->id)->update(['match_status' => 'pending']);
    }

    public function test_line_ignore_shape_requires_reason_and_text_on_postgres(): void
    {
        $this->requirePostgres();
        [, , $line] = $this->aggregate();

        $this->expectException(QueryException::class);
        DB::table('bank_statement_lines')->where('id', $line->id)->update([
            'match_status' => 'ignored',
            'ignore_reason' => null,
            'ignore_text' => null,
        ]);
    }

    public function test_line_ignore_reason_check_rejects_unknown_values_on_postgres(): void
    {
        $this->requirePostgres();
        [, , $line] = $this->aggregate();

        $this->expectException(QueryException::class);
        DB::table('bank_statement_lines')->where('id', $line->id)->update([
            'match_status' => 'ignored',
            'ignore_reason' => 'hand_waved',
            'ignore_text' => 'Operator supplied a reason.',
        ]);
    }

    public function test_allocation_unique_pair_and_enum_relations_round_trip(): void
    {
        [, , $line, , $user] = $this->aggregate();
        $movementId = $this->insertMovement($line->repository, $user);
        $allocation = BankStatementLineAllocation::query()->create([
            'bank_statement_line_id' => $line->id,
            'repository_movement_id' => $movementId,
            'matched_amount' => '100.000',
            'match_type' => StatementMatchType::Manual,
            'matched_by' => $user->id,
            'matched_at' => now(),
        ])->fresh();

        $this->assertNotNull($allocation);
        $this->assertSame(StatementMatchType::Manual, $allocation->match_type);
        $this->assertSame($line->id, $allocation->line->id);
        $this->assertSame($movementId, $allocation->movement->id);

        $this->expectException(QueryException::class);
        BankStatementLineAllocation::query()->create([
            'bank_statement_line_id' => $line->id,
            'repository_movement_id' => $movementId,
            'matched_amount' => '1.000',
            'match_type' => StatementMatchType::SuggestionConfirmed,
            'matched_by' => $user->id,
            'matched_at' => now(),
        ]);
    }

    public function test_allocation_amount_must_be_positive(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraints are exercised on PostgreSQL.');
        }

        [, , $line, , $user] = $this->aggregate();
        $movementId = $this->insertMovement($line->repository, $user);

        $this->expectException(QueryException::class);
        BankStatementLineAllocation::query()->create([
            'bank_statement_line_id' => $line->id,
            'repository_movement_id' => $movementId,
            'matched_amount' => '0.000',
            'match_type' => StatementMatchType::Manual,
            'matched_by' => $user->id,
            'matched_at' => now(),
        ]);
    }

    public function test_allocation_type_check_rejects_unknown_values_on_postgres(): void
    {
        $this->requirePostgres();
        [, , $line, , $user] = $this->aggregate();
        $movementId = $this->insertMovement($line->repository, $user);
        $allocation = BankStatementLineAllocation::query()->create([
            'bank_statement_line_id' => $line->id,
            'repository_movement_id' => $movementId,
            'matched_amount' => '1.000',
            'match_type' => StatementMatchType::Manual,
            'matched_by' => $user->id,
            'matched_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('bank_statement_line_allocations')->where('id', $allocation->id)->update(['match_type' => 'automatic']);
    }

    public function test_execution_requires_digest_has_unique_action_key_and_is_immutable(): void
    {
        [, , $line, , $user] = $this->aggregate();
        $execution = BankStatementMatchExecution::query()->create([
            'bank_statement_line_id' => $line->id,
            'action_type' => MatchActionType::OutboundClear,
            'action_key' => "stmtline:{$line->id}:outbound_clear",
            'semantic_digest' => hash('sha256', 'semantic-input'),
            'target_type' => 'payment_instrument',
            'target_id' => Str::uuid()->toString(),
            'produced_repository_movement_ids' => [],
            'executed_by' => $user->id,
            'executed_at' => now(),
        ])->fresh();

        $this->assertNotNull($execution);
        $this->assertSame(MatchActionType::OutboundClear, $execution->action_type);
        $this->assertSame([], $execution->produced_repository_movement_ids);

        $this->expectException(\LogicException::class);
        $execution->update(['target_type' => 'tampered']);
    }

    public function test_execution_action_key_is_unique(): void
    {
        [, , $line, , $user] = $this->aggregate();
        $attributes = [
            'bank_statement_line_id' => $line->id,
            'action_type' => MatchActionType::CreateExpense,
            'action_key' => "stmtline:{$line->id}:create_expense",
            'semantic_digest' => hash('sha256', 'expense-input'),
            'produced_repository_movement_ids' => [],
            'executed_by' => $user->id,
            'executed_at' => now(),
        ];
        BankStatementMatchExecution::query()->create($attributes);

        $this->expectException(QueryException::class);
        BankStatementMatchExecution::query()->create($attributes);
    }

    public function test_execution_semantic_digest_is_required(): void
    {
        [, , $line, , $user] = $this->aggregate();

        $this->expectException(QueryException::class);
        BankStatementMatchExecution::query()->create([
            'bank_statement_line_id' => $line->id,
            'action_type' => MatchActionType::CreateExpense,
            'action_key' => "stmtline:{$line->id}:missing_digest",
            'semantic_digest' => null,
            'produced_repository_movement_ids' => [],
            'executed_by' => $user->id,
            'executed_at' => now(),
        ]);
    }

    public function test_execution_action_check_rejects_unknown_values_on_postgres(): void
    {
        $this->requirePostgres();
        [, , $line, , $user] = $this->aggregate();

        $this->expectException(QueryException::class);
        DB::table('bank_statement_match_executions')->insert([
            ...$this->rawExecutionAttributes($line, $user),
            'action_type' => 'write_money_directly',
        ]);
    }

    public function test_execution_digest_check_rejects_non_hex_values_on_postgres(): void
    {
        $this->requirePostgres();
        [, , $line, , $user] = $this->aggregate();

        $this->expectException(QueryException::class);
        DB::table('bank_statement_match_executions')->insert([
            ...$this->rawExecutionAttributes($line, $user),
            'semantic_digest' => str_repeat('z', 64),
        ]);
    }

    public function test_execution_target_pair_check_rejects_half_a_target_on_postgres(): void
    {
        $this->requirePostgres();
        [, , $line, , $user] = $this->aggregate();

        $this->expectException(QueryException::class);
        DB::table('bank_statement_match_executions')->insert([
            ...$this->rawExecutionAttributes($line, $user),
            'target_type' => 'payment_instrument',
            'target_id' => null,
        ]);
    }

    public function test_execution_movements_check_rejects_a_json_object_on_postgres(): void
    {
        $this->requirePostgres();
        [, , $line, , $user] = $this->aggregate();

        $this->expectException(QueryException::class);
        DB::table('bank_statement_match_executions')->insert([
            ...$this->rawExecutionAttributes($line, $user),
            'produced_repository_movement_ids' => json_encode(['movement' => Str::uuid()->toString()], JSON_THROW_ON_ERROR),
        ]);
    }

    public function test_execution_rows_reject_direct_database_updates_on_postgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The immutable provenance trigger is PostgreSQL-specific.');
        }

        [, , $line, , $user] = $this->aggregate();
        $execution = BankStatementMatchExecution::query()->create([
            'bank_statement_line_id' => $line->id,
            'action_type' => MatchActionType::CreateExpense,
            'action_key' => "stmtline:{$line->id}:immutable",
            'semantic_digest' => hash('sha256', 'immutable-input'),
            'produced_repository_movement_ids' => [],
            'executed_by' => $user->id,
            'executed_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('bank_statement_match_executions')
            ->where('id', $execution->id)
            ->update(['semantic_digest' => hash('sha256', 'tampered')]);
    }

    public function test_execution_rows_reject_direct_database_deletes_on_postgres(): void
    {
        $this->requirePostgres();
        [, , $line, , $user] = $this->aggregate();
        $execution = BankStatementMatchExecution::query()->create($this->executionAttributes($line, $user));

        $this->expectException(QueryException::class);
        DB::table('bank_statement_match_executions')->where('id', $execution->id)->delete();
    }

    public function test_execution_model_rejects_deletes(): void
    {
        [, , $line, , $user] = $this->aggregate();
        $execution = BankStatementMatchExecution::query()->create($this->executionAttributes($line, $user));

        $this->expectException(\LogicException::class);
        $execution->delete();
    }

    public function test_statement_delete_cascades_lines_and_allocations_without_an_execution(): void
    {
        [, $statement, $line, , $user] = $this->aggregate();
        $movementId = $this->insertMovement($line->repository, $user);
        $allocation = BankStatementLineAllocation::query()->create([
            'bank_statement_line_id' => $line->id,
            'repository_movement_id' => $movementId,
            'matched_amount' => '100.000',
            'match_type' => StatementMatchType::Manual,
            'matched_by' => $user->id,
            'matched_at' => now(),
        ]);

        $statement->delete();

        $this->assertDatabaseMissing('bank_statement_lines', ['id' => $line->id]);
        $this->assertDatabaseMissing('bank_statement_line_allocations', ['id' => $allocation->id]);
        $this->assertDatabaseHas('repository_movements', ['id' => $movementId]);
    }

    public function test_statement_delete_cascades_lines_and_allocations_but_is_restricted_by_execution(): void
    {
        [, $statement, $line, , $user] = $this->aggregate();
        BankStatementMatchExecution::query()->create([
            'bank_statement_line_id' => $line->id,
            'action_type' => MatchActionType::CreateIncome,
            'action_key' => "stmtline:{$line->id}:create_income",
            'semantic_digest' => hash('sha256', 'income-input'),
            'produced_repository_movement_ids' => [],
            'executed_by' => $user->id,
            'executed_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $statement->delete();
    }

    public function test_profile_delete_nulls_statement_reference(): void
    {
        [$profile, $statement] = $this->aggregate();

        $profile->delete();

        $this->assertNull($statement->refresh()->parser_profile_id);
    }

    public function test_migration_down_order_is_fk_safe_and_reapply_is_clean(): void
    {
        $this->assertTenantMigrationRoundTrips(
            self::AGGREGATE_MIGRATIONS,
            function (string $context): void {
                foreach (self::AGGREGATE_TABLES as $table) {
                    $this->assertTrue(Schema::hasTable($table), "{$table} should exist {$context}.");
                }
                $this->assertTrue(
                    Schema::hasColumn('statement_import_profiles', 'matching_window_days'),
                    "statement_import_profiles.matching_window_days should exist {$context}.",
                );
            },
            function (string $context): void {
                foreach (self::AGGREGATE_TABLES as $table) {
                    $this->assertFalse(Schema::hasTable($table), "{$table} should not exist {$context}.");
                }
            },
        );

        // The direct up()/down() calls above never touch the `migrations`
        // bookkeeping table (still recording these as applied from the
        // suite's initial `RefreshDatabase` migrate) — prove a real
        // `artisan migrate` afterwards stays a clean no-op rather than
        // trying to re-run them against tables that already exist.
        $this->assertSame(0, Artisan::call('migrate', [
            '--force' => true,
            '--path' => 'database/migrations/tenant',
        ]), Artisan::output());
    }

    /**
     * @return array{StatementImportProfile, BankStatement, BankStatementLine, PaymentRepository, User}
     */
    private function aggregate(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'TND']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $repository = PaymentRepository::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => 'bank_account',
            'currency' => 'TND',
        ]);
        $secondRepository = PaymentRepository::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => 'bank_account',
            'currency' => 'TND',
        ]);
        $profile = $this->createProfile($tenant, $company, $repository);
        $statement = $this->createStatement($profile, $repository, hash('sha256', Str::uuid()->toString()));
        $line = BankStatementLine::query()->create([
            'bank_statement_id' => $statement->id,
            'payment_repository_id' => $repository->id,
            'line_number' => 1,
            'value_date' => '2026-07-01',
            'booking_date' => '2026-07-02',
            'direction' => MovementDirection::In,
            'amount' => '100.000',
            'reference' => 'REF-001',
            'bank_transaction_id' => 'BANK-TX-001',
            'label' => 'Customer transfer',
            'counterparty_hint' => 'Customer A',
            'match_status' => StatementLineMatchStatus::Unmatched,
            'fingerprint' => hash('sha256', 'line-one'),
        ])->fresh();

        $this->assertNotNull($line);

        return [$profile, $statement, $line, $secondRepository, $user];
    }

    private function createProfile(
        Tenant $tenant,
        Company $company,
        PaymentRepository $repository,
    ): StatementImportProfile {
        return StatementImportProfile::query()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'payment_repository_id' => $repository->id,
            'name' => 'Bank CSV',
            'is_active' => true,
            'parser_key' => StatementParserKey::Csv,
            'column_map' => [
                'value_date' => 'Date',
                'amount' => 'Amount',
                'label' => 'Label',
            ],
            'date_format' => 'd/m/Y',
            'decimal_format' => 'comma',
            'direction_convention' => StatementDirectionConvention::SignedAmount,
            'header_rows' => 1,
        ]);
    }

    private function createStatement(
        StatementImportProfile $profile,
        PaymentRepository $repository,
        string $sourceHash,
    ): BankStatement {
        return BankStatement::query()->create([
            'tenant_id' => $profile->tenant_id,
            'company_id' => $profile->company_id,
            'payment_repository_id' => $repository->id,
            'currency' => 'TND',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'opening_balance' => '1000.000',
            'closing_balance' => '1100.000',
            'status' => BankStatementStatus::Imported,
            'source_file_sha256' => $sourceHash,
            'source_file_path' => 'bank-statements/'.$sourceHash.'.csv',
            'parser_profile_id' => $profile->id,
            'imported_by' => User::query()->where('tenant_id', $profile->tenant_id)->value('id'),
            'imported_at' => now(),
        ]);
    }

    private function insertMovement(PaymentRepository $repository, User $user): string
    {
        $id = Str::uuid()->toString();
        DB::table('repository_movements')->insert([
            'id' => $id,
            'tenant_id' => $repository->tenant_id,
            'company_id' => $repository->company_id,
            'payment_repository_id' => $repository->id,
            'direction' => MovementDirection::In->value,
            'amount' => '100.000',
            'currency' => $repository->currency,
            'balance_after' => '100.000',
            'ordinal' => 1,
            'source_type' => 'adjustment',
            'source_id' => Str::uuid()->toString(),
            'idempotency_key' => 'schema-test:'.Str::uuid()->toString(),
            'occurred_at' => now(),
            'created_by' => $user->id,
        ]);

        return $id;
    }

    /** @return array<string, mixed> */
    private function executionAttributes(BankStatementLine $line, User $user): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'bank_statement_line_id' => $line->id,
            'action_type' => MatchActionType::CreateExpense->value,
            'action_key' => "stmtline:{$line->id}:".Str::uuid()->toString(),
            'semantic_digest' => hash('sha256', 'execution-'.Str::uuid()->toString()),
            'target_type' => null,
            'target_id' => null,
            'produced_repository_movement_ids' => [],
            'executed_by' => $user->id,
            'executed_at' => now(),
        ];
    }

    /** @return array<string, mixed> */
    private function rawExecutionAttributes(BankStatementLine $line, User $user): array
    {
        return [
            ...$this->executionAttributes($line, $user),
            'produced_repository_movement_ids' => json_encode([], JSON_THROW_ON_ERROR),
        ];
    }

    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraints are exercised on PostgreSQL.');
        }
    }
}
