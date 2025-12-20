# Super Admin Dashboard Roadmap

> Production-Ready SaaS Platform Management

This document outlines the complete roadmap for building a production-ready super admin dashboard that supports multi-region deployment, payment processing, and enterprise-grade monitoring.

---

## Table of Contents

1. [Current State Assessment](#current-state-assessment)
2. [SaaS Standards Gap Analysis](#saas-standards-gap-analysis)
3. [Payment Integration Strategy](#payment-integration-strategy)
4. [Monitoring & Observability](#monitoring--observability)
5. [Implementation Phases](#implementation-phases)
6. [Technical Architecture](#technical-architecture)

---

## Current State Assessment

### What Exists Today

| Feature | Status | Notes |
|---------|--------|-------|
| Super admin authentication | Done | Sanctum tokens, EnsureSuperAdmin middleware |
| Basic tenant listing | Done | Pagination, search, status filter |
| Tenant details view | Done | Basic stats (users, companies, locations) |
| Trial extension | Done | Manual extension with audit log |
| Plan change | Done | Manual plan change with audit log |
| Tenant suspend/activate | Done | With audit logging |
| Audit logs | Done | Admin actions logged with IP, user agent |
| Rate limiting | Done | 5/min login, 30/min sensitive ops |
| Security middleware | Done | Proper authorization, not just authentication |

### What's Missing (Critical for Production)

| Category | Gap | Priority |
|----------|-----|----------|
| **Billing** | No payment processing | P0 |
| **Billing** | No invoice generation | P0 |
| **Monitoring** | No system health dashboard | P0 |
| **Monitoring** | No error tracking integration | P0 |
| **Operations** | No tenant impersonation | P1 |
| **Operations** | No bulk operations | P1 |
| **Analytics** | No revenue metrics | P1 |
| **Analytics** | No usage analytics | P1 |
| **Support** | No support ticket integration | P2 |
| **Security** | No 2FA for super admins | P2 |
| **Communication** | No announcement system | P2 |

---

## SaaS Standards Gap Analysis

### 1. Tenant Lifecycle Management

**Current:** Basic CRUD operations
**Required:**

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   Trial     │────▶│   Active    │────▶│  Churned    │
│  (14 days)  │     │  (Paying)   │     │ (Cancelled) │
└─────────────┘     └─────────────┘     └─────────────┘
       │                   │                   │
       │                   ▼                   │
       │            ┌─────────────┐            │
       └───────────▶│  Suspended  │◀───────────┘
                    │ (Non-payment)│
                    └─────────────┘
                           │
                           ▼
                    ┌─────────────┐
                    │  Deleted    │
                    │ (Data purge)│
                    └─────────────┘
```

**Missing Features:**
- [ ] Automated trial expiration handling
- [ ] Grace period for failed payments (dunning)
- [ ] Data retention policy enforcement
- [ ] Tenant data export (GDPR compliance)
- [ ] Scheduled tenant deletion with confirmation

### 2. Subscription & Billing

**Current:** Manual plan assignment, no payment processing
**Required:**

| Feature | Description | Priority |
|---------|-------------|----------|
| Payment providers | Stripe (EU), Manual/Bank transfer (Tunisia) | P0 |
| Subscription plans | Configurable features per plan | P0 |
| Usage-based billing | Track and bill for overages | P1 |
| Invoice generation | Automated monthly invoices | P0 |
| Revenue recognition | MRR, ARR, churn metrics | P1 |
| Dunning management | Retry failed payments, notifications | P0 |
| Proration | Handle mid-cycle upgrades/downgrades | P1 |
| Tax handling | VAT for EU, local taxes for Tunisia | P1 |

### 3. Multi-Region Considerations

**Regions:**
- **Tunisia (TN)**: Offline payments, bank transfers, local currency (TND)
- **France (FR)**: Stripe, SEPA, EUR, VAT compliance
- **EU expansion**: Same as France + country-specific requirements

**Payment Method Matrix:**

| Region | Primary | Secondary | Currency | Tax |
|--------|---------|-----------|----------|-----|
| Tunisia | Bank Transfer | Cash/Check | TND | TVA 19% |
| France | Stripe (Card) | SEPA Direct Debit | EUR | TVA 20% |
| EU | Stripe (Card) | SEPA, Local methods | EUR | Country VAT |

### 4. Security & Compliance

**Current:** Basic auth with rate limiting
**Required:**

- [ ] 2FA for all super admin accounts (TOTP)
- [ ] Session management (view/revoke sessions)
- [ ] IP allowlisting for admin access
- [ ] Security event alerts (failed logins, permission changes)
- [ ] Audit log export for compliance
- [ ] GDPR data subject request handling
- [ ] SOC 2 audit trail requirements

### 5. Support Operations

**Current:** None
**Required:**

- [ ] Tenant impersonation (login as tenant user for debugging)
- [ ] Support ticket integration (Zendesk, Freshdesk, or built-in)
- [ ] Customer communication log
- [ ] Feature flag management per tenant
- [ ] Custom configuration overrides
- [ ] Tenant notes and internal comments

---

## Payment Integration Strategy

### Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                    Payment Abstraction Layer                │
│                    (PaymentProviderInterface)               │
└─────────────────────────────────────────────────────────────┘
                              │
        ┌─────────────────────┼─────────────────────┐
        ▼                     ▼                     ▼
┌───────────────┐     ┌───────────────┐     ┌───────────────┐
│    Stripe     │     │    Manual     │     │    Future     │
│   Provider    │     │   Provider    │     │  (PayPal,etc) │
└───────────────┘     └───────────────┘     └───────────────┘
        │                     │
        ▼                     ▼
┌───────────────┐     ┌───────────────┐
│ Stripe API    │     │ Admin Review  │
│ Webhooks      │     │ Bank Transfer │
└───────────────┘     └───────────────┘
```

### Provider Interface

```php
interface PaymentProviderInterface
{
    public function createCustomer(Tenant $tenant): string;
    public function createSubscription(Tenant $tenant, Plan $plan): Subscription;
    public function cancelSubscription(Subscription $subscription): void;
    public function updatePaymentMethod(Tenant $tenant, array $data): void;
    public function processPayment(Invoice $invoice): PaymentResult;
    public function refund(Payment $payment, Money $amount): RefundResult;
    public function getInvoices(Tenant $tenant): Collection;
    public function handleWebhook(array $payload): void;
}
```

### Stripe Integration (EU Markets)

**Features to implement:**
1. Customer creation on tenant signup
2. Subscription management via Stripe Billing
3. Payment method management (cards, SEPA)
4. Webhook handling for payment events
5. Invoice sync with Stripe Invoicing
6. Tax calculation via Stripe Tax

**Webhook Events to Handle:**
- `customer.subscription.created`
- `customer.subscription.updated`
- `customer.subscription.deleted`
- `invoice.payment_succeeded`
- `invoice.payment_failed`
- `payment_method.attached`
- `payment_method.detached`

### Manual/Offline Provider (Tunisia)

**Workflow:**
```
1. Tenant requests subscription
2. System generates proforma invoice
3. Tenant pays via bank transfer
4. Super admin receives payment notification
5. Super admin marks invoice as paid
6. System activates subscription
```

**Features:**
- Proforma invoice generation (PDF)
- Bank account details display
- Payment reference generation
- Manual payment recording
- Receipt generation
- Payment reminder automation

### Database Schema Additions

```sql
-- Payment providers configuration
CREATE TABLE payment_providers (
    id UUID PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,  -- 'stripe', 'manual'
    name VARCHAR(100) NOT NULL,
    is_active BOOLEAN DEFAULT true,
    config JSONB,  -- API keys (encrypted), settings
    supported_countries TEXT[],
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- Tenant payment configuration
CREATE TABLE tenant_payment_configs (
    id UUID PRIMARY KEY,
    tenant_id UUID REFERENCES tenants(id),
    provider_id UUID REFERENCES payment_providers(id),
    external_customer_id VARCHAR(255),  -- Stripe customer ID
    default_payment_method_id VARCHAR(255),
    billing_email VARCHAR(255),
    billing_address JSONB,
    tax_id VARCHAR(50),
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE(tenant_id)
);

-- Invoices
CREATE TABLE invoices (
    id UUID PRIMARY KEY,
    tenant_id UUID REFERENCES tenants(id),
    subscription_id UUID REFERENCES tenant_subscriptions(id),
    invoice_number VARCHAR(50) UNIQUE NOT NULL,
    status VARCHAR(20) NOT NULL,  -- draft, pending, paid, failed, void
    type VARCHAR(20) NOT NULL,  -- subscription, one-time, proforma
    currency VARCHAR(3) NOT NULL,
    subtotal DECIMAL(12,2) NOT NULL,
    tax_amount DECIMAL(12,2) NOT NULL,
    total DECIMAL(12,2) NOT NULL,
    due_date DATE,
    paid_at TIMESTAMP,
    external_id VARCHAR(255),  -- Stripe invoice ID
    pdf_url TEXT,
    line_items JSONB,
    metadata JSONB,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- Payments
CREATE TABLE subscription_payments (
    id UUID PRIMARY KEY,
    tenant_id UUID REFERENCES tenants(id),
    invoice_id UUID REFERENCES invoices(id),
    provider_id UUID REFERENCES payment_providers(id),
    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(3) NOT NULL,
    status VARCHAR(20) NOT NULL,  -- pending, processing, succeeded, failed
    payment_method VARCHAR(50),  -- card, sepa, bank_transfer
    external_id VARCHAR(255),  -- Stripe payment intent ID
    failure_reason TEXT,
    metadata JSONB,
    recorded_by UUID,  -- For manual payments
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- Dunning (failed payment retry)
CREATE TABLE dunning_attempts (
    id UUID PRIMARY KEY,
    invoice_id UUID REFERENCES invoices(id),
    attempt_number INT NOT NULL,
    scheduled_at TIMESTAMP NOT NULL,
    attempted_at TIMESTAMP,
    status VARCHAR(20),  -- pending, succeeded, failed
    failure_reason TEXT,
    created_at TIMESTAMP
);
```

---

## Monitoring & Observability

### 1. System Health Dashboard

**Metrics to Display:**

| Category | Metric | Source |
|----------|--------|--------|
| **Infrastructure** | Server CPU/Memory | Server metrics |
| **Infrastructure** | Database connections | PostgreSQL |
| **Infrastructure** | Redis memory usage | Redis INFO |
| **Infrastructure** | Queue depth/latency | Laravel Horizon |
| **Application** | Request rate (RPM) | Laravel logs |
| **Application** | Error rate (%) | Error tracking |
| **Application** | Response time (p50, p95, p99) | APM |
| **Business** | Active tenants | Database |
| **Business** | API calls per tenant | Rate limiter |
| **Business** | Storage usage per tenant | Filesystem |

**Dashboard Layout:**
```
┌─────────────────────────────────────────────────────────────┐
│                    SYSTEM HEALTH OVERVIEW                   │
├─────────────────┬─────────────────┬─────────────────────────┤
│   Services      │   Performance   │   Alerts                │
│   ● API: UP     │   RPM: 1,234    │   ⚠ 2 warnings         │
│   ● DB: UP      │   p95: 120ms    │   ✓ 0 critical         │
│   ● Redis: UP   │   Errors: 0.1%  │                         │
│   ● Queue: UP   │                 │                         │
├─────────────────┴─────────────────┴─────────────────────────┤
│                    REQUEST VOLUME (24h)                     │
│   [═══════════════════════════════════════════════════]    │
├─────────────────────────────────────────────────────────────┤
│   ERROR RATE (24h)              │   RESPONSE TIME (24h)    │
│   [Chart]                       │   [Chart]                │
└─────────────────────────────────┴───────────────────────────┘
```

### 2. Error Tracking Integration

**Recommended: Sentry**

**Why Sentry:**
- Real-time error tracking
- Stack traces with context
- Release tracking
- Performance monitoring
- User impact analysis
- Slack/email alerts

**Integration Points:**
```php
// Laravel exception handler integration
public function register(): void
{
    $this->reportable(function (Throwable $e) {
        if (app()->bound('sentry')) {
            app('sentry')->captureException($e);
        }
    });
}
```

**Super Admin Features:**
- View recent errors by severity
- Filter errors by tenant
- Link to Sentry issue
- Error trends over time
- Affected tenant count per error

### 3. Log Aggregation

**Recommended: Laravel Pail (development) + Loki/ELK (production)**

**Log Levels to Track:**

| Level | Use Case | Alert |
|-------|----------|-------|
| emergency | System unusable | Immediate |
| alert | Action required now | Immediate |
| critical | Critical conditions | Within 5 min |
| error | Error conditions | Batch hourly |
| warning | Warning conditions | Daily digest |
| notice | Normal but significant | No alert |
| info | Informational | No alert |
| debug | Debug info | No alert |

**Structured Logging Format:**
```json
{
  "timestamp": "2025-12-12T10:00:00Z",
  "level": "error",
  "message": "Payment processing failed",
  "context": {
    "tenant_id": "uuid",
    "user_id": "uuid",
    "request_id": "uuid",
    "payment_amount": 99.00,
    "error_code": "card_declined"
  },
  "tags": ["payment", "stripe"]
}
```

**Super Admin Log Viewer:**
- Real-time log streaming
- Filter by tenant, level, time range
- Search across logs
- Download logs for analysis
- Correlation by request_id

### 4. Performance Monitoring (APM)

**Recommended: Laravel Telescope (dev) + New Relic/Datadog (prod)**

**Metrics to Capture:**

| Metric | Description | Threshold |
|--------|-------------|-----------|
| Response time | End-to-end request time | p95 < 500ms |
| Database queries | Count per request | < 20 queries |
| N+1 detection | Lazy loading issues | 0 |
| Memory usage | Per request peak | < 128MB |
| External API calls | Third-party latency | < 2s |
| Queue job duration | Background job time | < 60s |

**Slow Request Investigation:**
- Request timeline breakdown
- Database query analysis
- Cache hit/miss rates
- External service calls

### 5. Business Analytics

**Metrics for Super Admin:**

| Category | Metric | Calculation |
|----------|--------|-------------|
| **Revenue** | MRR | Sum of active subscription amounts |
| **Revenue** | ARR | MRR × 12 |
| **Revenue** | ARPU | MRR / Active tenants |
| **Growth** | New signups | Count per period |
| **Growth** | Conversions | Trial → Paid rate |
| **Growth** | Net revenue retention | (MRR + expansion - churn) / MRR |
| **Churn** | Logo churn | Cancelled tenants / Total |
| **Churn** | Revenue churn | Lost MRR / Total MRR |
| **Usage** | DAU/MAU | Active users ratio |
| **Usage** | Feature adoption | Usage per feature |

**Dashboard Widgets:**
```
┌─────────────────────────────────────────────────────────────┐
│                    BUSINESS METRICS                         │
├──────────────┬──────────────┬──────────────┬────────────────┤
│     MRR      │     ARR      │    ARPU      │   Net Revenue  │
│   €12,450    │   €149,400   │    €89       │   Retention    │
│   ↑ 5.2%     │              │   ↑ 2.1%     │     112%       │
├──────────────┴──────────────┴──────────────┴────────────────┤
│                 REVENUE TREND (12 months)                   │
│   [═══════════════════════════════════════════════════]    │
├─────────────────────────────┬───────────────────────────────┤
│   NEW SIGNUPS (30 days)     │   CHURN (30 days)            │
│   [Chart]                   │   [Chart]                     │
│   Total: 23                 │   Logo: 2.1%                  │
│   Trial: 18                 │   Revenue: 1.8%               │
│   Paid: 5                   │                               │
└─────────────────────────────┴───────────────────────────────┘
```

### 6. Alerting Strategy

**Alert Channels:**
- Critical: SMS + Slack + Email
- Warning: Slack + Email
- Info: Email only

**Alert Rules:**

| Alert | Condition | Severity | Action |
|-------|-----------|----------|--------|
| API down | Health check fails 3× | Critical | Page on-call |
| High error rate | > 5% errors in 5 min | Critical | Page on-call |
| Slow response | p95 > 2s for 10 min | Warning | Notify team |
| Database connections | > 80% pool used | Warning | Scale up |
| Queue backlog | > 1000 jobs pending | Warning | Investigate |
| Failed payments | > 10 failures/hour | Warning | Review |
| Disk space | > 80% used | Warning | Cleanup |
| SSL expiry | < 14 days | Warning | Renew |

---

## Implementation Phases

### Phase 1: Foundation (Week 1-2)
**Goal:** Core infrastructure for payments and monitoring

- [ ] Create payment provider abstraction layer
- [ ] Implement manual payment provider (Tunisia)
- [ ] Add invoice generation service
- [ ] Create subscription payment tables
- [ ] Integrate Sentry for error tracking
- [ ] Add basic health check endpoint
- [ ] Create system status dashboard widget

**Deliverables:**
- Manual payment flow working
- Invoice PDF generation
- Error tracking active
- Health endpoint returning status

### Phase 2: Stripe Integration (Week 3-4)
**Goal:** Automated payments for EU markets

- [ ] Integrate Stripe SDK
- [ ] Implement Stripe payment provider
- [ ] Set up webhook handling
- [ ] Add payment method management UI
- [ ] Implement subscription lifecycle
- [ ] Add dunning for failed payments
- [ ] Tax calculation setup

**Deliverables:**
- Stripe checkout working
- Webhooks processing
- Subscription automation
- Failed payment retry

### Phase 3: Analytics & Monitoring (Week 5-6)
**Goal:** Full observability stack

- [ ] Implement MRR/ARR calculations
- [ ] Add revenue dashboard
- [ ] Create tenant usage tracking
- [ ] Build log viewer in admin
- [ ] Add APM integration
- [ ] Create alert rules
- [ ] Build anomaly detection

**Deliverables:**
- Revenue metrics dashboard
- Log viewer functional
- Alerts configured
- Performance baselines set

### Phase 4: Operations & Security (Week 7-8)
**Goal:** Production-ready operations

- [ ] Implement tenant impersonation
- [ ] Add 2FA for super admins
- [ ] Create bulk operations UI
- [ ] Build tenant data export
- [ ] Add announcement system
- [ ] Implement feature flags
- [ ] Security hardening review

**Deliverables:**
- Impersonation working
- 2FA enforced
- GDPR export ready
- Feature flags active

---

## Technical Architecture

### Service Structure

```
app/
├── Modules/
│   └── Admin/
│       ├── Domain/
│       │   ├── PaymentProvider.php
│       │   ├── Invoice.php
│       │   ├── SubscriptionPayment.php
│       │   └── DunningAttempt.php
│       ├── Application/
│       │   ├── Services/
│       │   │   ├── PaymentService.php
│       │   │   ├── InvoiceService.php
│       │   │   ├── SubscriptionService.php
│       │   │   ├── MetricsService.php
│       │   │   └── AlertService.php
│       │   ├── Commands/
│       │   │   ├── ProcessDunning.php
│       │   │   ├── GenerateInvoices.php
│       │   │   └── CalculateMetrics.php
│       │   └── DTOs/
│       │       ├── RevenueMetricsData.php
│       │       ├── SystemHealthData.php
│       │       └── TenantAnalyticsData.php
│       ├── Infrastructure/
│       │   ├── Providers/
│       │   │   ├── StripePaymentProvider.php
│       │   │   └── ManualPaymentProvider.php
│       │   ├── Monitoring/
│       │   │   ├── SentryErrorReporter.php
│       │   │   ├── MetricsCollector.php
│       │   │   └── AlertDispatcher.php
│       │   └── External/
│       │       └── StripeClient.php
│       └── Presentation/
│           ├── Controllers/
│           │   ├── PaymentController.php
│           │   ├── InvoiceController.php
│           │   ├── MetricsController.php
│           │   └── MonitoringController.php
│           └── Webhooks/
│               └── StripeWebhookController.php
```

### API Endpoints (New)

```
# Payments & Billing
POST   /api/v1/admin/payment-providers           # Configure provider
GET    /api/v1/admin/invoices                    # List all invoices
GET    /api/v1/admin/invoices/{id}               # Invoice details
POST   /api/v1/admin/invoices/{id}/mark-paid     # Manual payment record
GET    /api/v1/admin/tenants/{id}/invoices       # Tenant invoices
GET    /api/v1/admin/tenants/{id}/payments       # Tenant payments
POST   /api/v1/admin/tenants/{id}/generate-invoice # Generate proforma

# Monitoring
GET    /api/v1/admin/health                      # System health
GET    /api/v1/admin/metrics/revenue             # Revenue metrics
GET    /api/v1/admin/metrics/usage               # Usage metrics
GET    /api/v1/admin/errors                      # Recent errors
GET    /api/v1/admin/logs                        # Log viewer
GET    /api/v1/admin/performance                 # APM data

# Operations
POST   /api/v1/admin/tenants/{id}/impersonate    # Login as tenant
POST   /api/v1/admin/announcements               # Create announcement
GET    /api/v1/admin/feature-flags               # List feature flags
POST   /api/v1/admin/feature-flags               # Create/update flag
```

### Environment Configuration

```env
# Payment Providers
STRIPE_KEY=pk_live_xxx
STRIPE_SECRET=sk_live_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx
PAYMENT_DEFAULT_PROVIDER=manual  # or 'stripe'

# Monitoring
SENTRY_DSN=https://xxx@sentry.io/xxx
SENTRY_ENVIRONMENT=production
LOG_CHANNEL=stack
LOG_LEVEL=info

# APM (optional)
NEW_RELIC_LICENSE_KEY=xxx
NEW_RELIC_APP_NAME=AutoERP

# Alerts
ALERT_SLACK_WEBHOOK=https://hooks.slack.com/xxx
ALERT_EMAIL=alerts@company.com
ALERT_SMS_PROVIDER=twilio
```

---

## Next Steps

1. **Review this document** with stakeholders
2. **Prioritize Phase 1** items for immediate implementation
3. **Set up Sentry** account and integrate
4. **Design invoice PDF** template
5. **Create Stripe** test account for development

---

## Related Documents

- [Payment Provider Integration Guide](./PAYMENT-PROVIDERS.md) (to be created)
- [Monitoring Setup Guide](./MONITORING-SETUP.md) (to be created)
- [Alert Runbook](./ALERT-RUNBOOK.md) (to be created)
- [Impersonation Security Policy](./IMPERSONATION-POLICY.md) (to be created)

---

*Document Version: 1.0*
*Created: December 2025*
*Last Updated: December 2025*
