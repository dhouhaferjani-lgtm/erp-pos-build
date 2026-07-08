<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Company\Domain\Company;
use App\Modules\Media\Application\Services\MediaAttachmentService;
use App\Modules\Media\Application\Services\MediaUploadService;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Product\Domain\Brand;
use App\Modules\Product\Domain\Enums\BrandSource;
use App\Modules\Product\Domain\Product;
use App\Modules\Uom\Domain\Entities\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds ~40 REAL parapharmacy products with real product photography into the
 * local IziPOS demo tenant (`demo-pharmacy-tn`), so the POS can be reviewed with
 * proper images instead of the synthetic (image-less) {@see ParapharmacySeeder}
 * catalog.
 *
 * Data provenance: sourced from a scratch SQLite dump of the platform catalog
 * (products + products_media, `media_link_intern` hosted on the DigitalOcean
 * CDN `iziposapp.fra1.cdn.digitaloceanspaces.com`). The rows are BAKED IN below
 * — this seeder does NOT read the scratch dump at runtime (it only exists on
 * the laptop that produced it and would not be present on any other machine
 * or CI runner).
 *
 * Images are attached via {@see MediaUploadService::registerExternalUrl()},
 * which hotlinks the DO CDN URL as a display-only MediaAsset (SSRF-guarded,
 * HTTPS only) with status READY immediately (no rendition pipeline needed for
 * external URLs), then linked to the product as the PRIMARY image via
 * {@see MediaAttachmentService::attach()}.
 *
 * Idempotent / additive: does NOT touch the existing synthetic catalog. Each
 * row is skipped on re-run if a product with the same barcode already exists
 * for the company.
 */
