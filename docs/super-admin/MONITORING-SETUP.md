# Monitoring & Observability Setup Guide

> Production monitoring for AutoERP SaaS platform

This document details the monitoring stack, integration steps, and best practices for maintaining visibility into system health, performance, and business metrics.

---

## Monitoring Stack Overview

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         MONITORING ARCHITECTURE                         │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│  ┌─────────────┐    ┌─────────────┐    ┌─────────────┐                │
│  │   Sentry    │    │   Metrics   │    │    Logs     │                │
│  │   (Errors)  │    │ (Prometheus)│    │   (Loki)    │                │
│  └──────┬──────┘    └──────┬──────┘    └──────┬──────┘                │
│         │                  │                  │                        │
│         └──────────────────┼──────────────────┘                        │
│                            │                                           │
│                     ┌──────▼──────┐                                    │
│                     │   Grafana   │                                    │
│                     │ (Dashboards)│                                    │
│                     └──────┬──────┘                                    │
│                            │                                           │
│         ┌──────────────────┼──────────────────┐                        │
│         │                  │                  │                        │
│  ┌──────▼──────┐    ┌──────▼──────┐    ┌──────▼──────┐                │
│  │   Slack     │    │   Email     │    │ PagerDuty   │                │
│  │  (Alerts)   │    │  (Reports)  │    │ (On-call)   │                │
│  └─────────────┘    └─────────────┘    └─────────────┘                │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## 1. Error Tracking (Sentry)

### Why Sentry

- Real-time error detection and alerting
- Full stack traces with context
- Release tracking and regression detection
- Performance monitoring (transactions)
- User impact analysis
- Integrations (Slack, GitHub, Jira)

### Installation

```bash
composer require sentry/sentry-laravel
php artisan sentry:publish --dsn=https://xxx@sentry.io/xxx
```

### Configuration

```php
// config/sentry.php

return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),
    'release' => env('SENTRY_RELEASE', trim(exec('git rev-parse --short HEAD'))),
    'environment' => env('APP_ENV', 'production'),

    'breadcrumbs' => [
        'logs' => true,
        'sql_queries' => true,
        'sql_bindings' => true,
        'queue_info' => true,
        'command_info' => true,
    ],

    'send_default_pii' => false,

    'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE', 0.2),

    'profiles_sample_rate' => env('SENTRY_PROFILES_SAMPLE_RATE', 0.1),
];
```

### Laravel Integration

```php
// bootstrap/app.php

use Sentry\Laravel\Integration;

->withExceptions(function (Exceptions $exceptions): void {
    Integration::handles($exceptions);

    // Custom context for all errors
    $exceptions->reportable(function (Throwable $e) {
        if (app()->bound('sentry')) {
            \Sentry\configureScope(function (\Sentry\State\Scope $scope): void {
                // Add tenant context
                if ($tenantId = app('tenant.id')) {
                    $scope->setTag('tenant_id', $tenantId);
                }

                // Add user context
                if ($user = auth()->user()) {
                    $scope->setUser([
                        'id' => $user->id,
                        'email' => $user->email,
                    ]);
                }

                // Add request context
                $scope->setExtra('request_id', request()->header('X-Request-ID'));
            });
        }
    });
})
```

### Custom Error Context

```php
// Add context to specific operations
use function Sentry\captureException;
use function Sentry\withScope;

public function processPayment(Invoice $invoice): void
{
    withScope(function (\Sentry\State\Scope $scope) use ($invoice): void {
        $scope->setContext('invoice', [
            'id' => $invoice->id,
            'amount' => $invoice->total,
            'tenant_id' => $invoice->tenant_id,
        ]);

        try {
            // Process payment...
        } catch (\Exception $e) {
            captureException($e);
            throw $e;
        }
    });
}
```

### Performance Monitoring

```php
// Manual transaction for background jobs
use function Sentry\startTransaction;
use function Sentry\SentrySdk;

public function handle(): void
{
    $transaction = startTransaction([
        'op' => 'job',
        'name' => 'ProcessDunning',
    ]);

    SentrySdk::getCurrentHub()->setSpan($transaction);

    try {
        // Job logic with child spans
        $span = $transaction->startChild([
            'op' => 'db.query',
            'description' => 'Fetch overdue invoices',
        ]);

        $invoices = Invoice::overdue()->get();

        $span->finish();

        // More work...

        $transaction->setStatus(\Sentry\Tracing\SpanStatus::ok());
    } catch (\Exception $e) {
        $transaction->setStatus(\Sentry\Tracing\SpanStatus::internalError());
        throw $e;
    } finally {
        $transaction->finish();
    }
}
```

