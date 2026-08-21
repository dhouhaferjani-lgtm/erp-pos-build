<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Contracts\CurrencyScaleResolverInterface;

/**
 * A pass-through observer around the REAL {@see CurrencyScaleResolverInterface}.
 *
 * It fakes nothing: every call is delegated to the injected resolver and the
 * genuine return value (or exception) is propagated. It only records what the
 * production code ASKED FOR, so a test can prove that a scale was resolved from
 * an entity currency (rule 19) rather than from the ambient `CompanyContext` or
 * a baked-in constant.
 *
 * A `null` argument is recorded as the bare, context-reading call shape that
 * rule 19/20 forbids on queued/projection paths.
 */
final class RecordingCurrencyScaleResolver implements CurrencyScaleResolverInterface
{
    /** @var list<array{method: string, currency: string|null, scale: int}> */
    private array $calls = [];

    public function __construct(
        private readonly CurrencyScaleResolverInterface $inner,
    ) {}

    public function getScale(?string $currencyCode = null): int
    {
        $scale = $this->inner->getScale($currencyCode);
        $this->calls[] = ['method' => 'getScale', 'currency' => $currencyCode, 'scale' => $scale];

        return $scale;
    }

    public function getScaleSafe(?string $currencyCode = null, int $fallback = 3): int
    {
        $scale = $this->inner->getScaleSafe($currencyCode, $fallback);
        $this->calls[] = ['method' => 'getScaleSafe', 'currency' => $currencyCode, 'scale' => $scale];

        return $scale;
    }

    /**
     * @return list<array{method: string, currency: string|null, scale: int}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * Distinct currency arguments seen, `null` included as the literal string
     * `'<bare>'` so an unbound-context call is visible in a failure diff.
     *
     * @return list<string>
     */
    public function currenciesSeen(): array
    {
        $seen = [];
        foreach ($this->calls as $call) {
            $seen[] = $call['currency'] ?? '<bare>';
        }

        return array_values(array_unique($seen));
    }

    /**
     * The scale actually returned for an explicit currency argument.
     */
    public function scaleResolvedFor(string $currencyCode): ?int
    {
        foreach ($this->calls as $call) {
            if ($call['currency'] === $currencyCode) {
                return $call['scale'];
            }
        }

        return null;
    }

    public function reset(): void
    {
        $this->calls = [];
    }
}