final class DemoImageProductsSeeder extends Seeder
{
    /**
     * @var list<array{barcode:string,name:string,brand:string,image_url:string,price:string,cost:string}>
     */
    private const PRODUCTS = [
        ['barcode' => '3282770104783', 'name' => 'A-Derma Cytelium Spray 100ml', 'brand' => 'A-Derma', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/46/034ad053-bf70-4e31-afbd-7515eaadd9a1_0.webp', 'price' => '32.500', 'cost' => '19.500'],
        ['barcode' => '5201279073619', 'name' => 'Apivita Soin des Lèvres au Cassis', 'brand' => 'Apivita', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/5012/d930a71a-45c0-43fc-9a91-8af904a10526_0.webp', 'price' => '14.900', 'cost' => '8.900'],
        ['barcode' => '3282779402705', 'name' => 'Avène 50+ Émulsion 50ml', 'brand' => 'Avène', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/13564/2cffb696-0204-4819-bb11-10c7c6c91b40_0.webp', 'price' => '45.000', 'cost' => '27.000'],
        ['barcode' => '840101542166', 'name' => 'Beurer Vessie à Glace 28cm', 'brand' => 'Beurer', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/14750/38e10b48-3d01-4aa4-ae62-afd78aeaf480_0.webp', 'price' => '22.000', 'cost' => '13.000'],
        ['barcode' => '3701129800225', 'name' => 'Bioderma Cicabio Lotion', 'brand' => 'Bioderma', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/13715/6fa37344-229a-4144-a4db-2a30d4eb313e_0.webp', 'price' => '38.500', 'cost' => '23.000'],
        ['barcode' => '8436097092734', 'name' => 'Byphasse Crème Hydratante', 'brand' => 'Byphasse', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/3529/bd54c4ac-2c51-415e-acb4-a46f48053ab0_0.webp', 'price' => '12.500', 'cost' => '7.000'],
        ['barcode' => '5903407024073', 'name' => 'Canpol Babies Peluche Musicale', 'brand' => 'Canpol Babies', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/3226/f6b94f98-ffba-4276-9ac9-764aad7b5f0e_0.webp', 'price' => '28.000', 'cost' => '16.000'],
        ['barcode' => '3522930003199', 'name' => 'Caudalie Eau de Beauté 100ml', 'brand' => 'Caudalie', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/12186/3af1ade7-34bc-450c-b523-30a0a846d2c4_0.webp', 'price' => '55.000', 'cost' => '33.000'],
        ['barcode' => '3499320012348', 'name' => 'Cetaphil Crème Hydratante 50g', 'brand' => 'Cetaphil', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/409/b355050d-3fc2-4c18-b188-20161e96e7ec_0.webp', 'price' => '18.000', 'cost' => '10.500'],
        ['barcode' => '8058664009916', 'name' => 'Chicco Ciseaux Rose', 'brand' => 'Chicco', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/760/58e99305-bd24-4f97-98d5-15c1fa0a7fd1_0.webp', 'price' => '15.500', 'cost' => '9.000'],
        ['barcode' => '5906739782864', 'name' => 'Dermedic Lotion Après-Soleil', 'brand' => 'Dermedic', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/13753/1b3a7306-3057-4c8b-828f-fe2a1883efd0_0.webp', 'price' => '26.000', 'cost' => '15.500'],
        ['barcode' => '3282770074468', 'name' => 'Ducray Keracnyl Sérum 30ml', 'brand' => 'Ducray', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/10830/e04f5301-26e7-4d5c-bf4f-ffd4a9ce31f7_0.webp', 'price' => '42.000', 'cost' => '25.000'],
        ['barcode' => '3577056020476', 'name' => 'Elgydium Clinic Flex 123', 'brand' => 'Elgydium', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/6313/a822d697-a05e-402b-9d66-ca9e3d3112e0_0.webp', 'price' => '9.500', 'cost' => '5.500'],
        ['barcode' => '4005900728562', 'name' => 'Eucerin Hyaluron Spray 150ml', 'brand' => 'Eucerin', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/2679/712fe655-98af-4ce1-bb3f-374327bd07a4_0.webp', 'price' => '48.000', 'cost' => '29.000'],
        ['barcode' => '3540550008141', 'name' => 'Filorga Scrub & Peel', 'brand' => 'Filorga', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/13848/23191fcb-6943-407b-8a8f-b1eeed83c462_0.webp', 'price' => '65.000', 'cost' => '39.000'],
        ['barcode' => '7630019906388', 'name' => 'Gum Dentifrice Kids 3+', 'brand' => 'Gum', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/140/8d6aff74-5f4a-48b9-a5d4-899ac458ba52_0.webp', 'price' => '8.900', 'cost' => '5.200'],
        ['barcode' => '6199106101538', 'name' => 'Herbeos Crème de Jouvence 30ml', 'brand' => 'Herbeos', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/6854/d4d375b3-ca0d-46ab-99dc-9aa21b274b2d_0.webp', 'price' => '19.500', 'cost' => '11.500'],
        ['barcode' => '8470001769145', 'name' => 'Isdin Flavo-C Sérum 30ml', 'brand' => 'Isdin', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/1618/82b7f6e4-8d09-4513-869d-ac4f371e000f_0.webp', 'price' => '72.000', 'cost' => '43.000'],
        ['barcode' => '6192427311327', 'name' => 'K-Reine Coffret Clouds', 'brand' => 'K-Reine', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/13962/d947bd04-b614-42ab-8129-821c7bc038b6_0.webp', 'price' => '34.000', 'cost' => '20.000'],
        ['barcode' => '3433422406728', 'name' => 'La Roche-Posay Sérozinc 150ml', 'brand' => 'La Roche-Posay', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/6774/45c2686b-27d9-4353-af37-609d6546a2d5_0.webp', 'price' => '24.500', 'cost' => '14.500'],
        ['barcode' => '3616826020558', 'name' => 'Laino Eau de Rose 250ml', 'brand' => 'Laino', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/1451/70d506d6-0e25-414a-993d-6a617face8e8_0.webp', 'price' => '11.000', 'cost' => '6.500'],
        ['barcode' => '1234567920924', 'name' => 'Lierac Trousse Phyto', 'brand' => 'Lierac', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/6814/ce32a5eb-6008-47be-b6b8-a2b64e3320e5_0.webp', 'price' => '58.000', 'cost' => '35.000'],
        ['barcode' => '3504105036133', 'name' => 'Mustela Shampooing 500ml', 'brand' => 'Mustela', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/1479/25ac77af-e45c-413a-bbea-171ecca532c5_0.webp', 'price' => '27.500', 'cost' => '16.000'],
        ['barcode' => '8429449015345', 'name' => 'Naturtint Masque Éco Force 150ml', 'brand' => 'Naturtint', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/7825/c0b7f94d-daba-4622-b4ed-cf5245f3e209_0.webp', 'price' => '21.000', 'cost' => '12.500'],
        ['barcode' => '3571940004528', 'name' => 'Noreva Exfoliac Global X Pro', 'brand' => 'Noreva', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/7415/0f4464b6-4ac3-408a-bf41-6c1e8d2cc120_0.webp', 'price' => '36.000', 'cost' => '21.500'],
        ['barcode' => '4008600119111', 'name' => 'Nuk Ciseaux Bébé', 'brand' => 'Nuk', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/10817/c433d14a-084e-4a51-bd3e-3dea2b46f0e7_0.webp', 'price' => '13.000', 'cost' => '7.500'],
        ['barcode' => '3264680023323', 'name' => 'Nuxe Super Sérum [10] 30ml', 'brand' => 'Nuxe', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/4591/a1bbf247-a767-416f-b8af-cdec27a93694_0.webp', 'price' => '89.000', 'cost' => '53.000'],
        ['barcode' => '3014260278342', 'name' => 'Oral-B Brosse Enfant 8 Ans', 'brand' => 'Oral-B', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/14612/edc5154f-770c-40d2-9b20-d499ab068ba0_0.webp', 'price' => '7.500', 'cost' => '4.200'],
        ['barcode' => '8006540924228', 'name' => 'Pampers S6 Boîte de 24', 'brand' => 'Pampers', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/10049/0dbff992-d058-4914-aca3-fa0e3b27d094_0.webp', 'price' => '33.000', 'cost' => '20.000'],
        ['barcode' => '5900717167117', 'name' => 'Pharmaceris Viti Melo Nuit 40ml', 'brand' => 'Pharmaceris', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/13453/09c0a726-00e3-425f-813f-709610c4ad64_0.webp', 'price' => '47.000', 'cost' => '28.000'],
        ['barcode' => '6192421300396', 'name' => 'Phyto Vasculux', 'brand' => 'Phyto', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/14122/28f7d29c-1445-45c4-8abe-6e96e755d5a1_0.webp', 'price' => '39.000', 'cost' => '23.500'],
        ['barcode' => '6192312200101', 'name' => 'Roge Cavaillès Antador Gel-Crème 50ml', 'brand' => 'Roge Cavaillès', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/14546/072e804e-90f5-4db3-805b-9a9ad12d41f9_0.webp', 'price' => '22.500', 'cost' => '13.500'],
        ['barcode' => '3094901340003', 'name' => 'Sensodyne Fil Dentaire', 'brand' => 'Sensodyne', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/14175/6f5b7582-3523-431a-bb91-7b66fac0f434_0.webp', 'price' => '6.900', 'cost' => '3.900'],
        ['barcode' => '3662361001194', 'name' => 'SVR Pepti Biotic 50ml', 'brand' => 'SVR', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/14214/2c90caae-f11e-4477-ad0e-f697a32d35c1_0.webp', 'price' => '44.000', 'cost' => '26.500'],
        ['barcode' => '4008576091077', 'name' => 'Titania Éponge de Bain', 'brand' => 'Titania', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/4145/9d2a4ee7-7f7b-433b-a21e-0957c6f6d3a1_0.webp', 'price' => '4.500', 'cost' => '2.500'],
        ['barcode' => '8903424148544', 'name' => 'Tynor Canne en T L07', 'brand' => 'Tynor', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/14352/20871ff9-973b-403d-874b-6224a37a1e93_0.webp', 'price' => '38.000', 'cost' => '23.000'],
        ['barcode' => '3661434010033', 'name' => 'Uriage Hyséac Mat 40ml', 'brand' => 'Uriage', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/333/43386c17-054c-4d46-9794-afc26e2e19d6_0.webp', 'price' => '29.000', 'cost' => '17.500'],
        ['barcode' => '129911120626', 'name' => 'Vichy Pastille Cassis & Menthe', 'brand' => 'Vichy', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/14106/691671a3-74cd-4be0-b680-0dec97f20a89_0.webp', 'price' => '5.500', 'cost' => '3.100'],
        ['barcode' => '8690797108878', 'name' => 'Wee Baby Fork-Spoon Travel Case', 'brand' => 'Wee Baby', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/11305/2cdf760a-837e-4ed7-aa44-9059c756f48d_0.webp', 'price' => '16.500', 'cost' => '9.500'],
        ['barcode' => '5010415471208', 'name' => 'Tommee Tippee Tasse Sport 12M+ 300ml', 'brand' => 'Tommee Tippee', 'image_url' => 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/3196/fbf826c9-ab24-4aca-b3e1-2ee7149c5f4b_0.webp', 'price' => '19.900', 'cost' => '11.900'],
    ];

    public function run(): void
    {
        /** @var Company $company */
        $company = Company::firstOrFail();
        $tenantId = $company->tenant_id;

        $unit = Unit::where('code', 'pc')->first();

        /** @var MediaUploadService $uploadService */
        $uploadService = $this->container->make(MediaUploadService::class);
        /** @var MediaAttachmentService $attachmentService */
        $attachmentService = $this->container->make(MediaAttachmentService::class);

        $created = 0;
        $skipped = 0;
        $imagesAttached = 0;

        foreach (self::PRODUCTS as $row) {
            // Additive / idempotent: skip a barcode already present for this company
            // (either from a prior run of this seeder or a coincidental collision
            // with the synthetic ParapharmacySeeder catalog).
            $exists = Product::where('company_id', $company->id)
                ->where('barcode', $row['barcode'])
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            $brand = Brand::firstOrCreate(
                ['tenant_id' => $tenantId, 'slug' => Brand::slugFor($row['brand'])],
                [
                    'id' => (string) Str::uuid(),
                    'name' => $row['brand'],
                    'is_active' => true,
                ],
            );

            $product = Product::create([
                'tenant_id' => $tenantId,
                'company_id' => $company->id,
                'name' => $row['name'],
                'sku' => 'DEMO-IMG-'.$row['barcode'],
                'barcode' => $row['barcode'],
                'is_physical' => true,
                'purchase_price' => $row['cost'],
                'cost_price' => $row['cost'],
                'sale_price' => $row['price'],
                'tax_rate' => '19.00',
                'is_active' => true,
                'requires_batch_tracking' => false,
                'unit_id' => $unit?->id,
                'unit' => 'pc',
                'brand_id' => $brand->id,
                'brand_source' => BrandSource::User,
            ]);

            $created++;

            // registerExternalUrl() creates the MediaAsset with status = READY
            // immediately (external URLs need no rendition pipeline), so the
            // attachment is visible to CatalogMediaQuery / the POS sync payload
            // right after this transaction commits — no further status transition
            // is needed.
            $asset = $uploadService->registerExternalUrl($tenantId, $product->id, $row['image_url']);

            $attachmentService->attach(
                $asset->id,
                MediaOwnerType::Product,
                $product->id,
                MediaRole::Primary,
                0,
                $tenantId,
            );

            $imagesAttached++;
        }

        $this->command?->info(
            "DemoImageProductsSeeder: created {$created} products (skipped {$skipped} already-existing), ".
            "{$imagesAttached} with a PRIMARY image attached.",
        );
    }
}
