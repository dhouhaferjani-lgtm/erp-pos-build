<?php

declare(strict_types=1);

use App\Modules\SupportAccess\Domain\Enums\AuditOutcome;
use App\Modules\SupportAccess\Domain\Enums\SessionEventType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('impersonation_grants', function (Blueprint $table): void {
            $table->unsignedBigInteger('chain_sequence')->default(0);
            $table->char('chain_previous_hash', 64)->nullable();
            $table->char('chain_head_hash', 64)->nullable();
        });

        Schema::create('impersonation_grant_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('grant_id')->index();
            $table->unsignedBigInteger('sequence');
            $table->string('event_type', 64)->index();
            $table->string('outcome', 16);
            $table->uuid('tenant_id')->index();
            $table->uuid('subject_user_id')->nullable()->index();
            $table->uuid('operator_id')->nullable()->index();
            $table->uuid('actor_id');
            $table->string('actor_type', 32);
            $table->jsonb('details')->default('{}');
            $table->char('previous_hash', 64);
            $table->char('hash', 64)->unique();
            $table->timestamp('occurred_at')->index();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['grant_id', 'sequence']);
            $table->foreign('grant_id')->references('id')->on('impersonation_grants')->restrictOnDelete();
        });

        Schema::create('impersonation_audit_deliveries', function (Blueprint $table): void {
            $table->uuid('event_id')->primary();
            $table->string('aggregate_type', 16);
            $table->uuid('tenant_id')->index();
            $table->timestamp('admin_delivered_at')->nullable();
            $table->timestamp('tenant_delivered_at')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['admin_delivered_at', 'tenant_delivered_at']);
        });

        Schema::table('admin_audit_logs', function (Blueprint $table): void {
            $table->uuid('super_admin_id')->nullable()->change();
        });

        $this->addPostgresConstraints();
        $this->createImmutabilityTriggers();
    }

    public function down(): void
    {
        $this->dropImmutabilityTriggers();
        Schema::dropIfExists('impersonation_audit_deliveries');
        Schema::dropIfExists('impersonation_grant_events');
        Schema::table('impersonation_grants', function (Blueprint $table): void {
            $table->dropColumn(['chain_sequence', 'chain_previous_hash', 'chain_head_hash']);
        });
        DB::table('admin_audit_logs')
            ->whereNull('super_admin_id')
            ->where('entity_type', 'impersonation_grant')
            ->whereNull('impersonation_session_id')
            ->delete();
        Schema::table('admin_audit_logs', function (Blueprint $table): void {
            $table->uuid('super_admin_id')->nullable(false)->change();
        });
    }

    private function addPostgresConstraints(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $eventTypes = implode(', ', array_map(
            static fn (SessionEventType $case): string => DB::getPdo()->quote($case->value),
            SessionEventType::cases(),
        ));
        $outcomes = implode(', ', array_map(
            static fn (AuditOutcome $case): string => DB::getPdo()->quote($case->value),
            AuditOutcome::cases(),
        ));
        DB::statement("ALTER TABLE impersonation_grant_events ADD CONSTRAINT impersonation_grant_events_event_type_check CHECK (event_type IN ({$eventTypes}))");
        DB::statement("ALTER TABLE impersonation_grant_events ADD CONSTRAINT impersonation_grant_events_outcome_check CHECK (outcome IN ({$outcomes}))");
        DB::statement('ALTER TABLE impersonation_session_events DROP CONSTRAINT impersonation_session_events_event_type_check');
        DB::statement("ALTER TABLE impersonation_session_events ADD CONSTRAINT impersonation_session_events_event_type_check CHECK (event_type IN ({$eventTypes}))");
    }

    private function createImmutabilityTriggers(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER impersonation_grant_events_no_update
                BEFORE UPDATE ON impersonation_grant_events
                BEGIN
                    SELECT RAISE(ABORT, 'impersonation grant events are append-only');
                END;
                CREATE TRIGGER impersonation_grant_events_no_delete
                BEFORE DELETE ON impersonation_grant_events
                BEGIN
                    SELECT RAISE(ABORT, 'impersonation grant events are append-only');
                END;
            SQL);

            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION impersonation_grant_events_immutable() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'impersonation grant events are append-only'
                        USING ERRCODE = 'integrity_constraint_violation';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER impersonation_grant_events_no_update
                    BEFORE UPDATE ON impersonation_grant_events
                    FOR EACH ROW EXECUTE FUNCTION impersonation_grant_events_immutable();
                CREATE TRIGGER impersonation_grant_events_no_delete
                    BEFORE DELETE ON impersonation_grant_events
                    FOR EACH ROW EXECUTE FUNCTION impersonation_grant_events_immutable();
                CREATE TRIGGER impersonation_grant_events_no_truncate
                    BEFORE TRUNCATE ON impersonation_grant_events
                    FOR EACH STATEMENT EXECUTE FUNCTION impersonation_grant_events_immutable();
            SQL);
        }
    }

    private function dropImmutabilityTriggers(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS impersonation_grant_events_no_delete; DROP TRIGGER IF EXISTS impersonation_grant_events_no_update;');

            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS impersonation_grant_events_no_truncate ON impersonation_grant_events;
                DROP TRIGGER IF EXISTS impersonation_grant_events_no_delete ON impersonation_grant_events;
                DROP TRIGGER IF EXISTS impersonation_grant_events_no_update ON impersonation_grant_events;
                DROP FUNCTION IF EXISTS impersonation_grant_events_immutable();
            SQL);
        }
    }
};