---

## 2. Application Metrics

### Prometheus Integration

```bash
composer require promphp/prometheus_client_php
```

### Metrics Service

```php
<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Monitoring;

use Prometheus\CollectorRegistry;
use Prometheus\Counter;
use Prometheus\Gauge;
use Prometheus\Histogram;

final class MetricsCollector
{
    private Counter $httpRequestsTotal;
    private Histogram $httpRequestDuration;
    private Gauge $activeUsers;
    private Gauge $queueSize;
    private Counter $paymentsTotal;
    private Gauge $mrr;

    public function __construct(
        private readonly CollectorRegistry $registry,
    ) {
        $this->initializeMetrics();
    }

    private function initializeMetrics(): void
    {
        $this->httpRequestsTotal = $this->registry->getOrRegisterCounter(
            'autoerp',
            'http_requests_total',
            'Total HTTP requests',
            ['method', 'route', 'status']
        );

        $this->httpRequestDuration = $this->registry->getOrRegisterHistogram(
            'autoerp',
            'http_request_duration_seconds',
            'HTTP request duration in seconds',
            ['method', 'route'],
            [0.01, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10]
        );

        $this->activeUsers = $this->registry->getOrRegisterGauge(
            'autoerp',
            'active_users',
            'Number of active users in last 24h',
            ['tenant_id']
        );

        $this->queueSize = $this->registry->getOrRegisterGauge(
            'autoerp',
            'queue_size',
            'Number of jobs in queue',
            ['queue']
        );

        $this->paymentsTotal = $this->registry->getOrRegisterCounter(
            'autoerp',
            'payments_total',
            'Total payments processed',
            ['provider', 'status', 'currency']
        );

        $this->mrr = $this->registry->getOrRegisterGauge(
            'autoerp',
            'mrr_cents',
            'Monthly Recurring Revenue in cents',
            ['currency', 'plan']
        );
    }

    public function recordRequest(
        string $method,
        string $route,
        int $status,
        float $duration
    ): void {
        $this->httpRequestsTotal->incBy(1, [$method, $route, (string) $status]);
        $this->httpRequestDuration->observe($duration, [$method, $route]);
    }

    public function recordPayment(
        string $provider,
        string $status,
        string $currency,
        float $amount
    ): void {
        $this->paymentsTotal->incBy(1, [$provider, $status, $currency]);
    }

    public function updateActiveUsers(string $tenantId, int $count): void
    {
        $this->activeUsers->set($count, [$tenantId]);
    }

    public function updateQueueSize(string $queue, int $size): void
    {
        $this->queueSize->set($size, [$queue]);
    }

    public function updateMRR(string $currency, string $plan, float $amount): void
    {
        $this->mrr->set((int) ($amount * 100), [$currency, $plan]);
    }
}
```

### Metrics Middleware

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Admin\Infrastructure\Monitoring\MetricsCollector;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RecordMetrics
{
    public function __construct(
        private readonly MetricsCollector $metrics,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        $duration = microtime(true) - $start;

        $this->metrics->recordRequest(
            method: $request->method(),
            route: $request->route()?->getName() ?? 'unknown',
            status: $response->getStatusCode(),
            duration: $duration
        );

        return $response;
    }
}
```

### Metrics Endpoint

```php
// routes/api.php

Route::get('/metrics', function () {
    $registry = app(CollectorRegistry::class);
    $renderer = new RenderTextFormat();

    return response(
        $renderer->render($registry->getMetricFamilySamples()),
        200,
        ['Content-Type' => RenderTextFormat::MIME_TYPE]
    );
})->middleware('auth.metrics'); // Restrict access
```

---

## 3. Structured Logging

### Log Configuration

```php
// config/logging.php

return [
    'default' => env('LOG_CHANNEL', 'stack'),

    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => ['daily', 'stderr'],
            'ignore_exceptions' => false,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 14,
            'tap' => [App\Logging\AddContextToLog::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'info'),
            'handler' => StreamHandler::class,
            'formatter' => JsonFormatter::class,
            'with' => [
                'stream' => 'php://stderr',
            ],
            'tap' => [App\Logging\AddContextToLog::class],
        ],
    ],
];
```

### Log Context Enrichment

```php
<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Processor\ProcessorInterface;

