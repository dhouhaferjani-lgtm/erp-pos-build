# F&B Boss - Phase 4: Loyalty Integration

**Phase:** 4 of 4
**Duration:** 1 week (40 hours)
**Status:** Planning
**Dependencies:** Phase 3 complete + Core Loyalty Module complete

---

## Phase Overview

### Goals

1. Register MenuItem as a loyaltyable entity
2. Configure stamp card programs for coffee shops
3. Enable points earning on menu item purchases
4. Support reward redemption in F&B POS
5. Display loyalty balance in POS cart
6. Print loyalty info on receipts

### Deliverables

- [ ] MenuItem implements LoyaltyableContract
- [ ] Loyalty service provider registration
- [ ] Stamp card configuration for common F&B programs
- [ ] POS cart integration with loyalty balance
- [ ] Reward redemption flow in POS
- [ ] Receipt template with loyalty details
- [ ] 20+ integration tests

---

## Loyaltyable Entity Registration

### MenuItem Implements LoyaltyableContract

**File:** `app/Modules/Catalog/Domain/Entities/MenuItem.php`

```php
<?php

namespace App\Modules\Catalog\Domain\Entities;

use App\Shared\Contracts\LoyaltyableContract;
use App\Shared\Contracts\LoyaltyableCategoryContract;
use Money\Money;

class MenuItem implements LoyaltyableContract
{
    // ... existing code

    public function getLoyaltyableId(): string
    {
        return $this->id->toString();
    }

    public function getLoyaltyableType(): string
    {
        return 'menu_item';
    }

    public function getLoyaltyableCategory(): ?LoyaltyableCategoryContract
    {
        return $this->category;
    }

    public function getLoyaltyablePrice(): Money
    {
        return $this->basePrice;
    }

    public function getLoyaltyableName(): string
    {
        return $this->name;
    }

    public function isEligibleForLoyalty(): bool
    {
        // Could exclude certain categories or items
        return $this->isActive;
    }
}
```

---

### MenuCategory Implements LoyaltyableCategoryContract

**File:** `app/Modules/Catalog/Domain/Entities/MenuCategory.php`

```php
<?php

namespace App\Modules\Catalog\Domain\Entities;

use App\Shared\Contracts\LoyaltyableCategoryContract;

class MenuCategory implements LoyaltyableCategoryContract
{
    // ... existing code

    public function getCategoryId(): string
    {
        return $this->id->toString();
    }

    public function getCategoryName(): string
    {
        return $this->name;
    }

    public function getCategoryType(): string
    {
        return 'menu_category';
    }
}
```

---

### Register with Loyalty System

**File:** `app/Modules/Catalog/Providers/CatalogServiceProvider.php`

```php
<?php

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Domain\Entities\MenuItem;
use App\Modules\Catalog\Domain\Entities\MenuCategory;
use App\Modules\Loyalty\Infrastructure\LoyaltyRegistry;
use Illuminate\Support\ServiceProvider;

class CatalogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Register Menu Items as loyaltyable entities
        LoyaltyRegistry::register('menu_item', MenuItem::class);
        LoyaltyRegistry::register('menu_category', MenuCategory::class);
    }
}
```

---

## Loyalty Program Templates

### Coffee Shop Stamp Card

**Configuration:** Pre-built template for "Buy 10, Get 1 Free"

