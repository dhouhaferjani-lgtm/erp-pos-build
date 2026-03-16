<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Vertical;
use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Billing\Domain\Enums\SubscriptionStatus;
use App\Modules\Billing\Domain\Plan;
use App\Modules\Billing\Domain\TenantSubscription;
use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\CompositeItemVariant;
use App\Modules\Catalog\Domain\Entities\ModifierGroup;
use App\Modules\Catalog\Domain\Entities\Recipe;
use App\Modules\Catalog\Domain\Entities\RecipeLine;
use App\Modules\Catalog\Domain\Enums\PriceAdjustmentType;
use App\Modules\Catalog\Domain\Enums\PricingMode;
use App\Modules\Catalog\Domain\Enums\ProductionType;
use App\Modules\Catalog\Domain\Enums\SelectionType;
use App\Modules\Catalog\Domain\Enums\VerticalType;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Menu\Domain\Entities\Menu;
use App\Modules\Menu\Domain\Entities\MenuCategory;
use App\Modules\Menu\Domain\Entities\MenuCategoryItem;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Promotion\Domain\Entities\Promotion;
use App\Modules\Promotion\Domain\Enums\DiscountAppliesTo;
use App\Modules\Promotion\Domain\Enums\DiscountType;
use App\Modules\Promotion\Domain\Enums\PromotionStatus;
use App\Modules\Promotion\Domain\Enums\PromotionType;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * CoffeeShopSeeder - Complete coffee shop with F&B composite items, recipes, and menus.
 *
 * Creates:
 * - ~30 ingredient products (coffee beans, milk, syrups, tea, pastry supplies, packaging)
 * - ~15 composite items (hot drinks, cold drinks, pastries)
 * - Recipes linking composite items to ingredient products
 * - Variants (Small/Medium/Large) for drinks
 * - 4 modifier groups (Milk, Extras, Sweetness, Temperature)
 * - 3 menus (All Day default, Breakfast, Summer)
 * - Stock levels, 2 test users
 *
 * Usage: php artisan db:seed --class=CoffeeShopSeeder
 */
class CoffeeShopSeeder extends Seeder
{
    private Tenant $tenant;

    private Company $company;

    private Location $location;

    /** @var array<string, Product> */
    private array $ingredients = [];

    /** @var array<string, CompositeItem> */
    private array $compositeItems = [];

    /** @var array<string, Product> */
    private array $retailProducts = [];

    /** @var array<string, ModifierGroup> */
    private array $modifierGroups = [];

    public function run(): void
    {
        $this->command->newLine();
        $this->command->info('Seeding Cafe Tunis - Coffee Shop with F&B');
        $this->command->newLine();

        // 1. Reference data
        $this->command->info('Checking reference data...');
        $this->call(RolesAndPermissionsSeeder::class);
        if (DB::table('countries')->count() === 0) {
            $this->call(CountriesSeeder::class);
        }
        $this->call(UomSeeder::class);
        $this->command->info('Reference data ready');

        // 2. Tenant
        $this->command->info('Creating tenant...');
        $this->tenant = $this->createTenant();
        $this->command->info("Tenant: {$this->tenant->name}");

        // 3. Company + Location
        $this->command->info('Creating company...');
        [$this->company, $this->location] = $this->createCompanyWithLocation();
        $this->command->info("Company: {$this->company->name}");
        $this->command->info("Location: {$this->location->name} (POS enabled)");

        // 4. Financial foundation
        $this->command->info('Setting up financial foundation...');
        $this->setupFinancialFoundation();

        // 5. Ingredient products
        $this->command->info('Creating ingredient products...');
        $this->seedIngredients();
        $this->command->info('Created ' . count($this->ingredients) . ' ingredient products');

        // 6. Composite items + recipes + variants
        $this->command->info('Creating composite items with recipes...');
        $this->seedCompositeItems();
        $this->command->info('Created ' . count($this->compositeItems) . ' composite items');

        // 6b. Combo items (fixed_bundle pricing)
        $this->command->info('Creating combo items (fixed bundles)...');
        $this->seedCombos();

        // 6c. Retail products (sold as-is, no recipe)
        $this->command->info('Creating retail products...');
        $this->seedRetailProducts();
        $this->command->info('Created ' . count($this->retailProducts) . ' retail products');

        // 7. Modifier groups
        $this->command->info('Creating modifier groups...');
        $this->seedModifierGroups();
        $this->command->info('Created ' . count($this->modifierGroups) . ' modifier groups');

        // 8. Menus
        $this->command->info('Creating menus...');
        $this->seedMenus();
        $this->command->info('Created 3 menus');

        // 9. Stock levels
        $this->command->info('Seeding stock levels...');
        $this->seedStockLevels();

        // 10. Promotions
        $this->command->info('Creating demo promotions...');
        $this->seedPromotions();

        // 11. Partner transactions (GL entries for non-zero balances)
        $this->command->info('Creating partner transactions...');
        $this->seedPartnerTransactions();

        // 12. Test users
        $this->command->info('Creating test users...');
        $this->createTestUsers();

        $this->command->newLine();
        $this->command->info('Coffee shop seeded successfully!');
        $this->command->newLine();
        $this->command->info('Test Credentials:');
        $this->command->info('   Owner:   owner@cafe-tunis.tn / password');
        $this->command->info('   Barista: barista@cafe-tunis.tn / password');
        $this->command->newLine();
    }

