<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Treasury\Application\DTOs\ParsedStatement;
use App\Modules\Treasury\Application\DTOs\ParsedStatementLine;
use App\Modules\Treasury\Application\Exceptions\DuplicateStatementFileException;
use App\Modules\Treasury\Domain\BankStatement;
use App\Modules\Treasury\Domain\BankStatementLine;
use App\Modules\Treasury\Domain\Enums\BankStatementStatus;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Enums\StatementLineMatchStatus;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\StatementImportProfile;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class StatementImportService
{
    private const PREVIEW_LIMIT = 50;

    private const TOKEN_LIFETIME_SECONDS = 14400;

    public function __construct(
        private StatementParserRegistry $parsers,
        private CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /** @return array<string, mixed> */
    public function preview(
        UploadedFile $file,
        PaymentRepository $repository,
        StatementImportProfile $profile,
        string $tenantId,
        string $companyId,
    ): array {
        $this->guardOwnership($repository, $profile, $tenantId, $companyId);
        $sourcePath = $file->getRealPath();
        $sha256 = hash_file('sha256', $sourcePath);
        if ($sha256 === false) {
            throw new RuntimeException('The statement upload could not be hashed.');
        }
        $this->rejectDuplicateFile($repository->id, $sha256);

        $extension = strtolower($file->getClientOriginalExtension());
        $storedPath = 'bank-statements/'.$tenantId.'/'.$companyId.'/'.Str::uuid()->toString().'.'.$extension;
        $stored = Storage::disk('local')->putFileAs(
            dirname($storedPath),
            $file,
            basename($storedPath),
        );
        if (! is_string($stored)) {
            throw new RuntimeException('The statement upload could not be stored.');
        }

        try {
            $parsed = $this->parseStored($storedPath, $repository, $profile);
            [$accepted, $duplicateCount] = $this->withoutExistingFingerprints($repository->id, $parsed);
            $token = Crypt::encryptString(json_encode([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'payment_repository_id' => $repository->id,
                'parser_profile_id' => $profile->id,
                'source_file_path' => $storedPath,
                'source_file_sha256' => $sha256,
                'profile_digest' => $this->profileDigest($profile),
                'issued_at' => now()->timestamp,
            ], JSON_THROW_ON_ERROR));
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPath);

            throw $exception;
        }

        return [
            'preview_token' => $token,
            'source_file_sha256' => $sha256,
            'preview_lines' => array_map($this->formatParsedLine(...), array_slice($accepted, 0, self::PREVIEW_LIMIT)),
            'accepted_line_count' => count($accepted),
            'duplicate_fingerprint_count' => $duplicateCount,
            'dropped_zero_amount_rows' => $parsed->droppedZeroAmountRows,
            'unparseable_rows' => $parsed->unparseableRows,
            'detected_opening' => $parsed->detectedOpening,
            'detected_closing' => $parsed->detectedClosing,
        ];
    }

    /**
     * @param array{
     *   currency: string, period_start: string, period_end: string,
     *   opening_balance: string, closing_balance: string, acknowledge_empty?: bool
     * } $input
     * @return array{statement: BankStatement, imported_line_count: int, skipped_duplicate_count: int, continuity_warning: array<string, string>|null}
     */
    public function confirm(string $previewToken, array $input, string $userId, string $tenantId, string $companyId): array
    {
        $payload = $this->decodeToken($previewToken);
        if ($payload['tenant_id'] !== $tenantId || $payload['company_id'] !== $companyId) {
            throw new DomainException('The statement preview belongs to a different tenant or company.');
        }
        if (now()->getTimestamp() - $payload['issued_at'] > self::TOKEN_LIFETIME_SECONDS) {
            throw new DomainException('The statement preview has expired. Upload the file again.');
        }

        $repository = PaymentRepository::query()->find($payload['payment_repository_id']);
        $profile = StatementImportProfile::query()->find($payload['parser_profile_id']);
        if (! $repository instanceof PaymentRepository || ! $profile instanceof StatementImportProfile) {
            throw new DomainException('The statement repository or parser profile no longer exists.');
        }
        $this->guardOwnership($repository, $profile, $tenantId, $companyId);
        if (! hash_equals($payload['profile_digest'], $this->profileDigest($profile))) {
            throw new DomainException('The parser profile changed after preview. Upload the file again.');
        }
        if (strtoupper($input['currency']) !== strtoupper($repository->currency)) {
            throw new DomainException('Statement currency must match the repository currency.');
        }
        if (! Storage::disk('local')->exists($payload['source_file_path'])) {
            throw new DomainException('The staged statement file no longer exists.');
        }
        $absolutePath = Storage::disk('local')->path($payload['source_file_path']);
        $this->assertFileIntegrity(
            $absolutePath,
            $payload['source_file_sha256'],
            'The staged statement file failed its integrity check.',
        );

        $parsed = $this->parseStored($payload['source_file_path'], $repository, $profile);
        $this->assertFileIntegrity(
            $absolutePath,
            $payload['source_file_sha256'],
            'The staged statement file changed while it was being parsed.',
        );

        $scale = $this->scaleResolver->getScale($repository->currency);
        $opening = $this->canonicalMoney($input['opening_balance'], $scale);
        $closing = $this->canonicalMoney($input['closing_balance'], $scale);
        $profileDigest = $payload['profile_digest'];
        $repositoryCurrency = strtoupper($repository->currency);

        return DB::transaction(function () use (
            $payload,
            $repository,
            $profile,
            $tenantId,
            $companyId,
            $input,
            $userId,
            $opening,
            $closing,
            $parsed,
            $profileDigest,
            $repositoryCurrency,
        ): array {
            $lockedRepository = PaymentRepository::query()->lockForUpdate()->find($repository->id);
            $lockedProfile = StatementImportProfile::query()->lockForUpdate()->find($profile->id);
            if (! $lockedRepository instanceof PaymentRepository || ! $lockedProfile instanceof StatementImportProfile) {
                throw new DomainException('The statement repository or parser profile no longer exists.');
            }
            $this->guardOwnership($lockedRepository, $lockedProfile, $tenantId, $companyId);
            if (strtoupper($lockedRepository->currency) !== $repositoryCurrency) {
                throw new DomainException('The repository currency changed after preview. Upload the file again.');
            }
            if (! hash_equals($profileDigest, $this->profileDigest($lockedProfile))) {
                throw new DomainException('The parser profile changed after preview. Upload the file again.');
            }
            $this->rejectDuplicateFile($lockedRepository->id, $payload['source_file_sha256']);
            [$accepted, $duplicateCount] = $this->withoutExistingFingerprints($lockedRepository->id, $parsed, true);
            if ($accepted === [] && ! ($input['acknowledge_empty'] ?? false)) {
                throw new DomainException('No statement lines can be accepted; acknowledge the empty import to continue.');
            }

            $continuityWarning = $this->continuityWarning(
                $lockedRepository->id,
                $input['period_start'],
                $opening,
            );
            $statement = BankStatement::query()->create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'payment_repository_id' => $lockedRepository->id,
                'currency' => strtoupper($lockedRepository->currency),
                'period_start' => $input['period_start'],
                'period_end' => $input['period_end'],
                'opening_balance' => $opening,
                'closing_balance' => $closing,
                'status' => BankStatementStatus::Imported,
                'source_file_sha256' => $payload['source_file_sha256'],
                'source_file_path' => $payload['source_file_path'],
                'parser_profile_id' => $lockedProfile->id,
                'imported_by' => $userId,
                'imported_at' => now(),
            ]);

            foreach ($accepted as $line) {
                BankStatementLine::query()->create([
                    'bank_statement_id' => $statement->id,
                    'payment_repository_id' => $lockedRepository->id,
                    'line_number' => $line->lineNumber,
                    'value_date' => $line->valueDate,
                    'booking_date' => $line->bookingDate,
                    'direction' => $line->direction,
                    'amount' => $line->amount,
                    'reference' => $line->reference,
                    'bank_transaction_id' => $line->bankTransactionId,
                    'label' => $line->label,
                    'counterparty_hint' => $line->counterpartyHint,
                    'match_status' => StatementLineMatchStatus::Unmatched,
                    'location_id' => $lockedRepository->location_id,
                    'fingerprint' => $line->fingerprint,
                ]);
            }

            return [
                'statement' => $statement->loadCount('lines'),
                'imported_line_count' => count($accepted),
                'skipped_duplicate_count' => $duplicateCount,
                'continuity_warning' => $continuityWarning,
            ];
        });
    }

    public function void(BankStatement $statement): BankStatement
    {
        return DB::transaction(function () use ($statement): BankStatement {
            $locked = BankStatement::query()->lockForUpdate()->findOrFail($statement->id);
            if (! $locked->status->canTransitionTo(BankStatementStatus::Voided)) {
                throw new DomainException("Statement status {$locked->status->value} cannot transition to voided.");
            }
            $hasAllocations = DB::table('bank_statement_line_allocations as allocations')
                ->join('bank_statement_lines as lines', 'lines.id', '=', 'allocations.bank_statement_line_id')
                ->where('lines.bank_statement_id', $locked->id)
                ->exists();
            $hasExecutions = DB::table('bank_statement_match_executions as executions')
                ->join('bank_statement_lines as lines', 'lines.id', '=', 'executions.bank_statement_line_id')
                ->where('lines.bank_statement_id', $locked->id)
                ->exists();
            if ($hasAllocations || $hasExecutions) {
                throw new DomainException('A statement with allocations or executions cannot be voided.');
            }

            $locked->lines()->delete();
            $locked->status = BankStatementStatus::Voided;
            $locked->save();

            return $locked->fresh() ?? $locked;
        });
    }

    private function guardOwnership(
        PaymentRepository $repository,
        StatementImportProfile $profile,
        string $tenantId,
        string $companyId,
    ): void {
        if ($repository->tenant_id !== $tenantId || $repository->company_id !== $companyId) {
            throw new DomainException('The payment repository does not belong to the active company.');
        }
        if ($repository->type !== RepositoryType::BankAccount) {
            throw new DomainException('Bank statements can only be imported against a bank-account repository.');
        }
        if (! $repository->is_active) {
            throw new DomainException('Bank statements cannot be imported against an inactive repository.');
        }
        if ($profile->tenant_id !== $tenantId
            || $profile->company_id !== $companyId
            || $profile->payment_repository_id !== $repository->id) {
            throw new DomainException('The parser profile does not belong to the active company and repository.');
        }
        if (! $profile->is_active) {
            throw new DomainException('The parser profile is inactive.');
        }
    }

    private function rejectDuplicateFile(string $repositoryId, string $sha256): void
    {
        $existing = BankStatement::query()
            ->where('payment_repository_id', $repositoryId)
            ->where('source_file_sha256', $sha256)
            ->where('status', '!=', BankStatementStatus::Voided)
            ->first();
        if ($existing instanceof BankStatement) {
            throw new DuplicateStatementFileException($existing->id);
        }
    }

    private function parseStored(
        string $storedPath,
        PaymentRepository $repository,
        StatementImportProfile $profile,
    ): ParsedStatement {
        $profile->setRelation('repository', $repository);

        return $this->parsers->parser($profile->parser_key)->parse(
            Storage::disk('local')->path($storedPath),
            $profile,
        );
    }

    private function profileDigest(StatementImportProfile $profile): string
    {
        return hash('sha256', json_encode([
            'payment_repository_id' => $profile->payment_repository_id,
            'parser_key' => $profile->parser_key->value,
            'column_map' => $profile->column_map,
            'date_format' => $profile->date_format,
            'decimal_format' => $profile->decimal_format,
            'direction_convention' => $profile->direction_convention->value,
            'header_rows' => $profile->header_rows,
        ], JSON_THROW_ON_ERROR));
    }

    private function assertFileIntegrity(string $absolutePath, string $expectedSha256, string $message): void
    {
        clearstatcache(true, $absolutePath);
        $actualSha256 = hash_file('sha256', $absolutePath);
        if (! is_string($actualSha256) || ! hash_equals($expectedSha256, $actualSha256)) {
            throw new DomainException($message);
        }
    }

    /**
     * @return array{list<ParsedStatementLine>, int}
     */
    private function withoutExistingFingerprints(
        string $repositoryId,
        ParsedStatement $parsed,
        bool $lock = false,
    ): array {
        $fingerprints = array_map(static fn (ParsedStatementLine $line): string => $line->fingerprint, $parsed->lines);
        $query = BankStatementLine::query()
            ->where('payment_repository_id', $repositoryId)
            ->whereIn('fingerprint', $fingerprints);
        if ($lock) {
            $query->lockForUpdate();
        }
        $existing = $query->pluck('fingerprint')->flip();
        $accepted = array_values(array_filter(
            $parsed->lines,
            static fn (ParsedStatementLine $line): bool => ! $existing->has($line->fingerprint),
        ));

        return [$accepted, count($parsed->lines) - count($accepted)];
    }

    /**
     * @param  numeric-string  $opening
     * @return array<string, string>|null
     */
    private function continuityWarning(string $repositoryId, string $periodStart, string $opening): ?array
    {
        $previous = BankStatement::query()
            ->where('payment_repository_id', $repositoryId)
            ->where('status', BankStatementStatus::Reconciled)
            ->whereDate('period_end', '<', $periodStart)
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->first();
        if (! $previous instanceof BankStatement) {
            return null;
        }
        $scale = $this->scaleResolver->getScale($previous->currency);
        if (bccomp($previous->closing_balance, $opening, $scale) === 0) {
            return null;
        }

        return [
            'previous_statement_id' => $previous->id,
            'expected_opening' => $previous->closing_balance,
            'actual_opening' => $opening,
        ];
    }

    /**
     * @return array{
     *   tenant_id: string, company_id: string, payment_repository_id: string,
     *   parser_profile_id: string, source_file_path: string, source_file_sha256: string, profile_digest: string,
     *   issued_at: int
     * }
     */
    private function decodeToken(string $token): array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new DomainException('The statement preview token is invalid.');
        }
        if (! is_array($decoded)) {
            throw new DomainException('The statement preview token is invalid.');
        }
        foreach (['tenant_id', 'company_id', 'payment_repository_id', 'parser_profile_id', 'source_file_path', 'source_file_sha256', 'profile_digest'] as $key) {
            if (! isset($decoded[$key]) || ! is_string($decoded[$key])) {
                throw new DomainException('The statement preview token is invalid.');
            }
        }
        if (! isset($decoded['issued_at']) || ! is_int($decoded['issued_at'])) {
            throw new DomainException('The statement preview token is invalid.');
        }

        /** @var array{tenant_id: string, company_id: string, payment_repository_id: string, parser_profile_id: string, source_file_path: string, source_file_sha256: string, profile_digest: string, issued_at: int} $decoded */
        return $decoded;
    }

    /** @return numeric-string */
    private function canonicalMoney(string $value, int $scale): string
    {
        $pattern = $scale === 0 ? '/^-?\d+$/' : '/^-?\d+(?:\.\d{1,'.$scale.'})?$/';
        if (preg_match($pattern, $value) !== 1) {
            throw new DomainException('Statement balances must use the repository currency precision.');
        }

        return CurrencyScale::bcformatStrict($value, $scale);
    }

    /** @return array<string, mixed> */
    private function formatParsedLine(ParsedStatementLine $line): array
    {
        return [
            'line_number' => $line->lineNumber,
            'value_date' => $line->valueDate,
            'booking_date' => $line->bookingDate,
            'direction' => $line->direction->value,
            'amount' => $line->amount,
            'reference' => $line->reference,
            'bank_transaction_id' => $line->bankTransactionId,
            'label' => $line->label,
            'counterparty_hint' => $line->counterpartyHint,
            'fingerprint' => $line->fingerprint,
        ];
    }
}