```php
<?php

namespace App\Modules\Loyalty\Application\Services;

use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Entities\StampCardDefinition;
use App\Modules\Loyalty\Domain\Entities\EarningRule;

class LoyaltyProgramTemplateService
{
    public function createCoffeeStampCard(Company $company): LoyaltyProgram
    {
        $program = LoyaltyProgram::create(
            tenant: $company->getTenant(),
            company: $company,
            name: 'Coffee Loyalty Card',
            type: ProgramType::STAMP_CARD,
            isActive: true
        );

        // Define stamp card
        $stampCard = StampCardDefinition::create(
            program: $program,
            name: 'Buy 10, Get 1 Free',
            stampsRequired: 10,
            rewardMenuItem: null, // Can be configured later
            rewardDescription: '1 Free Coffee (any size up to Large)'
        );

        // Earning rule: 1 stamp per qualifying coffee purchase
        $earningRule = EarningRule::create(
            program: $program,
            name: 'Coffee Purchase Stamp',
            type: EarningRuleType::PER_QUALIFYING_ITEM,
            loyaltyableType: 'menu_item',
            categoryFilter: ['hot_drinks', 'cold_drinks'], // Category IDs
            pointsPerUnit: 1, // 1 stamp
            minimumPurchase: null
        );

        return $program;
    }

    public function createPointsBasedProgram(Company $company): LoyaltyProgram
    {
        $program = LoyaltyProgram::create(
            tenant: $company->getTenant(),
            company: $company,
            name: 'Café Rewards',
            type: ProgramType::POINTS_BASED,
            isActive: true
        );

        // Earning rule: 1 point per 1 TND spent
        EarningRule::create(
            program: $program,
            name: 'Spend Points',
            type: EarningRuleType::PER_CURRENCY_SPENT,
            pointsPerUnit: 1,
            minimumPurchase: Money::TND(0)
        );

        // Reward: Free pastry at 50 points
        Reward::create(
            program: $program,
            name: 'Free Pastry',
            pointsCost: 50,
            rewardType: RewardType::FREE_ITEM,
            loyaltyableType: 'menu_item',
            categoryFilter: ['pastries']
        );

        // Reward: 10% discount at 100 points
        Reward::create(
            program: $program,
            name: '10% Off',
            pointsCost: 100,
            rewardType: RewardType::PERCENTAGE_DISCOUNT,
            discountPercentage: 10.0
        );

        return $program;
    }
}
```

---

## POS Integration

### Loyalty Customer Lookup

**Component:** `apps/web/src/features/pos/components/fnb/LoyaltyCustomerLookup.tsx`

```tsx
interface LoyaltyCustomerLookupProps {
  onCustomerSelected: (member: LoyaltyMember) => void
}

export function LoyaltyCustomerLookup({ onCustomerSelected }: Props) {
  const { t } = useTranslation(['loyalty'])
  const [phoneNumber, setPhoneNumber] = useState('')
  const [isSearching, setIsSearching] = useState(false)

  const searchMutation = useMutation({
    mutationFn: (phone: string) => loyaltyApi.findMemberByPhone(phone),
    onSuccess: (member) => {
      if (member) {
        onCustomerSelected(member)
      } else {
        // Prompt to create new member
        if (confirm(t('loyalty.createNewMember'))) {
          createMemberMutation.mutate({ phoneNumber })
        }
      }
    },
  })

  const createMemberMutation = useMutation({
    mutationFn: (data: CreateMemberInput) => loyaltyApi.createMember(data),
    onSuccess: (member) => {
      onCustomerSelected(member)
      toast.success(t('loyalty.memberCreated'))
    },
  })

  const handleSearch = () => {
    if (phoneNumber.length >= 8) {
      searchMutation.mutate(phoneNumber)
    }
  }

  return (
    <div className="flex gap-2 items-center">
      <Label htmlFor="phone" className="whitespace-nowrap">
        {t('loyalty.customerPhone')}:
      </Label>
      <Input
        id="phone"
        type="tel"
        placeholder="12345678"
        value={phoneNumber}
        onChange={(e) => setPhoneNumber(e.target.value)}
        onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
        className="flex-1"
      />
      <Button
        onClick={handleSearch}
        disabled={phoneNumber.length < 8 || searchMutation.isPending}
      >
        {t('common.search')}
      </Button>
    </div>
  )
}
```

---

### Loyalty Balance Display

**Component:** `apps/web/src/features/pos/components/fnb/LoyaltyBalanceCard.tsx`

