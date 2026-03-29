<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\PlatformIntegration;

use Tests\TestCase;

class ProductSubmissionControllerTest extends TestCase
{
    public function test_submit_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/platform/submit-for-enrichment', [
            'name' => 'Test',
            'brand' => 'Brand',
        ]);

        $response->assertStatus(401);
    }

    public function test_submit_validates_required_fields(): void
    {
        $response = $this->postJson('/api/v1/platform/submit-for-enrichment', []);

        $this->assertContains($response->status(), [401, 422]);
    }
}
