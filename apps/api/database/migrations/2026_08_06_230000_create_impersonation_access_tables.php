<?php

declare(strict_types=1);

use App\Modules\SupportAccess\Domain\Enums\AuditOutcome;
use App\Modules\SupportAccess\Domain\Enums\ElevationStatus;
use App\Modules\SupportAccess\Domain\Enums\GrantStatus;
use App\Modules\SupportAccess\Domain\Enums\GrantType;
use App\Modules\SupportAccess\Domain\Enums\SessionAccessLevel;
use App\Modules\SupportAccess\Domain\Enums\SessionEndReason;
use App\Modules\SupportAccess\Domain\Enums\SessionEventType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonation_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('subject_user_id')->nullable()->index();
            $table->uuid('operator_id')->nullable()->index();
            $table->string('type', 32);
            $table->string('status', 40)->index();
            $table->text('reason');
            $table->string('ticket_ref', 100);
            $table->timestamp('requested_at');
            $table->timestamp('starts_at');
            $table->timestamp('expires_at')->index();
            $table->uuid('tenant_approved_by')->nullable();
            $table->timestamp('tenant_approved_at')->nullable();
            $table->uuid('second_approved_by')->nullable();
            $table->timestamp('second_approved_at')->nullable();
            $table->uuid('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'expires_at']);
        });

        Schema::create('impersonation_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('grant_id')->index();
            $table->unsignedBigInteger('personal_access_token_id')->nullable()->unique();
            $table->uuid('operator_id')->index();
            $table->uuid('subject_user_id')->index();
            $table->uuid('tenant_id')->index();
            $table->string('access_level', 20);
            $table->timestamp('started_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('write_elevated_at')->nullable();
            $table->timestamp('write_expires_at')->nullable();
            $table->uuid('write_approved_by')->nullable();
            $table->timestamp('ended_at')->nullable()->index();
            $table->string('end_reason', 32)->nullable();
            $table->unsignedBigInteger('chain_sequence')->default(0);
            $table->char('chain_previous_hash', 64)->nullable();
            $table->char('chain_head_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'ended_at', 'expires_at']);
            $table->foreign('grant_id')->references('id')->on('impersonation_grants')->restrictOnDelete();
        });

        Schema::create('impersonation_elevations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('session_id')->index();
            $table->uuid('requested_by');
            $table->uuid('approved_by')->nullable();
            $table->string('status', 32)->index();
            $table->text('reason');
            $table->timestamp('requested_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->foreign('session_id')->references('id')->on('impersonation_sessions')->restrictOnDelete();
        });

        Schema::create('impersonation_session_permissions', function (Blueprint $table): void {
            $table->uuid('session_id');
            $table->string('permission', 150);
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['session_id', 'permission']);
            $table->foreign('session_id')->references('id')->on('impersonation_sessions')->restrictOnDelete();
        });

        Schema::create('impersonation_session_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('session_id')->index();
            $table->unsignedBigInteger('sequence');
            $table->string('event_type', 64)->index();
            $table->string('outcome', 16);
            $table->uuid('operator_id')->index();
            $table->uuid('subject_user_id')->index();
            $table->uuid('tenant_id')->index();
            $table->uuid('request_id')->nullable()->index();
            $table->string('http_method', 12)->nullable();
            $table->text('path')->nullable();
            $table->jsonb('details')->default('{}');
            $table->char('previous_hash', 64);
            $table->char('hash', 64)->unique();
            $table->timestamp('occurred_at')->index();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['session_id', 'sequence']);
            $table->foreign('session_id')->references('id')->on('impersonation_sessions')->restrictOnDelete();
        });

        $this->addPostgresEnumConstraints();
        $this->createEventImmutabilityTriggers();
    }

    public function down(): void
    {
        $this->dropEventImmutabilityTriggers();

        Schema::dropIfExists('impersonation_session_events');
        Schema::dropIfExists('impersonation_session_permissions');
        Schema::dropIfExists('impersonation_elevations');
        Schema::dropIfExists('impersonation_sessions');
        Schema::dropIfExists('impersonation_grants');
    }

    private function addPostgresEnumConstraints(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $constraints = [
            ['impersonation_grants', 'type', GrantType::cases()],
            ['impersonation_grants', 'status', GrantStatus::cases()],
            ['impersonation_sessions', 'access_level', SessionAccessLevel::cases()],
            ['impersonation_sessions', 'end_reason', SessionEndReason::cases()],
            ['impersonation_elevations', 'status', ElevationStatus::cases()],
            ['impersonation_session_events', 'event_type', SessionEventType::cases()],
            ['impersonation_session_events', 'outcome', AuditOutcome::cases()],
        ];

        foreach ($constraints as [$table, $column, $cases]) {
            $values = implode(', ', array_map(
                static fn (BackedEnum $case): string => DB::getPdo()->quote($case->value),
                $cases,
            ));
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ({$column} IN ({$values}))");
        }
    }

    private function createEventImmutabilityTriggers(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER impersonation_session_events_no_update
                BEFORE UPDATE ON impersonation_session_events
                BEGIN
                    SELECT RAISE(ABORT, 'impersonation session events are append-only');
                END;
                CREATE TRIGGER impersonation_session_events_no_delete
                BEFORE DELETE ON impersonation_session_events
                BEGIN
                    SELECT RAISE(ABORT, 'impersonation session events are append-only');
                END;
            SQL);

            return;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION impersonation_session_events_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'impersonation session events are append-only'
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER impersonation_session_events_no_update
                BEFORE UPDATE ON impersonation_session_events
                FOR EACH ROW EXECUTE FUNCTION impersonation_session_events_immutable();
            CREATE TRIGGER impersonation_session_events_no_delete
                BEFORE DELETE ON impersonation_session_events
                FOR EACH ROW EXECUTE FUNCTION impersonation_session_events_immutable();
            CREATE TRIGGER impersonation_session_events_no_truncate
                BEFORE TRUNCATE ON impersonation_session_events
                FOR EACH STATEMENT EXECUTE FUNCTION impersonation_session_events_immutable();
        SQL);
    }

    private function dropEventImmutabilityTriggers(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS impersonation_session_events_no_delete;
                DROP TRIGGER IF EXISTS impersonation_session_events_no_update;
            SQL);

            return;
        }

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS impersonation_session_events_no_truncate ON impersonation_session_events;
                DROP TRIGGER IF EXISTS impersonation_session_events_no_delete ON impersonation_session_events;
                DROP TRIGGER IF EXISTS impersonation_session_events_no_update ON impersonation_session_events;
                DROP FUNCTION IF EXISTS impersonation_session_events_immutable();
            SQL);
        }
    }
};