final class AddContextToLog
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            $handler->pushProcessor(new class implements ProcessorInterface {
                public function __invoke(array $record): array
                {
                    // Add request context
                    if (app()->runningInConsole() === false) {
                        $record['extra']['request_id'] = request()->header('X-Request-ID');
                        $record['extra']['ip'] = request()->ip();
                        $record['extra']['url'] = request()->fullUrl();
                        $record['extra']['method'] = request()->method();
                    }

                    // Add tenant context
                    if ($tenantId = app('tenant.id')) {
                        $record['extra']['tenant_id'] = $tenantId;
                    }

                    // Add user context
                    if (auth()->check()) {
                        $record['extra']['user_id'] = auth()->id();
                    }

                    // Add environment
                    $record['extra']['environment'] = config('app.env');
                    $record['extra']['hostname'] = gethostname();

                    return $record;
                }
            });
        }
    }
}
```

### Logging Best Practices

```php
// GOOD: Structured logging with context
Log::info('Payment processed', [
    'invoice_id' => $invoice->id,
    'tenant_id' => $invoice->tenant_id,
    'amount' => $payment->amount,
    'provider' => $payment->provider,
    'duration_ms' => $duration * 1000,
]);

// GOOD: Error with exception context
Log::error('Payment failed', [
    'invoice_id' => $invoice->id,
    'error_code' => $e->getCode(),
    'error_message' => $e->getMessage(),
    'exception' => $e,
]);

// BAD: Unstructured logging
Log::info("Payment of {$payment->amount} processed for invoice {$invoice->id}");

// BAD: Sensitive data in logs
Log::info('User login', ['password' => $password]); // NEVER DO THIS
```

---

## 4. Health Checks

### Health Check Service

```php
<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

