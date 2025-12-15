# Testing Data & Credentials

This document contains all testing credentials and data for manual testing of AutoERP.

## Application URLs

| Service | URL | Notes |
|---------|-----|-------|
| Web App | http://localhost:5173 | React frontend |
| API | http://localhost:8001 | Laravel backend |

> **Note:** Port 8000 may be used by another application. The mecanospex API runs on port 8001.

## Test User Accounts

### Standard User
- **Email:** `test@example.com`
- **Password:** `password`
- **Tenant:** Demo Garage
- **Role:** Standard user

### Admin User
- **Email:** `admin@example.com`
- **Password:** `admin123`
- **Tenant:** Demo Garage
- **Role:** Administrator

## Test Tenant

| Field | Value |
|-------|-------|
| Name | Demo Garage |
| Slug | demo-garage |
| Country | France (FR) |
| Currency | EUR |
| Tax ID | FR12345678901 |
| Plan | Professional |
| Timezone | Europe/Paris |

## Test Partners

| Name | Code | Type | Email | Phone |
|------|------|------|-------|-------|
| Acme Corporation | ACME | Customer | contact@acme.com | +33123456789 |
| TechSupply Inc | TECH | Supplier | orders@techsupply.com | +33987654321 |
| Auto Parts France | APF | Customer | info@autoparts.fr | +33111222333 |
| Client Premium SA | PREM | Customer | premium@client.com | +33444555666 |

## Starting the Application

### Quick Start

```bash
# From project root
cd apps/web
pnpm dev

# API should already be running on port 8000
# If not, start it:
cd apps/api
php artisan serve
```

### Full Stack (from scratch)

```bash
# Terminal 1 - API (on port 8001 to avoid conflicts)
cd apps/api
php artisan serve --port=8001

# Terminal 2 - Web
cd apps/web
pnpm dev
```

## Creating Fresh Test Data

If you need to recreate test data, run:

```bash
cd apps/api
php artisan migrate:fresh --seed
```

This will:
1. Drop all tables and re-run all migrations
2. Seed the database with:
   - 1 Demo tenant (Demo Garage)
   - 2 Users (test@example.com and admin@example.com)
   - 4 Partners (ACME, TechSupply, Auto Parts France, Client Premium)

## Running Tests

### Unit Tests (Vitest)
```bash
cd apps/web
pnpm test
```

### E2E Tests (Playwright)
```bash
cd apps/web
pnpm test:e2e        # Headless
pnpm test:e2e:ui     # Interactive UI
```

## Database Reset

To completely reset and reseed:

```bash
cd apps/api
php artisan migrate:fresh --seed
```

## Troubleshooting

### "Unauthenticated" error
- Check that the API is running on port 8001
- Verify the user exists in the database
- Clear browser cookies and try again

### CORS errors
- The Vite dev server proxies `/api` requests to the backend
- Make sure you're accessing the app via `localhost:5173`, not directly to the API

### Database connection errors
- Ensure PostgreSQL is running
- Check `.env` file in `apps/api` for correct database credentials
