# Super Admin Documentation

This folder contains all documentation related to the Super Admin dashboard and platform management functionality.

## Documents

| Document | Description | Status |
|----------|-------------|--------|
| [DASHBOARD-ROADMAP.md](./DASHBOARD-ROADMAP.md) | Complete roadmap for production-ready dashboard | Active |
| [PAYMENT-PROVIDERS.md](./PAYMENT-PROVIDERS.md) | Payment integration strategy (Stripe, Manual) | Active |
| [MONITORING-SETUP.md](./MONITORING-SETUP.md) | Monitoring and observability guide | Planned |
| [ALERT-RUNBOOK.md](./ALERT-RUNBOOK.md) | Alert response procedures | Planned |
| [IMPERSONATION-POLICY.md](./IMPERSONATION-POLICY.md) | Security policy for tenant impersonation | Planned |

## Quick Links

### Current Implementation
- Backend: `apps/api/app/Http/Controllers/Api/Admin/`
- Middleware: `apps/api/app/Http/Middleware/EnsureSuperAdmin.php`
- Routes: `apps/api/routes/api.php` (admin prefix)
- Tests: `apps/api/tests/Feature/Admin/`

### Key Features Implemented
- Super admin authentication (Sanctum)
- Tenant management (list, view, suspend, activate)
- Trial extension and plan changes
- Audit logging
- Rate limiting
- Security middleware

### Upcoming Features
- Payment processing (Stripe + Manual)
- Invoice generation
- Revenue metrics dashboard
- System health monitoring
- Error tracking integration
- Tenant impersonation

## Development Guidelines

1. **All admin endpoints** must use `['auth:sanctum', 'super_admin']` middleware
2. **All sensitive operations** must be audit logged
3. **Rate limiting** is mandatory for all endpoints
4. **Type safety** - use DTOs for all data transfer
5. **Testing** - maintain security test coverage
