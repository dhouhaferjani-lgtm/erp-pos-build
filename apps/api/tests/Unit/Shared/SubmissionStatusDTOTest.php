<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\DTOs\SubmissionStatusDTO;
use PHPUnit\Framework\TestCase;

class SubmissionStatusDTOTest extends TestCase
{
    public function test_from_api_response_parses_locale(): void
    {
        $dto = SubmissionStatusDTO::fromApiResponse([
            'tracking_id' => 'track-123',
            'status' => 'approved',
            'enrichment_quality' => 'full',
            'locale' => 'fr_FR',
            'enriched_data' => [
                'name' => 'Doliprane 1000mg',
            ],
        ]);

        $this->assertSame('fr_FR', $dto->locale);
    }

    public function test_from_api_response_locale_is_null_when_absent(): void
    {
        $dto = SubmissionStatusDTO::fromApiResponse([
            'tracking_id' => 'track-123',
            'status' => 'approved',
            'enriched_data' => [],
        ]);

        $this->assertNull($dto->locale);
    }

    public function test_from_api_response_ignores_non_string_locale(): void
    {
        // A malformed platform payload (locale not a string) must degrade to
        // null rather than throwing a TypeError into the ?string property
        // under declare(strict_types=1).
        $dto = SubmissionStatusDTO::fromApiResponse([
            'tracking_id' => 'track-123',
            'status' => 'approved',
            'locale' => ['fr_FR'],
            'enriched_data' => [],
        ]);

        $this->assertNull($dto->locale);
    }
}