```tsx
interface LoyaltyBalanceCardProps {
  member: LoyaltyMember
  program: LoyaltyProgram
}

export function LoyaltyBalanceCard({ member, program }: Props) {
  const { t } = useTranslation(['loyalty'])

  if (program.type === 'STAMP_CARD') {
    const enrollment = member.enrollments.find(e => e.programId === program.id)
    const stampCard = enrollment?.stampCard

    if (!stampCard) return null

    return (
      <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
        <div className="flex items-center justify-between mb-2">
          <h3 className="font-medium">{program.name}</h3>
          <Badge variant="secondary">
            {stampCard.currentStamps} / {stampCard.stampsRequired}
          </Badge>
        </div>

        {/* Visual stamp card */}
        <div className="grid grid-cols-5 gap-2">
          {Array.from({ length: stampCard.stampsRequired }, (_, i) => (
            <div
              key={i}
              className={cn(
                "w-10 h-10 rounded-full flex items-center justify-center",
                i < stampCard.currentStamps
                  ? "bg-blue-600 text-white"
                  : "bg-gray-200"
              )}
            >
              {i < stampCard.currentStamps ? (
                <Check className="w-5 h-5" />
              ) : (
                <Coffee className="w-5 h-5 text-gray-400" />
              )}
            </div>
          ))}
        </div>

        {stampCard.isComplete && (
          <Alert variant="success" className="mt-3">
            <Gift className="h-4 w-4" />
            <AlertDescription>
              {t('loyalty.stampCardComplete')}
            </AlertDescription>
          </Alert>
        )}
      </div>
    )
  }

  // Points-based program
  const enrollment = member.enrollments.find(e => e.programId === program.id)
  const availableRewards = program.rewards.filter(
    r => r.pointsCost <= enrollment.pointsBalance
  )

  return (
    <div className="bg-green-50 border border-green-200 rounded-lg p-4">
      <div className="flex items-center justify-between mb-2">
        <h3 className="font-medium">{program.name}</h3>
        <div className="text-2xl font-bold text-green-600">
          {enrollment.pointsBalance} pts
        </div>
      </div>

      {availableRewards.length > 0 && (
        <div className="mt-3">
          <p className="text-sm text-gray-600 mb-2">
            {t('loyalty.availableRewards')}:
          </p>
          <div className="space-y-1">
            {availableRewards.map(reward => (
              <div
                key={reward.id}
                className="flex items-center justify-between text-sm"
              >
                <span>{reward.name}</span>
                <Badge variant="outline">{reward.pointsCost} pts</Badge>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
```

---

### Reward Redemption in Cart

**Component:** Updated `FnBCartPanel.tsx`

```tsx
export function FnBCartPanel({ cart, loyaltyMember, onRedeemReward }: Props) {
  const { t } = useTranslation(['loyalty', 'fnb'])
  const [selectedReward, setSelectedReward] = useState<Reward | null>(null)

  const availableRewards = loyaltyMember
    ? getAvailableRewards(loyaltyMember, cart.total)
    : []

  const handleRedeemReward = () => {
    if (selectedReward) {
      onRedeemReward(selectedReward)
      setSelectedReward(null)
    }
  }

  return (
    <div className="flex flex-col h-full">
      {/* Existing cart items */}
      <div className="flex-1 overflow-y-auto">
        {cart.lines.map(line => (
          <CartLineItem key={line.id} line={line} ... />
        ))}
      </div>

      {/* Loyalty section */}
      {loyaltyMember && (
        <div className="border-t pt-3">
          <LoyaltyBalanceCard
            member={loyaltyMember}
            program={loyaltyMember.program}
          />

          {availableRewards.length > 0 && (
            <div className="mt-3">
              <Select
                value={selectedReward?.id}
                onValueChange={(id) =>
                  setSelectedReward(availableRewards.find(r => r.id === id))
                }
              >
                <SelectTrigger>
                  <SelectValue placeholder={t('loyalty.selectReward')} />
                </SelectTrigger>
                <SelectContent>
                  {availableRewards.map(reward => (
                    <SelectItem key={reward.id} value={reward.id}>
                      {reward.name} ({reward.pointsCost} pts)
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>

              {selectedReward && (
                <Button
                  className="w-full mt-2"
                  variant="outline"
                  onClick={handleRedeemReward}
                >
                  {t('loyalty.redeemReward')}
                </Button>
              )}
            </div>
          )}
        </div>
      )}

      {/* Cart totals and actions */}
      <div className="border-t pt-3 mt-auto">
        <CartTotals cart={cart} />
        <CartActions cart={cart} />
      </div>
    </div>
  )
}
```

