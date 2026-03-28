# User Management Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix 4 blocking user management bugs: clipped action menu, 500 on user creation, email required for cashiers, and PIN reset UI accessibility.

**Architecture:** Fixes span frontend (UsersPage.tsx overflow, conditional email field) and backend (async notification, nullable email migration, conditional validation). Each fix is independent and commits separately.

**Tech Stack:** Laravel 12 / PHP 8.2, React 19 / TypeScript, PostgreSQL 16, TanStack Query 5

---

## File Map

| File | Action | Purpose |
|------|--------|---------|
| `apps/web/src/features/settings/UsersPage.tsx` | Modify | Fix overflow-hidden, make email conditional on role |
| `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php` | Modify | Move notification outside transaction, handle cashier email |
| `apps/api/app/Modules/Identity/Presentation/Requests/CreateUserRequest.php` | Modify | Make email conditionally required (not for cashier role) |
| `apps/api/app/Modules/Identity/Application/Notifications/UserInvitation.php` | Modify | Implement ShouldQueue |
| `apps/api/database/migrations/2026_03_23_000001_make_user_email_nullable.php` | Create | Make email column nullable, update unique constraint |
| `apps/api/tests/Feature/Identity/UserManagement/CreateUserTest.php` | Modify | Add tests for cashier without email |
| `apps/web/src/locales/en/common.json` | Modify | Add translation keys for email-optional hint |
| `apps/web/src/locales/fr/common.json` | Modify | Add French translation keys |

---

### Task 1: Fix Action Menu Clipping (Bug 1)

**Files:**
- Modify: `apps/web/src/features/settings/UsersPage.tsx:365`

- [ ] **Step 1: Change overflow-hidden to overflow-visible on table container**

At line 365, change:
```tsx
<div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
```
to:
```tsx
<div className="rounded-lg border border-gray-200 bg-white">
```

The `rounded-lg` with `border` still looks correct without `overflow-hidden`. The dropdown at line 453 already has `z-10` positioning.

- [ ] **Step 2: Verify the action menu dropdown renders outside the table bounds**

Run: `cd apps/web && pnpm typecheck`
Expected: PASS (no type errors)

- [ ] **Step 3: Commit**

```bash
git add apps/web/src/features/settings/UsersPage.tsx
git commit -m "fix(users): remove overflow-hidden that clips action menu dropdown"
```

---

### Task 2: Fix 500 Error on User Creation (Bug 2)

**Files:**
- Modify: `apps/api/app/Modules/Identity/Application/Notifications/UserInvitation.php`
- Modify: `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:134-186`

- [ ] **Step 1: Make UserInvitation implement ShouldQueue**

In `apps/api/app/Modules/Identity/Application/Notifications/UserInvitation.php`, add `ShouldQueue` interface:

```php
use Illuminate\Contracts\Queue\ShouldQueue;

class UserInvitation extends Notification implements ShouldQueue
{
    use Queueable;
    // ... rest unchanged
}
```

This ensures the email is dispatched to the queue instead of sent synchronously. If the queue worker isn't running, it stays in the queue — user creation still succeeds.

- [ ] **Step 2: Move notification outside the DB transaction in UserController::store**

In `UserController.php`, restructure the `store` method so the notification is sent AFTER the transaction commits:

```php
public function store(CreateUserRequest $request): JsonResponse
{
    /** @var User $currentUser */
    $currentUser = $request->user();
    $validated = $request->validated();

    /** @var Tenant $tenant */
    $tenant = Tenant::findOrFail($currentUser->tenant_id);

    $user = DB::transaction(function () use ($validated, $currentUser, $request) {
        $tempPassword = Str::random(32);

        $user = User::create([
            'tenant_id' => $currentUser->tenant_id,
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($tempPassword),
            'status' => UserStatus::PendingVerification,
            'locale' => $validated['locale'] ?? null,
            'timezone' => $validated['timezone'] ?? null,
        ]);

        setPermissionsTeamId($currentUser->tenant_id);
        $user->assignRole($validated['role']);

        $this->logAuditEvent(
            eventType: 'user.created',
            aggregateId: $user->id,
            userId: $currentUser->id,
            companyId: $request->header('X-Company-Id'),
            payload: [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $validated['role'],
            ]
        );

        return $user;
    });

    // Send invitation AFTER transaction commits, only if user has email
    if ($user->email !== null) {
        try {
            $user->notify(new UserInvitation(
                inviterName: $currentUser->name,
                tenantName: $tenant->name
            ));
        } catch (\Throwable $e) {
            report($e);
            // User was created successfully — email failure is non-fatal
        }
    }

    return response()->json([
        'data' => UserData::fromUser($user),
        'meta' => $this->getMeta($request),
    ], Response::HTTP_CREATED);
}
```

