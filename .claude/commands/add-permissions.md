Add permissions for the module "$ARGUMENTS" to AutoERP.

## Steps

1. **Edit `apps/api/database/seeders/RolesAndPermissionsSeeder.php`**:
   - Add standard CRUD permissions: `{resource}.view`, `{resource}.create`, `{resource}.update`, `{resource}.delete`
   - Add any action-specific permissions: `{resource}.post`, `{resource}.cancel`, etc.
   - Assign to roles:
     - **admin**: all permissions
     - **manager**: all operational permissions (create, update, delete)
     - **accountant**: view + financial operations
     - **viewer/cashier**: view only (+ role-specific actions)

2. **Protect backend routes** in the module's `routes.php`:
   ```php
   Route::middleware('permission:{resource}.view')->get('/{resource}', [Controller::class, 'index']);
   Route::middleware('permission:{resource}.create')->post('/{resource}', [Controller::class, 'store']);
   ```

3. **Protect frontend routes** in `apps/web/src/routes/index.tsx`:
   ```tsx
   <RequirePermission permission="{resource}.view">
     <SuspenseWrapper><ListPage /></SuspenseWrapper>
   </RequirePermission>
   ```

4. **Add permission checks in UI** using `usePermissions` hook:
   ```tsx
   const { can } = usePermissions()
   {can('{resource}.create') && <Button>{t('common.create')}</Button>}
   ```

5. **Run the seeder**:
   ```bash
   cd apps/api && php artisan db:seed --class=RolesAndPermissionsSeeder
   ```

## Reference
See `docs/conventions/03-AUTHORIZATION.md` for the full permission system guide.
