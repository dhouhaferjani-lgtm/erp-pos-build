# AutoERP Deployment Guide

Complete guide for deploying AutoERP to production using DocPloy or any container platform.

---

## Table of Contents
1. [Quick Start](#quick-start)
2. [Backend Deployment](#backend-deployment)
3. [Frontend Deployment](#frontend-deployment)
4. [Database Setup](#database-setup)
5. [Common Issues & Solutions](#common-issues--solutions)
6. [Environment Variables Reference](#environment-variables-reference)

---

## Quick Start

### Prerequisites
- PostgreSQL 16+ database
- Redis 7+ (optional, for queues)
- MinIO or S3-compatible storage (optional, for file uploads)
- Docker/container platform (DocPloy, AWS ECS, etc.)

### Deployment Order
1. Deploy database service (PostgreSQL)
2. Deploy backend API service
3. Deploy frontend web service

---

## Backend Deployment

### Required Environment Variables

```env
# Application
APP_NAME=AutoERP
APP_ENV=production
APP_KEY=<generate-with: php artisan key:generate --show>
APP_DEBUG=false
APP_URL=https://api.your-domain.com

# Database
DB_CONNECTION=pgsql
DB_HOST=your-database-host
DB_PORT=5432
DB_DATABASE=autoerp
DB_USERNAME=your-db-user
DB_PASSWORD=your-db-password

# Redis (optional but recommended)
REDIS_HOST=your-redis-host
REDIS_PASSWORD=null
REDIS_PORT=6379

# Auto-seeding (set to true ONLY for first deployment)
AUTO_SEED=true

# Session/Cache
SESSION_DRIVER=database
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis

# Mail (configure your mail provider)
MAIL_MAILER=smtp
MAIL_HOST=your-smtp-host
MAIL_PORT=587
MAIL_USERNAME=your-smtp-user
MAIL_PASSWORD=your-smtp-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@your-domain.com
MAIL_FROM_NAME="${APP_NAME}"
```

### Backend Build Command (DocPloy)

```bash
# No build command needed - Dockerfile handles everything
```

### Backend Start Command (DocPloy)

```bash
# Automatic via Dockerfile CMD
/usr/local/bin/entrypoint.sh
```

### What Happens on Startup

The backend entrypoint script (`docker/entrypoint.sh`) automatically:

1. ✅ Clears Laravel caches
2. ✅ Rebuilds config/route/view caches
3. ✅ Tests database connection (with retry)
4. ✅ **Runs migrations automatically** (`php artisan migrate --force`)
5. ✅ **Seeds database if `AUTO_SEED=true` and database is empty**
6. ✅ Starts PHP-FPM + Nginx via supervisord

### Important Notes

- **AUTO_SEED**: Set to `true` ONLY for the first deployment. After the initial setup, set it to `false` to prevent re-seeding on every restart.
- **Migrations**: Always run automatically on container startup
- **faker** dependency: Currently required for seeders (included in production dependencies)

---

## Frontend Deployment

### Required Environment Variables

```env
# Backend API URL (CRITICAL!)
VITE_API_URL=https://api.your-domain.com
```

### Frontend Build Command (DocPloy)

```bash
pnpm install && pnpm build
```

### Frontend Nginx Configuration

**IMPORTANT:** Your frontend nginx config must proxy `/api/` requests to the backend correctly.

Copy the template from `apps/web/nginx.conf.template` and replace `${BACKEND_URL}` with your actual backend URL:

```bash
# In your frontend container
sed 's|${BACKEND_URL}|https://api.your-domain.com|g' nginx.conf.template > /etc/nginx/conf.d/default.conf
```

**Critical Configuration Points:**

1. **NO trailing slash on `proxy_pass`:**
   ```nginx
   location /api/ {
       proxy_pass https://api.your-domain.com;  # ← NO TRAILING SLASH!
   }
   ```

2. **Use `$proxy_host` for Host header:**
   ```nginx
   proxy_set_header Host $proxy_host;  # ← NOT $host
   ```

These two settings are CRITICAL for proper routing!

---

## Database Setup

### Initial Seeding (Automatic)

When you deploy with `AUTO_SEED=true`, the following will be created:

1. **Super Admin**
   - Email: `superadmin@mecanospex.com`
   - Password: (check `SuperAdminSeeder.php`)

2. **Demo Tenant & Company**
   - Tenant: "Demo Garage"
   - Company: "Demo Garage SARL" (France)

3. **Test Users**
   - Admin: `admin@example.com` / `admin123`
   - Manager: `test@example.com` / `password`

4. **Chart of Accounts** (French PCG)
   - ~150 accounts created
   - System accounts mapped

5. **Fiscal Years**
   - 2024 (closed)
   - 2025 (current/open)
   - 2026 (future)

6. **Sample Data**
   - 95 partners (customers/suppliers)
   - 1,000 products
   - Payment methods
   - Payment repositories

### Manual Database Operations

If you need to run operations manually:

```bash
# Access backend container shell
docker exec -it <backend-container> sh

# Run migrations only
php artisan migrate --force

# Seed database
php artisan db:seed --force

# Seed specific seeders
php artisan db:seed --class=FranceChartOfAccountsSeeder --force
php artisan db:seed --class=FiscalYearSeeder --force
```

---

## Common Issues & Solutions

### Issue 1: Login takes 10-20 seconds and times out

**Cause:** bcrypt work factor too high for production server CPU

**Solution:** Add to backend `.env`:
```env
BCRYPT_ROUNDS=10
```

Then restart backend service.

---

### Issue 2: 403 Forbidden on finance pages

**Cause:** User doesn't have proper roles/permissions assigned

**Solution:**
```bash
# Access backend container
php artisan tinker

# Assign admin role
$user = \App\Modules\Identity\Domain\User::where('email', 'admin@example.com')->first();
setPermissionsTeamId($user->tenant_id);
$adminRole = \Spatie\Permission\Models\Role::where('name', 'admin')->first();
$user->assignRole($adminRole);
exit
```

**Prevention:** This is now fixed in the updated `DatabaseSeeder`. Future deployments will automatically assign roles.

---

### Issue 3: API returns 405 Method Not Allowed

**Cause:** Backend nginx using `$uri` instead of `$request_uri` for REQUEST_URI

**Status:** ✅ **FIXED** in `apps/api/docker/nginx/default.conf`

The nginx config now correctly uses:
```nginx
fastcgi_param REQUEST_URI $request_uri;
```

---

### Issue 4: Frontend can't reach backend API

**Cause:** Incorrect nginx proxy configuration

**Status:** ✅ **FIXED** via `apps/web/nginx.conf.template`

Key fixes:
1. No trailing slash on `proxy_pass`
2. Use `$proxy_host` for Host header

---

### Issue 5: Chart of Accounts missing

**Cause:** Seeder not run or failed

**Solution:**
```bash
# Check if accounts exist
php artisan tinker
$company = \App\Modules\Company\Domain\Company::first();
\App\Modules\Accounting\Domain\Account::where('company_id', $company->id)->count();
exit

# If count is 0, seed manually
php artisan tinker
$company = \App\Modules\Company\Domain\Company::first();
$seeder = new \Database\Seeders\FranceChartOfAccountsSeeder();
$seeder->run($company->id, $company->tenant_id);
exit
```

---

## Environment Variables Reference

### Backend Critical Variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `APP_KEY` | ✅ Yes | - | Generate with `php artisan key:generate --show` |
| `DB_HOST` | ✅ Yes | - | PostgreSQL host |
| `DB_DATABASE` | ✅ Yes | - | Database name |
| `DB_USERNAME` | ✅ Yes | - | Database user |
| `DB_PASSWORD` | ✅ Yes | - | Database password |
| `AUTO_SEED` | ❌ No | `false` | Set to `true` for first deployment only |
| `BCRYPT_ROUNDS` | ❌ No | `12` | Set to `10` for better performance |
| `REDIS_HOST` | ❌ No | - | Redis host (for queues/cache) |
| `APP_URL` | ✅ Yes | - | Backend URL (for API responses) |

### Frontend Critical Variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `VITE_API_URL` | ✅ Yes | - | Backend API URL (no trailing slash) |

---

## Verification Checklist

After deployment, verify:

- [ ] Backend health check: `curl https://api.your-domain.com/health`
- [ ] Frontend loads: `https://your-domain.com`
- [ ] Login works with test credentials
- [ ] Finance pages load without 403 errors
- [ ] API requests in browser Network tab show 200 status
- [ ] No console errors in browser

---

## Rollback Procedure

If deployment fails:

1. **Database:** Migrations are safe (additive only)
2. **Backend:** Revert to previous container image
3. **Frontend:** Revert to previous container image
4. **No data loss:** Database changes are preserved

---

## Support & Debugging

### Backend Logs

```bash
# Application logs
tail -f storage/logs/laravel.log

# Nginx access logs
tail -f /var/log/nginx/access.log

# Nginx error logs
tail -f /var/log/nginx/error.log
```

### Frontend Logs

Check browser console (F12) for:
- API request URLs
- Response status codes
- CORS errors
- Network timeouts

---

## Future Improvements

### Country-Scoped Chart of Accounts

Currently, Chart of Accounts is created per-company. Planned improvement:

1. Store country templates (France, Tunisia) at system level
2. Copy template to company on creation
3. Allow customization per company

This will eliminate the need for `faker` in production and make seeding more efficient.

---

**Last Updated:** December 2025
**Version:** 1.0