- [ ] **Step 3: Run existing tests**

Run: `cd apps/api && php artisan test --filter=CreateUserTest`
Expected: All existing tests PASS

- [ ] **Step 4: Commit**

```bash
git add apps/api/app/Modules/Identity/Application/Notifications/UserInvitation.php \
       apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php
git commit -m "fix(users): queue invitation email and move outside transaction to prevent 500"
```

---

### Task 3: Make Email Optional for Cashiers (Bug 3)

**Files:**
- Create: `apps/api/database/migrations/2026_03_23_000001_make_user_email_nullable.php`
- Modify: `apps/api/app/Modules/Identity/Presentation/Requests/CreateUserRequest.php`
- Modify: `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php`
- Modify: `apps/api/tests/Feature/Identity/UserManagement/CreateUserTest.php`
- Modify: `apps/web/src/features/settings/UsersPage.tsx` (AddUserModal)
- Modify: `apps/web/src/locales/en/common.json`
- Modify: `apps/web/src/locales/fr/common.json`

#### Backend

- [ ] **Step 1: Create migration to make email nullable**

Create `apps/api/database/migrations/2026_03_23_000001_make_user_email_nullable.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });

        // Drop the old unique index and create a conditional one
        // PostgreSQL supports unique indexes with WHERE clause
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'email']);
        });

        // Unique constraint only for non-null emails
        DB::statement('CREATE UNIQUE INDEX users_tenant_id_email_unique ON users (tenant_id, email) WHERE email IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_tenant_id_email_unique');

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->unique(['tenant_id', 'email']);
        });
    }
};
```

Add `use Illuminate\Support\Facades\DB;` at the top.

- [ ] **Step 2: Run migration locally**

Run: `cd apps/api && php artisan migrate`
Expected: Migration runs successfully

- [ ] **Step 3: Update CreateUserRequest validation — email required_unless role is cashier**

In `CreateUserRequest.php`, change the email rule:

```php
public function rules(): array
{
    /** @var User $currentUser */
    $currentUser = $this->user();

    return [
        'name' => ['required', 'string', 'max:255'],
        'email' => [
            'nullable',
            'required_unless:role,cashier',
            'email',
            Rule::unique('users', 'email')->where('tenant_id', $currentUser->tenant_id)->whereNotNull('email'),
        ],
        'phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{7,20}$/'],
        'role' => ['required', 'string', 'exists:roles,name'],
        'locale' => ['nullable', 'string', 'max:10'],
        'timezone' => ['nullable', 'string', 'max:50'],
    ];
}
```

- [ ] **Step 4: Update UserController store — handle null email for cashiers**

In the `store` method, the user creation already handles `$validated['email'] ?? null` from Task 2. Also set cashier status to `active` (no email verification needed):

After the `User::create(...)` block, add:

```php
// Cashiers without email are immediately active (PIN-only users)
if ($user->email === null) {
    $user->update(['status' => UserStatus::Active]);
}
```

- [ ] **Step 5: Write test for creating cashier without email**

Add to `CreateUserTest.php`:

```php
public function test_can_create_cashier_without_email(): void
{
    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/users', [
            'name' => 'POS Cashier',
            'role' => 'cashier',
        ]);

    $response->assertStatus(201);
    $this->assertDatabaseHas('users', [
        'name' => 'POS Cashier',
        'email' => null,
        'status' => 'active',
    ]);
}

public function test_non_cashier_requires_email(): void
{
    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/users', [
            'name' => 'New Manager',
            'role' => 'manager',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
}
```

- [ ] **Step 6: Run tests**

Run: `cd apps/api && php artisan test --filter=CreateUserTest`
Expected: All tests PASS including new ones

#### Frontend

- [ ] **Step 7: Update AddUserModal — conditionally show email field**

In `UsersPage.tsx`, modify the `AddUserModal` component:

1. Update the `validate` function (around line 663) to only require email for non-cashier roles:

```tsx
const validate = (): boolean => {
    const newErrors: Record<string, string> = {}

    if (!formData.name.trim()) {
      newErrors['name'] = t('users.validation.nameRequired')
    }
    if (formData.role !== 'cashier') {
      if (!formData.email.trim()) {
        newErrors['email'] = t('users.validation.emailRequired')
      } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.email)) {
        newErrors['email'] = t('users.validation.invalidEmail')
      }
    }
    if (!formData.role) {
      newErrors['role'] = t('users.validation.roleRequired')
    }

    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }
```

