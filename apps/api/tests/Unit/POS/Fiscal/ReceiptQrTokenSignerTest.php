<?php

declare(strict_types=1);

namespace Tests\Unit\POS\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\Fiscal\ReceiptQrTokenSigner;
use App\Modules\POS\Application\Services\Fiscal\VerifiedReceiptToken;
use App\Modules\POS\Domain\Exceptions\InvalidReceiptTokenException;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Services\Fiscal\V3\CanonicalJsonEncoder;
use App\Modules\POS\Domain\TenantSigningKey;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for ReceiptQrTokenSigner.
 *
 * Covers:
 *   - sign → verify round-trip (happy path)
 *   - Tampered MAC → InvalidReceiptTokenException
 *   - Wrong kid → InvalidReceiptTokenException (generic message)
 *   - Retired kid → InvalidReceiptTokenException (generic message)
 *   - Cross-tenant token → InvalidReceiptTokenException (same generic message)
 *   - Constant-time compare via hash_equals (structural)
 *   - Malformed token formats
 */
final class ReceiptQrTokenSignerTest extends TestCase
{
    use RefreshDatabase;

    private ReceiptQrTokenSigner $signer;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Terminal $terminal;

    private Receipt $receipt;

    private TenantSigningKey $signingKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signer = new ReceiptQrTokenSigner(new CanonicalJsonEncoder);

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
        $cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->receipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $cashier->id,
        ]);

        $this->signingKey = TenantSigningKey::factory()
            ->forTenant($this->tenant)
            ->withKid('current')
            ->create();
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_sign_then_verify_round_trip(): void
    {
        $token = $this->signer->sign($this->receipt, $this->signingKey);

        $verified = $this->signer->verify($token, $this->terminal);

        $this->assertInstanceOf(VerifiedReceiptToken::class, $verified);
        $this->assertSame('1', $verified->version);
        $this->assertSame('current', $verified->kid);
        $this->assertSame($this->receipt->id, $verified->receiptUuid);
        $this->assertSame($this->tenant->id, $verified->tenantId);
        $this->assertSame($this->company->id, $verified->companyId);
    }

    public function test_token_has_four_colon_separated_parts(): void
    {
        $token = $this->signer->sign($this->receipt, $this->signingKey);

        $parts = explode(':', $token);

        $this->assertCount(4, $parts, 'Token must have exactly 4 parts: v:kid:receipt_uuid:mac');
        $this->assertSame('1', $parts[0]);
        $this->assertSame('current', $parts[1]);
        $this->assertSame($this->receipt->id, $parts[2]);
        $this->assertNotEmpty($parts[3]);
    }

    // -------------------------------------------------------------------------
    // MAC tampering
    // -------------------------------------------------------------------------

    public function test_verify_rejects_tampered_mac(): void
    {
        $this->expectException(InvalidReceiptTokenException::class);

        $token = $this->signer->sign($this->receipt, $this->signingKey);

        $parts = explode(':', $token);
        // Flip a character in the mac part.
        $mac = $parts[3];
        $parts[3] = $mac[0] === 'A' ? str_replace('A', 'B', $mac) : str_replace($mac[0], 'A', $mac);

        $tampered = implode(':', $parts);

        $this->signer->verify($tampered, $this->terminal);
    }

    // -------------------------------------------------------------------------
    // Wrong kid
    // -------------------------------------------------------------------------

    public function test_verify_rejects_wrong_kid(): void
    {
        $this->expectException(InvalidReceiptTokenException::class);

        $token = $this->signer->sign($this->receipt, $this->signingKey);
        $parts = explode(':', $token);
        // Replace the kid with a non-existent one.
        $parts[1] = 'nonexistent-kid';
        $wrongKid = implode(':', $parts);

        $this->signer->verify($wrongKid, $this->terminal);
    }

    public function test_wrong_kid_error_message_is_generic(): void
    {
        $token = $this->signer->sign($this->receipt, $this->signingKey);
        $parts = explode(':', $token);
        $parts[1] = 'nonexistent-kid';
        $wrongKid = implode(':', $parts);

        try {
            $this->signer->verify($wrongKid, $this->terminal);
            $this->fail('Expected InvalidReceiptTokenException');
        } catch (InvalidReceiptTokenException $e) {
            // The public message must be generic (does not reveal kid existence).
            $this->assertStringContainsString('invalid or could not be verified', $e->getMessage());
            $this->assertStringNotContainsString('nonexistent-kid', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Retired kid
    // -------------------------------------------------------------------------

    public function test_verify_rejects_retired_kid(): void
    {
        $this->expectException(InvalidReceiptTokenException::class);

        // Sign with the current key.
        $token = $this->signer->sign($this->receipt, $this->signingKey);

        // Retire the key.
        $this->signingKey->update([
            'is_active' => false,
            'retired_at' => now(),
        ]);

        // Verify should now fail because the key is retired.
        $this->signer->verify($token, $this->terminal);
    }

    // -------------------------------------------------------------------------
    // Cross-tenant protection
    // -------------------------------------------------------------------------

    public function test_verify_rejects_cross_tenant_token(): void
    {
        $this->expectException(InvalidReceiptTokenException::class);

        // Token signed for tenant A.
        $token = $this->signer->sign($this->receipt, $this->signingKey);

        // Terminal belonging to tenant B.
        $tenantB = Tenant::factory()->create();
        $companyB = Company::factory()->create(['tenant_id' => $tenantB->id]);
        $locationB = Location::factory()->create(['company_id' => $companyB->id]);
        $terminalB = Terminal::factory()->create([
            'tenant_id' => $tenantB->id,
            'company_id' => $companyB->id,
            'location_id' => $locationB->id,
        ]);

        // The verifier uses terminal B's tenant_id to look up the key.
        // No key exists for tenant B with kid "current" → same generic exception.
        $this->signer->verify($token, $terminalB);
    }

    public function test_cross_tenant_error_message_matches_tampered_mac_message(): void
    {
        $token = $this->signer->sign($this->receipt, $this->signingKey);

        // Tampered MAC failure.
        $parts = explode(':', $token);
        $mac = $parts[3];
        $parts[3] = $mac[0] === 'A' ? str_replace('A', 'B', $mac) : str_replace($mac[0], 'A', $mac);
        $tampered = implode(':', $parts);

        $tamperedMessage = null;

        try {
            $this->signer->verify($tampered, $this->terminal);
        } catch (InvalidReceiptTokenException $e) {
            $tamperedMessage = $e->getMessage();
        }

        // Cross-tenant failure.
        $tenantB = Tenant::factory()->create();
        $companyB = Company::factory()->create(['tenant_id' => $tenantB->id]);
        $locationB = Location::factory()->create(['company_id' => $companyB->id]);
        $terminalB = Terminal::factory()->create([
            'tenant_id' => $tenantB->id,
            'company_id' => $companyB->id,
            'location_id' => $locationB->id,
        ]);

        $crossTenantMessage = null;

        try {
            $this->signer->verify($token, $terminalB);
        } catch (InvalidReceiptTokenException $e) {
            $crossTenantMessage = $e->getMessage();
        }

        $this->assertSame($tamperedMessage, $crossTenantMessage,
            'Cross-tenant failure must produce the same public message as a tampered-MAC failure.'
        );
    }

    // -------------------------------------------------------------------------
    // Malformed tokens
    // -------------------------------------------------------------------------

    public function test_verify_rejects_token_with_too_few_parts(): void
    {
        $this->expectException(InvalidReceiptTokenException::class);

        $this->signer->verify('1:current:only-three-parts', $this->terminal);
    }

    public function test_verify_rejects_empty_token(): void
    {
        $this->expectException(InvalidReceiptTokenException::class);

        $this->signer->verify('', $this->terminal);
    }

    public function test_verify_rejects_wrong_version(): void
    {
        $this->expectException(InvalidReceiptTokenException::class);

        $this->signer->verify('9:current:some-uuid:some-mac', $this->terminal);
    }

    // -------------------------------------------------------------------------
    // Constant-time compare assertion (structural / source-code level)
    // -------------------------------------------------------------------------

    public function test_verify_uses_hash_equals_for_constant_time_compare(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../../../app/Modules/POS/Application/Services/Fiscal/ReceiptQrTokenSigner.php'
        );

        $this->assertNotFalse($source);
        $this->assertStringContainsString(
            'hash_equals(',
            $source,
            'ReceiptQrTokenSigner::verify() must use hash_equals() for constant-time MAC comparison.'
        );
    }
}
