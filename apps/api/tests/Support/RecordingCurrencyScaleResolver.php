<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Contracts\CurrencyScaleResolverInterface;

/**
 * A pass-through observer around the REAL {@see CurrencyScaleResolverInterface}.
 *
 * It fakes nothing: every call is delegated to the injected resolver and the
 * genuine return value (or exception) is propagated. It only records what the
 * production code ASKED FOR — and, crucially, WHICH METHOD asked — so a test
 * can prove that one specific seam resolved its scale from an entity currency
 * (rule 19) rather than from the ambient `CompanyContext`, from the company
 * record, or from a baked-in constant.
 *
 * Caller attribution matters because a single request/projection resolves scale
 * many times for many legitimate reasons: asserting only on the SET of
 * currencies seen cannot tell the seam under test apart from its neighbours,
 * and cannot detect a hardcoded scale at all (a constant simply makes no call).
 *
 * A `null` argument is recorded as `'<bare>'` — the context-reading call shape
 * that rule 19/20 forbids on queued/projection paths.
 */
final class RecordingCurrencyScaleResolver implements CurrencyScaleResolverInterface
{
    private const BARE = '<bare>';

    /** @var list<array{method: string, caller: string, currency: string, scale: int}> */
    private array $calls = [];

    public function __construct(
        private readonly CurrencyScaleResolverInterface $inner,
    ) {}

    public function getScale(?string $currencyCode = null): int
    {
        $caller = $this->caller();
        $scale = $this->inner->getScale($currencyCode);
        $this->record('getScale', $caller, $currencyCode, $scale);

        return $scale;
    }

    public function getScaleSafe(?string $currencyCode = null, int $fallback = 3): int
    {
        $caller = $this->caller();
        $scale = $this->inner->getScaleSafe($currencyCode, $fallback);
        $this->record('getScaleSafe', $caller, $currencyCode, $scale);

        return $scale;
    }

    /**
     * @return list<array{method: string, caller: string, currency: string, scale: int}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * Distinct currency arguments seen across all callers.
     *
     * @return list<string>
     */
    public function currenciesSeen(): array
    {
        return array_values(array_unique(array_column($this->calls, 'currency')));
    }

    /**
     * The currency argument the named method passed, or `null` if that method
     * never resolved a scale at all — which is exactly what a hardcoded
     * constant looks like from the outside.
     */
    public function currencyResolvedBy(string $callerMethod): ?string
    {
        foreach ($this->calls as $call) {
            if ($call['caller'] === $callerMethod) {
                return $call['currency'];
            }
        }

        return null;
    }

    /**
     * The scale the named method actually received.
     */
    public function scaleResolvedBy(string $callerMethod): ?int
    {
        foreach ($this->calls as $call) {
            if ($call['caller'] === $callerMethod) {
                return $call['scale'];
            }
        }

        return null;
    }

    /**
     * Human-readable dump for assertion failure messages.
     */
    public function describe(): string
    {
        return implode("\n", array_map(
            static fn (array $c): string => sprintf('  %s() %s(%s) => %d', $c['caller'], $c['method'], $c['currency'], $c['scale']),
            $this->calls,
        ));
    }

    public function reset(): void
    {
        $this->calls = [];
    }

    private function record(string $method, string $caller, ?string $currencyCode, int $scale): void
    {
        $this->calls[] = [
            'method' => $method,
            'caller' => $caller,
            'currency' => $currencyCode ?? self::BARE,
            'scale' => $scale,
        ];
    }

    /**
     * The unqualified name of the method that called into this resolver.
     */
    private function caller(): string
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $frame = $frames[2] ?? null;

        if (! is_array($frame) || ! isset($frame['function']) || ! is_string($frame['function'])) {
            return '<unknown>';
        }

        return $frame['function'];
    }
}