final class HealthCheckService
{
    public function check(): HealthStatus
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueue(),
            'storage' => $this->checkStorage(),
            'external_apis' => $this->checkExternalApis(),
        ];

        $allHealthy = collect($checks)->every(fn ($check) => $check['healthy']);

        return new HealthStatus(
            healthy: $allHealthy,
            checks: $checks,
            timestamp: now()->toIso8601String(),
        );
    }

    private function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $duration = (microtime(true) - $start) * 1000;

            return [
                'healthy' => true,
                'latency_ms' => round($duration, 2),
                'connections' => DB::connection()->getDoctrineConnection()->getNativeConnection()->getAttribute(\PDO::ATTR_CONNECTION_STATUS) ?? 'unknown',
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkRedis(): array
    {
        try {
            $start = microtime(true);
            Redis::ping();
            $duration = (microtime(true) - $start) * 1000;

            $info = Redis::info();

            return [
                'healthy' => true,
                'latency_ms' => round($duration, 2),
                'used_memory' => $info['used_memory_human'] ?? 'unknown',
                'connected_clients' => $info['connected_clients'] ?? 'unknown',
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkQueue(): array
    {
        try {
            $queueSize = Queue::size();
            $failedCount = DB::table('failed_jobs')->count();

            return [
                'healthy' => $queueSize < 10000, // Alert if queue is backing up
                'pending_jobs' => $queueSize,
                'failed_jobs' => $failedCount,
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkStorage(): array
    {
        try {
            $disk = disk_free_space(storage_path());
            $total = disk_total_space(storage_path());
            $usedPercent = (($total - $disk) / $total) * 100;

            return [
                'healthy' => $usedPercent < 80,
                'free_gb' => round($disk / 1024 / 1024 / 1024, 2),
                'used_percent' => round($usedPercent, 2),
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkExternalApis(): array
    {
        $results = [];

        // Check Stripe
        if (config('services.stripe.secret')) {
            try {
                $start = microtime(true);
                $stripe = new \Stripe\StripeClient(config('services.stripe.secret'));
                $stripe->balance->retrieve();
                $duration = (microtime(true) - $start) * 1000;

                $results['stripe'] = [
                    'healthy' => true,
                    'latency_ms' => round($duration, 2),
                ];
            } catch (\Exception $e) {
                $results['stripe'] = [
                    'healthy' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'healthy' => collect($results)->every(fn ($r) => $r['healthy']),
            'services' => $results,
        ];
    }
}
```

### Health Endpoints

```php
// routes/api.php

// Public health check (for load balancers)
Route::get('/health', function (HealthCheckService $health) {
    $status = $health->check();

    return response()->json([
        'status' => $status->healthy ? 'healthy' : 'unhealthy',
        'timestamp' => $status->timestamp,
    ], $status->healthy ? 200 : 503);
});

// Detailed health check (admin only)
Route::get('/api/v1/admin/health', function (HealthCheckService $health) {
    return response()->json($health->check());
})->middleware(['auth:sanctum', 'super_admin']);
```

---

## 5. Super Admin Monitoring Dashboard

### Dashboard Components

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         SYSTEM OVERVIEW                                 │
├─────────────────┬─────────────────┬─────────────────┬───────────────────┤
│   API Status    │   Database      │     Redis       │     Queue         │
│   ● HEALTHY     │   ● HEALTHY     │   ● HEALTHY     │   ● HEALTHY       │
│   p95: 120ms    │   12ms latency  │   2ms latency   │   45 pending      │
└─────────────────┴─────────────────┴─────────────────┴───────────────────┘

┌─────────────────────────────────────────────────────────────────────────┐
│                    ERROR RATE (Last 24 hours)                          │
│                                                                         │
│  0.5% ┤                                                                │
│       │    ╭─╮                                                          │
│  0.3% ┤    │ │                                                          │
│       │────╯ ╰─────────────────────────────────────────────────────────│
│  0.1% ┤                                                                 │
│       └────────────────────────────────────────────────────────────────│
│         00:00    06:00    12:00    18:00    24:00                      │
└─────────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────┬──────────────────────────────────────────┐
│     RECENT ERRORS            │     SLOW REQUESTS                        │
├──────────────────────────────┼──────────────────────────────────────────┤
│ PaymentException (12)        │ GET /api/v1/reports/aging    3.2s        │
│ ValidationException (8)      │ POST /api/v1/documents       2.1s        │
│ QueryException (3)           │ GET /api/v1/products         1.8s        │
│                              │                                          │
│ [View in Sentry →]           │ [View details →]                         │
└──────────────────────────────┴──────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────┐
│                    TENANT HEALTH                                        │
├─────────────────┬─────────────┬─────────────┬───────────────────────────┤
│ Tenant          │ Status      │ Error Rate  │ Last Active               │
├─────────────────┼─────────────┼─────────────┼───────────────────────────┤
│ Demo Garage     │ ● Active    │ 0.1%        │ 2 minutes ago             │
│ Test Company    │ ● Active    │ 0.0%        │ 1 hour ago                │
│ Suspended Inc   │ ○ Suspended │ -           │ 3 days ago                │
└─────────────────┴─────────────┴─────────────┴───────────────────────────┘
```

### API Endpoints for Dashboard

```php
// Monitoring Controller
Route::prefix('admin/monitoring')->middleware(['auth:sanctum', 'super_admin'])->group(function () {
    Route::get('/overview', [MonitoringController::class, 'overview']);
    Route::get('/errors', [MonitoringController::class, 'errors']);
    Route::get('/slow-requests', [MonitoringController::class, 'slowRequests']);
    Route::get('/tenant-health', [MonitoringController::class, 'tenantHealth']);
    Route::get('/metrics/requests', [MonitoringController::class, 'requestMetrics']);
    Route::get('/metrics/errors', [MonitoringController::class, 'errorMetrics']);
});
```

### Monitoring Controller

```php
<?php

declare(strict_types=1);

namespace App\Modules\Admin\Presentation\Controllers;

use App\Modules\Admin\Application\Services\HealthCheckService;
use App\Modules\Admin\Application\Services\MetricsService;
use Illuminate\Http\JsonResponse;

final class MonitoringController
{
    public function __construct(
        private readonly HealthCheckService $healthService,
        private readonly MetricsService $metricsService,
    ) {}

    public function overview(): JsonResponse
    {
        return response()->json([
            'data' => [
                'health' => $this->healthService->check(),
                'metrics' => [
                    'requests_per_minute' => $this->metricsService->getRequestsPerMinute(),
                    'error_rate' => $this->metricsService->getErrorRate(),
                    'p95_latency' => $this->metricsService->getP95Latency(),
                    'active_users' => $this->metricsService->getActiveUsers(),
                ],
                'alerts' => $this->metricsService->getActiveAlerts(),
            ],
        ]);
    }

    public function errors(): JsonResponse
    {
        // Fetch from Sentry API or local error tracking
        return response()->json([
            'data' => $this->metricsService->getRecentErrors(limit: 50),
        ]);
    }

    public function slowRequests(): JsonResponse
    {
        return response()->json([
            'data' => $this->metricsService->getSlowRequests(
                threshold: 1.0, // seconds
                limit: 20
            ),
        ]);
    }

    public function tenantHealth(): JsonResponse
    {
        return response()->json([
            'data' => $this->metricsService->getTenantHealthMetrics(),
        ]);
    }

    public function requestMetrics(): JsonResponse
    {
        return response()->json([
            'data' => $this->metricsService->getRequestTimeSeries(
                period: '24h',
                interval: '1h'
            ),
        ]);
    }

    public function errorMetrics(): JsonResponse
    {
        return response()->json([
            'data' => $this->metricsService->getErrorTimeSeries(
                period: '24h',
                interval: '1h'
            ),
        ]);
    }
}
```

---

## 6. Alerting

### Alert Rules

```yaml
# alerts.yml (for Prometheus Alertmanager)

groups:
  - name: autoerp
    rules:
      # High error rate
      - alert: HighErrorRate
        expr: |
          sum(rate(autoerp_http_requests_total{status=~"5.."}[5m]))
          /
          sum(rate(autoerp_http_requests_total[5m]))
          > 0.05
        for: 5m
        labels:
          severity: critical
        annotations:
          summary: "High error rate detected"
          description: "Error rate is {{ $value | humanizePercentage }}"

      # Slow response times
      - alert: SlowResponseTime
        expr: |
          histogram_quantile(0.95,
            rate(autoerp_http_request_duration_seconds_bucket[5m])
          ) > 2
        for: 10m
        labels:
          severity: warning
        annotations:
          summary: "Slow response times"
          description: "p95 latency is {{ $value }}s"

      # Database connection issues
      - alert: DatabaseConnectionsHigh
        expr: pg_stat_activity_count > 80
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "High database connections"
          description: "{{ $value }} active connections"

      # Queue backlog
      - alert: QueueBacklog
        expr: autoerp_queue_size > 1000
        for: 15m
        labels:
          severity: warning
        annotations:
          summary: "Queue backlog detected"
          description: "{{ $value }} jobs pending"

      # Failed payments spike
      - alert: FailedPaymentsSpike
        expr: |
          increase(autoerp_payments_total{status="failed"}[1h]) > 10
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "Spike in failed payments"
          description: "{{ $value }} failed payments in last hour"
```

### Slack Alert Integration

```php
<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Monitoring;

use Illuminate\Support\Facades\Http;

final class AlertDispatcher
{
    public function __construct(
        private readonly string $slackWebhook,
        private readonly string $environment,
    ) {}

    public function sendAlert(Alert $alert): void
    {
        $color = match ($alert->severity) {
            'critical' => '#FF0000',
            'warning' => '#FFA500',
            'info' => '#0000FF',
            default => '#808080',
        };

        Http::post($this->slackWebhook, [
            'attachments' => [
                [
                    'color' => $color,
                    'title' => "[{$this->environment}] {$alert->title}",
                    'text' => $alert->description,
                    'fields' => [
                        [
                            'title' => 'Severity',
                            'value' => $alert->severity,
                            'short' => true,
                        ],
                        [
                            'title' => 'Time',
                            'value' => $alert->timestamp->format('Y-m-d H:i:s'),
                            'short' => true,
                        ],
                    ],
                    'footer' => 'AutoERP Monitoring',
                    'ts' => $alert->timestamp->timestamp,
                ],
            ],
        ]);
    }
}
```

---

## 7. Implementation Checklist

### Phase 1: Error Tracking
- [ ] Create Sentry account
- [ ] Install sentry-laravel package
- [ ] Configure DSN and environment
- [ ] Add tenant/user context to errors
- [ ] Set up Slack integration for alerts
- [ ] Create error dashboard widget

### Phase 2: Health Checks
- [ ] Implement HealthCheckService
- [ ] Create health endpoints
- [ ] Configure load balancer health checks
- [ ] Add external service checks

### Phase 3: Metrics
- [ ] Install Prometheus client
- [ ] Create MetricsCollector service
- [ ] Add metrics middleware
- [ ] Expose /metrics endpoint
- [ ] Configure Prometheus scraping

### Phase 4: Dashboards
- [ ] Set up Grafana
- [ ] Create system overview dashboard
- [ ] Create tenant health dashboard
- [ ] Create business metrics dashboard

### Phase 5: Alerting
- [ ] Configure alert rules
- [ ] Set up Slack notifications
- [ ] Create on-call rotation (PagerDuty)
- [ ] Document runbooks

---

## Environment Variables

```env
# Sentry
SENTRY_LARAVEL_DSN=https://xxx@sentry.io/xxx
SENTRY_TRACES_SAMPLE_RATE=0.2
SENTRY_PROFILES_SAMPLE_RATE=0.1

# Monitoring
PROMETHEUS_ENABLED=true
METRICS_AUTH_TOKEN=your-secret-token

# Alerting
ALERT_SLACK_WEBHOOK=https://hooks.slack.com/xxx
ALERT_EMAIL=alerts@company.com
ALERT_PAGERDUTY_KEY=xxx

# Grafana
GRAFANA_URL=https://grafana.company.com
GRAFANA_API_KEY=xxx
```

---

*Document Version: 1.0*
*Created: December 2025*
