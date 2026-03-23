# Discount Permissions Configuration — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enable admin users to configure discount permissions (per-user and per-terminal), fix defaults so admins can discount out of the box, add manager PIN override in the POS desktop app.

**Architecture:** Hexagonal architecture. Backend changes in Identity module (user discount fields) and POS module (terminal settings, auth bypass). Web frontend adds user edit modal and terminal discount settings. POS desktop adds inline manager PIN override flow in discount modals.

**Tech Stack:** Laravel 12, PHP 8.2, PostgreSQL, PHPUnit | React 19, TypeScript, TanStack Query, Vitest | Tauri 2

**Spec:** `docs/superpowers/specs/2026-03-23-discount-permissions-config-design.md`

---

## Task 1: Migration — Fix Defaults for Existing Data

**Files:**
- Create: `apps/api/database/migrations/2026_03_23_200000_fix_discount_permission_defaults.php`

- [ ] **Step 1: Create migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite: simple UPDATE (no role join needed in test env)
            DB::table('users')->where('can_discount', false)->update(['can_discount' => true]);
            DB::table('pos_terminals')->where('max_discount_percent', '0.00')->update(['max_discount_percent' => '100.00']);
            return;
        }

        // Enable discounts for admin/super_admin users
        DB::statement("
            UPDATE users
            SET can_discount = true
            WHERE id IN (
                SELECT model_id FROM model_has_roles
                JOIN roles ON roles.id = model_has_roles.role_id
                WHERE roles.name IN ('super_admin', 'admin')
                AND model_has_roles.model_type = 'App\\\\Modules\\\\Identity\\\\Domain\\\\User'
            )
        ");

        // Fix terminal default: 0% → 100%
        DB::statement("
            UPDATE pos_terminals
            SET max_discount_percent = 100.00
            WHERE max_discount_percent = 0.00
        ");

        // Change column default for future terminals
        DB::statement("ALTER TABLE pos_terminals ALTER COLUMN max_discount_percent SET DEFAULT 100.00");
    }

    public function down(): void
    {
        // Not reversible — data migration
    }
};
```

- [ ] **Step 2: Verify migration detected**

Run: `cd apps/api && php artisan migrate:status | grep discount_permission_defaults`

- [ ] **Step 3: Run Pint + PHPStan**

Run: `cd apps/api && ./vendor/bin/pint && ./vendor/bin/phpstan analyse --memory-limit=512M`

- [ ] **Step 4: Commit**

```bash
git commit -m "fix(db): enable discount permissions for admin users, fix terminal default to 100%"
```

---

## Task 2: Backend — Super Admin Bypass in verify-pin + UserFactory

**Files:**
- Modify: `apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:37-47,92-100,123-133`
- Modify: `apps/api/database/factories/UserFactory.php`
- Test: `apps/api/tests/Feature/POS/PosAuthControllerTest.php` (create or extend)

- [ ] **Step 1: Write test for super admin bypass**

Create or extend a test that verifies: when a user with `super_admin` role has `can_discount=false` in DB, the verify-pin response still returns `can_discount=true` and `max_discount_percent=100`.

```php
public function test_verify_pin_grants_discount_to_admin(): void
{
    $user = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'can_discount' => false,
        'max_discount_percent' => null,
        'pos_pin' => Hash::make('1234'),
    ]);
    $user->assignRole('super_admin');

    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/pos/auth/verify-pin', ['pin' => '1234']);

    $response->assertOk();
    $response->assertJsonPath('data.can_discount', true);
    $response->assertJsonPath('data.max_discount_percent', 100.0);
}
```

Read existing POS auth tests to match the setup pattern (tenant, permissions seeder, etc.).

- [ ] **Step 2: Run test — should FAIL**

Run: `cd apps/api && php artisan test --filter=test_verify_pin_grants_discount_to_admin`

- [ ] **Step 3: Implement super admin bypass in PosAuthController**

In `verifyPin` (line 44-46), after finding the matching user, before returning:

```php
// Admin bypass: admins always have full discount capability
$isAdmin = $user->hasRole(['super_admin', 'admin']);
$canDiscount = $isAdmin || $user->can_discount;
$maxDiscountPercent = $isAdmin ? 100.0 : $user->max_discount_percent;
```

Then in the response:
```php
'can_discount' => $canDiscount,
'max_discount_percent' => $maxDiscountPercent,
```

Apply the same pattern to `setupPin` (lines 97-98) and `pinData` (lines 130-131).

- [ ] **Step 4: Update UserFactory**

Add `can_discount => true` to the factory definition array.

- [ ] **Step 5: Run test — should PASS**

Run: `cd apps/api && php artisan test --filter=test_verify_pin_grants_discount_to_admin`

- [ ] **Step 6: Run full test suite**

Run: `cd apps/api && php artisan test`

- [ ] **Step 7: Commit**

```bash
git commit -m "feat(pos): super admin bypass for discount permissions in verify-pin + factory default"
```

---

## Task 3: Backend — Extend User Update API for Discount Fields

**Files:**
- Modify: `apps/api/app/Modules/Identity/Application/DTOs/UserData.php`
- Modify: `apps/api/app/Modules/Identity/Presentation/Requests/UpdateUserRequest.php`
- Modify: `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:216`
- Test: `apps/api/tests/Feature/Identity/UserControllerTest.php` (extend)

- [ ] **Step 1: Write test for updating discount fields**

```php
public function test_update_user_discount_permissions(): void
{
    $user = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'can_discount' => false,
        'max_discount_percent' => null,
    ]);

    $response = $this->actingAs($this->admin)
        ->patchJson("/api/v1/users/{$user->id}", [
            'can_discount' => true,
            'max_discount_percent' => 25.00,
        ]);

    $response->assertOk();
    $response->assertJsonPath('data.can_discount', true);
    $response->assertJsonPath('data.max_discount_percent', 25.0);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'can_discount' => true,
    ]);
}
```

Read existing `UserControllerTest` to match setup patterns.

- [ ] **Step 2: Run test — should FAIL**

- [ ] **Step 3: Extend UserData DTO**

Add two fields to the constructor:
```php
public bool $canDiscount,
public ?float $maxDiscountPercent,
```

Add to `fromUser()`:
```php
canDiscount: $user->can_discount,
maxDiscountPercent: $user->max_discount_percent,
```

- [ ] **Step 4: Extend UpdateUserRequest**

Add to the `rules()` array:
```php
'can_discount' => ['sometimes', 'boolean'],
'max_discount_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
```

- [ ] **Step 5: Extend UserController.update()**

Add `'can_discount', 'max_discount_percent'` to the `$fieldsToUpdate` array (line 216).

- [ ] **Step 6: Run test — should PASS**

- [ ] **Step 7: Run full test suite + PHPStan**

Run: `cd apps/api && php artisan test && ./vendor/bin/phpstan analyse --memory-limit=512M`

- [ ] **Step 8: Commit**

```bash
git commit -m "feat(identity): extend user update API with can_discount and max_discount_percent fields"
```

---

## Task 4: Backend — Extend Terminal Update API for Discount Settings

**Files:**
- Modify: `apps/api/app/Modules/POS/Presentation/Requests/UpdateTerminalRequest.php`
- Modify: `apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php`
- Test: `apps/api/tests/Feature/POS/TerminalControllerTest.php` (extend)

- [ ] **Step 1: Write test for terminal discount settings**

```php
public function test_update_terminal_discount_settings(): void
{
    $response = $this->actingAs($this->admin)
        ->patchJson("/api/v1/pos/terminals/{$this->terminal->id}", [
            'max_discount_percent' => 50.00,
            'allow_line_discounts' => true,
            'allow_transaction_discounts' => false,
        ]);

    $response->assertOk();
    $response->assertJsonPath('data.max_discount_percent', 50.0);
    $response->assertJsonPath('data.allow_line_discounts', true);
    $response->assertJsonPath('data.allow_transaction_discounts', false);
}
```

- [ ] **Step 2: Run test — should FAIL**

- [ ] **Step 3: Extend UpdateTerminalRequest**

Add to `rules()`:
```php
'max_discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
'allow_line_discounts' => ['sometimes', 'boolean'],
'allow_transaction_discounts' => ['sometimes', 'boolean'],
```

- [ ] **Step 4: Extend TerminalResource**

Add to `toArray()`:
```php
'max_discount_percent' => (float) $this->max_discount_percent,
'allow_line_discounts' => (bool) $this->allow_line_discounts,
'allow_transaction_discounts' => (bool) $this->allow_transaction_discounts,
```

- [ ] **Step 5: Run test — should PASS**

- [ ] **Step 6: Commit**

```bash
git commit -m "feat(pos): extend terminal API with discount settings (max_percent, allow_line, allow_transaction)"
```

---

## Task 5: Web Frontend — User Edit Modal with Discount Fields

**Files:**
- Modify: `apps/web/src/features/users/types.ts` (add discount fields)
- Modify: `apps/web/src/features/users/api/users.ts` (add updateUser function)
- Create: `apps/web/src/features/settings/components/UserEditModal.tsx`
- Modify: `apps/web/src/features/settings/UsersPage.tsx` (add edit action + modal)
- Modify: `apps/web/src/locales/en/settings.json` (add i18n keys)
- Modify: `apps/web/src/locales/fr/settings.json` (add i18n keys)

- [ ] **Step 1: Extend User types**

In `apps/web/src/features/users/types.ts`, add to `User` interface:
```typescript
canDiscount?: boolean
maxDiscountPercent?: number | null
```

Note: the backend DTO uses camelCase (`canDiscount`), so the JSON key will be `can_discount` from Laravel's snake_case default — check how other fields are cased and match.

Actually, read the existing `UsersPage.tsx` local `User` interface (line 23-32) — it may have its own type that also needs updating. Update BOTH.

- [ ] **Step 2: Add updateUser API function**

In `apps/web/src/features/users/api/users.ts`:
```typescript
export async function updateUser(id: string, data: Partial<{
  name: string;
  email: string;
  phone: string | null;
  role: string;
  can_discount: boolean;
  max_discount_percent: number | null;
}>): Promise<User> {
  const response = await api.patch<{ data: User }>(`/users/${id}`, data);
  return response.data.data;
}
```

- [ ] **Step 3: Create UserEditModal component**

Create `apps/web/src/features/settings/components/UserEditModal.tsx`:

A modal with:
- Name, Email, Phone, Role fields (pre-populated from user data)
- **POS Discount Settings** section:
  - Toggle: "Can apply discounts" (`can_discount`)
  - Number input: "Maximum discount %" (`max_discount_percent`) — shown only when toggle is on
- Save button calls `updateUser()` with the changed fields
- Uses `useMutation` from TanStack Query
- All labels via `t()` keys

Read existing modals in the settings area to match the pattern (e.g., AddUserModal if it exists, or any other modal in the features).

- [ ] **Step 4: Add edit action to UsersPage**

Add an "Edit" button/icon to each user row that opens the `UserEditModal`.

- [ ] **Step 5: Add i18n keys**

In both en and fr settings.json:
```json
"userEdit": {
  "title": "Edit User",
  "discountSection": "POS Discount Settings",
  "canDiscount": "Can apply discounts",
  "maxDiscountPercent": "Maximum discount percentage",
  "maxDiscountHelp": "Leave empty for no limit (terminal limit applies)",
  "save": "Save Changes",
  "saving": "Saving...",
  "success": "User updated successfully"
}
```

French:
```json
"userEdit": {
  "title": "Modifier l'utilisateur",
  "discountSection": "Paramètres de remise POS",
  "canDiscount": "Peut appliquer des remises",
  "maxDiscountPercent": "Pourcentage maximum de remise",
  "maxDiscountHelp": "Laisser vide pour aucune limite (la limite du terminal s'applique)",
  "save": "Enregistrer",
  "saving": "Enregistrement...",
  "success": "Utilisateur modifié avec succès"
}
```

- [ ] **Step 6: Run typecheck**

Run: `cd apps/web && pnpm typecheck`

- [ ] **Step 7: Commit**

```bash
git commit -m "feat(web): add user edit modal with discount permission fields"
```

---

## Task 6: Web Frontend — Terminal Discount Settings

**Files:**
- Modify: existing terminal settings page or create section in settings
- Modify: `apps/web/src/locales/en/settings.json`
- Modify: `apps/web/src/locales/fr/settings.json`

- [ ] **Step 1: Find existing terminal management**

Search for terminal edit/settings pages in `apps/web/src/features/pos/` or `apps/web/src/features/settings/`. Read the route definitions to find where terminals are managed.

- [ ] **Step 2: Add discount settings fields**

Add to the terminal edit form/page:
- `max_discount_percent` — number input (0-100)
- `allow_line_discounts` — toggle
- `allow_transaction_discounts` — toggle

These should be in a "Discount Settings" section.

- [ ] **Step 3: Add i18n keys**

```json
"terminalDiscount": {
  "title": "Discount Settings",
  "maxPercent": "Maximum discount percentage",
  "allowLine": "Allow line item discounts",
  "allowTransaction": "Allow transaction discounts"
}
```

- [ ] **Step 4: Run typecheck**

Run: `cd apps/web && pnpm typecheck`

- [ ] **Step 5: Commit**

```bash
git commit -m "feat(web): add terminal discount settings to terminal management"
```

---

## Task 7: POS Desktop — Manager PIN Override in Discount Modals

**Files:**
- Modify: `apps/pos/src/components/organisms/DiscountModal/DiscountModal.tsx`
- Modify: `apps/pos/src/components/organisms/LineDiscountModal/LineDiscountModal.tsx`
- Modify: `apps/pos/src/pages/HomePage.tsx` (change discount guard to pass control to modal)
- Modify: `apps/pos/src/locales/en/pos.json`
- Modify: `apps/pos/src/locales/fr/pos.json`

The flow: instead of blocking discounts outright, the modal shows a manager PIN prompt when the cashier lacks permission or exceeds their limit.

- [ ] **Step 1: Add manager PIN state to DiscountModal**

Add new state and props:

```typescript
interface DiscountModalProps {
  // ...existing props...
  canDiscount: boolean;          // NEW: from operator store
  onVerifyManagerPin: (pin: string) => Promise<{ canDiscount: boolean; maxDiscountPercent: number | null } | null>;  // NEW
}
```

Add state:
```typescript
const [needsApproval, setNeedsApproval] = useState(false);
const [managerPin, setManagerPin] = useState('');
const [managerError, setManagerError] = useState<string | null>(null);
const [verifyingPin, setVerifyingPin] = useState(false);
const [approvedBy, setApprovedBy] = useState<{ canDiscount: boolean; maxDiscountPercent: number | null } | null>(null);
```

When the user presses Apply:
- If `canDiscount` is true (or `approvedBy` is set) AND value is within limit → apply normally
- If `canDiscount` is false OR value exceeds limit → switch to manager PIN mode (`setNeedsApproval(true)`)
- In manager PIN mode, the numpad switches to PIN entry. On submit, call `onVerifyManagerPin(pin)`. If the returned manager has sufficient permissions, set `approvedBy` and apply the discount.

- [ ] **Step 2: Implement the PIN verification handler in HomePage**

```typescript
const handleVerifyManagerPin = useCallback(async (pin: string) => {
  try {
    const response = await api.post('/pos/auth/verify-pin', { pin });
    const data = response.data.data;
    return {
      canDiscount: data.can_discount,
      maxDiscountPercent: data.max_discount_percent,
    };
  } catch {
    return null;
  }
}, []);
```

Pass it to both DiscountModal and LineDiscountModal.

- [ ] **Step 3: Remove the error toast guard from HomePage**

The discount button handlers should no longer show the "not allowed" error. Instead, always open the modal — the modal itself handles the permission check and manager PIN flow.

Change `onDiscount` handler: remove the `if (!canDiscount)` guard. Always open the modal, passing `canDiscount` and `maxDiscountPct` as props.

Same for `handleLineDiscount`.

- [ ] **Step 4: Apply same pattern to LineDiscountModal**

Same props and state additions as DiscountModal.

- [ ] **Step 5: Add i18n keys**

```json
"discount": {
  ...existing keys...,
  "managerApproval": "Manager Approval Required",
  "enterManagerPin": "Enter manager PIN to authorize this discount",
  "verifyingPin": "Verifying...",
  "managerDenied": "Manager does not have sufficient discount permission",
  "invalidPin": "Invalid PIN",
  "approvedBy": "Approved by {{name}}"
}
```

French:
```json
"discount": {
  ...existing keys...,
  "managerApproval": "Approbation du responsable requise",
  "enterManagerPin": "Entrez le PIN du responsable pour autoriser cette remise",
  "verifyingPin": "Vérification...",
  "managerDenied": "Le responsable n'a pas une autorisation de remise suffisante",
  "invalidPin": "PIN invalide",
  "approvedBy": "Approuvé par {{name}}"
}
```

- [ ] **Step 6: Run checks**

Run: `cd apps/pos && pnpm typecheck && pnpm vitest run`

- [ ] **Step 7: Commit**

```bash
git commit -m "feat(pos): inline manager PIN override for discount authorization in modals"
```

---

## Task Dependencies

```
Task 1 (migration) ── do first, unblocks everything
Task 2 (super admin bypass) ── depends on Task 1
Task 3 (user update API) ── independent of Task 2
Task 4 (terminal update API) ── independent
Task 5 (user edit modal) ── depends on Task 3
Task 6 (terminal settings UI) ── depends on Task 4
Task 7 (manager PIN override) ── depends on Task 2 (needs verify-pin to return correct data)
```

Tasks 3+4 can run in parallel. Tasks 5+6 can run in parallel. Task 7 is last.
