<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Treasury\Application\DTOs\ParsedStatement;
use App\Modules\Treasury\Application\DTOs\ParsedStatementLine;
use App\Modules\Treasury\Application\DTOs\StatementColumnMap;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\StatementDirectionConvention;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\StatementImportProfile;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use DateTimeImmutable;
use InvalidArgumentException;

final class StatementRowMapper
{
    public function __construct(private readonly CurrencyScaleResolverInterface $scaleResolver) {}

    /**
     * @param  list<string>  $headers
     * @param  list<array{row: int, values: list<string>}>  $rows
     * @param  list<array{row: int, reason: string}>  $readerErrors
     */
    public function map(
        array $headers,
        array $rows,
        StatementImportProfile $profile,
        array $readerErrors = [],
    ): ParsedStatement {
        $repository = $profile->repository;
        if (! $repository instanceof PaymentRepository) {
            throw new InvalidArgumentException('Statement parser profile has no repository context.');
        }
        $scale = $this->scaleResolver->getScale($repository->currency);
        $columnMap = StatementColumnMap::fromArray($profile->column_map);
        $headerIndexes = [];
        foreach ($headers as $index => $header) {
            $normalized = $this->normalizeText($header);
            if ($normalized !== '' && ! array_key_exists($normalized, $headerIndexes)) {
                $headerIndexes[$normalized] = $index;
            }
        }

        $indexes = $this->resolveIndexes($columnMap, $headerIndexes, $profile->direction_convention);
        $lines = [];
        $unparseable = $readerErrors;
        $droppedZero = 0;
        $detectedOpening = null;
        $detectedClosing = null;
        /** @var array<string, int> $occurrences */
        $occurrences = [];

        foreach ($rows as $row) {
            try {
                $valueDateRaw = $this->value($row['values'], $indexes['valueDate']);
                $valueDate = $this->parseDate($valueDateRaw, $profile->date_format, 'value');
                $bookingRaw = $this->value($row['values'], $indexes['bookingDate']);
                $bookingDate = $bookingRaw === ''
                    ? null
                    : $this->parseDate($bookingRaw, $profile->date_format, 'booking');
                [$direction, $amount] = $this->parseDirectionAndAmount(
                    $row['values'],
                    $indexes,
                    $profile->direction_convention,
                    $profile->decimal_format,
                    $scale,
                );

                if (bccomp($amount, '0', $scale) === 0) {
                    $droppedZero++;

                    continue;
                }

                $reference = $this->nullableValue($row['values'], $indexes['reference']);
                $bankTransactionId = $this->nullableValue($row['values'], $indexes['bankTransactionId']);
                $label = $this->value($row['values'], $indexes['label']);
                $counterpartyHint = $this->nullableValue($row['values'], $indexes['counterpartyHint']);

                if ($detectedOpening === null) {
                    $detectedOpening = $this->optionalBalance(
                        $row['values'],
                        $indexes['openingBalance'],
                        $profile->decimal_format,
                        $scale,
                    );
                }
                $rowClosing = $this->optionalBalance(
                    $row['values'],
                    $indexes['closingBalance'],
                    $profile->decimal_format,
                    $scale,
                );
                if ($rowClosing !== null) {
                    $detectedClosing = $rowClosing;
                }

                $identity = $bankTransactionId !== null
                    ? 'bank-transaction|'.$this->normalizeText($bankTransactionId)
                    : implode('|', [
                        $valueDate,
                        $direction->value,
                        $amount,
                        $this->normalizeText($reference ?? ''),
                        $this->normalizeText($label),
                    ]);
                $occurrenceIndex = ($occurrences[$identity] ?? 0) + 1;
                $occurrences[$identity] = $occurrenceIndex;
                $fingerprint = $bankTransactionId !== null
                    ? hash('sha256', $identity)
                    : hash('sha256', $identity.'|occurrence:'.$occurrenceIndex);

                $lines[] = new ParsedStatementLine(
                    lineNumber: $row['row'],
                    valueDate: $valueDate,
                    bookingDate: $bookingDate,
                    direction: $direction,
                    amount: $amount,
                    reference: $reference,
                    bankTransactionId: $bankTransactionId,
                    label: $label,
                    counterpartyHint: $counterpartyHint,
                    occurrenceIndex: $occurrenceIndex,
                    fingerprint: $fingerprint,
                );
            } catch (InvalidArgumentException $exception) {
                $unparseable[] = ['row' => $row['row'], 'reason' => $exception->getMessage()];
            }
        }

        usort($unparseable, static fn (array $left, array $right): int => $left['row'] <=> $right['row']);

        return new ParsedStatement(
            lines: $lines,
            droppedZeroAmountRows: $droppedZero,
            unparseableRows: $unparseable,
            detectedOpening: $detectedOpening,
            detectedClosing: $detectedClosing,
        );
    }

    /**
     * @param  array<string, int>  $headers
     * @return array{
     *   valueDate: int, bookingDate: int|null, amount: int|null, debit: int|null,
     *   credit: int|null, reference: int|null, bankTransactionId: int|null,
     *   label: int, counterpartyHint: int|null, openingBalance: int|null,
     *   closingBalance: int|null
     * }
     */
    private function resolveIndexes(
        StatementColumnMap $map,
        array $headers,
        StatementDirectionConvention $convention,
    ): array {
        if ($convention === StatementDirectionConvention::SignedAmount && $map->amount === null) {
            throw new InvalidArgumentException("Statement column mapping 'amount' is required for signed amounts.");
        }
        if ($convention === StatementDirectionConvention::DebitCreditColumns
            && ($map->debit === null || $map->credit === null)) {
            throw new InvalidArgumentException("Statement column mappings 'debit' and 'credit' are required.");
        }

        return [
            'valueDate' => $this->requiredIndexFor($map->valueDate, $headers),
            'bookingDate' => $this->indexFor($map->bookingDate, $headers),
            'amount' => $this->indexFor($map->amount, $headers),
            'debit' => $this->indexFor($map->debit, $headers),
            'credit' => $this->indexFor($map->credit, $headers),
            'reference' => $this->indexFor($map->reference, $headers),
            'bankTransactionId' => $this->indexFor($map->bankTransactionId, $headers),
            'label' => $this->requiredIndexFor($map->label, $headers),
            'counterpartyHint' => $this->indexFor($map->counterpartyHint, $headers),
            'openingBalance' => $this->indexFor($map->openingBalance, $headers),
            'closingBalance' => $this->indexFor($map->closingBalance, $headers),
        ];
    }

