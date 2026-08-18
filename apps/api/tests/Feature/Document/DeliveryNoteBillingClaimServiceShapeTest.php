<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Document\Domain\Enums\DeliveryNoteBillingLane;
use App\Modules\Document\Domain\Exceptions\DeliveryNoteClaimRequiresTransactionException;
use App\Modules\Document\Domain\Services\Billing\DeliveryNoteBillingClaimService;
use App\Modules\Document\Domain\Services\Billing\DeliveryNoteClaimRequest;
use Closure;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class DeliveryNoteBillingClaimServiceShapeTest extends TestCase
{
    private const REQUEST = 'App\\Modules\\Document\\Domain\\Services\\Billing\\DeliveryNoteClaimRequest';

    private const SERVICE = 'App\\Modules\\Document\\Domain\\Services\\Billing\\DeliveryNoteBillingClaimService';

    private const SET = 'App\\Modules\\Document\\Domain\\Services\\Billing\\DeliveryNoteClaimSet';

    public function test_claim_is_the_only_public_entry_point_and_count_is_owned_by_the_set(): void
    {
        $this->assertTrue(class_exists(self::SERVICE), 'The billing claim service must exist.');
        $this->assertTrue(class_exists(self::REQUEST), 'The immutable billing claim request must exist.');
        $this->assertTrue(class_exists(self::SET), 'The immutable reserved claim set must exist.');

        $service = new ReflectionClass(self::SERVICE);
        $publicEntryPoints = array_values(array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            array_filter(
                $service->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn (ReflectionMethod $method): bool => ! $method->isConstructor()
                    && $method->getDeclaringClass()->getName() === self::SERVICE,
            ),
        ));

        $this->assertSame(['claim'], $publicEntryPoints);

        $claim = $service->getMethod('claim');
        $claimParameters = $claim->getParameters();
        $this->assertCount(2, $claimParameters);
        $this->assertSame(self::REQUEST, (string) $claimParameters[0]->getType());
        $this->assertSame(Closure::class, (string) $claimParameters[1]->getType());
        $this->assertSame(self::SET, (string) $claim->getReturnType());

        foreach (['reserve', 'finalise'] as $methodName) {
            $method = $service->getMethod($methodName);
            $this->assertTrue($method->isProtected(), $methodName.' must remain a protected test seam.');
        }

        foreach ($service->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                $this->assertNotSame('int', (string) $parameter->getType(), 'N must come only from the reserved set.');
            }
        }

        $request = new ReflectionClass(self::REQUEST);
        $set = new ReflectionClass(self::SET);
        $this->assertTrue($request->isReadOnly());
        $this->assertTrue($set->isReadOnly());
        $this->assertFalse(
            $set->getConstructor()?->isPublic() ?? true,
            'The private constructor must block direct new DeliveryNoteClaimSet(...).',
        );
        $this->assertSame('int', (string) $set->getMethod('count')->getReturnType());
    }

    public function test_literal_claim_set_factory_has_no_external_app_call_sites(): void
    {
        $allowedPath = realpath(app_path('Modules/Document/Domain/Services/Billing/DeliveryNoteBillingClaimService.php'));
        $externalCallSites = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file->isFile()
                || $file->getExtension() !== 'php'
                || $file->getRealPath() === $allowedPath) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }

            $executableCode = '';
            foreach (token_get_all($contents) as $token) {
                if (is_array($token)
                    && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }

                $executableCode .= is_array($token) ? $token[1] : $token;
            }

            if (preg_match('/\\bDeliveryNoteClaimSet\\s*::\\s*fromReservation\\s*\\(/', $executableCode) === 1) {
                $externalCallSites[] = $file->getPathname();
            }
        }

        $this->assertSame(
            [],
            $externalCallSites,
            'Literal DeliveryNoteClaimSet::fromReservation(...) app/ calls must stay inside DeliveryNoteBillingClaimService.',
        );
    }

    public function test_claim_refuses_to_write_without_an_open_caller_transaction(): void
    {
        $this->assertSame(0, DB::connection()->transactionLevel());
        $closureCalled = false;
        $service = new DeliveryNoteBillingClaimService(DB::connection());

        try {
            $service->claim(
                new DeliveryNoteClaimRequest(
                    ['00000000-0000-0000-0000-000000000001'],
                    '00000000-0000-0000-0000-000000000002',
                    DeliveryNoteBillingLane::Consolidation,
                ),
                function () use (&$closureCalled): string {
                    $closureCalled = true;

                    return '00000000-0000-0000-0000-000000000003';
                },
            );
            $this->fail('The service must reject calls outside a caller-owned transaction.');
        } catch (DeliveryNoteClaimRequiresTransactionException) {
            $this->assertFalse($closureCalled);
        }
    }
}
