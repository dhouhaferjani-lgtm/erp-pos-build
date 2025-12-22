# Sign-Up Flow & Email Verification

**Status:** In Progress
**Branch:** `dev`
**Created:** 2025-12-21

---

## Overview

Implement user self-registration with email verification using Resend.

**Flow:**
1. User fills multi-step sign-up form (Account + Company)
2. System creates tenant, user, company, location
3. Verification email sent (non-blocking)
4. User can use app immediately
5. Banner reminds to verify email
6. Document sending blocked until verified

---

## Phase 1: Tasks (Current)

### 1. Production Seeding
- [ ] Create `ProductionSeeder.php`
- [ ] Run on deployed database: Countries, TaxRates, Plans, Roles, Permissions, PaymentMethods

### 2. Resend Email Integration
- [ ] Install `resend/resend-laravel` package
- [ ] Configure environment variables in Dokploy
- [ ] Verify domain DNS (otospex.dev)

### 3. Email Verification Backend
- [ ] Create migration: `email_verification_tokens` table
- [ ] Create `VerifyEmailNotification.php`
- [ ] Add endpoint: `POST /api/v1/auth/verify-email`
- [ ] Add endpoint: `POST /api/v1/auth/resend-verification`
- [ ] Modify registration to send verification email
- [ ] Add rate limiting (5 registrations/min, 3 resends/min)

### 4. Frontend Sign-Up Wizard
- [ ] Create `RegisterPage.tsx` with 3 steps
- [ ] Add registration API hook
- [ ] Add `/register` route
- [ ] Add "Sign up" link to login page

### 5. Email Verification Banner
- [ ] Create `EmailVerificationBanner.tsx`
- [ ] Create `VerifyEmailPage.tsx`
- [ ] Add `/verify-email` route
- [ ] Integrate banner in Layout

### 6. Translations
- [ ] Backend: `lang/en/auth.php`, `lang/fr/auth.php`
- [ ] Frontend: `locales/en/auth.json`, `locales/fr/auth.json`

### 7. Document Sending Restriction
- [ ] Add email verification check in `DocumentEmailService`

---

## Files to Create

| File | Purpose |
|------|---------|
| `apps/api/database/seeders/ProductionSeeder.php` | Orchestrate production seeding |
| `apps/api/database/migrations/*_create_email_verification_tokens_table.php` | Token storage |
| `apps/api/app/Modules/Identity/Application/Notifications/VerifyEmailNotification.php` | Email template |
| `apps/web/src/features/auth/RegisterPage.tsx` | Sign-up wizard |
| `apps/web/src/features/auth/VerifyEmailPage.tsx` | Token verification |
| `apps/web/src/components/organisms/EmailVerificationBanner/` | Reminder banner |

## Files to Modify

| File | Changes |
|------|---------|
| `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php` | Add verification endpoints |
| `apps/api/app/Modules/Identity/routes.php` | Add verification routes |
| `apps/web/src/routes/index.tsx` | Add /register, /verify-email routes |
| `apps/web/src/features/auth/LoginPage.tsx` | Add sign-up link |
| `apps/web/src/components/layout/Layout.tsx` | Add verification banner |

---

## Environment Variables (Dokploy)

```env
MAIL_MAILER=resend
RESEND_API_KEY=re_xxxxxxxxxxxxx
MAIL_FROM_ADDRESS=noreply@otospex.dev
MAIL_FROM_NAME=AutoERP
FRONTEND_URL=https://erp.otospex.dev
```

---

## Phase 2: Tenant Approval (Deferred)

For future implementation:
- Country-specific document requirements
- Tenant status: pending/active/blocked
- Super admin approval panel
- Non-blocking: users can work while pending