2. Move the role selector ABOVE the email field so the user picks role first.

3. Conditionally show email as optional for cashiers — change the email label:

```tsx
<label htmlFor="email" className="block text-sm font-medium text-gray-700">
  {t('users.modal.emailLabel')} {formData.role !== 'cashier' && '*'}
</label>
```

4. Add a hint below email for cashier role:

```tsx
{formData.role === 'cashier' && (
  <p className="mt-1 text-xs text-gray-500">
    {t('users.modal.emailOptionalHint')}
  </p>
)}
```

5. Update the `handleSubmit` to send email only if provided:

```tsx
const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (validate()) {
      onSubmit({
        ...formData,
        email: formData.email || undefined,
        phone: formData.phone || undefined,
      })
    }
  }
```

6. Update the invitation note to be conditional:

```tsx
<p className="text-sm text-gray-500">
  {formData.role === 'cashier' && !formData.email.trim()
    ? t('users.modal.cashierPinNote')
    : t('users.modal.invitationNote')}
</p>
```

- [ ] **Step 8: Update CreateUserData type — email optional**

In `UsersPage.tsx`, update the interface (line 41):

```tsx
interface CreateUserData {
  name: string
  email?: string | undefined
  phone?: string | undefined
  role: string
  locale?: string | undefined
  timezone?: string | undefined
}
```

- [ ] **Step 9: Add translation keys**

In `apps/web/src/locales/en/common.json`, add under `users.modal`:
```json
"emailOptionalHint": "Email is optional for cashiers. They can use a PIN to log in at the terminal.",
"cashierPinNote": "This cashier will use a POS PIN to log in. You can set the PIN after creating the account."
```

In `apps/web/src/locales/fr/common.json`, add under `users.modal`:
```json
"emailOptionalHint": "L'e-mail est facultatif pour les caissiers. Ils peuvent utiliser un code PIN pour se connecter au terminal.",
"cashierPinNote": "Ce caissier utilisera un code PIN POS pour se connecter. Vous pourrez définir le PIN après la création du compte."
```

- [ ] **Step 10: Run frontend typecheck**

Run: `cd apps/web && pnpm typecheck`
Expected: PASS

- [ ] **Step 11: Commit**

```bash
git add apps/api/database/migrations/2026_03_23_000001_make_user_email_nullable.php \
       apps/api/app/Modules/Identity/Presentation/Requests/CreateUserRequest.php \
       apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php \
       apps/api/tests/Feature/Identity/UserManagement/CreateUserTest.php \
       apps/web/src/features/settings/UsersPage.tsx \
       apps/web/src/locales/en/common.json \
       apps/web/src/locales/fr/common.json
git commit -m "feat(users): make email optional for cashier role, PIN-only accounts"
```

---

### Task 4: Verify PIN Reset UI Works After Bug 1 Fix

**Files:**
- Verify: `apps/web/src/features/settings/UsersPage.tsx` (PosPinModal already exists at line 570)

- [ ] **Step 1: Verify the "Set POS PIN" action is in the dropdown menu**

Confirm at line 480-486 of `UsersPage.tsx` that the "Set POS PIN" button exists in the action menu. It does — this action was just hidden because of Bug 1 (clipped menu).

- [ ] **Step 2: Verify the PosPinModal supports set and clear operations**

Confirm at lines 570-651 that the modal:
- Accepts 4-6 digit PIN input
- Has "Clear PIN" button (admin can reset by clearing)
- Has "Save" button to set new PIN
- Validates PIN format client-side

This is already functional. The admin flow to "reset" a PIN is: click Clear PIN, then set a new one. No additional code needed.

- [ ] **Step 3: No changes needed — document in commit**

The PIN modal was already complete but inaccessible due to the clipped menu (Bug 1). With Bug 1 fixed, admins can:
1. Click three-dots menu → "Set POS PIN"
2. Enter a new 4-6 digit PIN, or click "Clear PIN" to remove it

---

### Task 5: Final Verification

- [ ] **Step 1: Run full backend test suite**

Run: `cd apps/api && php artisan test --filter=Identity`
Expected: All PASS

- [ ] **Step 2: Run frontend checks**

Run: `cd apps/web && pnpm typecheck && pnpm lint`
Expected: All PASS

- [ ] **Step 3: Run preflight**

Run: `./scripts/preflight.sh`
Expected: All checks PASS
