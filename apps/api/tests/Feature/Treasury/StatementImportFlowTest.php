<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Contracts\StatementParserInterface;
use App\Modules\Treasury\Application\DTOs\ParsedStatement;
use App\Modules\Treasury\Application\Services\CsvStatementParser;
use App\Modules\Treasury\Application\Services\StatementImportService;
use App\Modules\Treasury\Application\Services\StatementParserRegistry;
use App\Modules\Treasury\Domain\BankStatement;
use App\Modules\Treasury\Domain\BankStatementLineAllocation;
use App\Modules\Treasury\Domain\BankStatementMatchExecution;
use App\Modules\Treasury\Domain\Enums\BankStatementStatus;
use App\Modules\Treasury\Domain\Enums\MatchActionType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Enums\StatementDirectionConvention;
use App\Modules\Treasury\Domain\Enums\StatementParserKey;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\StatementImportProfile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class StatementImportFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $admin;

    private User $accountant;

    private User $manager;

    private PaymentRepository $repository;

    private StatementImportProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->user('admin');
        $this->accountant = $this->user('accountant');
        $this->manager = $this->user('manager');
        app(CompanyContext::class)->setCompanyId($this->company->id);
        $this->repository = PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'type' => RepositoryType::BankAccount,
            'currency' => 'TND',
            'location_id' => $this->location->id,
        ]);
        $this->profile = $this->profile($this->company, $this->repository);
    }

    public function test_upload_previews_without_persisting_and_confirm_stages_statement_lines_only(): void
    {
        $movementCount = DB::table('repository_movements')->count();
        $journalCount = DB::table('journal_entries')->count();
        $upload = $this->upload($this->csv([
            '17/07/2026,250,TX-001,Customer transfer,1000,1250',
            '18/07/2026,0,,Opening marker,1000,1250',
            'not-a-date,20,TX-BAD,Broken row,,',
        ]));

        $upload->assertOk()
            ->assertJsonPath('data.accepted_line_count', 1)
            ->assertJsonPath('data.dropped_zero_amount_rows', 1)
            ->assertJsonPath('data.duplicate_fingerprint_count', 0)
            ->assertJsonPath('data.detected_opening', '1000.000')
            ->assertJsonPath('data.detected_closing', '1250.000')
            ->assertJsonCount(1, 'data.unparseable_rows')
            ->assertJsonCount(1, 'data.preview_lines');
        $this->assertDatabaseCount('bank_statements', 0);
        $this->assertDatabaseCount('bank_statement_lines', 0);

        $confirm = $this->confirm((string) $upload->json('data.preview_token'), [
            'opening_balance' => '1000',
            'closing_balance' => '1250.000',
        ]);

        $confirm->assertCreated()
            ->assertJsonPath('data.status', 'imported')
            ->assertJsonPath('data.currency', 'TND')
            ->assertJsonPath('meta.imported_line_count', 1)
            ->assertJsonPath('meta.skipped_duplicate_count', 0);
        $statementId = (string) $confirm->json('data.id');
        $this->assertDatabaseHas('bank_statement_lines', [
            'bank_statement_id' => $statementId,
            'location_id' => $this->location->id,
            'amount' => '250.000',
        ]);
        $this->assertSame($movementCount, DB::table('repository_movements')->count());
        $this->assertSame($journalCount, DB::table('journal_entries')->count());
    }

    public function test_duplicate_file_is_rejected_with_existing_statement_id(): void
    {
        $file = $this->csv(['17/07/2026,25,TX-DUP,Duplicate file,,']);
        $first = $this->upload($file);
        $statementId = (string) $this->confirm((string) $first->json('data.preview_token'))->json('data.id');

        $this->upload($file)
            ->assertUnprocessable()
            ->assertJsonPath('errors.existing_statement_id', $statementId);
        $this->confirm((string) $first->json('data.preview_token'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.existing_statement_id', $statementId);
    }

    public function test_overlapping_import_skips_existing_fingerprints_in_preview_and_confirm(): void
    {
        $first = $this->upload($this->csv([
            '17/07/2026,25,TX-OVERLAP,Same transfer,,',
        ]));
        $this->confirm((string) $first->json('data.preview_token'))->assertCreated();

        $second = $this->upload($this->csv([
            '17/07/2026,25,TX-OVERLAP,Same transfer,,',
            '18/07/2026,30,TX-NEW,New transfer,,',
        ]));
        $second->assertOk()
            ->assertJsonPath('data.duplicate_fingerprint_count', 1)
            ->assertJsonPath('data.accepted_line_count', 1);

        $confirmed = $this->confirm((string) $second->json('data.preview_token'));
        $confirmed->assertCreated()
            ->assertJsonPath('meta.imported_line_count', 1)
            ->assertJsonPath('meta.skipped_duplicate_count', 1);
        $this->assertDatabaseCount('bank_statement_lines', 2);
    }

    public function test_zero_accepted_lines_require_explicit_acknowledgment(): void
    {
        $first = $this->upload($this->csv(['17/07/2026,25,TX-ONLY,Same transfer,,']));
        $this->confirm((string) $first->json('data.preview_token'))->assertCreated();
        $overlap = $this->upload($this->csv([
            '17/07/2026,25,TX-ONLY,Same transfer,,',
            'not-a-date,9,TX-BAD,Broken,,',
        ]));
        $overlap->assertJsonPath('data.accepted_line_count', 0);

        $this->confirm((string) $overlap->json('data.preview_token'))
            ->assertUnprocessable();
        $this->confirm((string) $overlap->json('data.preview_token'), ['acknowledge_empty' => true])
            ->assertCreated()
            ->assertJsonPath('meta.imported_line_count', 0);
    }

    public function test_continuity_mismatch_warns_but_does_not_block_confirm(): void
    {
        $this->statement([
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'opening_balance' => '900.000',
            'closing_balance' => '1000.000',
            'status' => BankStatementStatus::Reconciled,
        ]);
        $upload = $this->upload($this->csv(['17/07/2026,25,TX-WARN,Transfer,,']));

        $this->confirm((string) $upload->json('data.preview_token'), ['opening_balance' => '999'])
            ->assertCreated()
            ->assertJsonPath('meta.continuity_warning.expected_opening', '1000.000')
            ->assertJsonPath('meta.continuity_warning.actual_opening', '999.000');
    }

    public function test_confirm_rejects_currency_mismatch(): void
    {
        $upload = $this->upload($this->csv(['17/07/2026,25,TX-CUR,Currency mismatch,,']));

        $this->confirm((string) $upload->json('data.preview_token'), ['currency' => 'EUR'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('bank_statements', 0);
    }

    public function test_void_requires_zero_allocations_and_executions(): void
    {
        $voidableFile = $this->csv(['17/07/2026,25,TX-VOID,Voidable,,']);
        $upload = $this->upload($voidableFile);
        $statementId = (string) $this->confirm((string) $upload->json('data.preview_token'))->json('data.id');
        $line = BankStatement::query()->findOrFail($statementId)->lines()->firstOrFail();
        $line->update([
            'match_status' => 'ignored',
            'ignore_reason' => 'other',
            'ignore_text' => 'Imported against the wrong statement period.',
        ]);
        BankStatement::query()->whereKey($statementId)->update(['status' => BankStatementStatus::Reconciling]);
        $this->actingAs($this->accountant)
            ->postJson("/api/v1/bank-statements/{$statementId}/void")
            ->assertOk()
            ->assertJsonPath('data.status', 'voided')
            ->assertJsonPath('data.lines_count', 1);
        $this->assertDatabaseHas('bank_statement_lines', [
            'id' => $line->id,
            'label' => 'Voidable',
            'match_status' => 'ignored',
            'ignore_reason' => 'other',
            'ignore_text' => 'Imported against the wrong statement period.',
            'dedupe_active' => false,
        ]);
        $this->actingAs($this->accountant)
            ->getJson("/api/v1/bank-statements/{$statementId}")
            ->assertOk()
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.lines.0.label', 'Voidable')
            ->assertJsonPath('data.lines.0.match_status', 'ignored');
        $reimport = $this->upload($voidableFile);
        $reimport->assertOk();
        $this->confirm((string) $reimport->json('data.preview_token'))
            ->assertCreated()
            ->assertJsonPath('meta.imported_line_count', 1);

        $blockedUpload = $this->upload($this->csv(['18/07/2026,30,TX-BLOCK,Blocked,,']));
        $blockedId = (string) $this->confirm((string) $blockedUpload->json('data.preview_token'))->json('data.id');
        $line = BankStatement::query()->findOrFail($blockedId)->lines()->firstOrFail();
        BankStatementMatchExecution::query()->create([
            'bank_statement_line_id' => $line->id,
            'action_type' => MatchActionType::CreateExpense,
            'action_key' => "stmtline:{$line->id}:create_expense",
            'semantic_digest' => hash('sha256', 'void-block'),
            'produced_repository_movement_ids' => [],
            'executed_by' => $this->admin->id,
            'executed_at' => now(),
        ]);

        $this->actingAs($this->accountant)
            ->postJson("/api/v1/bank-statements/{$blockedId}/void")
            ->assertUnprocessable();
        $this->assertSame(BankStatementStatus::Imported, BankStatement::query()->findOrFail($blockedId)->status);
    }

    public function test_void_rejects_allocated_or_reconciled_statements(): void
    {
        $upload = $this->upload($this->csv(['17/07/2026,25,TX-ALLOC,Allocated,,']));
        $statementId = (string) $this->confirm((string) $upload->json('data.preview_token'))->json('data.id');
        $line = BankStatement::query()->findOrFail($statementId)->lines()->firstOrFail();
        $movementId = Str::uuid()->toString();
        DB::table('repository_movements')->insert([
            'id' => $movementId,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_repository_id' => $this->repository->id,
            'direction' => 'in',
            'amount' => '25.000',
            'currency' => 'TND',
            'balance_after' => '25.000',
            'ordinal' => 1,
            'source_type' => 'adjustment',
            'source_id' => Str::uuid()->toString(),
            'idempotency_key' => 'statement-import-test:'.Str::uuid()->toString(),
            'occurred_at' => now(),
            'created_by' => $this->admin->id,
        ]);
        BankStatementLineAllocation::query()->create([
            'bank_statement_line_id' => $line->id,
            'repository_movement_id' => $movementId,
            'matched_amount' => '25.000',
            'match_type' => 'manual',
            'matched_by' => $this->admin->id,
            'matched_at' => now(),
        ]);
        $this->actingAs($this->accountant)
            ->postJson("/api/v1/bank-statements/{$statementId}/void")
            ->assertUnprocessable();

        $reconciled = $this->statement(['status' => BankStatementStatus::Reconciled]);
        $this->actingAs($this->accountant)
            ->postJson("/api/v1/bank-statements/{$reconciled->id}/void")
            ->assertUnprocessable();
    }

    public function test_profile_crud_is_company_scoped_and_repository_bound(): void
    {
        $created = $this->actingAs($this->accountant)->postJson('/api/v1/statement-import-profiles', [
            ...$this->profilePayload(),
            'name' => 'Second profile',
            'matching_window_days' => 7,
        ]);
        $created->assertCreated()
            ->assertJsonPath('data.name', 'Second profile')
            ->assertJsonPath('data.matching_window_days', 7);
        $id = (string) $created->json('data.id');

        $this->actingAs($this->accountant)
            ->patchJson("/api/v1/statement-import-profiles/{$id}", ['matching_window_days' => 31])
            ->assertUnprocessable();

        $this->actingAs($this->accountant)->getJson('/api/v1/statement-import-profiles')
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->actingAs($this->accountant)->patchJson("/api/v1/statement-import-profiles/{$id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');
        $this->actingAs($this->accountant)->deleteJson("/api/v1/statement-import-profiles/{$id}")
            ->assertNoContent();

        [$otherCompany, $otherRepository] = $this->otherCompanyRepository();
        $otherProfile = $this->profile($otherCompany, $otherRepository);
        $this->actingAs($this->accountant)->getJson("/api/v1/statement-import-profiles/{$otherProfile->id}")
            ->assertNotFound();
        $this->actingAs($this->accountant)->postJson('/api/v1/statement-import-profiles', [
            ...$this->profilePayload(),
            'payment_repository_id' => $otherRepository->id,
        ])->assertUnprocessable();
    }

    public function test_cross_company_repository_or_profile_is_rejected_before_file_storage(): void
    {
        [$otherCompany, $otherRepository] = $this->otherCompanyRepository();
        $otherProfile = $this->profile($otherCompany, $otherRepository);
        $file = $this->statementFile($this->csv(['17/07/2026,25,TX-XCO,Cross company,,']));

        $this->actingAs($this->accountant)->post('/api/v1/bank-statements/upload', [
            'payment_repository_id' => $otherRepository->id,
            'parser_profile_id' => $otherProfile->id,
            'file' => $file,
        ])->assertUnprocessable();
        Storage::disk('local')->assertDirectoryEmpty('bank-statements');
    }

    public function test_permissions_grant_accountant_not_manager_and_reopen_is_admin_only(): void
    {
        $this->assertTrue($this->accountant->can('bank-statements.view'));
        $this->assertTrue($this->accountant->can('bank-statements.import'));
        $this->assertTrue($this->accountant->can('bank-statements.reconcile'));
        $this->assertFalse($this->accountant->can('bank-statements.reopen'));
        $this->assertTrue($this->admin->can('bank-statements.reopen'));

        $this->actingAs($this->manager)->getJson('/api/v1/bank-statements')->assertForbidden();
        $this->actingAs($this->manager)->post('/api/v1/bank-statements/upload', [
            'payment_repository_id' => $this->repository->id,
            'parser_profile_id' => $this->profile->id,
            'file' => $this->statementFile($this->csv(['17/07/2026,25,TX-NO,Forbidden,,'])),
        ])->assertForbidden();
    }

    public function test_statement_reads_hide_other_companies_and_reject_malformed_ids(): void
    {
        [$otherCompany, $otherRepository] = $this->otherCompanyRepository();
        $otherProfile = $this->profile($otherCompany, $otherRepository);
        $otherStatement = BankStatement::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'payment_repository_id' => $otherRepository->id,
            'currency' => 'TND',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'opening_balance' => '0.000',
            'closing_balance' => '0.000',
            'status' => BankStatementStatus::Imported,
            'source_file_sha256' => hash('sha256', 'other-company-statement'),
            'source_file_path' => 'bank-statements/other.csv',
            'parser_profile_id' => $otherProfile->id,
            'imported_by' => $this->admin->id,
            'imported_at' => now(),
        ]);

        $this->actingAs($this->accountant)->getJson("/api/v1/bank-statements/{$otherStatement->id}")
            ->assertNotFound();
        $this->actingAs($this->accountant)->postJson("/api/v1/bank-statements/{$otherStatement->id}/void")
            ->assertNotFound();
        $this->actingAs($this->accountant)->getJson('/api/v1/bank-statements/not-a-uuid')
            ->assertNotFound();
    }

    public function test_tampered_preview_token_is_rejected_without_persistence(): void
    {
        $upload = $this->upload($this->csv(['17/07/2026,25,TX-TOKEN,Tamper,,']));

        $this->confirm((string) $upload->json('data.preview_token').'tampered')
            ->assertUnprocessable();
        $this->assertDatabaseCount('bank_statements', 0);
    }

    public function test_confirm_reparses_before_opening_the_repository_transaction(): void
    {
        $upload = $this->upload($this->csv(['17/07/2026,25,TX-LOCK,No long lock,,']));
        $delegate = $this->app->make(CsvStatementParser::class);
        $baselineTransactionLevel = DB::transactionLevel();
        $guardedParser = new class($delegate, $baselineTransactionLevel) implements StatementParserInterface
        {
            public function __construct(
                private readonly CsvStatementParser $delegate,
                private readonly int $baselineTransactionLevel,
            ) {}

            public function parse(string $storedFilePath, StatementImportProfile $profile): ParsedStatement
            {
                if (DB::transactionLevel() !== $this->baselineTransactionLevel) {
                    throw new \DomainException('Statement parsing must occur before the repository transaction opens.');
                }

                return $this->delegate->parse($storedFilePath, $profile);
            }
        };
        $this->app->instance(StatementParserRegistry::class, new StatementParserRegistry([
            StatementParserKey::Csv->value => $guardedParser,
        ]));

        $this->confirm((string) $upload->json('data.preview_token'))->assertCreated();
    }

    public function test_repository_profile_expiry_and_integrity_guards_fail_loud(): void
    {
        $cash = PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'type' => RepositoryType::CashRegister,
            'currency' => 'TND',
        ]);
        $cashProfile = $this->profile($this->company, $cash);
        $this->uploadFor($cash, $cashProfile, $this->csv(['17/07/2026,1,TX-CASH,Cash,,']))
            ->assertUnprocessable();

        $this->repository->update(['is_active' => false]);
        $this->upload($this->csv(['17/07/2026,1,TX-INACTIVE-REPO,Inactive,,']))
            ->assertUnprocessable();
        $this->repository->update(['is_active' => true]);

        $this->profile->update(['is_active' => false]);
        $this->upload($this->csv(['17/07/2026,1,TX-INACTIVE-PROFILE,Inactive,,']))
            ->assertUnprocessable();
        $this->profile->update(['is_active' => true]);

        $expired = $this->upload($this->csv(['17/07/2026,1,TX-EXPIRED,Expired,,']));
        $expiredPayload = json_decode(Crypt::decryptString((string) $expired->json('data.preview_token')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($expiredPayload);
        $expiredPayload['issued_at'] = now()->subHours(5)->getTimestamp();
        $expiredToken = Crypt::encryptString(json_encode($expiredPayload, JSON_THROW_ON_ERROR));
        $this->confirm($expiredToken)->assertUnprocessable();

        $integrity = $this->upload($this->csv(['17/07/2026,1,TX-HASH,Integrity,,']));
        $integrityPayload = json_decode(Crypt::decryptString((string) $integrity->json('data.preview_token')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($integrityPayload);
        Storage::disk('local')->put((string) $integrityPayload['source_file_path'], 'tampered bytes');
        $this->confirm((string) $integrity->json('data.preview_token'))
            ->assertUnprocessable()
            ->assertJsonPath('error.message', 'The staged statement file failed its integrity check.');
    }

    public function test_referenced_profile_cannot_be_deleted_but_can_be_deactivated(): void
    {
        $this->statement(['parser_profile_id' => $this->profile->id]);

        $this->actingAs($this->accountant)
            ->deleteJson("/api/v1/statement-import-profiles/{$this->profile->id}")
            ->assertUnprocessable();
        $this->actingAs($this->accountant)
            ->patchJson("/api/v1/statement-import-profiles/{$this->profile->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('bank_statements', ['parser_profile_id' => $this->profile->id]);
    }

    public function test_confirm_rejects_parser_profile_changes_after_preview(): void
    {
        $upload = $this->upload($this->csv(['17/07/2026,1,TX-PROFILE,Profile changed,,']));
        $this->profile->update(['date_format' => 'Y-m-d']);

        $this->confirm((string) $upload->json('data.preview_token'))
            ->assertUnprocessable()
            ->assertJsonPath('error.message', 'The parser profile changed after preview. Upload the file again.');
        $this->assertDatabaseCount('bank_statements', 0);
    }

    public function test_chunked_lookup_and_bulk_insert_preserve_all_rows_and_overlap_dedupe(): void
    {
        $reflection = new \ReflectionClass(StatementImportService::class);
        $lookupChunk = $reflection->getConstant('FINGERPRINT_LOOKUP_CHUNK');
        $insertChunk = $reflection->getConstant('LINE_INSERT_CHUNK');
        $this->assertIsInt($lookupChunk);
        $this->assertIsInt($insertChunk);
        $this->assertLessThanOrEqual(999, $lookupChunk);
        $this->assertLessThanOrEqual(32000, $insertChunk * 16);

        $rows = [];
        for ($number = 1; $number <= 1201; $number++) {
            $rows[] = sprintf(
                '17/07/2026,1,TX-CHUNK-%04d,Chunk %d,,',
                $number,
                $number,
            );
        }
        $upload = $this->upload($this->csv($rows));
        $upload->assertOk()->assertJsonPath('data.accepted_line_count', 1201);
        $firstFingerprint = (string) $upload->json('data.preview_lines.0.fingerprint');
        $firstLineNumber = (int) $upload->json('data.preview_lines.0.line_number');

        $lineInsertQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$lineInsertQueries): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'insert')
                && str_contains(strtolower($query->sql), 'bank_statement_lines')) {
                $lineInsertQueries++;
            }
        });
        $confirmed = $this->confirm((string) $upload->json('data.preview_token'));
        $confirmed->assertCreated()->assertJsonPath('meta.imported_line_count', 1201);
        $statementId = (string) $confirmed->json('data.id');
        $this->assertSame(1201, BankStatement::query()->findOrFail($statementId)->lines()->count());
        $this->assertGreaterThan(0, $lineInsertQueries);
        $this->assertLessThanOrEqual((int) ceil(1201 / $insertChunk), $lineInsertQueries);
        $persistedFirstLine = BankStatement::query()->findOrFail($statementId)->lines()
            ->where('fingerprint', $firstFingerprint)
            ->firstOrFail();
        $this->assertTrue(Str::isUuid($persistedFirstLine->id));
        $this->assertDatabaseHas('bank_statement_lines', [
            'bank_statement_id' => $statementId,
            'payment_repository_id' => $this->repository->id,
            'line_number' => $firstLineNumber,
            'value_date' => '2026-07-17',
            'booking_date' => null,
            'direction' => 'in',
            'amount' => '1.000',
            'reference' => null,
            'bank_transaction_id' => 'TX-CHUNK-0001',
            'label' => 'Chunk 1',
            'counterparty_hint' => null,
            'match_status' => 'unmatched',
            'location_id' => $this->repository->location_id,
            'fingerprint' => $firstFingerprint,
            'dedupe_active' => true,
        ]);

        $overlap = $this->upload($this->csv([
            ...$rows,
            '18/07/2026,2,TX-CHUNK-NEW,New row,,',
        ]));
        $overlap->assertOk()
            ->assertJsonPath('data.duplicate_fingerprint_count', 1201)
            ->assertJsonPath('data.accepted_line_count', 1);
        $this->confirm((string) $overlap->json('data.preview_token'))
            ->assertCreated()
            ->assertJsonPath('meta.imported_line_count', 1)
            ->assertJsonPath('meta.skipped_duplicate_count', 1201);
    }

    /** @return TestResponse<Response> */
    private function upload(string $contents): TestResponse
    {
        return $this->uploadFor($this->repository, $this->profile, $contents);
    }

    /** @return TestResponse<Response> */
    private function uploadFor(
        PaymentRepository $repository,
        StatementImportProfile $profile,
        string $contents,
    ): TestResponse {
        return $this->actingAs($this->accountant)->post('/api/v1/bank-statements/upload', [
            'payment_repository_id' => $repository->id,
            'parser_profile_id' => $profile->id,
            'file' => $this->statementFile($contents),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function confirm(string $token, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->accountant)->postJson('/api/v1/bank-statements', [
            'preview_token' => $token,
            'currency' => 'TND',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'opening_balance' => '1000',
            'closing_balance' => '1025',
            ...$overrides,
        ]);
    }

    /** @param list<string> $rows */
    private function csv(array $rows): string
    {
        return "Date,Amount,Transaction ID,Label,Opening,Closing\n".implode("\n", $rows)."\n";
    }

    private function statementFile(string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('statement-'.Str::random(8).'.csv', $contents);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole($role);
        UserCompanyMembership::query()->create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => $role,
        ]);

        return $user;
    }

    private function profile(Company $company, PaymentRepository $repository): StatementImportProfile
    {
        return StatementImportProfile::query()->create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'payment_repository_id' => $repository->id,
            'name' => 'Bank CSV',
            'is_active' => true,
            'parser_key' => StatementParserKey::Csv,
            'column_map' => $this->columnMap(),
            'date_format' => 'd/m/Y',
            'decimal_format' => 'dot',
            'direction_convention' => StatementDirectionConvention::SignedAmount,
            'header_rows' => 0,
        ]);
    }

    /** @return array<string, mixed> */
    private function profilePayload(): array
    {
        return [
            'payment_repository_id' => $this->repository->id,
            'name' => 'Bank CSV',
            'is_active' => true,
            'parser_key' => 'csv',
            'column_map' => $this->columnMap(),
            'date_format' => 'd/m/Y',
            'decimal_format' => 'dot',
            'direction_convention' => 'signed_amount',
            'header_rows' => 0,
        ];
    }

    /** @return array<string, string> */
    private function columnMap(): array
    {
        return [
            'value_date' => 'Date',
            'amount' => 'Amount',
            'bank_transaction_id' => 'Transaction ID',
            'label' => 'Label',
            'opening_balance' => 'Opening',
            'closing_balance' => 'Closing',
        ];
    }

    /** @return array{Company, PaymentRepository} */
    private function otherCompanyRepository(): array
    {
        $company = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);
        $repository = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $this->tenant->id,
            'type' => RepositoryType::BankAccount,
            'currency' => 'TND',
        ]);

        return [$company, $repository];
    }

    /** @param array<string, mixed> $overrides */
    private function statement(array $overrides = []): BankStatement
    {
        return BankStatement::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_repository_id' => $this->repository->id,
            'currency' => 'TND',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'opening_balance' => '1000.000',
            'closing_balance' => '1025.000',
            'status' => BankStatementStatus::Imported,
            'source_file_sha256' => hash('sha256', Str::uuid()->toString()),
            'source_file_path' => 'bank-statements/test.csv',
            'parser_profile_id' => $this->profile->id,
            'imported_by' => $this->admin->id,
            'imported_at' => now(),
            ...$overrides,
        ]);
    }
}
