# Identity Module

> Users, roles, permissions, and authentication.

---

## Purpose

The Identity module handles authentication, authorization, and user management across tenants and companies.

---

## User Structure

```php
User {
    id: UUID
    email: string
    name: string
    phone: string?
    password: hashed
    email_verified_at: datetime?
    is_active: boolean
}
```

---

## Multi-Company Access

Users can belong to multiple companies within a tenant:

```php
UserCompanyMembership {
    user_id: UUID
    company_id: UUID
    role: string           // 'admin', 'manager', 'staff', etc.
    is_primary: boolean    // Default company on login
}
```

---

## Roles & Permissions

### Built-in Roles

| Role | Level | Description |
|------|-------|-------------|
| `super_admin` | Tenant | Full access to all companies |
| `admin` | Company | Full access to one company |
| `manager` | Company | Most operations |
| `accountant` | Company | Finance operations |
| `sales` | Company | Sales documents only |
| `warehouse` | Company | Inventory operations |
| `staff` | Company | Read + limited write |

### Permission System

Permissions follow pattern: `{resource}.{action}`

```
documents.create
documents.read
documents.update
documents.delete
documents.post
payments.create
inventory.adjust
settings.manage
```

---

## Authentication

- **API**: Bearer token (Laravel Sanctum)
- **Session**: Cookie-based for web
- **Mobile**: Token with refresh

---

## API Endpoints

```
POST   /api/auth/login              # Login
POST   /api/auth/logout             # Logout
GET    /api/auth/me                 # Current user
POST   /api/auth/refresh            # Refresh token

GET    /api/users                   # List users
POST   /api/users                   # Create user
PATCH  /api/users/{id}              # Update user
DELETE /api/users/{id}              # Deactivate user

GET    /api/roles                   # List roles
POST   /api/roles                   # Create custom role
```
