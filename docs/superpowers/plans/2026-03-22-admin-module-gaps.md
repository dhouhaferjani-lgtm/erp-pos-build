# Admin Module Toggles — Gap Fixes

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix 3 gaps in admin module management: replace fragile static map with API-driven `compatible_extras`, and add confirmation dialog before toggling modules.

**Architecture:** Backend adds `compatible_extras` to admin tenant response via `VerticalConfigService`. Frontend removes hardcoded map and uses API data. Existing `ConfirmDialog` component reused for toggle confirmation.

**Tech Stack:** Laravel 12 / PHP 8.2+, React 19 / TypeScript strict, existing `ConfirmDialog` component.

---

## Task 1: Backend — Add `compatible_extras` to `showTenant` response

**Files:**
- Modify: `apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php:65-85`

**Context:** The `showTenant` method returns `{ tenant, stats, plan_summary }`. The `tenant` object includes `vertical` and `enabled_extras` (from Eloquent serialization), but NOT `compatible_extras` — that lives in `config/verticals.php` and is accessed via `VerticalConfigService`. The controller already has `VerticalConfigService` injected (added in Task 12).

- [ ] **Step 1: Add `compatible_extras` to the showTenant response**

In `SuperAdminController::showTenant()`, after fetching the tenant, compute `compatible_extras`:

```php
$compatibleExtras = [];
if ($tenant->vertical !== null) {
    $compatibleExtras = $this->verticalConfigService->getCompatibleExtras($tenant->vertical);
}
```

Add it to the response:
```php
return response()->json([
    'data' => [
        'tenant' => $tenant,
        'stats' => $stats,
        'plan_summary' => $planSummary,
        'compatible_extras' => $compatibleExtras,
    ],
]);
```

- [ ] **Step 2: Run PHPStan**

Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Http/Controllers/Api/Admin/SuperAdminController.php --level=8`
Expected: No errors.

- [ ] **Step 3: Commit**

```bash
git add apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php
git commit -m "feat(admin): include compatible_extras in showTenant response"
```

---

## Task 2: Frontend — Replace static map with API data + add confirmation dialog

**Files:**
- Modify: `apps/web/src/features/admin/components/TenantDetailModal.tsx`
- Modify: `apps/web/src/features/admin/types.ts` (ensure `compatible_extras` is in the response type)
- Modify: `apps/web/src/locales/en/settings.json`
- Modify: `apps/web/src/locales/fr/settings.json`

**Context:** The `TenantDetailModal` currently uses a hardcoded `VERTICAL_COMPATIBLE_EXTRAS` map (lines 8-21) to determine which extras are available for a vertical. This is fragile — it's already missing `parapharmacy`. The fix: remove the map entirely and use `compatible_extras` from the API response (added in Task 1).

The existing `ConfirmDialog` component at `apps/web/src/components/ui/ConfirmDialog.tsx` accepts `{ isOpen, onClose, onConfirm, title, message, variant, isLoading }`.

- [ ] **Step 1: Update the admin types**

In `apps/web/src/features/admin/types.ts`, find the type used for the `showTenant` response (likely `TenantDetailResponse` or similar). Ensure it includes:
```typescript
compatible_extras: string[]
```
Make it required (not optional) since the backend will always return it now.

- [ ] **Step 2: Remove the static map and use API data**

In `TenantDetailModal.tsx`:

1. Delete the entire `VERTICAL_COMPATIBLE_EXTRAS` constant (lines 8-21)

2. Update the `TenantDetailModal` component to pass `compatible_extras` from the API response to `ManageModulesSection`:

Where the component currently does:
```tsx
const vertical = tenant?.vertical ?? null
```
Add:
```tsx
const compatibleExtras = data?.compatible_extras ?? []
```

3. Update `ManageModulesSection` props — replace `vertical: string | null` with `compatibleExtras: string[]`

4. Update the `ManageModulesSection` component:
- Remove the `vertical` prop
- Accept `compatibleExtras: string[]` instead
- Remove the line that looks up from the static map:
  ```tsx
  // DELETE THIS:
  const compatibleExtras = vertical !== null ? (VERTICAL_COMPATIBLE_EXTRAS[vertical] ?? []) : []
  ```
- Use the `compatibleExtras` prop directly

5. Update the call site to pass the new prop:
```tsx
<ManageModulesSection
  tenantId={tenantId}
  compatibleExtras={compatibleExtras}
  enabledExtras={enabledExtras}
  onRefresh={onRefresh}
/>
```

- [ ] **Step 3: Add confirmation dialog**

Import `ConfirmDialog` and add state for the pending toggle:

```tsx
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'

// Inside ManageModulesSection:
const [pendingToggle, setPendingToggle] = useState<{ module: string; enable: boolean } | null>(null)

const handleToggleClick = (module: string, enable: boolean) => {
  setPendingToggle({ module, enable })
}

const handleConfirmToggle = () => {
  if (!pendingToggle) return
  const newExtras = pendingToggle.enable
    ? [...enabledExtras, pendingToggle.module]
    : enabledExtras.filter((e) => e !== pendingToggle.module)

  updateExtras.mutate(
    { tenantId, enabledExtras: newExtras },
    {
      onSuccess: () => {
        setPendingToggle(null)
        onRefresh?.()
      },
      onSettled: () => {
        setPendingToggle(null)
      },
    }
  )
}
```

Update the toggle button's onClick to use `handleToggleClick` instead of directly mutating:
```tsx
onClick={() => handleToggleClick(extra, !isEnabled)}
```

Add the ConfirmDialog at the end of the return JSX:
```tsx
<ConfirmDialog
  isOpen={pendingToggle !== null}
  onClose={() => setPendingToggle(null)}
  onConfirm={handleConfirmToggle}
  title={
    pendingToggle?.enable
      ? t('settings:admin.tenants.enableModule')
      : t('settings:admin.tenants.disableModule')
  }
  message={
    pendingToggle?.enable
      ? t('settings:admin.tenants.enableModuleConfirm', { module: pendingToggle.module })
      : t('settings:admin.tenants.disableModuleConfirm', { module: pendingToggle?.module })
  }
  variant={pendingToggle?.enable ? 'info' : 'warning'}
  isLoading={updateExtras.isPending}
  confirmText={pendingToggle?.enable ? t('common:actions.enable') : t('common:actions.disable')}
/>
```

- [ ] **Step 4: Add i18n keys**

In `apps/web/src/locales/en/settings.json`, under `admin.tenants`:
```json
"enableModule": "Enable Module",
"disableModule": "Disable Module",
"enableModuleConfirm": "Are you sure you want to enable {{module}} for this tenant?",
"disableModuleConfirm": "Are you sure you want to disable {{module}} for this tenant? This will immediately restrict access to module features."
```

In `apps/web/src/locales/fr/settings.json`:
```json
"enableModule": "Activer le module",
"disableModule": "Désactiver le module",
"enableModuleConfirm": "Voulez-vous activer {{module}} pour ce locataire ?",
"disableModuleConfirm": "Voulez-vous désactiver {{module}} pour ce locataire ? L'accès aux fonctionnalités du module sera immédiatement restreint."
```

Also check if `common:actions.enable` and `common:actions.disable` exist. If not, add them to the common translations.

- [ ] **Step 5: Verify frontend compiles**

Run: `cd apps/web && pnpm typecheck`
Expected: No errors.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/features/admin/ apps/web/src/locales/
git commit -m "fix(admin): use API-driven compatible_extras, add toggle confirmation dialog"
```