    /** @param array<string, int> $headers */
    private function indexFor(?string $mappedHeader, array $headers): ?int
    {
        if ($mappedHeader === null) {
            return null;
        }

        $normalized = $this->normalizeText($mappedHeader);
        if (! array_key_exists($normalized, $headers)) {
            throw new InvalidArgumentException("Mapped statement column '{$mappedHeader}' was not found.");
        }

        return $headers[$normalized];
    }

    /** @param array<string, int> $headers */
    private function requiredIndexFor(string $mappedHeader, array $headers): int
    {
        $index = $this->indexFor($mappedHeader, $headers);
        if ($index === null) {
            throw new InvalidArgumentException("Mapped statement column '{$mappedHeader}' was not found.");
        }

        return $index;
    }

    /**
     * @param  list<string>  $values
     * @param  array{amount: int|null, debit: int|null, credit: int|null}  $indexes
     * @return array{MovementDirection, numeric-string}
     */
    private function parseDirectionAndAmount(
        array $values,
        array $indexes,
        StatementDirectionConvention $convention,
        string $decimalFormat,
        int $scale,
    ): array {
        if ($convention === StatementDirectionConvention::SignedAmount) {
            $signed = $this->normalizeDecimal($this->value($values, $indexes['amount']), $decimalFormat, $scale);
            if (str_starts_with($signed, '-')) {
                $absolute = substr($signed, 1);
                if (! is_numeric($absolute)) {
                    throw new InvalidArgumentException("Invalid amount: {$signed}");
                }

                return [MovementDirection::Out, $absolute];
            }

            return [MovementDirection::In, $signed];
        }

        $debit = $this->normalizeDecimal($this->value($values, $indexes['debit']), $decimalFormat, $scale, true);
        $credit = $this->normalizeDecimal($this->value($values, $indexes['credit']), $decimalFormat, $scale, true);
        if (str_starts_with($debit, '-') || str_starts_with($credit, '-')) {
            throw new InvalidArgumentException('Debit and credit columns cannot contain negative values.');
        }
        $hasDebit = bccomp($debit, '0', $scale) !== 0;
        $hasCredit = bccomp($credit, '0', $scale) !== 0;
        if ($hasDebit === $hasCredit) {
            throw new InvalidArgumentException('Exactly one of debit or credit must contain a positive amount.');
        }

        return $hasDebit
            ? [MovementDirection::Out, $debit]
            : [MovementDirection::In, $credit];
    }

    private function parseDate(string $raw, string $format, string $label): string
    {
        $raw = trim($raw);
        foreach (array_unique(['Y-m-d', $format]) as $candidate) {
            $date = DateTimeImmutable::createFromFormat('!'.$candidate, $raw);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date instanceof DateTimeImmutable
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $date->format($candidate) === $raw) {
                return $date->format('Y-m-d');
            }
        }

        throw new InvalidArgumentException("Invalid {$label} date: {$raw}");
    }

    /** @return numeric-string */
    private function normalizeDecimal(
        string $raw,
        string $format,
        int $scale,
        bool $emptyIsZero = false,
    ): string {
        $value = trim(str_replace(["\u{00A0}", "\u{202F}", ' '], '', $raw));
        if ($value === '' && $emptyIsZero) {
            return '0';
        }
        if ($value === '') {
            throw new InvalidArgumentException('Amount is required.');
        }

        $value = match (strtolower($format)) {
            'comma', 'comma_decimal' => str_replace(',', '.', str_replace('.', '', $value)),
            'dot', 'dot_decimal' => str_replace(',', '', $value),
            default => throw new InvalidArgumentException("Unsupported decimal format: {$format}"),
        };

        $pattern = $scale === 0
            ? '/^[+-]?\d+$/'
            : '/^[+-]?\d+(?:\.\d{1,'.$scale.'})?$/';
        if (preg_match($pattern, $value) !== 1 || ! is_numeric($value)) {
            throw new InvalidArgumentException("Invalid amount: {$raw}");
        }

        return $value;
    }

    /**
     * @param  list<string>  $values
     * @return numeric-string|null
     */
    private function optionalBalance(
        array $values,
        ?int $index,
        string $decimalFormat,
        int $scale,
    ): ?string {
        if ($index === null) {
            return null;
        }

        $raw = $this->value($values, $index);

        return $raw === '' ? null : $this->normalizeDecimal($raw, $decimalFormat, $scale);
    }

    /** @param list<string> $values */
    private function nullableValue(array $values, ?int $index): ?string
    {
        if ($index === null) {
            return null;
        }

        $value = $this->value($values, $index);

        return $value === '' ? null : $value;
    }

    /** @param list<string> $values */
    private function value(array $values, ?int $index): string
    {
        return $index === null ? '' : trim($values[$index] ?? '');
    }

    private function normalizeText(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', trim($value)) ?? trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower($value, 'UTF-8');
    }
}