---

## Receipt Integration

### Receipt Template with Loyalty

**Backend:** Extend receipt data to include loyalty info

```php
public function generateReceipt(Receipt $receipt, ?LoyaltyMember $member): array
{
    $data = [
        // ... existing receipt fields
    ];

    if ($member && $receipt->getLoyaltyTransactionId()) {
        $transaction = $this->loyaltyRepo->findTransactionById(
            $receipt->getLoyaltyTransactionId()
        );

        $data['loyalty'] = [
            'member_name' => $member->getName(),
            'member_phone' => $member->getPhoneNumber(),
            'program_name' => $member->getProgram()->getName(),
            'points_earned' => $transaction->getPointsEarned(),
            'stamps_earned' => $transaction->getStampsEarned(),
            'new_balance' => $transaction->getNewBalance(),
            'rewards_redeemed' => $transaction->getRewardsRedeemed(),
        ];
    }

    return $data;
}
```

---

### Frontend Receipt Template

```tsx
export function ReceiptTemplate({ receipt }: Props) {
  return (
    <div className="receipt">
      {/* ... existing receipt content */}

      {receipt.loyalty && (
        <div className="loyalty-section mt-4 pt-4 border-t">
          <h3 className="font-bold text-center">
            {receipt.loyalty.programName}
          </h3>

          <div className="mt-2 text-sm">
            <p>Member: {receipt.loyalty.memberName}</p>

            {receipt.loyalty.pointsEarned > 0 && (
              <p className="text-green-600">
                ✓ Points Earned: +{receipt.loyalty.pointsEarned}
              </p>
            )}

            {receipt.loyalty.stampsEarned > 0 && (
              <p className="text-green-600">
                ✓ Stamps Earned: +{receipt.loyalty.stampsEarned}
              </p>
            )}

            <p className="font-medium">
              New Balance: {receipt.loyalty.newBalance} {receipt.loyalty.programType === 'POINTS' ? 'points' : 'stamps'}
            </p>

            {receipt.loyalty.rewardsRedeemed?.length > 0 && (
              <div className="mt-2">
                <p className="font-medium">Rewards Redeemed:</p>
                <ul>
                  {receipt.loyalty.rewardsRedeemed.map(reward => (
                    <li key={reward.id}>- {reward.name}</li>
                  ))}
                </ul>
              </div>
            )}
          </div>

          <p className="text-center text-xs mt-3">
            Thank you for your loyalty! ❤️
          </p>
        </div>
      )}
    </div>
  )
}
```

---

## Loyalty Transaction Recording

### Service Integration

**File:** `app/Modules/POS/Application/Services/ReceiptCreationService.php`

```php
public function createReceipt(CreateReceiptCommand $command): Receipt
{
    return DB::transaction(function () use ($command) {
        // 1. Create receipt
        $receipt = $this->receiptRepo->create($command->toReceiptData());

        // 2. Record loyalty transaction (if customer enrolled)
        if ($command->getLoyaltyMemberId()) {
            $loyaltyTransaction = $this->loyaltyService->recordTransaction(
                memberId: $command->getLoyaltyMemberId(),
                transactionType: 'PURCHASE',
                amount: $receipt->getTotal(),
                items: $this->mapReceiptLinesToLoyaltyItems($receipt->getLines()),
                rewardsRedeemed: $command->getRedeemedRewards(),
                referenceType: 'pos_receipt',
                referenceId: $receipt->getId()
            );

            // Link loyalty transaction to receipt
            $receipt->setLoyaltyTransactionId($loyaltyTransaction->getId());
            $this->receiptRepo->update($receipt);
        }

        // 3. Inventory deduction (if enabled)
        // ... existing logic

        return $receipt;
    });
}

private function mapReceiptLinesToLoyaltyItems(array $lines): array
{
    return array_map(function ($line) {
        return [
            'loyaltyable_type' => $line->isMenuItem() ? 'menu_item' : 'product',
            'loyaltyable_id' => $line->getMenuItemId() ?? $line->getProductId(),
            'quantity' => $line->getQuantity(),
            'amount' => $line->getTotalPrice(),
        ];
    }, $lines);
}
```

