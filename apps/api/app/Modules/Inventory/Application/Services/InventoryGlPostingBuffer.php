<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Inventory\Application\DTOs\MovementGlContext;
use App\Modules\Inventory\Domain\Enums\MovementGlKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Request/job-scoped inventory GL buffer. Enqueue is deliberately pure: all
 * database work occurs at the root transaction's terminal flush.
 */
final class InventoryGlPostingBuffer
{
    /** @var list<MovementGlContext> */
    private array $contexts = [];

    private bool $leakAlarmRegistered = false;

    public function __construct(private readonly InventoryGlPostingService $posting) {}

    public function mark(): int
    {
        return count($this->contexts);
    }

    public function enqueue(MovementGlContext $ctx): void
    {
        $this->contexts[] = $ctx;
    }

    public function rollbackTo(int $marker): void
    {
        if ($marker < 0 || $marker > count($this->contexts)) {
            throw new \OutOfBoundsException('Inventory GL rollback marker is outside the current buffer.');
        }

        $this->contexts = array_slice($this->contexts, 0, $marker);
    }

    public function isEmpty(): bool
    {
        return $this->contexts === [];
    }

    /**
     * @return list<JournalEntry|null>
     */
    public function flushIfOutermost(): array
    {
        $level = DB::transactionLevel();

        if ($level > 1) {
            $this->registerLeakAlarm();

            return [];
        }

        if ($level !== 1) {
            throw new \LogicException('Inventory GL postings require an explicit root transaction.');
        }

        $pending = $this->contexts;
        $this->reset();

        $posted = [];
        foreach ($pending as $ctx) {
            $posted[] = match ($ctx->kind) {
                MovementGlKind::Exit => $this->posting->postForExit($ctx),
                MovementGlKind::Entry => $this->posting->postForExit($ctx),
                MovementGlKind::CountCorrection => $this->posting->postForCountCorrection($ctx),
                MovementGlKind::BatchWriteOff => $this->posting->postForBatchWriteOff($ctx),
            };
        }

        return $posted;
    }

    public function reset(): void
    {
        $this->contexts = [];
        $this->leakAlarmRegistered = false;
    }

    private function registerLeakAlarm(): void
    {
        if ($this->leakAlarmRegistered) {
            return;
        }

        $this->leakAlarmRegistered = true;
        DB::afterCommit(function (): void {
            if (! $this->isEmpty()) {
                Log::critical('Inventory GL posting buffer leaked past the root transaction; no entries were posted.', [
                    'pending_contexts' => count($this->contexts),
                ]);
            }

            $this->reset();
        });
    }
}
