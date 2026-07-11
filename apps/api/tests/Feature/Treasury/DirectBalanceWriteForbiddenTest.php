<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Application\Services\TreasuryMovementService;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Exceptions\IdempotencyConflictException;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Treasury Money-Movement Spine, Task 22 (cutover): the DB trigger that forbids
 * any direct `payment_repositories.balance` write outside the movement port.
 *
 * The trigger is pgsql-only (a `SET LOCAL` GUC guard) — the sqlite fast loop has
 * no trigger, so the rejection tests skip there. The port-write and MED-10 tests
 * that must actually observe the trigger are pgsql-gated; the port-round-trip and
 * source-ordering assertions run on both drivers.
 *
 * Each pgsql RAISE aborts the surrounding RefreshDatabase transaction, so a
 * single test method cannot both trip the trigger AND assert further DB state —
 * the rejection and the allow-path are therefore split into separate methods.
 */
final class DirectBalanceWriteForbiddenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        // Rule 20: the port runs with NO CompanyContext in queued/fiscal
        // contexts — clear it so scale resolution flows via the explicit intent
        // currency, matching the worker reality.
        app(CompanyContext::class)->clear();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create([
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_direct_balance_update_outside_port_is_rejected(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('direct-balance-write forbidden enforced by pgsql trigger');
        }

        $repo = $this->seedRepository(balance: '100.000');

        // A rogue direct write via the query builder (bypasses the model's
        // guarded `$fillable`, exactly what the trigger must catch). No GUC is
        // set → the trigger fires.
        try {
            DB::table('payment_repositories')->where('id', $repo->id)->update(['balance' => '999.000']);
            $this->fail('direct balance UPDATE should have been rejected by the trigger');
        } catch (QueryException $e) {
            $this->assertStringContainsString('TreasuryMovementService', $e->getMessage());
        }
    }

    public function test_nonzero_balance_insert_outside_port_is_rejected(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('INSERT-with-nonzero-balance forbidden enforced by pgsql trigger');
        }

        // A repository BORN with a non-zero balance would mint a cached balance
        // with NO backing movement — the INSERT escape the trigger must close. A
        // RAW insert (no port GUC) is exactly the rogue write the trigger guards,
        // and it bypasses the factory's fixture bracket. Clone a real born-at-zero
        // row so every NOT NULL column is present, then flip only id/code/balance.
        $seed = $this->seedRepository(balance: '0.000');

        /** @var \stdClass $row */
        $row = DB::table('payment_repositories')->where('id', $seed->id)->firstOrFail();
        $insert = (array) $row;
        $insert['id'] = (string) Str::uuid();
        $insert['code'] = 'RAW-'.substr((string) Str::uuid(), 0, 6);
        $insert['balance'] = '5000.000';

        try {
            DB::table('payment_repositories')->insert($insert);
            $this->fail('INSERT with a non-zero balance should have been rejected by the trigger');
        } catch (QueryException $e) {
            $this->assertStringContainsString('TreasuryMovementService', $e->getMessage());
        }
    }

    public function test_opening_balance_via_port_sets_balance_and_records_backing_movement(): void
    {
        // Runs on BOTH drivers: repo born at 0, opening established via the port,
        // and the cached balance is BACKED by an opening_balance movement (so the
        // ledger reconciles — the whole point of routing opening through the port).
        $repo = $this->seedRepository(balance: '250.000');

        $this->assertSame('250.000', $repo->balance);

        $opening = RepositoryMovement::query()
            ->where('payment_repository_id', $repo->id)
            ->where('source_type', MovementSourceType::OpeningBalance->value)
            ->first();

        $this->assertNotNull($opening, 'opening balance must be backed by an opening_balance movement');
        $this->assertSame('250.000', $opening->balance_after);
    }

    public function test_guc_is_reset_after_successful_port_call_so_a_later_direct_write_is_rejected(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('GUC reset is observable only via the pgsql trigger');
        }

        $repo = $this->seedRepository(balance: '100.000');

        // Within ONE outer transaction: a SUCCESSFUL port call, then a direct
        // balance UPDATE. The port must have reset the GUC to 'off' on its way out
        // (Fix 2 finally), so the direct write trips the trigger.
        try {
            DB::transaction(function () use ($repo): void {
                $this->service()->record($this->intent($repo, amount: '40.000', sourceId: (string) Str::uuid()));

                DB::table('payment_repositories')->where('id', $repo->id)->update(['balance' => '999.000']);
            });
            $this->fail('direct balance UPDATE after a port call in the same txn should be rejected (GUC reset)');
        } catch (QueryException $e) {
            $this->assertStringContainsString('TreasuryMovementService', $e->getMessage());
        }
    }

    public function test_guc_is_reset_when_the_port_throws_so_a_later_direct_write_is_rejected(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('GUC reset is observable only via the pgsql trigger');
        }

        $repo = $this->seedRepository(balance: '100.000');
        $conflictSourceId = (string) Str::uuid();

        // Establish the idempotency key with a committed movement.
        DB::transaction(fn () => $this->service()->record(
            $this->intent($repo, amount: '10.000', sourceId: $conflictSourceId),
        ));

        try {
            DB::transaction(function () use ($repo, $conflictSourceId): void {
                // Replay the SAME key with a DIFFERENT amount → unique violation →
                // handleIdempotentHit detects the mismatch and THROWS, AFTER the
                // port has already SET the GUC to 'on'. The Fix 2 finally must
                // still reset it to 'off'.
                try {
                    $this->service()->record($this->intent($repo, amount: '999.000', sourceId: $conflictSourceId));
                    $this->fail('a conflicting idempotency replay should have thrown');
                } catch (IdempotencyConflictException) {
                    // expected — the port threw after opening the GUC.
                }

                // Same outer transaction: the GUC must have been reset by the
                // finally, so this direct write trips the trigger.
                DB::table('payment_repositories')->where('id', $repo->id)->update(['balance' => '777.000']);
            });
            $this->fail('direct balance UPDATE after the port threw should be rejected (GUC reset in finally)');
        } catch (QueryException $e) {
            $this->assertStringContainsString('TreasuryMovementService', $e->getMessage());
        }
    }

    public function test_port_record_moves_balance_and_passes_the_trigger(): void
    {
        // Runs on BOTH drivers: on sqlite it proves the port round-trips; on
        // pgsql it additionally proves the trigger ALLOWS the port's write
        // (the port sets `app.treasury_movement_port = 'on'`).
        $repo = $this->seedRepository(balance: '100.000');

        $result = DB::transaction(fn () => $this->service()->record(
            $this->intent($repo, amount: '40.000', sourceId: (string) Str::uuid()),
        ));

        $this->assertFalse($result->wasIdempotentHit);
        $repo->refresh();
        $this->assertSame('140.000', $repo->balance);
    }

    public function test_freeze_is_not_blocked_by_the_trigger(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('trigger only exists on pgsql');
        }

        $repo = $this->seedRepository(balance: '100.000');

        // freeze() is a direct column update OUTSIDE the port and sets no GUC —
        // but it touches only frozen_at/frozen_reason, so
        // `NEW.balance IS DISTINCT FROM OLD.balance` is false and the trigger
        // skips it.
        $this->service()->freeze($repo->id, 'end_of_day_count');

        $repo->refresh();
        $this->assertNotNull($repo->frozen_at);
        $this->assertSame('end_of_day_count', $repo->frozen_reason);
        $this->assertSame('100.000', $repo->balance);
    }

    public function test_med10_guc_survives_savepoint_rollback_and_later_port_write_passes(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('MED-10 GUC/savepoint behaviour is pgsql-specific');
        }

        $repo = $this->seedRepository(balance: '100.000');
        $replaySourceId = (string) Str::uuid();

        // One outer transaction spanning: a first record(), an idempotent replay
        // (which hits a duplicate-key rollback-to-savepoint inside record()), and
        // a THIRD legitimate port write — all three balance writes must pass the
        // trigger. The GUC was `SET LOCAL … 'on'` BEFORE the savepoint, so the
        // rollback-to-savepoint cannot unset it, and each record() re-opens it.
        DB::transaction(function () use ($repo, $replaySourceId): void {
            $first = $this->service()->record(
                $this->intent($repo, amount: '10.000', sourceId: $replaySourceId),
            );
            $this->assertFalse($first->wasIdempotentHit);

            // Same intent → same idempotency key → unique-violation →
            // rollback-to-savepoint → handleIdempotentHit returns the existing row.
            $replay = $this->service()->record(
                $this->intent($repo, amount: '10.000', sourceId: $replaySourceId),
            );
            $this->assertTrue($replay->wasIdempotentHit);

            // A subsequent legitimate balance write in the SAME outer transaction
            // still passes the trigger (proving the GUC survived the savepoint
            // rollback and the port re-opens it cleanly).
            $third = $this->service()->record(
                $this->intent($repo, amount: '25.000', sourceId: (string) Str::uuid()),
            );
            $this->assertFalse($third->wasIdempotentHit);
        });

        // 100 + 10 (once, not twice — the replay was idempotent) + 25 = 135.
        $repo->refresh();
        $this->assertSame('135.000', $repo->balance);
    }

    public function test_port_sets_the_guc_before_opening_its_idempotency_savepoint(): void
    {
        // Driver-agnostic MED-10 guard: the `SET LOCAL … 'on'` MUST precede the
        // savepoint (`DB::beginTransaction()`) in record(), so a rollback-to-
        // savepoint can never unset it. Assert the source ordering directly.
        $method = new ReflectionMethod(TreasuryMovementService::class, 'record');
        $source = $this->methodSource($method);

        $setPos = strpos($source, "SET LOCAL app.treasury_movement_port = 'on'");
        $savepointPos = strpos($source, 'DB::beginTransaction()');

        $this->assertNotFalse($setPos, "record() must issue SET LOCAL app.treasury_movement_port = 'on'");
        $this->assertNotFalse($savepointPos, 'record() must open an idempotency savepoint via DB::beginTransaction()');
        $this->assertLessThan(
            $savepointPos,
            $setPos,
            'record() must SET the port GUC BEFORE opening its savepoint (MED-10), so a rollback-to-savepoint cannot unset it.',
        );
    }

    private function service(): TreasuryMovementServiceInterface
    {
        return app(TreasuryMovementServiceInterface::class);
    }

    /**
     * @param  numeric-string  $balance
     */
    private function seedRepository(string $currency = 'TND', string $balance = '100.000'): PaymentRepository
    {
        // Cutover-hardening (Fix 1): repositories are BORN at balance 0 — the
        // INSERT guard rejects a non-zero balance minted with no backing movement.
        // A non-zero opening balance is established through the PORT (which lays
        // down the opening_balance movement so the ledger reconciles), mirroring
        // production seeders. This runs on both drivers (the port round-trips on
        // sqlite too).
        $repo = PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'currency' => $currency,
            'balance' => '0.000',
            'next_movement_ordinal' => 0,
            'frozen_at' => null,
            'frozen_reason' => null,
        ]);

        if (bccomp($balance, '0', 3) === 1) {
            DB::transaction(fn () => $this->service()->record(new MovementIntent(
                repositoryId: $repo->id,
                tenantId: $repo->tenant_id,
                companyId: $repo->company_id,
                direction: MovementDirection::In,
                amount: $balance,
                currency: $repo->currency,
                sourceType: MovementSourceType::OpeningBalance,
                sourceId: $repo->id,
                idempotencyLeg: 'opening',
                journalEntryId: null,
                occurredAt: null,
                reasonCode: null,
                reversesMovementId: null,
                createdBy: null,
                notes: null,
                allowWhileFrozen: false,
            )));
            $repo->refresh();
        }

        return $repo;
    }

    /**
     * @param  numeric-string  $amount
     */
    private function intent(PaymentRepository $repo, string $amount, string $sourceId): MovementIntent
    {
        return new MovementIntent(
            repositoryId: $repo->id,
            tenantId: $repo->tenant_id,
            companyId: $repo->company_id,
            direction: MovementDirection::In,
            amount: $amount,
            currency: $repo->currency,
            sourceType: MovementSourceType::Payment,
            sourceId: $sourceId,
            idempotencyLeg: 'main',
            journalEntryId: null,
            occurredAt: null,
            reasonCode: null,
            reversesMovementId: null,
            createdBy: null,
            notes: null,
            allowWhileFrozen: false,
        );
    }

    private function methodSource(ReflectionMethod $method): string
    {
        $file = (string) $method->getFileName();
        $lines = file($file) ?: [];
        $start = (int) $method->getStartLine() - 1;
        $length = (int) $method->getEndLine() - $start;

        return implode('', array_slice($lines, $start, $length));
    }
}
