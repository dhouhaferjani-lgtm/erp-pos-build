<?php

declare(strict_types=1);

namespace Tests\Unit\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Enums\AgeRestriction;
use App\Modules\Product\Domain\Enums\DosageForm;
use App\Modules\Product\Domain\Enums\ParapharmacyCategory;
use App\Modules\Product\Domain\ParapharmacyProductMetadata;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParapharmacyProductMetadataTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();
    }

    /** @test */
    public function it_can_create_parapharmacy_metadata_with_all_fields(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $metadata = ParapharmacyProductMetadata::create([
            'product_id' => $product->id,
            'category' => ParapharmacyCategory::Supplement,
            'dosage_form' => DosageForm::Capsule,
            'usage_instructions' => 'Take 2 capsules daily with food',
            'warnings' => 'Do not exceed recommended dose',
            'contraindications' => 'Not suitable for pregnant women',
            'minimum_age' => 18,
            'age_restriction' => AgeRestriction::AdultOnly,
            'requires_consultation' => true,
            'regulatory_code' => 'FR123456',
            'storage_requirements' => 'Store in cool, dry place',
        ]);

        $this->assertNotNull($metadata->id);
        $this->assertEquals($product->id, $metadata->product_id);
        $this->assertEquals(ParapharmacyCategory::Supplement, $metadata->category);
        $this->assertEquals(DosageForm::Capsule, $metadata->dosage_form);
        $this->assertTrue($metadata->requires_consultation);
        $this->assertEquals(18, $metadata->minimum_age);
        $this->assertEquals(AgeRestriction::AdultOnly, $metadata->age_restriction);
    }

    /** @test */
    public function it_belongs_to_a_product(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $metadata = ParapharmacyProductMetadata::factory()->create([
            'product_id' => $product->id,
        ]);

        $this->assertInstanceOf(Product::class, $metadata->product);
        $this->assertEquals($product->id, $metadata->product->id);
    }

    /** @test */
    public function it_casts_enums_correctly(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $metadata = ParapharmacyProductMetadata::create([
            'product_id' => $product->id,
            'category' => 'cosmetic',
            'dosage_form' => 'cream',
            'age_restriction' => 'all_ages',
        ]);

        $this->assertInstanceOf(ParapharmacyCategory::class, $metadata->category);
        $this->assertEquals(ParapharmacyCategory::Cosmetic, $metadata->category);
        $this->assertInstanceOf(DosageForm::class, $metadata->dosage_form);
        $this->assertEquals(DosageForm::Cream, $metadata->dosage_form);
        $this->assertInstanceOf(AgeRestriction::class, $metadata->age_restriction);
        $this->assertEquals(AgeRestriction::AllAges, $metadata->age_restriction);
    }

    /** @test */
    public function it_has_relational_collections_for_ingredients_and_components(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $metadata = ParapharmacyProductMetadata::create([
            'product_id' => $product->id,
            'category' => ParapharmacyCategory::Supplement,
        ]);

        // These are now relational (BelongsToMany), not JSON columns
        $this->assertInstanceOf(Collection::class, $metadata->ingredients);
        $this->assertInstanceOf(Collection::class, $metadata->keyComponents);
        $this->assertInstanceOf(Collection::class, $metadata->healthClaims);
        $this->assertInstanceOf(Collection::class, $metadata->certifications);
        $this->assertCount(0, $metadata->ingredients);
        $this->assertCount(0, $metadata->keyComponents);
    }

    /** @test */
    public function it_has_requires_consultation_default_false(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $metadata = ParapharmacyProductMetadata::create([
            'product_id' => $product->id,
            'category' => ParapharmacyCategory::Cosmetic,
        ]);

        $this->assertFalse($metadata->requires_consultation);
    }

    /** @test */
    public function it_can_create_metadata_with_minimal_fields(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $metadata = ParapharmacyProductMetadata::create([
            'product_id' => $product->id,
            'category' => ParapharmacyCategory::BabyCare,
        ]);

        $this->assertNotNull($metadata->id);
        $this->assertEquals(ParapharmacyCategory::BabyCare, $metadata->category);
        $this->assertNull($metadata->dosage_form);
        $this->assertNull($metadata->usage_instructions);
    }

    /** @test */
    public function product_can_have_parapharmacy_metadata(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $metadata = ParapharmacyProductMetadata::factory()->create([
            'product_id' => $product->id,
        ]);

        $product->load('parapharmacyMetadata');

        $this->assertNotNull($product->parapharmacyMetadata);
        $this->assertEquals($metadata->id, $product->parapharmacyMetadata->id);
    }

    /** @test */
    public function metadata_is_deleted_when_product_is_deleted(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $metadata = ParapharmacyProductMetadata::factory()->create([
            'product_id' => $product->id,
        ]);

        $metadataId = $metadata->id;

        // Use forceDelete() because Product uses soft deletes
        $product->forceDelete();

        $this->assertDatabaseMissing('parapharmacy_product_metadata', [
            'id' => $metadataId,
        ]);
    }
}
