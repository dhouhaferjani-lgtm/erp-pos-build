# User Management and Company Settings - Implementation Plan

## Current State

### What Exists (Backend)
- **User Model**: `apps/api/app/Models/User.php` with fields: id, tenant_id, name, email, phone, password, status, locale, timezone, preferences, email_verified_at, last_login_at, last_login_ip
- **User Migration**: `2025_11_30_000003_create_users_table.php`
- **Permission Tables**: Spatie Permission package with roles, permissions, model_has_roles, model_has_permissions, role_has_permissions
- **Existing API Routes**:
  - `GET /api/v1/roles` - List roles with permissions
  - `GET /api/v1/permissions` - List permissions grouped by module
  - `GET /api/v1/users/{userId}/roles` - Get user roles
  - `POST /api/v1/users/{userId}/roles` - Assign role to user
  - `DELETE /api/v1/users/{userId}/roles` - Remove role from user

### What's Missing (Backend)
- No user CRUD endpoints (list, create, update, delete users)
- No company/tenant settings endpoints
- No user invitation/password reset flow

### What Exists (Frontend)
- `UsersPage.tsx` - Shows current user info + "Coming Soon" placeholder
- `RolesPage.tsx` - Displays roles and permission matrix (functional)
- `CompanyPage.tsx` - Disabled form preview + "Coming Soon" placeholder
- `SettingsPage.tsx` - Settings hub with navigation

---

## Implementation Plan

### Phase 1: Backend - User Management API

#### 1.1 Create UserController
**File**: `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php`

```php
// Endpoints to implement:
GET    /api/v1/users              // List users (paginated, filterable)
GET    /api/v1/users/{id}         // Get single user
POST   /api/v1/users              // Create user
PATCH  /api/v1/users/{id}         // Update user
DELETE /api/v1/users/{id}         // Soft delete/deactivate user
POST   /api/v1/users/{id}/activate    // Activate user
POST   /api/v1/users/{id}/deactivate  // Deactivate user
POST   /api/v1/users/{id}/reset-password  // Trigger password reset
```

#### 1.2 Update User Model
**File**: `apps/api/app/Modules/Identity/Domain/User.php`

- Add HasRoles trait from Spatie Permission
- Add proper fillable fields
- Add status enum: `active`, `inactive`, `pending_verification`
- Add relationships (tenant, roles)

#### 1.3 Create Request Validators
- `CreateUserRequest.php` - Validate name, email, phone, role, password
- `UpdateUserRequest.php` - Validate updates (email unique within tenant)

#### 1.4 Update Routes
**File**: `apps/api/app/Modules/Identity/routes.php`

Add routes with proper permission middleware.

---

### Phase 2: Backend - Company/Tenant Settings API

#### 2.1 Create Tenant Model Enhancement
**File**: `apps/api/app/Modules/Tenant/Domain/Tenant.php`

Fields to manage:
- name (company name)
- legal_name
- tax_id (VAT number)
- registration_number
- address (JSON: street, city, postal_code, country)
- phone
- email
- website
- logo_path
- primary_color
- currency (default: EUR)
- timezone (default: Europe/Paris)
- date_format (default: DD/MM/YYYY)
- locale (default: fr)

#### 2.2 Create TenantSettingsController
**File**: `apps/api/app/Modules/Tenant/Presentation/Controllers/TenantSettingsController.php`

```php
// Endpoints:
GET    /api/v1/settings/company    // Get company settings
PATCH  /api/v1/settings/company    // Update company settings
POST   /api/v1/settings/company/logo  // Upload logo
```

#### 2.3 Create Migration for Tenant Settings
Add columns to tenants table or create tenant_settings table.

---

### Phase 3: Frontend - User Management

#### 3.1 Update UsersPage.tsx
- Fetch users from `/api/v1/users`
- Display users in table with columns: Name, Email, Role, Status, Last Login
- Add search/filter functionality
- Add pagination

#### 3.2 Create UserFormModal.tsx
- Modal for creating/editing users
- Fields: name, email, phone, role (dropdown), status
- Validation with react-hook-form
- Role assignment using existing `/api/v1/users/{id}/roles` endpoint

#### 3.3 Create UserDetailModal.tsx (optional)
- View user details
- Activity log (if implemented)
- Session management (if implemented)

#### 3.4 Add User Actions
- Activate/Deactivate user
- Reset password (sends email)
- Delete user (soft delete)

---

### Phase 4: Frontend - Company Settings

#### 4.1 Update CompanyPage.tsx
- Fetch company settings from `/api/v1/settings/company`
- Enable all form fields
- Add save functionality
- Add logo upload with preview

#### 4.2 Form Sections
1. **Company Information**
   - Company Name (required)
   - Legal Name
   - Tax ID / VAT Number
   - Registration Number

2. **Contact Information**
   - Address (street, city, postal code, country)
   - Phone
   - Email
   - Website

3. **Branding**
   - Logo upload (drag & drop)
   - Primary color picker

4. **Regional Settings**
   - Currency (dropdown)
   - Timezone (dropdown)
   - Date Format (dropdown)
   - Locale/Language (dropdown)

---

## Database Schema Changes

### Users Table (existing, no changes needed)
Already has all required fields.

### Tenants Table Updates
```sql
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS legal_name VARCHAR(255);
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS tax_id VARCHAR(50);
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS registration_number VARCHAR(100);
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS address JSONB DEFAULT '{}';
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS phone VARCHAR(30);
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS email VARCHAR(255);
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS website VARCHAR(255);
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS logo_path VARCHAR(255);
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS primary_color VARCHAR(7) DEFAULT '#2563EB';
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS currency VARCHAR(3) DEFAULT 'EUR';
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS timezone VARCHAR(50) DEFAULT 'Europe/Paris';
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS date_format VARCHAR(20) DEFAULT 'DD/MM/YYYY';
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS locale VARCHAR(10) DEFAULT 'fr';
```

---

## Implementation Order

1. **Backend User CRUD** (Phase 1) - ~2-3 hours
   - Create UserController with all endpoints
   - Add routes with permissions
   - Test with API client

2. **Frontend User Management** (Phase 3) - ~2-3 hours
   - Update UsersPage with real data
   - Create UserFormModal
   - Add user actions

3. **Backend Company Settings** (Phase 2) - ~1-2 hours
   - Create migration for tenant settings
   - Create TenantSettingsController
   - Add routes

4. **Frontend Company Settings** (Phase 4) - ~2 hours
   - Update CompanyPage with real form
   - Add logo upload
   - Add save functionality

---

## Permissions Required

### User Management
- `users.view` - View user list
- `users.create` - Create new users
- `users.edit` - Edit existing users
- `users.delete` - Delete/deactivate users
- `users.assign-roles` - Assign roles to users (already exists)

### Company Settings
- `settings.view` - View company settings
- `settings.edit` - Edit company settings

---

## Questions for Clarification

1. **User Invitation Flow**: Should new users receive an email invitation to set their password, or should admin set the initial password?

2. **Password Requirements**: What password policy should be enforced? (min length, special chars, etc.)

3. **Logo Upload**: What file size limit and dimensions for company logo?

4. **Multi-language**: Should company settings include multiple language options, or just the primary locale?

5. **Audit Trail**: Should user changes (create/edit/delete) be logged in an audit table?

---

## Estimated Total Time

- Backend: 3-5 hours
- Frontend: 4-5 hours
- Testing: 1-2 hours
- **Total: 8-12 hours**
