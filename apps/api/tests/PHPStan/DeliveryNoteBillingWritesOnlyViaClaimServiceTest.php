<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\PHPStan\Rules\DeliveryNoteBillingWritesOnlyViaClaimService;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<DeliveryNoteBillingWritesOnlyViaClaimService> */
final class DeliveryNoteBillingWritesOnlyViaClaimServiceTest extends RuleTestCase
{
    private const MESSAGE = 'Delivery-note billing writes must go through App\\Modules\\Document\\Domain\\Services\\Billing\\DeliveryNoteBillingClaimService.';

    private const SET_MESSAGE = 'Literal DeliveryNoteClaimSet::fromReservation(...) calls in app/ must go through App\\Modules\\Document\\Domain\\Services\\Billing\\DeliveryNoteBillingClaimService.';

    protected function getRule(): Rule
    {
        return new DeliveryNoteBillingWritesOnlyViaClaimService;
    }

    public function test_rule_exists_and_is_registered(): void
    {
        $this->assertTrue(class_exists(DeliveryNoteBillingWritesOnlyViaClaimService::class));
        $this->assertStringContainsString(
            '- '.DeliveryNoteBillingWritesOnlyViaClaimService::class,
            (string) file_get_contents(dirname(__DIR__, 2).'/phpstan.neon'),
        );
    }

    public function test_reports_each_enumerated_literal_write_form(): void
    {
        $this->analyse(
            [
                __DIR__.'/Fixtures/DeliveryNoteBillingPropertyAssignFixture.php',
                __DIR__.'/Fixtures/DeliveryNoteBillingArrayWriteFixture.php',
                __DIR__.'/Fixtures/DeliveryNoteBillingMarkerTableInsertFixture.php',
                __DIR__.'/Fixtures/DeliveryNoteBillingMarkerModelWriteFixture.php',
                __DIR__.'/Fixtures/DeliveryNoteBillingRawStatementFixture.php',
            ],
            [
                [self::MESSAGE, 13],
                [self::MESSAGE, 13],
                [self::MESSAGE, 13],
                [self::MESSAGE, 20],
                [self::MESSAGE, 13],
            ],
        );
    }

    public function test_stays_silent_inside_claim_service_and_on_unrelated_model(): void
    {
        $this->analyse(
            [
                __DIR__.'/Fixtures/DeliveryNoteBillingAllowedClaimServiceFixture.php',
                __DIR__.'/Fixtures/DeliveryNoteBillingUnrelatedModelFixture.php',
                __DIR__.'/Fixtures/DeliveryNoteBillingNonBillingPayloadAssignmentFixture.php',
                __DIR__.'/Fixtures/DeliveryNoteBillingNonMarkerRawSqlFixture.php',
            ],
            [],
        );
    }

    public function test_reports_auxiliary_external_literal_claim_set_issuance(): void
    {
        $this->analyse(
            [__DIR__.'/Fixtures/DeliveryNoteClaimSetExternalIssuanceFixture.php'],
            [[self::SET_MESSAGE, 14]],
        );
    }
}