    private function createTenant(): Tenant
    {
        $existing = Tenant::where('slug', 'cafe-tunis')->first();
        if ($existing) {
            $this->command->warn('Tenant cafe-tunis already exists. Deleting and recreating...');

            // Temporarily disable FK checks for clean tenant deletion
            DB::statement('ALTER TABLE pos_receipts DISABLE TRIGGER enforce_receipt_immutability');
            DB::statement('SET session_replication_role = replica');
            $existing->forceDelete();
            DB::statement('SET session_replication_role = DEFAULT');
            DB::statement('ALTER TABLE pos_receipts ENABLE TRIGGER enforce_receipt_immutability');

            $existing->delete();
        }

        $professionalPlan = Plan::where('code', 'professional')->first();
        if ($professionalPlan === null) {
            $this->call(PlansSeeder::class);
            $professionalPlan = Plan::where('code', 'professional')->first();
        }

        $tenant = Tenant::create([
            'name' => 'Cafe Tunis',
            'slug' => 'cafe-tunis',
            'status' => TenantStatus::Active,
            'plan' => 'professional',
            'vertical' => Vertical::CoffeeShop,
            'tax_id' => '1234567A',
            'country_code' => 'TN',
            'currency_code' => 'TND',
            'settings' => [
                'timezone' => 'Africa/Tunis',
                'locale' => 'fr',
                'date_format' => 'd/m/Y',
                'fiscal_year_start' => '01-01',
                'enabled_extras' => ['Loyalty'],
            ],
            'trial_ends_at' => null,
            'subscription_ends_at' => now()->addYear(),
        ]);

        if ($professionalPlan) {
            TenantSubscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $professionalPlan->id,
                'status' => SubscriptionStatus::Active,
                'billing_cycle' => 'yearly',
                'price' => 0,
                'started_at' => now(),
                'current_period_start' => now(),
                'current_period_end' => now()->addYear(),
            ]);
        }

        return $tenant;
    }

    /**
     * @return array{0: Company, 1: Location}
     */
    private function createCompanyWithLocation(): array
    {
        $company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cafe Tunis',
            'legal_name' => 'Cafe Tunis SARL',
            'country_code' => 'TN',
            'tax_id' => '1234567A',
            'currency' => 'TND',
            'locale' => 'fr',
            'timezone' => 'Africa/Tunis',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
            'address_street' => '15 Avenue Habib Bourguiba',
            'address_city' => 'Tunis',
            'address_postal_code' => '1000',
            'phone' => '+216 71 123 456',
            'email' => 'contact@cafe-tunis.tn',
        ]);

        $location = Location::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $company->id,
            'code' => 'CAFE-01',
            'name' => 'Cafe Tunis Centre',
            'type' => 'shop',
            'is_default' => true,
            'is_active' => true,
            'pos_enabled' => true,
            'address_street' => '15 Avenue Habib Bourguiba',
            'address_city' => 'Tunis',
            'address_postal_code' => '1000',
            'address_country' => 'TN',
            'phone' => '+216 71 123 456',
            'email' => 'contact@cafe-tunis.tn',
        ]);

        return [$company, $location];
    }

    private function setupFinancialFoundation(): void
    {
        $tunisiaSeeder = new TunisiaChartOfAccountsSeeder;
        $tunisiaSeeder->setCommand($this->command);
        $tunisiaSeeder->run($this->company->id, $this->company->tenant_id);
        $this->command->info('Chart of Accounts ready');

        $this->call(PaymentMethodSeeder::class, false, ['company' => $this->company]);
        $this->command->info('Payment Methods ready');

        $this->call(PaymentRepositorySeeder::class, false, ['company' => $this->company]);
        $this->command->info('Payment Repositories ready');
    }

    private function seedIngredients(): void
    {
        $ingredientData = [
            // Coffee
            ['code' => 'ING-ESPRESSO', 'name' => 'Espresso Beans (1kg)', 'price' => 35.000, 'cost' => 22.000],
            ['code' => 'ING-DECAF', 'name' => 'Decaf Beans (1kg)', 'price' => 40.000, 'cost' => 26.000],
            ['code' => 'ING-GROUND', 'name' => 'Ground Coffee (500g)', 'price' => 18.000, 'cost' => 11.000],
            // Milk
            ['code' => 'ING-MILK-WHOLE', 'name' => 'Whole Milk (1L)', 'price' => 1.800, 'cost' => 1.200],
            ['code' => 'ING-MILK-SKIM', 'name' => 'Skim Milk (1L)', 'price' => 1.900, 'cost' => 1.300],
            ['code' => 'ING-MILK-OAT', 'name' => 'Oat Milk (1L)', 'price' => 4.500, 'cost' => 3.200],
            ['code' => 'ING-MILK-ALMOND', 'name' => 'Almond Milk (1L)', 'price' => 5.000, 'cost' => 3.500],
            ['code' => 'ING-MILK-SOY', 'name' => 'Soy Milk (1L)', 'price' => 3.500, 'cost' => 2.400],
            // Syrups
            ['code' => 'ING-SYR-VANILLA', 'name' => 'Vanilla Syrup (750ml)', 'price' => 12.000, 'cost' => 7.500],
            ['code' => 'ING-SYR-CARAMEL', 'name' => 'Caramel Syrup (750ml)', 'price' => 12.000, 'cost' => 7.500],
            ['code' => 'ING-SYR-HAZELNUT', 'name' => 'Hazelnut Syrup (750ml)', 'price' => 12.000, 'cost' => 7.500],
            ['code' => 'ING-SYR-CHOC', 'name' => 'Chocolate Syrup (750ml)', 'price' => 10.000, 'cost' => 6.500],
            // Tea
            ['code' => 'ING-TEA-GREEN', 'name' => 'Green Tea (100 bags)', 'price' => 8.000, 'cost' => 5.000],
            ['code' => 'ING-TEA-BLACK', 'name' => 'Black Tea (100 bags)', 'price' => 7.000, 'cost' => 4.500],
            ['code' => 'ING-TEA-CHAMOMILE', 'name' => 'Chamomile Tea (100 bags)', 'price' => 9.000, 'cost' => 6.000],
            ['code' => 'ING-TEA-MINT', 'name' => 'Mint Tea (100 bags)', 'price' => 8.500, 'cost' => 5.500],
            // Pastry supplies
            ['code' => 'ING-FLOUR', 'name' => 'All-Purpose Flour (5kg)', 'price' => 4.000, 'cost' => 2.500],
            ['code' => 'ING-SUGAR', 'name' => 'White Sugar (5kg)', 'price' => 5.500, 'cost' => 3.500],
            ['code' => 'ING-BUTTER', 'name' => 'Butter (500g)', 'price' => 6.000, 'cost' => 4.000],
            ['code' => 'ING-EGGS', 'name' => 'Eggs (30 pack)', 'price' => 8.000, 'cost' => 5.500],
            ['code' => 'ING-CHOCOLATE', 'name' => 'Dark Chocolate (1kg)', 'price' => 15.000, 'cost' => 10.000],
            // Toppings
            ['code' => 'ING-WHIP-CREAM', 'name' => 'Whipped Cream (500ml)', 'price' => 5.000, 'cost' => 3.200],
            ['code' => 'ING-COCOA', 'name' => 'Cocoa Powder (500g)', 'price' => 8.000, 'cost' => 5.000],
            ['code' => 'ING-CINNAMON', 'name' => 'Cinnamon Powder (250g)', 'price' => 6.000, 'cost' => 3.800],
            // Packaging
            ['code' => 'ING-CUP-S', 'name' => 'Paper Cup Small 8oz (100)', 'price' => 6.000, 'cost' => 4.000],
            ['code' => 'ING-CUP-M', 'name' => 'Paper Cup Medium 12oz (100)', 'price' => 7.000, 'cost' => 4.800],
            ['code' => 'ING-CUP-L', 'name' => 'Paper Cup Large 16oz (100)', 'price' => 8.500, 'cost' => 5.800],
            ['code' => 'ING-LIDS', 'name' => 'Cup Lids (100)', 'price' => 3.500, 'cost' => 2.200],
            ['code' => 'ING-STRAWS', 'name' => 'Paper Straws (200)', 'price' => 4.000, 'cost' => 2.500],
            // Ice
            ['code' => 'ING-ICE', 'name' => 'Ice Cubes (5kg bag)', 'price' => 2.500, 'cost' => 1.500],
        ];

        foreach ($ingredientData as $data) {
            $product = Product::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'name' => $data['name'],
                'sku' => $data['code'],
                'is_physical' => true,
                'purchase_price' => $data['cost'],
                'sale_price' => $data['price'],
                'tax_rate' => 7.00, // 7% Tunisia VAT on food items
                'is_active' => true,
            ]);
            $this->ingredients[$data['code']] = $product;
        }
    }

    private function seedCompositeItems(): void
    {
        $items = [
            // Hot drinks
            ['code' => 'ESP', 'name' => 'Espresso', 'price' => 3.500, 'type' => 'hot',
                'recipe' => [['ING-ESPRESSO', 0.018]], // 18g beans
            ],
            ['code' => 'AMR', 'name' => 'Americano', 'price' => 4.000, 'type' => 'hot',
                'recipe' => [['ING-ESPRESSO', 0.018]],
            ],
            ['code' => 'LAT', 'name' => 'Latte', 'price' => 5.500, 'type' => 'hot',
                'recipe' => [['ING-ESPRESSO', 0.018], ['ING-MILK-WHOLE', 0.200]],
            ],
            ['code' => 'CAP', 'name' => 'Cappuccino', 'price' => 5.500, 'type' => 'hot',
                'recipe' => [['ING-ESPRESSO', 0.018], ['ING-MILK-WHOLE', 0.150]],
            ],
            ['code' => 'MOC', 'name' => 'Mocha', 'price' => 6.500, 'type' => 'hot',
                'recipe' => [['ING-ESPRESSO', 0.018], ['ING-MILK-WHOLE', 0.200], ['ING-SYR-CHOC', 0.030]],
            ],
            ['code' => 'HCH', 'name' => 'Hot Chocolate', 'price' => 5.000, 'type' => 'hot',
                'recipe' => [['ING-MILK-WHOLE', 0.250], ['ING-SYR-CHOC', 0.050]],
            ],
            // Cold drinks
            ['code' => 'ICL', 'name' => 'Iced Latte', 'price' => 6.000, 'type' => 'cold',
                'recipe' => [['ING-ESPRESSO', 0.036], ['ING-MILK-WHOLE', 0.200], ['ING-ICE', 0.100]],
            ],
            ['code' => 'ICA', 'name' => 'Iced Americano', 'price' => 4.500, 'type' => 'cold',
                'recipe' => [['ING-ESPRESSO', 0.036], ['ING-ICE', 0.150]],
            ],
            ['code' => 'FRP', 'name' => 'Frappuccino', 'price' => 7.500, 'type' => 'cold',
                'recipe' => [['ING-ESPRESSO', 0.036], ['ING-MILK-WHOLE', 0.200], ['ING-ICE', 0.150], ['ING-WHIP-CREAM', 0.030]],
            ],
            ['code' => 'ICT', 'name' => 'Iced Tea', 'price' => 4.000, 'type' => 'cold',
                'recipe' => [['ING-TEA-BLACK', 0.01], ['ING-ICE', 0.200]],
            ],
            // Pastries
            ['code' => 'CRO', 'name' => 'Croissant', 'price' => 3.000, 'type' => 'pastry',
                'recipe' => [['ING-FLOUR', 0.080], ['ING-BUTTER', 0.040], ['ING-EGGS', 0.033]],
            ],
            ['code' => 'PAC', 'name' => 'Pain au Chocolat', 'price' => 3.500, 'type' => 'pastry',
                'recipe' => [['ING-FLOUR', 0.080], ['ING-BUTTER', 0.035], ['ING-CHOCOLATE', 0.025]],
            ],
            ['code' => 'MUF', 'name' => 'Muffin', 'price' => 4.000, 'type' => 'pastry',
                'recipe' => [['ING-FLOUR', 0.060], ['ING-SUGAR', 0.030], ['ING-BUTTER', 0.025], ['ING-EGGS', 0.033]],
            ],
            ['code' => 'COK', 'name' => 'Cookie', 'price' => 2.500, 'type' => 'pastry',
                'recipe' => [['ING-FLOUR', 0.040], ['ING-SUGAR', 0.025], ['ING-BUTTER', 0.030], ['ING-CHOCOLATE', 0.015]],
            ],
            ['code' => 'CHE', 'name' => 'Cheesecake Slice', 'price' => 7.000, 'type' => 'pastry',
                'recipe' => [['ING-FLOUR', 0.030], ['ING-SUGAR', 0.040], ['ING-BUTTER', 0.020], ['ING-EGGS', 0.066]],
            ],
        ];

        $order = 0;
        foreach ($items as $data) {
            $item = CompositeItem::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'code' => $data['code'],
                'name' => $data['name'],
                'vertical_type' => VerticalType::Fnb,
                'base_price' => $data['price'],
                'production_type' => ProductionType::MadeToOrder,
                'tax_rate' => '7.00',
                'is_active' => true,
                'is_available' => true,
                'display_order' => $order++,
            ]);

            // Create recipe
            $recipe = Recipe::create([
                'composite_item_id' => $item->id,
                'version' => 1,
                'version_name' => 'Standard',
                'is_active' => true,
                'yield_quantity' => 1,
            ]);

            $lineOrder = 0;
            foreach ($data['recipe'] as [$ingredientCode, $qty]) {
                if (isset($this->ingredients[$ingredientCode])) {
                    RecipeLine::create([
                        'recipe_id' => $recipe->id,
                        'component_type' => 'product',
                        'component_id' => $this->ingredients[$ingredientCode]->id,
                        'quantity' => $qty,
                        'is_optional' => false,
                        'is_scalable' => true,
                        'display_order' => $lineOrder++,
                    ]);
                }
            }

            $item->update(['default_recipe_id' => $recipe->id]);

            // Add variants for drinks (not pastries)
            if ($data['type'] !== 'pastry') {
                CompositeItemVariant::create([
                    'composite_item_id' => $item->id,
                    'code' => $data['code'] . '-S',
                    'name' => 'Small',
                    'price_adjustment_type' => PriceAdjustmentType::Absolute,
                    'price_adjustment' => -1.000,
                    'recipe_multiplier' => 0.8,
                    'is_default' => true,
                    'is_active' => true,
                    'display_order' => 0,
                ]);
                CompositeItemVariant::create([
                    'composite_item_id' => $item->id,
                    'code' => $data['code'] . '-M',
                    'name' => 'Medium',
                    'price_adjustment_type' => PriceAdjustmentType::Absolute,
                    'price_adjustment' => 0,
                    'recipe_multiplier' => 1.0,
                    'is_default' => false,
                    'is_active' => true,
                    'display_order' => 1,
                ]);
                CompositeItemVariant::create([
                    'composite_item_id' => $item->id,
                    'code' => $data['code'] . '-L',
                    'name' => 'Large',
                    'price_adjustment_type' => PriceAdjustmentType::Absolute,
                    'price_adjustment' => 1.000,
                    'recipe_multiplier' => 1.2,
                    'is_default' => false,
                    'is_active' => true,
                    'display_order' => 2,
                ]);
            }

            $this->compositeItems[$data['code']] = $item;
        }

        // Add a recipe-less "Daily Special" composite item
        $dailySpecial = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'DAILY',
            'name' => 'Daily Special',
            'vertical_type' => VerticalType::Fnb,
            'base_price' => 8.000,
            'production_type' => ProductionType::MadeToOrder,
            'tax_rate' => '7.00',
            'is_active' => true,
            'is_available' => true,
            'display_order' => $order,
        ]);
        $this->compositeItems['DAILY'] = $dailySpecial;
    }

    private function seedCombos(): void
    {
        $combos = [
            [
                'code' => 'COMBO-BF',
                'name' => 'Breakfast Combo',
                'price' => 7.500,
                'components' => ['LAT', 'CRO'], // Latte + Croissant (standalone: 5.5 + 3.0 = 8.5)
            ],
            [
                'code' => 'COMBO-MOC',
                'name' => 'Mocha & Muffin',
                'price' => 9.000,
                'components' => ['MOC', 'MUF'], // Mocha + Muffin (standalone: 6.5 + 4.0 = 10.5)
            ],
            [
                'code' => 'COMBO-ICE',
                'name' => 'Iced Duo',
                'price' => 9.500,
                'components' => ['ICL', 'FRP'], // Iced Latte + Frappuccino (standalone: 6.0 + 7.5 = 13.5)
            ],
        ];

        $order = count($this->compositeItems);

        foreach ($combos as $data) {
            $combo = CompositeItem::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'code' => $data['code'],
                'name' => $data['name'],
                'vertical_type' => VerticalType::Fnb,
                'base_price' => $data['price'],
                'production_type' => ProductionType::MadeToOrder,
                'pricing_mode' => PricingMode::FixedBundle,
                'tax_rate' => '7.00',
                'is_active' => true,
                'is_available' => true,
                'display_order' => $order++,
            ]);

            $recipe = Recipe::create([
                'composite_item_id' => $combo->id,
                'version' => 1,
                'version_name' => 'Standard',
                'is_active' => true,
                'yield_quantity' => 1,
            ]);

            $lineOrder = 0;
            foreach ($data['components'] as $componentCode) {
                if (isset($this->compositeItems[$componentCode])) {
                    RecipeLine::create([
                        'recipe_id' => $recipe->id,
                        'component_type' => 'composite_item',
                        'component_id' => $this->compositeItems[$componentCode]->id,
                        'quantity' => 1,
                        'is_optional' => false,
                        'is_scalable' => false,
                        'display_order' => $lineOrder++,
                    ]);
                }
            }

            $combo->update(['default_recipe_id' => $recipe->id]);
            $this->compositeItems[$data['code']] = $combo;
        }

        $this->command->info('Created ' . count($combos) . ' combo items (fixed_bundle)');
    }

    private function seedRetailProducts(): void
    {
        $retailData = [
            ['code' => 'RET-WATER', 'name' => 'Bottled Water', 'price' => 1.500, 'cost' => 0.600],
            ['code' => 'RET-JUICE', 'name' => 'Bottled Juice', 'price' => 3.000, 'cost' => 1.500],
            ['code' => 'RET-ENERGY', 'name' => 'Energy Bar', 'price' => 2.500, 'cost' => 1.200],
            ['code' => 'RET-BEANS', 'name' => 'Bag of Coffee Beans 250g', 'price' => 25.000, 'cost' => 15.000],
            ['code' => 'RET-CHOC', 'name' => 'Chocolate Bar', 'price' => 2.000, 'cost' => 0.900],
        ];

        foreach ($retailData as $data) {
            $product = Product::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'name' => $data['name'],
                'sku' => $data['code'],
                'is_physical' => true,
                'purchase_price' => $data['cost'],
                'sale_price' => $data['price'],
                'tax_rate' => 7.00,
                'is_active' => true,
            ]);
            $this->retailProducts[$data['code']] = $product;
        }
    }

    private function seedModifierGroups(): void
    {
        // 1. Milk Choice
        $milkGroup = ModifierGroup::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'MOD-MILK',
            'name' => 'Milk Choice',
            'selection_type' => SelectionType::Single,
            'min_selections' => 1,
            'max_selections' => 1,
            'is_required' => false,
            'is_active' => true,
            'display_order' => 0,
        ]);

        $milkModifiers = [
            ['code' => 'MILK-WHOLE', 'name' => 'Whole Milk', 'price' => 0, 'default' => true, 'ingredient' => 'ING-MILK-WHOLE'],
            ['code' => 'MILK-SKIM', 'name' => 'Skim Milk', 'price' => 0, 'default' => false, 'ingredient' => 'ING-MILK-SKIM'],
            ['code' => 'MILK-OAT', 'name' => 'Oat Milk', 'price' => 0.500, 'default' => false, 'ingredient' => 'ING-MILK-OAT'],
            ['code' => 'MILK-ALMOND', 'name' => 'Almond Milk', 'price' => 0.500, 'default' => false, 'ingredient' => 'ING-MILK-ALMOND'],
            ['code' => 'MILK-SOY', 'name' => 'Soy Milk', 'price' => 0.500, 'default' => false, 'ingredient' => 'ING-MILK-SOY'],
        ];

        $order = 0;
        foreach ($milkModifiers as $mod) {
            $componentId = isset($this->ingredients[$mod['ingredient']]) ? $this->ingredients[$mod['ingredient']]->id : null;
            DB::table('modifiers')->insert([
                'id' => Str::uuid()->toString(),
                'modifier_group_id' => $milkGroup->id,
                'code' => $mod['code'],
                'name' => $mod['name'],
                'price_adjustment' => $mod['price'],
                'component_type' => $componentId ? 'product' : null,
                'component_id' => $componentId,
                'is_default' => $mod['default'],
                'is_active' => true,
                'display_order' => $order++,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->modifierGroups['milk'] = $milkGroup;

        // 2. Extras
        $extrasGroup = ModifierGroup::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'MOD-EXTRAS',
            'name' => 'Extras',
            'selection_type' => SelectionType::Multiple,
            'min_selections' => 0,
            'max_selections' => 3,
            'is_required' => false,
            'is_active' => true,
            'display_order' => 1,
        ]);

        $extrasModifiers = [
            ['code' => 'EXTRA-SHOT', 'name' => 'Extra Shot', 'price' => 0.800, 'ingredient' => 'ING-ESPRESSO'],
            ['code' => 'EXTRA-WHIP', 'name' => 'Whipped Cream', 'price' => 0.300, 'ingredient' => 'ING-WHIP-CREAM'],
        ];

        $order = 0;
        foreach ($extrasModifiers as $mod) {
            $componentId = isset($this->ingredients[$mod['ingredient']]) ? $this->ingredients[$mod['ingredient']]->id : null;
            DB::table('modifiers')->insert([
                'id' => Str::uuid()->toString(),
                'modifier_group_id' => $extrasGroup->id,
                'code' => $mod['code'],
                'name' => $mod['name'],
                'price_adjustment' => $mod['price'],
                'component_type' => $componentId ? 'product' : null,
                'component_id' => $componentId,
                'is_default' => false,
                'is_active' => true,
                'display_order' => $order++,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->modifierGroups['extras'] = $extrasGroup;

        // 3. Sweetness
        $sweetnessGroup = ModifierGroup::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'MOD-SWEET',
            'name' => 'Sweetness',
            'selection_type' => SelectionType::Single,
            'min_selections' => 0,
            'max_selections' => 1,
            'is_required' => false,
            'is_active' => true,
            'display_order' => 2,
        ]);

        $sweetnessModifiers = [
            ['code' => 'SWEET-NONE', 'name' => 'No Sugar', 'default' => false],
            ['code' => 'SWEET-LIGHT', 'name' => 'Light Sugar', 'default' => false],
            ['code' => 'SWEET-NORMAL', 'name' => 'Normal', 'default' => true],
            ['code' => 'SWEET-EXTRA', 'name' => 'Extra Sweet', 'default' => false],
        ];

        $order = 0;
        foreach ($sweetnessModifiers as $mod) {
            DB::table('modifiers')->insert([
                'id' => Str::uuid()->toString(),
                'modifier_group_id' => $sweetnessGroup->id,
                'code' => $mod['code'],
                'name' => $mod['name'],
                'price_adjustment' => 0,
                'is_default' => $mod['default'],
                'is_active' => true,
                'display_order' => $order++,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->modifierGroups['sweetness'] = $sweetnessGroup;

        // 4. Temperature
        $tempGroup = ModifierGroup::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'MOD-TEMP',
            'name' => 'Temperature',
            'selection_type' => SelectionType::Single,
            'min_selections' => 0,
            'max_selections' => 1,
            'is_required' => false,
            'is_active' => true,
            'display_order' => 3,
        ]);

        $tempModifiers = [
            ['code' => 'TEMP-HOT', 'name' => 'Hot', 'default' => true],
            ['code' => 'TEMP-ICED', 'name' => 'Iced', 'default' => false],
            ['code' => 'TEMP-XHOT', 'name' => 'Extra Hot', 'default' => false],
        ];

        $order = 0;
        foreach ($tempModifiers as $mod) {
            DB::table('modifiers')->insert([
                'id' => Str::uuid()->toString(),
                'modifier_group_id' => $tempGroup->id,
                'code' => $mod['code'],
                'name' => $mod['name'],
                'price_adjustment' => 0,
                'is_default' => $mod['default'],
                'is_active' => true,
                'display_order' => $order++,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->modifierGroups['temperature'] = $tempGroup;

        // Assign modifier groups to drink composite items
        $drinkCodes = ['ESP', 'AMR', 'LAT', 'CAP', 'MOC', 'HCH', 'ICL', 'ICA', 'FRP', 'ICT'];
        foreach ($drinkCodes as $code) {
            if (isset($this->compositeItems[$code])) {
                $item = $this->compositeItems[$code];
                $groupOrder = 0;
                foreach ($this->modifierGroups as $group) {
                    DB::table('composite_item_modifier_groups')->insert([
                        'composite_item_id' => $item->id,
                        'modifier_group_id' => $group->id,
                        'display_order' => $groupOrder++,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    private function seedMenus(): void
    {
        $hotDrinkCodes = ['ESP', 'AMR', 'LAT', 'CAP', 'MOC', 'HCH'];
        $coldDrinkCodes = ['ICL', 'ICA', 'FRP', 'ICT'];
        $pastryCodes = ['CRO', 'PAC', 'MUF', 'COK', 'CHE'];

        // 1. Default Menu - All Day
        $allDayMenu = Menu::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'All Day',
            'description' => 'Full menu available all day',
            'is_default' => true,
            'is_active' => true,
            'display_order' => 0,
        ]);

        $hotCat = MenuCategory::create([
            'menu_id' => $allDayMenu->id,
            'name' => 'Hot Drinks',
            'icon' => 'coffee',
            'display_order' => 0,
        ]);
        $this->attachItemsToCategory($hotCat, $hotDrinkCodes);

        $coldCat = MenuCategory::create([
            'menu_id' => $allDayMenu->id,
            'name' => 'Cold Drinks',
            'icon' => 'snowflake',
            'display_order' => 1,
        ]);
        $this->attachItemsToCategory($coldCat, $coldDrinkCodes);

        $pastryCat = MenuCategory::create([
            'menu_id' => $allDayMenu->id,
            'name' => 'Pastries',
            'icon' => 'cake',
            'display_order' => 2,
        ]);
        $this->attachItemsToCategory($pastryCat, $pastryCodes);

        // Specials category (with Daily Special composite item)
        $specialsCat = MenuCategory::create([
            'menu_id' => $allDayMenu->id,
            'name' => 'Specials',
            'icon' => 'star',
            'display_order' => 3,
        ]);
        $this->attachItemsToCategory($specialsCat, ['DAILY', 'COMBO-BF', 'COMBO-MOC', 'COMBO-ICE']);

        // Shop category (retail products — not composite items)
        $shopCat = MenuCategory::create([
            'menu_id' => $allDayMenu->id,
            'name' => 'Shop',
            'icon' => 'shopping-bag',
            'display_order' => 4,
        ]);
        $this->attachProductsToCategory($shopCat, array_keys($this->retailProducts));

        // 2. Breakfast Menu (6:00-11:00)
        $breakfastMenu = Menu::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Breakfast',
            'description' => 'Morning menu with hot drinks and pastries',
            'is_default' => false,
            'is_active' => true,
            'active_from' => '06:00',
            'active_until' => '11:00',
            'display_order' => 1,
        ]);

        $bfHotCat = MenuCategory::create([
            'menu_id' => $breakfastMenu->id,
            'name' => 'Hot Drinks',
            'icon' => 'coffee',
            'display_order' => 0,
        ]);
        $this->attachItemsToCategory($bfHotCat, $hotDrinkCodes);

        $bfPastryCat = MenuCategory::create([
            'menu_id' => $breakfastMenu->id,
            'name' => 'Pastries',
            'icon' => 'cake',
            'display_order' => 1,
        ]);
        $this->attachItemsToCategory($bfPastryCat, $pastryCodes);

        // 3. Summer Menu (June 1 - Sept 30)
        $summerMenu = Menu::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Summer',
            'description' => 'Refreshing cold drinks for summer',
            'is_default' => false,
            'is_active' => true,
            'start_date' => date('Y') . '-06-01',
            'end_date' => date('Y') . '-09-30',
            'display_order' => 2,
        ]);

        $summerColdCat = MenuCategory::create([
            'menu_id' => $summerMenu->id,
            'name' => 'Iced & Frozen',
            'icon' => 'snowflake',
            'display_order' => 0,
        ]);
        $this->attachItemsToCategory($summerColdCat, $coldDrinkCodes);

        $summerHotCat = MenuCategory::create([
            'menu_id' => $summerMenu->id,
            'name' => 'Hot Drinks',
            'icon' => 'coffee',
            'display_order' => 1,
        ]);
        $this->attachItemsToCategory($summerHotCat, $hotDrinkCodes);
    }

    /**
     * @param  array<int, string>  $codes
     */
    private function attachItemsToCategory(MenuCategory $category, array $codes): void
    {
        $order = 0;
        foreach ($codes as $code) {
            if (isset($this->compositeItems[$code])) {
                MenuCategoryItem::create([
                    'menu_category_id' => $category->id,
                    'composite_item_id' => $this->compositeItems[$code]->id,
                    'product_id' => null,
                    'display_order' => $order++,
                    'is_available' => true,
                ]);
            }
        }
    }

    /**
     * @param  array<int, string>  $codes
     */
    private function attachProductsToCategory(MenuCategory $category, array $codes): void
    {
        $order = 0;
        foreach ($codes as $code) {
            if (isset($this->retailProducts[$code])) {
                MenuCategoryItem::create([
                    'menu_category_id' => $category->id,
                    'composite_item_id' => null,
                    'product_id' => $this->retailProducts[$code]->id,
                    'display_order' => $order++,
                    'is_available' => true,
                ]);
            }
        }
    }

    private function seedStockLevels(): void
    {
        $count = 0;
        foreach ($this->ingredients as $product) {
            StockLevel::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'product_id' => $product->id,
                'location_id' => $this->location->id,
                'quantity' => rand(10, 50),
                'reserved' => 0,
            ]);
            $count++;
        }
        $this->command->info("Stock levels created ({$count} products)");
    }

    private function seedPromotions(): void
    {
        $frappId = $this->compositeItems['FRP']->id;
        $croissantId = $this->compositeItems['CRO']->id;

        Promotion::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Buy Frappuccino, Get Free Croissant',
            'description' => 'Purchase any Frappuccino and receive a free Croissant',
            'type' => PromotionType::BuyXGetY,
            'status' => PromotionStatus::Active,
            'priority' => 10,
            'is_exclusive' => false,
            'stacking_group' => 'default',
            'discount_type' => DiscountType::FreeItem,
            'discount_value' => '100',
            'applies_to' => DiscountAppliesTo::SpecificItem,
            'conditions' => [
                'qualifying_product_ids' => [$frappId],
                'trigger_qty' => 1,
                'reward_product_ids' => [$croissantId],
            ],
            'starts_at' => now(),
            'ends_at' => now()->addMonths(3),
        ]);

        $this->command->info('Created "Buy Frappuccino, Get Free Croissant" promotion');
    }

    private function seedPartnerTransactions(): void
    {
        $companyId = $this->company->id;
        $tenantId = $this->tenant->id;

        // Create customer partners
        $customers = [
            ['name' => 'Cafe Central', 'code' => 'CUST-001', 'email' => 'contact@cafecentral.tn'],
            ['name' => 'Restaurant Le Jardin', 'code' => 'CUST-002', 'email' => 'info@lejardin.tn'],
            ['name' => 'Hotel Meridien', 'code' => 'CUST-003', 'email' => 'purchase@meridien.tn'],
        ];

        $partnerIds = [];
        foreach ($customers as $data) {
            $partner = Partner::firstOrCreate(
                ['company_id' => $companyId, 'code' => $data['code']],
                [
                    'tenant_id' => $tenantId,
                    'name' => $data['name'],
                    'type' => PartnerType::Customer,
                    'email' => $data['email'],
                    'is_active' => true,
                ]
            );
            $partnerIds[$data['code']] = $partner->id;
        }

        // Create supplier partner
        $supplier = Partner::firstOrCreate(
            ['company_id' => $companyId, 'code' => 'SUPP-001'],
            [
                'tenant_id' => $tenantId,
                'name' => 'Coffee Bean Wholesale',
                'type' => PartnerType::Supplier,
                'email' => 'orders@coffeebeans.tn',
                'is_active' => true,
            ]
        );
        $partnerIds['SUPP-001'] = $supplier->id;

        // Lookup GL accounts
        $receivableAccount = Account::where('company_id', $companyId)
            ->where('system_purpose', SystemAccountPurpose::CustomerReceivable->value)
            ->firstOrFail();
        $revenueAccount = Account::where('company_id', $companyId)
            ->where('system_purpose', SystemAccountPurpose::ProductRevenue->value)
            ->firstOrFail();
        $vatAccount = Account::where('company_id', $companyId)
            ->where('system_purpose', SystemAccountPurpose::VatCollected->value)
            ->firstOrFail();
        $cashAccount = Account::where('company_id', $companyId)
            ->where('system_purpose', SystemAccountPurpose::Cash->value)
            ->firstOrFail();
        $payableAccount = Account::where('company_id', $companyId)
            ->where('system_purpose', SystemAccountPurpose::SupplierPayable->value)
            ->firstOrFail();

        $entrySeq = 9000;

        // Helper to create and post a journal entry
        $createEntry = function (string $description, string $sourceType, array $lines) use ($companyId, $tenantId, &$entrySeq): void {
            $entry = JournalEntry::create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'entry_number' => 'SEED-' . $entrySeq++,
                'entry_date' => now()->subDays(rand(1, 30))->toDateString(),
                'description' => $description,
                'status' => JournalEntryStatus::Posted,
                'source_type' => $sourceType,
                'posted_at' => now(),
            ]);

            $lineOrder = 0;
            foreach ($lines as $line) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $line['account_id'],
                    'partner_id' => $line['partner_id'] ?? null,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'description' => $line['description'],
                    'line_order' => $lineOrder++,
                ]);
            }
        };

        // Customer 1: Invoice 500 TND (Cafe Central)
        $createEntry('Invoice INV-SEED-001 - Cafe Central', 'invoice', [
            ['account_id' => $receivableAccount->id, 'partner_id' => $partnerIds['CUST-001'], 'debit' => '500.000', 'credit' => '0', 'description' => 'Accounts receivable'],
            ['account_id' => $revenueAccount->id, 'debit' => '0', 'credit' => '467.290', 'description' => 'Sales revenue'],
            ['account_id' => $vatAccount->id, 'debit' => '0', 'credit' => '32.710', 'description' => 'VAT collected'],
        ]);

        // Customer 1: Partial payment 200 TND (leaves 300 outstanding)
        $createEntry('Payment PAY-SEED-001 - Cafe Central', 'payment', [
            ['account_id' => $cashAccount->id, 'debit' => '200.000', 'credit' => '0', 'description' => 'Cash received'],
            ['account_id' => $receivableAccount->id, 'partner_id' => $partnerIds['CUST-001'], 'debit' => '0', 'credit' => '200.000', 'description' => 'Receivable cleared'],
        ]);

        // Customer 2: Invoice 1200 TND (Restaurant Le Jardin)
        $createEntry('Invoice INV-SEED-002 - Restaurant Le Jardin', 'invoice', [
            ['account_id' => $receivableAccount->id, 'partner_id' => $partnerIds['CUST-002'], 'debit' => '1200.000', 'credit' => '0', 'description' => 'Accounts receivable'],
            ['account_id' => $revenueAccount->id, 'debit' => '0', 'credit' => '1121.495', 'description' => 'Sales revenue'],
            ['account_id' => $vatAccount->id, 'debit' => '0', 'credit' => '78.505', 'description' => 'VAT collected'],
        ]);

        // Customer 3: Invoice 800 TND, fully paid (Hotel Meridien)
        $createEntry('Invoice INV-SEED-003 - Hotel Meridien', 'invoice', [
            ['account_id' => $receivableAccount->id, 'partner_id' => $partnerIds['CUST-003'], 'debit' => '800.000', 'credit' => '0', 'description' => 'Accounts receivable'],
            ['account_id' => $revenueAccount->id, 'debit' => '0', 'credit' => '747.664', 'description' => 'Sales revenue'],
            ['account_id' => $vatAccount->id, 'debit' => '0', 'credit' => '52.336', 'description' => 'VAT collected'],
        ]);
        $createEntry('Payment PAY-SEED-002 - Hotel Meridien', 'payment', [
            ['account_id' => $cashAccount->id, 'debit' => '800.000', 'credit' => '0', 'description' => 'Cash received'],
            ['account_id' => $receivableAccount->id, 'partner_id' => $partnerIds['CUST-003'], 'debit' => '0', 'credit' => '800.000', 'description' => 'Receivable cleared'],
        ]);

        // Supplier: Purchase 2500 TND (Coffee Bean Wholesale)
        $createEntry('Purchase PO-SEED-001 - Coffee Bean Wholesale', 'purchase', [
            ['account_id' => $payableAccount->id, 'partner_id' => $partnerIds['SUPP-001'], 'debit' => '0', 'credit' => '2500.000', 'description' => 'Supplier payable'],
            ['account_id' => $revenueAccount->id, 'debit' => '2500.000', 'credit' => '0', 'description' => 'Purchase cost'],
        ]);

        // Refresh cached balances for all seeded partners
        /** @var PartnerBalanceService $balanceService */
        $balanceService = app(PartnerBalanceService::class);
        foreach ($partnerIds as $partnerId) {
            $balanceService->refreshPartnerBalance($companyId, $partnerId);
        }

        $this->command->info('Created 4 partners with GL entries and non-zero balances');
    }

    private function createTestUsers(): void
    {
        setPermissionsTeamId($this->tenant->id);

        // Owner
        $owner = User::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Ahmed Ben Ali',
            'email' => 'owner@cafe-tunis.tn',
            'password' => Hash::make('password'),
            'status' => 'active',
            'email_verified_at' => now(),
            'preferences' => [],
            'can_discount' => true,
            'max_discount_percent' => '25.00',
        ]);

        UserCompanyMembership::create([
            'user_id' => $owner->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Owner,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);

        $adminRole = Role::where('name', 'admin')->where('guard_name', 'sanctum')->first();
        if ($adminRole) {
            $owner->assignRole($adminRole);
        }
        $this->command->info('Owner: owner@cafe-tunis.tn');

        // Barista (cashier role)
        $barista = User::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Fatma Trabelsi',
            'email' => 'barista@cafe-tunis.tn',
            'password' => Hash::make('password'),
            'status' => 'active',
            'email_verified_at' => now(),
            'preferences' => [],
            'can_discount' => true,
            'max_discount_percent' => '25.00',
        ]);

        UserCompanyMembership::create([
            'user_id' => $barista->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Cashier,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);

        $cashierRole = Role::where('name', 'cashier')->where('guard_name', 'sanctum')->first();
        if ($cashierRole) {
            $barista->assignRole($cashierRole);
        }
        $this->command->info('Barista: barista@cafe-tunis.tn');
    }
}
