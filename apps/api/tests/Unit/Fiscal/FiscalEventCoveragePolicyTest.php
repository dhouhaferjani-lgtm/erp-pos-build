<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Application\Services\FiscalEventCoveragePolicy;
use App\Modules\Fiscal\Application\Services\FiscalEventPayloadRegistry;
use App\Modules\Fiscal\Application\Services\FiscalEventProjectionRegistry;
use App\Modules\Fiscal\Application\Services\FiscalPayloadConstraintValidator;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Exceptions\FiscalEventTypeNotImplemented;
use LogicException;
use Tests\TestCase;

final class FiscalEventCoveragePolicyTest extends TestCase
{
    public function test_every_fiscal_event_type_has_explicit_policy(): void
    {
        $policy = new FiscalEventCoveragePolicy;

        $expected = array_map(static fn (FiscalEventType $type): string => $type->value, FiscalEventType::cases());
        $actual = array_keys($policy->all());
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    public function test_policy_matches_payload_registry_and_validator_coverage(): void
    {
        $policy = new FiscalEventCoveragePolicy;
        $registry = new FiscalEventPayloadRegistry;
        $validator = app(FiscalPayloadConstraintValidator::class);

        foreach (FiscalEventType::cases() as $type) {
            if ($policy->isReservedUnreachable($type)) {
                $this->assertFalse($registry->isImplemented($type), "{$type->name} must not resolve a payload DTO");
                $this->expectRegistryNotImplemented($registry, $type);

                continue;
            }

            $this->assertTrue($registry->isImplemented($type), "{$type->name} must resolve a payload DTO");
            $registry->dtoClassFor($type);
            $eventVersion = $registry->eventVersionFor($type);

            try {
                $validator->validatePerEventConstraints($type, [], eventVersion: $eventVersion);
            } catch (LogicException $exception) {
                $this->assertStringNotContainsString(
                    'missing per-event clause',
                    $exception->getMessage(),
                    "{$type->name} is implemented but has no validator policy",
                );
            } catch (\Throwable) {
                // Empty payloads should fail field validation for implemented events.
            }
        }
    }

    public function test_projected_policy_matches_registered_projectors(): void
    {
        $policy = new FiscalEventCoveragePolicy;
        $projectors = iterator_to_array(app(FiscalEventProjectionRegistry::class)->all());

        foreach (FiscalEventType::cases() as $type) {
            $hasProjector = false;
            foreach ($projectors as $projector) {
                if ($projector->handlesEventType($type)) {
                    $hasProjector = true;
                    break;
                }
            }

            $this->assertSame(
                $policy->isProjected($type),
                $hasProjector,
                "{$type->name} projector coverage does not match policy",
            );
        }
    }

    public function test_canonical_reader_policy_is_explicit(): void
    {
        $policy = new FiscalEventCoveragePolicy;

        foreach (FiscalEventType::cases() as $type) {
            $method = $policy->canonicalReaderMethodFor($type);

            if ($method === null) {
                $this->assertFalse($policy->hasCanonicalReader($type));

                continue;
            }

            $this->assertTrue($policy->hasCanonicalReader($type));
            $this->assertTrue(method_exists(
                CanonicalPayloadReader::class,
                $method,
            ), "{$type->name} canonical reader method {$method} does not exist");
        }
    }

    private function expectRegistryNotImplemented(FiscalEventPayloadRegistry $registry, FiscalEventType $type): void
    {
        try {
            $registry->dtoClassFor($type);
            $this->fail("{$type->name} unexpectedly resolved a payload DTO");
        } catch (FiscalEventTypeNotImplemented) {
            $this->addToAssertionCount(1);
        }
    }
}
