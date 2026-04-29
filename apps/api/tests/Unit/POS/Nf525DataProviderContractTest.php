<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\POS\Application\Services\Nf525DataProvider;
use App\Shared\Contracts\Compliance\Nf525DataProviderContract;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Contract test — the POS provider must implement every method declared on
 * Nf525DataProviderContract with matching signatures.
 *
 * If refund flow (or any future workstream) edits the contract without
 * updating the provider, this test fails fast with a precise message instead
 * of waiting for a runtime ArgumentCountError or TypeError.
 */
class Nf525DataProviderContractTest extends TestCase
{
    public function test_pos_provider_implements_the_contract(): void
    {
        $this->assertContains(
            Nf525DataProviderContract::class,
            class_implements(Nf525DataProvider::class) ?: [],
            'Nf525DataProvider must implement Nf525DataProviderContract',
        );
    }

    public function test_provider_declares_every_contract_method_with_matching_signature(): void
    {
        $contract = new ReflectionClass(Nf525DataProviderContract::class);
        $impl = new ReflectionClass(Nf525DataProvider::class);

        foreach ($contract->getMethods() as $contractMethod) {
            $this->assertTrue(
                $impl->hasMethod($contractMethod->getName()),
                'Nf525DataProvider is missing contract method '.$contractMethod->getName(),
            );

            $implMethod = $impl->getMethod($contractMethod->getName());

            $this->assertSame(
                $contractMethod->getNumberOfParameters(),
                $implMethod->getNumberOfParameters(),
                'Parameter count mismatch on '.$contractMethod->getName(),
            );

            foreach ($contractMethod->getParameters() as $i => $contractParam) {
                $implParam = $implMethod->getParameters()[$i];

                $contractType = $contractParam->getType();
                $implType = $implParam->getType();

                $this->assertNotNull($contractType, 'Contract param has no declared type');
                $this->assertNotNull($implType, 'Impl param has no declared type');
                $this->assertInstanceOf(ReflectionNamedType::class, $contractType);
                $this->assertInstanceOf(ReflectionNamedType::class, $implType);

                $this->assertSame(
                    $contractType->getName(),
                    $implType->getName(),
                    sprintf(
                        'Param type mismatch on %s::%s (#%d)',
                        $contractMethod->getName(),
                        $contractParam->getName(),
                        $i,
                    ),
                );
            }

            $contractReturn = $contractMethod->getReturnType();
            $implReturn = $implMethod->getReturnType();

            $this->assertNotNull($contractReturn, 'Contract method has no declared return type');
            $this->assertNotNull($implReturn, 'Impl method has no declared return type');
            $this->assertInstanceOf(ReflectionNamedType::class, $contractReturn);
            $this->assertInstanceOf(ReflectionNamedType::class, $implReturn);

            $this->assertSame(
                $contractReturn->getName(),
                $implReturn->getName(),
                'Return type mismatch on '.$contractMethod->getName(),
            );
        }
    }
}
