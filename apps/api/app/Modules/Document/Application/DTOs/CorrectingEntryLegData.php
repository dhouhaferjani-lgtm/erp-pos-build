<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\DTOs;

use App\Shared\Contracts\Accounting\DocumentGlCorrectionInterface;
use InvalidArgumentException;

/**
 * One GL leg of a correcting-entry document (R2-F4).
 *
 * Deliberately mirrors the shape `JournalEntryController::store()` already
 * accepts for a manual entry — `{account_id, debit, credit, description}` — so
 * the accountant's mental model does not change when the correction becomes a
 * document. What changes is that these legs are now attached to a document that
 * NAMES the original it repairs, which is exactly what owner ruling c4 requires.
 *
 * Amounts are `numeric-string` (rule 19). They are written verbatim into
 * `journal_lines.debit` / `.credit`; nothing here may become a float.
 *
 * `accountId` is an opaque identifier from the Document module's point of view.
 * Its EXISTENCE is verified by Accounting, through
 * {@see DocumentGlCorrectionInterface}, not
 * here — Document must not reach into the chart of accounts (rule 6).
 */
final class CorrectingEntryLegData
{
    /**
     * PRIVATE by design. Use {@see self::of()}.
     *
     * Every invariant below is a PRECONDITION here, not a check: the factory has
     * already proven the amounts are numeric and non-negative and that exactly
     * one side is non-zero. Splitting construction from validation is also what
     * lets the amounts be typed `numeric-string` honestly — the narrowing happens
     * inside `of()`, where `is_numeric()` proves it, instead of being asserted by
     * a docblock the callers cannot satisfy.
     *
     * @param  numeric-string  $debit
     * @param  numeric-string  $credit
     */
    private function __construct(
        public readonly string $accountId,
        public readonly string $debit,
        public readonly string $credit,
        public readonly ?string $description,
        public readonly ?string $partnerId,
    ) {}

    /**
     * Build a leg from untrusted strings, enforcing every per-leg invariant.
     *
     * @throws InvalidArgumentException
     */
    public static function of(
        string $accountId,
        string $debit,
        string $credit,
        ?string $description,
        ?string $partnerId = null,
    ): self {
        if (trim($accountId) === '') {
            throw new InvalidArgumentException('A correcting-entry leg must name an account.');
        }

        if (! is_numeric($debit)) {
            throw new InvalidArgumentException(
                "A correcting-entry leg's debit must be numeric, got: {$debit}",
            );
        }

        if (! is_numeric($credit)) {
            throw new InvalidArgumentException(
                "A correcting-entry leg's credit must be numeric, got: {$credit}",
            );
        }

        foreach (['debit' => $debit, 'credit' => $credit] as $side => $amount) {
            if (bccomp($amount, '0', 6) < 0) {
                throw new InvalidArgumentException(
                    "A correcting-entry leg's {$side} may not be negative. Post the amount on the "
                    .'other side instead — a negative leg makes Σdebits/Σcredits meaningless.',
                );
            }
        }

        // The same XOR rule `DoubleEntryValidator::hasValidLines()` enforces on
        // manual journal entries. A both-sided leg is ambiguous and a zero leg
        // corrects nothing while still occupying a line in a sealed entry.
        $hasDebit = bccomp($debit, '0', 6) > 0;
        $hasCredit = bccomp($credit, '0', 6) > 0;

        if ($hasDebit === $hasCredit) {
            throw new InvalidArgumentException(
                'A correcting-entry leg must carry EITHER a debit OR a credit, not both and not neither. '
                ."Got debit={$debit}, credit={$credit}.",
            );
        }

        if ($partnerId !== null && trim($partnerId) === '') {
            throw new InvalidArgumentException(
                'A correcting-entry leg partner must be an identifier or null, never a blank string — '
                .'a blank would read as "no partner" to this DTO and as "some partner" to a caller.',
            );
        }

        return new self($accountId, $debit, $credit, $description, $partnerId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $accountId = $data['account_id'] ?? null;
        $debit = $data['debit'] ?? null;
        $credit = $data['credit'] ?? null;
        $description = $data['description'] ?? null;
        $partnerId = $data['partner_id'] ?? null;

        if (! is_string($accountId) || ! is_string($debit) || ! is_string($credit)) {
            throw new InvalidArgumentException(
                'A correcting-entry leg needs string account_id, debit and credit.',
            );
        }

        if ($description !== null && ! is_string($description)) {
            throw new InvalidArgumentException('A correcting-entry leg description must be a string or null.');
        }

        if ($partnerId !== null && ! is_string($partnerId)) {
            throw new InvalidArgumentException('A correcting-entry leg partner_id must be a string or null.');
        }

        return self::of($accountId, $debit, $credit, $description, $partnerId);
    }

    /**
     * A COPY of this leg with its amounts restated and its partner resolved.
     *
     * Accounting owns both facts — the currency scale the ledger truncates at,
     * and whether the account is a partner control account — so it is Accounting
     * that produces the definitive leg. Returning a new instance rather than
     * mutating keeps every invariant `of()` proved intact, and re-proves them.
     *
     * @param  numeric-string  $debit
     * @param  numeric-string  $credit
     */
    public function restated(string $debit, string $credit, ?string $partnerId): self
    {
        return self::of($this->accountId, $debit, $credit, $this->description, $partnerId);
    }

    /**
     * @return array{account_id: string, debit: string, credit: string, description: string|null, partner_id: string|null}
     */
    public function toArray(): array
    {
        return [
            'account_id' => $this->accountId,
            'debit' => $this->debit,
            'credit' => $this->credit,
            'description' => $this->description,
            'partner_id' => $this->partnerId,
        ];
    }
}
