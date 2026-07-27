<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\OutboundTransitionResult;
use App\Modules\Treasury\Application\Services\OutboundInstrumentService;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\InstrumentEvent;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

/** PostgreSQL-only, two-process proof of the instrument-row serialization contract. */
final class OutboundInstrumentConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * PostgreSQL fixtures must commit before forking so both independent PDOs
     * can see them. SQLite stays on the normal transaction-backed test path.
     *
     * @return list<string|null>
     */
    protected function connectionsToTransact(): array
    {
        return DB::getDriverName() === 'pgsql' ? [] : [config('database.default')];
    }

    public function test_concurrent_identical_bounces_execute_once_and_replay_once(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Two-process outbound lifecycle contention requires PostgreSQL.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the two-process lifecycle proof.');
        }

        [$instrument, $tenant, $company, $user] = $this->clearedInstrument();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);
        [$parentSocket, $childSocket] = $sockets;
        $resultFile = tempnam(sys_get_temp_dir(), 'outbound-bounce-');
        self::assertIsString($resultFile);

        $pid = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            fclose($parentSocket);
            DB::disconnect();
            fread($childSocket, 1);
            fclose($childSocket);

            try {
                $result = app(OutboundInstrumentService::class)->bounce(
                    $instrument->id,
                    $tenant->id,
                    $company->id,
                    $user->id,
                    'Concurrent dishonor',
                );
                file_put_contents($resultFile, json_encode($this->resultPayload($result), JSON_THROW_ON_ERROR));
                exit(0);
            } catch (Throwable $exception) {
                file_put_contents($resultFile, json_encode([
                    'error' => $exception::class,
                    'message' => $exception->getMessage(),
                ], JSON_THROW_ON_ERROR));
                exit(1);
            }
        }

        fclose($childSocket);
        try {
            fwrite($parentSocket, '1');
            fclose($parentSocket);
            $parentResult = app(OutboundInstrumentService::class)->bounce(
                $instrument->id,
                $tenant->id,
                $company->id,
                $user->id,
                'Concurrent dishonor',
            );

            $status = 0;
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
            $childPayload = json_decode((string) file_get_contents($resultFile), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($childPayload);
            self::assertArrayNotHasKey('error', $childPayload);

            $parentPayload = $this->resultPayload($parentResult);
            self::assertSame($parentPayload['journalEntryId'], $childPayload['journalEntryId'] ?? null);
            self::assertSame($parentPayload['movementId'], $childPayload['movementId'] ?? null);
            self::assertCount(1, array_filter([
                $parentPayload['replayed'],
                $childPayload['replayed'] ?? null,
            ], static fn (mixed $value): bool => $value === true));
        } finally {
            if (is_resource($parentSocket)) {
                fclose($parentSocket);
            }
            if (is_file($resultFile)) {
                unlink($resultFile);
            }
        }
    }

    public function test_concurrent_cancel_and_clear_serialize_with_exactly_one_winner(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Two-process outbound lifecycle contention requires PostgreSQL.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the two-process lifecycle proof.');
        }

        [$instrument, $tenant, $company, $user] = $this->instrument();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);
        [$parentSocket, $childSocket] = $sockets;
        $resultFile = tempnam(sys_get_temp_dir(), 'outbound-cancel-clear-');
        self::assertIsString($resultFile);

        $pid = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            fclose($parentSocket);
            DB::disconnect();
            fread($childSocket, 1);
            fclose($childSocket);

            try {
                $result = app(OutboundInstrumentService::class)->cancel(
                    $instrument->id,
                    $tenant->id,
                    $company->id,
                    $user->id,
                    'Concurrent cancellation',
                );
                file_put_contents($resultFile, json_encode([
                    'succeeded' => true,
                    'toStatus' => $result->toStatus,
                ], JSON_THROW_ON_ERROR));
                exit(0);
            } catch (Throwable $exception) {
                file_put_contents($resultFile, json_encode([
                    'succeeded' => false,
                    'error' => $exception::class,
                ], JSON_THROW_ON_ERROR));
                exit(0);
            }
        }

        fclose($childSocket);
        try {
            fwrite($parentSocket, '1');
            fclose($parentSocket);
            $parentSucceeded = true;
            try {
                app(OutboundInstrumentService::class)->clear(
                    $instrument->id,
                    $tenant->id,
                    $company->id,
                    $user->id,
                    '2026-07-18',
                );
            } catch (Throwable) {
                $parentSucceeded = false;
            }

            $status = 0;
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
            $childPayload = json_decode((string) file_get_contents($resultFile), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($childPayload);
            $childSucceeded = ($childPayload['succeeded'] ?? null) === true;
            self::assertCount(1, array_filter(
                [$parentSucceeded, $childSucceeded],
                static fn (bool $value): bool => $value,
            ));

            $fresh = PaymentInstrument::query()->findOrFail($instrument->id);
            self::assertContains($fresh->status, [InstrumentStatus::Cleared, InstrumentStatus::Cancelled]);
            self::assertSame(
                1,
                InstrumentEvent::query()
                    ->where('instrument_id', $instrument->id)
                    ->whereNotNull('action_key')
                    ->count(),
            );
        } finally {
            if (is_resource($parentSocket)) {
                fclose($parentSocket);
            }
            if (is_file($resultFile)) {
                unlink($resultFile);
            }
        }
    }

    /** @return array{0: PaymentInstrument, 1: Tenant, 2: Company, 3: User} */
    private function clearedInstrument(): array
    {
        [$instrument, $tenant, $company, $user] = $this->instrument();
        app(OutboundInstrumentService::class)->clear(
            $instrument->id,
            $tenant->id,
            $company->id,
            $user->id,
            '2026-07-18',
        );

        return [$instrument, $tenant, $company, $user];
    }

    /** @return array{0: PaymentInstrument, 1: Tenant, 2: Company, 3: User} */
    private function instrument(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $partner = Partner::factory()->create(['tenant_id' => $tenant->id, 'company_id' => $company->id]);
        app(ChartOfAccountsService::class)->seedForCompany($company);
        $bank = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'type' => 'bank_account',
            'gl_account_id' => Account::findByPurposeOrFail($company->id, SystemAccountPurpose::Bank)->id,
            'currency' => 'TND',
            'balance' => '0.000',
        ]);
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);
        $instrument = PaymentInstrument::query()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'payment_method_id' => $method->id,
            'reference' => 'RACE-'.Str::upper(Str::random(8)),
            'partner_id' => $partner->id,
            'amount' => '125.000',
            'currency' => 'TND',
            'received_date' => '2026-07-18',
            'status' => InstrumentStatus::Received,
            'kind' => InstrumentKind::Cheque,
            'direction' => InstrumentDirection::Outbound,
            'origin' => InstrumentOrigin::Web,
            'repository_id' => $bank->id,
            'created_by' => $user->id,
        ]);

        return [$instrument, $tenant, $company, $user];
    }

    /** @return array{journalEntryId: string|null, movementId: string|null, replayed: bool} */
    private function resultPayload(OutboundTransitionResult $result): array
    {
        return [
            'journalEntryId' => $result->journalEntryId,
            'movementId' => $result->movementId,
            'replayed' => $result->replayed,
        ];
    }
}