---

## Testing Strategy

### Integration Tests

**Example: Loyalty transaction recording**

```php
it('records loyalty transaction when customer enrolled', function () {
    // Arrange
    $member = createLoyaltyMember(['program_type' => 'POINTS']);
    $menuItem = createMenuItem(['base_price' => Money::TND(5)]);

    // Act
    $receipt = $this->receiptService->createReceipt(
        CreateReceiptCommand::make([
            'loyalty_member_id' => $member->getId(),
            'lines' => [
                ['menu_item_id' => $menuItem->getId(), 'quantity' => 2],
            ],
        ])
    );

    // Assert
    expect($receipt->getLoyaltyTransactionId())->not->toBeNull();

    $transaction = $this->loyaltyRepo->findTransactionById(
        $receipt->getLoyaltyTransactionId()
    );

    expect($transaction->getPointsEarned())->toBe(10); // 2 × €5 = 10 points
    expect($transaction->getMemberId())->toBe($member->getId());
});

it('applies stamp to coffee purchase', function () {
    // Arrange
    $program = createStampCardProgram(['stamps_required' => 10]);
    $member = enrollMemberInProgram($program);
    $coffee = createMenuItem(['category' => 'hot_drinks']);

    // Act
    $receipt = $this->receiptService->createReceipt(
        CreateReceiptCommand::make([
            'loyalty_member_id' => $member->getId(),
            'lines' => [['menu_item_id' => $coffee->getId(), 'quantity' => 1]],
        ])
    );

    // Assert
    $member->refresh();
    $enrollment = $member->getEnrollment($program->getId());
    expect($enrollment->getStampCard()->getCurrentStamps())->toBe(1);
});

it('redeems reward and deducts points', function () {
    // Arrange
    $program = createPointsProgram();
    $reward = createReward(['points_cost' => 50, 'program_id' => $program->getId()]);
    $member = enrollMemberInProgram($program, ['points_balance' => 100]);

    // Act
    $receipt = $this->receiptService->createReceipt(
        CreateReceiptCommand::make([
            'loyalty_member_id' => $member->getId(),
            'redeemed_rewards' => [$reward->getId()],
            'lines' => [/* ... */],
        ])
    );

    // Assert
    $member->refresh();
    expect($member->getEnrollment($program->getId())->getPointsBalance())->toBe(50);
});
```

---

## Phase 4 Completion Criteria

- [ ] MenuItem and MenuCategory registered as loyaltyable entities
- [ ] Coffee stamp card program template working
- [ ] Points-based program template working
- [ ] POS cart shows loyalty balance
- [ ] Rewards can be redeemed during checkout
- [ ] Receipts display loyalty transaction details
- [ ] All integration tests passing
- [ ] No regression in POS performance

---

## Production Rollout

### Pilot Phase

1. **Select 2-3 pilot cafés** with different loyalty needs:
   - Café A: Stamp card only
   - Café B: Points-based with tiered rewards
   - Café C: Both stamp cards and points

2. **Train staff** on:
   - Customer lookup by phone
   - Enrolling new members
   - Redeeming rewards
   - Explaining program to customers

3. **Monitor for 2 weeks:**
   - Loyalty transaction accuracy
   - Member enrollment rate
   - Reward redemption rate
   - User feedback

### General Availability

After successful pilot:
- Enable for all F&B vertical tenants
- Provide self-service program configuration
- Create video tutorials
- Add loyalty analytics dashboard

---

*Phase 4 estimated completion: End of Week 7*

---

## F&B Boss MVP Complete!

After Phase 4, the F&B Boss vertical is feature-complete and ready for production deployment. 🎉

**Next Steps:**
- Marketing materials
- Customer onboarding process
- Ongoing feature enhancements based on user feedback
