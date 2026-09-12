Add permissions for the module "$ARGUMENTS" to AutoERP.

> **Where the catalogue lives, today and after wave 1.**
> **Today** the catalogue is two arrays in
> `apps/api/database/seeders/RolesAndPermissionsSeeder.php`
> (`permissionNames()` and `rolePermissionGrants()`), and step 1 below edits them by hand.
> **From wave 1** the catalogue is code — a per-module `PermissionManifest` feeding
> `PermissionRegistry` — and this command's step 1 is replaced by:
>     php artisan permissions:scaffold {Module} {resource}
> which writes the manifest entry, the module enum case and the label skeleton, and is
> byte-identical across two clean worktrees (`ScaffoldPermissionCommandTest`). Hand-editing
> the seeder after wave 1 lands is a merge conflict with the registry, not a shortcut.
> The wave-1 plan is `docs/superpowers/plans/2026-09-10-rbac-wave-1.md`; the programme row
> is `docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md` (wave 1 scope).

## Steps

1. **Edit `apps/api/database/seeders/RolesAndPermissionsSeeder.php`**:
   - Add standard CRUD permissions: `{resource}.view`, `{resource}.create`, `{resource}.update`, `{resource}.delete`
   - Add any action-specific permissions: `{resource}.post`, `{resource}.cancel`, etc.
   - Assign to roles:
     - **admin**: all permissions
     - **manager**: all operational permissions (create, update, delete)
     - **accountant**: view + financial operations
     - **viewer/cashier**: view only (+ role-specific actions)

2. **Protect backend routes** in the module's `routes.php` — the route middleware is
   `can:`, NOT `permission:` (that alias has never existed in this repository):
   ```php
   Route::get('{resource}', [Controller::class, 'index'])
       ->middleware('can:{resource}.view');
   Route::post('{resource}', [Controller::class, 'store'])
       ->middleware('can:{resource}.create');
   ```
   For an any-of gate use `require.any.permission:{resource}.view,{resource}.manage`.
   A route with no gate fails `RoutePermissionCoverageRatchetTest`, and new routes may
   never be added to its baseline.

3. **Protect frontend routes** in `apps/web/src/routes/index.tsx`:
   ```tsx
   <RequirePermission permission="{resource}.view">
     <SuspenseWrapper><ListPage /></SuspenseWrapper>
   </RequirePermission>
   ```

4. **Add permission checks in UI** using `usePermissions` hook:
   ```tsx
   const { hasPermission } = usePermissions()
   {hasPermission('{resource}.create') && <Button>{t('common.create')}</Button>}
   ```

5. **Reach existing tenants.** `db:seed --class=RolesAndPermissionsSeeder` only affects the
   database you are currently connected to. To reach the fleet use the deploy command for
   the current wave (`permissions:ensure-fleet` in wave 0b, `permissions:sync-fleet` from
   wave 1), then `php artisan permission:cache-reset`, then
   `php artisan permissions:export-frontend-map`. NEVER `tenants:run <command>` for a
   catalogue change: it discards child exit statuses and reports a partial deploy as a
   clean one.

## Reference
See `docs/conventions/03-AUTHORIZATION.md` for the full permission system guide.
