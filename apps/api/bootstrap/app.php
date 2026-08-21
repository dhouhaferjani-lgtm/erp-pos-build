<?php

use App\Http\Middleware\CompanyContextMiddleware;
use App\Http\Middleware\CrossTenantContext;
use App\Http\Middleware\EnsureCentralAdmin;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\RequireAnyPermission;
use App\Http\Middleware\RequireCentralAdminRole;
use App\Http\Middleware\RequireModule;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\ValidateLocationAccess;
use App\Modules\Accounting\Domain\Exceptions\UnpostableCorrectingEntryException;
use App\Modules\BatchExpiry\Domain\Exceptions\InsufficientBatchStockException;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\CountryDefaults\Domain\Exceptions\CountryDefaultsProvisioningUnavailableException;
use App\Modules\Document\Domain\Exceptions\DocumentHasPaymentsException;
use App\Modules\Document\Domain\Exceptions\ReturnDecisionConflictException;
use App\Modules\Document\Domain\Exceptions\ReturnDecisionForbiddenException;
use App\Modules\Document\Domain\Exceptions\ReturnDecisionMismatchesGoodsException;
use App\Modules\Document\Domain\Exceptions\ReturnLocationAmbiguousException;
use App\Modules\Document\Domain\Exceptions\ReturnLocationUnresolvedException;
use App\Modules\Document\Domain\Exceptions\ReturnNothingDeliveredException;
use App\Modules\Document\Domain\Exceptions\ReturnQuantityExceededException;
use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\ResolveTenancy;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Inventory\Domain\Exceptions\AdjustmentAlreadyCorrectedException;
use App\Modules\Inventory\Domain\Exceptions\AdjustmentExceedsAvailableException;
use App\Modules\Inventory\Domain\Exceptions\BatchNotApplicableException;
use App\Modules\Inventory\Domain\Exceptions\BatchRequiredForLineException;
use App\Modules\Inventory\Domain\Exceptions\CannotCorrectACorrectionException;
use App\Modules\Inventory\Domain\Exceptions\ContraLinesImmutableException;
use App\Modules\Inventory\Domain\Exceptions\LineTenantMismatchException;
use App\Modules\Inventory\Domain\Exceptions\StockAdjustmentStateException;
use App\Modules\Inventory\Domain\Exceptions\StockMovedSinceAuthoringException;
use App\Modules\Inventory\Domain\Exceptions\UseBatchWriteOffException;
use App\Modules\POS\Domain\Exceptions\DailyRefundCapExceededException;
use App\Modules\POS\Domain\Exceptions\LegacyCorrectionRetiredException;
use App\Modules\POS\Domain\Exceptions\ManagerOverrideRequiredException;
use App\Modules\POS\Domain\Exceptions\RefundDestinationNotAllowedException;
use App\Modules\POS\Domain\Exceptions\RefundWindowClosedException;
use App\Modules\Replenishment\Domain\Exceptions\CrossCompanyReplayException;
use App\Modules\Scheduling\Infrastructure\Http\Middleware\VerifyCaptcha;
use App\Modules\SupportAccess\Presentation\Middleware\ImpersonationAudit;
use App\Modules\SupportAccess\Presentation\Middleware\ImpersonationContext;
use App\Modules\SupportAccess\Presentation\Middleware\ImpersonationResponseMasking;
use App\Modules\SupportAccess\Presentation\Middleware\ImpersonationWriteGuard;
use App\Modules\Taxation\Domain\Exceptions\DocumentPeriodLockedException;
use App\Modules\Treasury\Domain\Exceptions\InsufficientRepositoryBalanceException;
use App\Modules\Voucher\Domain\Exceptions\VoucherDuplicateInTransactionException;
use App\Modules\Voucher\Domain\Exceptions\VoucherExpiredException;
use App\Modules\Voucher\Domain\Exceptions\VoucherInsufficientBalanceException;
use App\Modules\Voucher\Domain\Exceptions\VoucherInvalidStatusException;
use App\Modules\Voucher\Domain\Exceptions\VoucherNotForThisCustomerException;
use App\Modules\Voucher\Domain\Exceptions\VoucherNotForThisTerminalException;
use App\Shared\Exceptions\PermissionDeniedException;
use App\Shared\Exceptions\ReturnPeriodLockedException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Sentry\Laravel\Integration;
use Sentry\State\Scope;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust the reverse proxy (nginx / Traefik) that terminates TLS in front
        // of the container. Without this Laravel reads the upstream (plain HTTP)
        // scheme and mints every absolute URL — route(), url(), asset(), signed
        // URLs — as `http://…`, which a browser on an https:// page blocks as
        // mixed content (BUG-005 / RCA A1, aggravating factor).
        //
        // `at: '*'` trusts any forwarding hop — a CIDR would have to track
        // Dokploy's ephemeral bridge subnets. The safety therefore comes from
        // the HEADER set, not the proxy list (authz gate, 2026-08-06):
        //
        // The framework default also trusts X-Forwarded-HOST and -PREFIX. With
        // `at: '*'` that makes the request host attacker-controlled for anything
        // able to reach the container, and Dokploy co-locates containers on a
        // shared host — so "only the proxy can reach it" is verified for
        // external traffic (no `ports:` on the api service) but NOT for
        // co-resident containers. A poisoned host would corrupt every
        // url()/route()/asset() and any absolute signed URL.
        //
        // A1 only needs the SCHEME, so trust exactly FOR | PROTO | PORT.
        // Deploy invariant: the api service must never publish `ports:`.
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_PORT);

        // Register middleware aliases
        $middleware->alias([
            'super_admin' => EnsureSuperAdmin::class,
            'central_admin' => EnsureCentralAdmin::class,
            'central_admin_role' => RequireCentralAdminRole::class,
            'validate.location.access' => ValidateLocationAccess::class,
            'module' => RequireModule::class,
            'require.any.permission' => RequireAnyPermission::class,
            'scheduling.captcha' => VerifyCaptcha::class,
            'cross_tenant' => CrossTenantContext::class,
        ]);

        // Exclude auth endpoints from CSRF verification for token-based clients
        // (desktop apps, mobile apps) that don't use browser cookies
        $middleware->validateCsrfTokens(except: [
            'api/v1/auth/login',
            'api/v1/auth/register',
            'broadcasting/auth',
        ]);

        $middleware->prependToGroup('api', [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        // Pre-auth tenancy resolver (topology §9.4). Runs as part of the `api`
        // and Identity `web` groups, which expand BEFORE route-level
        // `auth:sanctum`, so the tenant is bound before the authenticated User
        // is resolved. Covers EVERY auth:sanctum surface, not just /auth/*.
        // It runs before SecurityHeaders/SetLocale/CompanyContext so any
        // tenant-scoped resolution downstream sees the initialized tenant
        // (a no-op DB switch today; a real one post-flip — see TenancyResolver).
        $middleware->appendToGroup('api', [
            ResolveTenancy::class,
            SecurityHeaders::class,
            SetLocale::class,
            CompanyContextMiddleware::class,
            ImpersonationContext::class,
            ImpersonationWriteGuard::class,
            ImpersonationAudit::class,
            ImpersonationResponseMasking::class,
        ]);

        // The Identity auth routes (login/register/verify-email/reset) sit under
        // the `web` group; wire the resolver there too so the tenant-qualified
        // pre-auth link flows initialize their tenant before the token lookup.
        $middleware->appendToGroup('web', [
            ResolveTenancy::class,
            ImpersonationContext::class,
            ImpersonationWriteGuard::class,
            ImpersonationAudit::class,
            ImpersonationResponseMasking::class,
        ]);

        // Group append order alone is NOT enough: Laravel's middleware-priority
        // sort runs Authenticate (auth:sanctum) BEFORE an un-prioritized
        // ResolveTenancy, so on authenticated routes the User would be resolved on
        // the central connection before the tenant database is opened — a 500 in
        // DB-per-tenant mode. Pin ResolveTenancy immediately before the
        // authentication middleware in the priority list (and after StartSession,
        // which precedes it, so the web-session tenant path still resolves) so the
        // tenant is always bound before authentication on every surface.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: ResolveTenancy::class,
        );
        $middleware->appendToPriorityList(
            after: AuthenticatesRequests::class,
            append: SetPermissionsTeam::class,
        );
        $middleware->appendToPriorityList(
            after: SetPermissionsTeam::class,
            append: EnforceTokenTenantClaim::class,
        );
        $middleware->appendToPriorityList(
            after: EnforceTokenTenantClaim::class,
            append: ImpersonationContext::class,
        );
        $middleware->appendToPriorityList(
            after: ImpersonationContext::class,
            append: ImpersonationWriteGuard::class,
        );
        $middleware->appendToPriorityList(
            after: ImpersonationWriteGuard::class,
            append: ImpersonationAudit::class,
        );
        $middleware->appendToPriorityList(
            after: ImpersonationAudit::class,
            append: ImpersonationResponseMasking::class,
        );

        // Ensure API requests get JSON responses for auth failures
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return null; // Don't redirect, let exception handler deal with it
            }

            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Sentry integration with context enrichment
        Integration::handles($exceptions);

        // Add custom context to Sentry errors
        $exceptions->reportable(function (Throwable $e) {
            if (app()->bound('sentry')) {
                \Sentry\configureScope(function (Scope $scope): void {
                    // Add tenant context
                    if (app()->bound(CompanyContext::class)) {
                        $companyContext = app(CompanyContext::class);
                        if ($companyId = $companyContext->getCompanyId()) {
                            $scope->setTag('company_id', $companyId);
                        }
                    }

                    // Add user context
                    $user = auth()->user();
                    if ($user instanceof User) {
                        $scope->setUser([
                            'id' => $user->id,
                            'email' => $user->email,
                            'tenant_id' => $user->tenant_id,
                        ]);
                        $scope->setTag('tenant_id', $user->tenant_id);
                    }

                    // Add request context
                    if (! app()->runningInConsole()) {
                        $scope->setExtra('request_id', request()->header('X-Request-ID'));
                        $scope->setExtra('url', request()->fullUrl());
                        $scope->setExtra('method', request()->method());
                    }
                });
            }
        });

        // Return JSON 401 for API authentication failures
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'UNAUTHENTICATED',
                        'message' => __('auth.unauthenticated'),
                    ],
                ], 401);
            }
        });

        // Return JSON 403 for authorization denials. Descriptive + i18n so an
        // onboarding tenant never hits a bare 403; names the missing ability when
        // it was raised via the AuthorizesAbility trait (PermissionDeniedException).
        //
        // NOTE: Laravel's prepareException() converts AuthorizationException into a
        // Symfony AccessDeniedHttpException (with the original as `previous`) BEFORE
        // render callbacks match — so we must type on the converted type and recover
        // the ability from getPrevious(). This also catches plain abort(403) so no
        // API 403 is ever a bare framework message.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if (! ($request->expectsJson() || $request->is('api/*'))) {
                return null;
            }

            $previous = $e->getPrevious();
            $ability = $previous instanceof PermissionDeniedException ? $previous->ability : null;

            // Plan CF CF-D8. The guided cancel flow authorizes its GOODS leg
            // separately from the cancel itself, and the two denials have different
            // remedies: "you cannot cancel this invoice" is a dead end for the user,
            // while "you cannot record the goods return" is "ask a manager". A
            // dedicated code keeps the modal from having to guess. Recognised here
            // rather than through its own render callback because Laravel's
            // prepareException() converts every AuthorizationException into this
            // Symfony type BEFORE render callbacks match (see the note above), so a
            // callback typed on the domain exception would never fire.
            if ($previous instanceof ReturnDecisionForbiddenException) {
                return response()->json([
                    'error' => [
                        'code' => ReturnDecisionForbiddenException::CODE,
                        'message' => __('messages.document.return_decision_forbidden', [
                            'ability' => $previous->ability,
                        ]),
                        'ability' => $previous->ability,
                    ],
                ], 403);
            }

            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => $ability !== null
                        ? __('auth.permission_denied', ['ability' => $ability])
                        : __('auth.permission_denied_generic'),
                    'ability' => $ability,
                ],
            ], 403);
        });

        // Return JSON 404 for model not found
        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                $modelName = class_basename($e->getModel());

                return response()->json([
                    'error' => [
                        'code' => 'NOT_FOUND',
                        'message' => __('messages.resource_not_found', ['resource' => $modelName]),
                    ],
                ], 404);
            }
        });

        // Return JSON 422 for validation errors with structured format
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'VALIDATION_ERROR',
                        'message' => $e->getMessage(),
                        'errors' => $e->errors(),
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (CrossCompanyReplayException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'REPLENISHMENT_UUID_COMPANY_CONFLICT',
                        'message' => $e->getMessage(),
                    ],
                ], 409);
            }
        });

        // Return JSON 422 for POS refund-flow exceptions — typed codes so the
        // frontend (RefundConfirmModal / refundConfirmation.ts) can route to the
        // correct inline UI (manager PIN panel, cap message, window-closed notice,
        // destination-blocked notice) rather than showing a generic toast.
        // These MUST be registered before the generic DomainException handler
        // because three of them extend \DomainException and Laravel 11 closures
        // match in registration order (first match wins).
        $exceptions->render(function (ManagerOverrideRequiredException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'MANAGER_OVERRIDE_REQUIRED',
                        'message' => $e->getMessage(),
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (DailyRefundCapExceededException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'DAILY_REFUND_CAP_EXCEEDED',
                        'message' => $e->getMessage(),
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (RefundWindowClosedException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'REFUND_WINDOW_CLOSED',
                        'message' => $e->getMessage(),
                    ],
                ], 422);
            }
        });

        // v3-refund-chain-integration spec §9.1/§9.4 — the typed refusal
        // copy MUST NOT say "use the legacy path" (per §9.4's correction:
        // once acknowledgement completes, the legacy path IS the one being
        // 409-blocked, so pointing a cashier at it would describe a dead
        // end). Exact copy per §9.4.
        $exceptions->render(function (LegacyCorrectionRetiredException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'LEGACY_CORRECTION_RETIRED',
                        'message' => 'Refunds are temporarily unavailable on this terminal — contact support',
                    ],
                ], 409);
            }
        });

        // RefundDestinationNotAllowedException extends \RuntimeException (not
        // \DomainException), so without this handler it would bubble to a 500.
        $exceptions->render(function (RefundDestinationNotAllowedException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'REFUND_DESTINATION_NOT_ALLOWED',
                        'message' => $e->getMessage(),
                    ],
                ], 422);
            }
        });

        // Codex review B5 (2026-05-01): Voucher redemption exceptions extend
        // \RuntimeException (not \DomainException), so without these handlers
        // the new ReceiptPaymentService::processReceiptPayments call to
        // VoucherRedemptionService::redeem would bubble to 500 on the
        // online checkout path. Map each to a typed 422 with a stable
        // error.code so the POS UI can surface clear cashier messages
        // (e.g. "Voucher not found", "Insufficient balance"). The codes
        // are deliberately distinct rather than collapsing to a single
        // BUSINESS_ERROR — the cashier needs different recovery actions
        // for "wrong code" vs. "voucher empty" vs. "wrong terminal."
        $exceptions->render(function (VoucherInvalidStatusException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'VOUCHER_INVALID_STATUS',
                        'message' => $e->getMessage(),
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (VoucherInsufficientBalanceException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'VOUCHER_INSUFFICIENT_BALANCE',
                        'message' => $e->getMessage(),
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (VoucherExpiredException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'VOUCHER_EXPIRED',
                        'message' => $e->getMessage(),
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (VoucherNotForThisTerminalException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'VOUCHER_WRONG_TERMINAL',
                        'message' => $e->getMessage(),
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (VoucherNotForThisCustomerException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'VOUCHER_WRONG_CUSTOMER',
                        'message' => $e->getMessage(),
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (VoucherDuplicateInTransactionException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'VOUCHER_DUPLICATE_IN_TRANSACTION',
                        'message' => $e->getMessage(),
                    ],
                ], 422);
            }
        });

        // W-5b Option B (owner ruling 2026-08-05): a typed 422 so the FE can
        // render both the available balance and the refused amount rather
        // than a generic BUSINESS_ERROR toast. Registered BEFORE the generic
        // DomainException handler below, per the same registration-order
        // rule as the POS refund-flow exceptions above.
        $exceptions->render(function (InsufficientRepositoryBalanceException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'INSUFFICIENT_REPOSITORY_BALANCE',
                        'message' => __('messages.treasury.insufficient_repository_balance', [
                            'available' => $e->available,
                            'requested' => $e->requested,
                            'currency' => $e->currency,
                        ]),
                        'repository_id' => $e->repositoryId,
                        'available' => $e->available,
                        'requested' => $e->requested,
                        'resulting_balance' => $e->resultingBalance,
                        'currency' => $e->currency,
                    ],
                ], 422);
            }
        });

        // R2-F1 — a cancellation refused because the document's VAT period is
        // CLOSED or FILED. Typed so the FE can tell the two apart: a CLOSED period
        // can be reopened by an accountant, a FILED one never can, and only the
        // second is a hard "issue a credit note" dead end. Registered BEFORE the
        // generic DomainException handler (it extends \DomainException and
        // Laravel 11 matches render callbacks in registration order).
        $exceptions->render(function (DocumentPeriodLockedException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => $e->refusalCode->value,
                        'message' => $e->getMessage(),
                        'document_number' => $e->documentNumber,
                        'period_label' => $e->periodLabel,
                        'period_status' => $e->periodStatus->value,
                    ],
                ], 422);
            }
        });

        // ---------------------------------------------------------------------
        // DPA V7 — stock-adjustment document refusals.
        //
        // ALL of these extend \DomainException, and Laravel 11 matches render
        // callbacks in REGISTRATION ORDER (first match wins), so every one of
        // them MUST stay above the generic DomainException handler below or the
        // frontend receives BUSINESS_ERROR and loses the code it routes on.
        //
        // Every quantity-bearing payload carries `quantity_decimals`: the two
        // acknowledgeable refusals are rendered with the product unit's
        // precision, and without it on the wire the only fallback is a literal
        // scale — exactly what the quantity-display ratchet forbids.
        // ---------------------------------------------------------------------
        $exceptions->render(function (StockMovedSinceAuthoringException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'STOCK_MOVED_SINCE_AUTHORING',
                        'message' => $e->getMessage(),
                        'details' => [
                            // Keyed on (product_id, variant_id, batch_uuid) — NEVER on a
                            // line id: an immediate-post refusal rolls the draft back with
                            // its transaction, so `line_id` would dangle (D15a).
                            'lines' => [[
                                'line_id' => null,
                                'product_id' => $e->productId,
                                'variant_id' => $e->variantId,
                                'batch_uuid' => $e->batchUuid,
                                'observed_before' => $e->observedBefore,
                                'quantity_before' => $e->quantityBefore,
                                'quantity_decimals' => $e->quantityDecimals,
                            ]],
                        ],
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (AdjustmentExceedsAvailableException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'ADJUSTMENT_EXCEEDS_AVAILABLE',
                        'message' => $e->getMessage(),
                        'details' => [
                            'product_id' => $e->productId,
                            'location_id' => $e->locationId,
                            'quantity_before' => $e->quantityBefore,
                            'reserved' => $e->reserved,
                            'available' => $e->available,
                            'delta_quantity' => $e->deltaQuantity,
                            'quantity_decimals' => $e->quantityDecimals,
                            // The frontend renders this as an ACKNOWLEDGEABLE refusal
                            // rather than a dead end (D1a).
                            'overridable' => true,
                        ],
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (InsufficientBatchStockException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'INSUFFICIENT_BATCH_STOCK',
                        'message' => $e->getMessage(),
                        'details' => [
                            'shortfall' => $e->shortfall,
                        ],
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (BatchRequiredForLineException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'BATCH_REQUIRED_FOR_LINE',
                        'message' => $e->getMessage(),
                        'details' => ['product_id' => $e->productId],
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (BatchNotApplicableException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'BATCH_NOT_APPLICABLE',
                        'message' => $e->getMessage(),
                        'details' => [
                            'product_id' => $e->productId,
                            'batch_uuid' => $e->batchUuid,
                        ],
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (UseBatchWriteOffException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'USE_BATCH_WRITE_OFF',
                        'message' => $e->getMessage(),
                        'details' => [
                            'product_id' => $e->productId,
                            'reason_code' => $e->reasonCode->value,
                        ],
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (LineTenantMismatchException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'LINE_TENANT_MISMATCH',
                        'message' => $e->getMessage(),
                        'details' => [
                            'product_id' => $e->productId,
                            'expected_tenant_id' => $e->expectedTenantId,
                            'found_tenant_id' => $e->foundTenantId,
                        ],
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (StockAdjustmentStateException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'INVALID_ADJUSTMENT_STATE',
                        'message' => $e->getMessage(),
                        'details' => [
                            'adjustment_id' => $e->adjustmentId,
                            'current_status' => $e->currentStatus->value,
                            'attempted' => $e->attemptedAction,
                            // allowedTransitions() is TOTAL, so the legal set can be
                            // reported rather than guessed at (D5).
                            'allowed' => $e->allowedValues(),
                        ],
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (AdjustmentAlreadyCorrectedException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'ADJUSTMENT_ALREADY_CORRECTED',
                        'message' => $e->getMessage(),
                        'details' => [
                            'adjustment_id' => $e->adjustmentId,
                            'correction_id' => $e->correctionId,
                        ],
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (ContraLinesImmutableException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'CONTRA_LINES_IMMUTABLE',
                        'message' => $e->getMessage(),
                        'details' => [
                            'adjustment_id' => $e->adjustmentId,
                            'corrects_adjustment_id' => $e->correctsAdjustmentId,
                        ],
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (CannotCorrectACorrectionException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'CANNOT_CORRECT_A_CORRECTION',
                        'message' => $e->getMessage(),
                        'details' => [
                            'adjustment_id' => $e->adjustmentId,
                            'corrects_adjustment_id' => $e->correctsAdjustmentId,
                        ],
                    ],
                ], 422);
            }
        });

        // Plan CF T6 — the guided cancel flow's typed refusals. All 422, all
        // registered BEFORE the generic DomainException handler (Laravel 11 matches
        // render callbacks in registration order), because every one of them extends
        // \DomainException and would otherwise be flattened into BUSINESS_ERROR —
        // leaving the modal unable to tell "nothing was delivered" from "the location
        // is unknown" from "someone already decided", which are three different
        // remedies.
        $exceptions->render(function (ReturnNothingDeliveredException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => ReturnNothingDeliveredException::CODE,
                        'message' => $e->getMessage(),
                        'invoice_id' => $e->invoiceId,
                        'invoice_number' => $e->invoiceNumber,
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (ReturnLocationUnresolvedException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => ReturnLocationUnresolvedException::CODE,
                        'message' => $e->getMessage(),
                        'invoice_id' => $e->invoiceId,
                        'invoice_number' => $e->invoiceNumber,
                    ],
                ], 422);
            }
        });

        // Gate CF round 1 / FE B3 — the boundary enforcement of "explicit, never
        // silent": a decision that ASSERTS AN ABSENCE of goods, posted for an invoice
        // that has them. The mirror of RETURN_NOTHING_DELIVERED.
        $exceptions->render(function (ReturnDecisionMismatchesGoodsException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => ReturnDecisionMismatchesGoodsException::CODE,
                        'message' => $e->getMessage(),
                        'invoice_id' => $e->invoiceId,
                        'invoice_number' => $e->invoiceNumber,
                        'posted_mode' => $e->postedMode,
                        'requires_return_decision' => $e->requiresReturnDecision,
                        'goods_issued' => $e->goodsIssued,
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (ReturnLocationAmbiguousException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => ReturnLocationAmbiguousException::CODE,
                        'message' => $e->getMessage(),
                        'invoice_id' => $e->invoiceId,
                        'invoice_number' => $e->invoiceNumber,
                        'product_ids' => $e->productIds,
                    ],
                ], 422);
            }
        });

        // Reaches this renderer RE-THROWN by RefundService after the
        // commit-then-refuse append (CF-D5). This entry only RENDERS it — it must
        // never swallow or re-wrap it, or the append/refuse ordering is lost.
        $exceptions->render(function (ReturnDecisionConflictException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => ReturnDecisionConflictException::CODE,
                        'message' => $e->getMessage(),
                        'invoice_id' => $e->invoiceId,
                        'invoice_number' => $e->invoiceNumber,
                        'existing_decision' => $e->existingDecision,
                        'return_note_id' => $e->existingReturnNoteId(),
                    ],
                ], 422);
            }
        });

        // Plan CF T6 / frontend gate I-1. `RefundController::cancelInvoice()`'s
        // generic catch flattens failures into `{error: <string>, code: <string>}`,
        // against which the web app's `getErrorMessage` yields axios' bare "Request
        // failed with status code 422" and `extractErrorCode` (which reads
        // `error.code`) yields undefined — so the modal could not recognise this
        // blocking, unfixable-by-retry condition at all.
        //
        // The TOP-LEVEL `code` here duplicates `error.code` DELIBERATELY. Plan CF
        // names `DocumentCancelConsolidationTest` as part of this lane's regression
        // contract ("stays green unmodified") and that class asserts
        // `assertJsonPath('code', 'DOCUMENT_HAS_PAYMENTS')` against the old flat
        // envelope in three tests, while the same plan requires the typed envelope.
        // Emitting both keys is the only way to satisfy both requirements; see the CF
        // report's contradiction note. Drop the duplicate only together with those
        // assertions.
        $exceptions->render(function (DocumentHasPaymentsException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => DocumentHasPaymentsException::CODE,
                        'message' => $e->getMessage(),
                        'document_id' => $e->documentId,
                        'document_number' => $e->documentNumber,
                    ],
                    'code' => DocumentHasPaymentsException::CODE,
                ], 422);
            }
        });

        // Plan CF CF-D3 — a return note refused because the period covering the
        // date the user typed is CLOSED / FILED / locked. Three distinct codes: the
        // FE renders this INLINE ON THE DATE FIELD (the obstacle is the date, not
        // the invoice) and only a non-FILED refusal may offer "ask your accountant
        // to reopen it". `recoverable` is emitted rather than left for the client to
        // re-derive from the code, so the two can never disagree.
        $exceptions->render(function (ReturnPeriodLockedException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => $e->refusalCode->value,
                        'message' => $e->getMessage(),
                        'document_number' => $e->documentNumber,
                        'return_date' => $e->returnDate,
                        'period_label' => $e->periodLabel,
                        'recoverable' => $e->refusalCode->isRecoverable(),
                    ],
                ], 422);
            }
        });

        // Plan CF T2 — the return-note over-return cap, moved out of
        // `ReturnNoteController` into `ReturnNoteService` so the guided cancel flow
        // and the standalone `POST /return-notes` cannot drift apart (fiscal gate
        // I-8). The guard used to throw a Presentation `HttpResponseException` with
        // a hand-built body; this entry reproduces that body EXACTLY — same
        // `error.code`, same five `details` keys — so nothing asserting on the old
        // envelope regresses. Registered BEFORE the generic DomainException handler
        // (Laravel 11 matches render callbacks in registration order).
        $exceptions->render(function (ReturnQuantityExceededException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => $e->refusalCode,
                        'message' => $e->getMessage(),
                        'details' => $e->details(),
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (CountryDefaultsProvisioningUnavailableException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                Log::error('Country-defaults provisioning configuration refused company creation.', [
                    'exception_class' => $e::class,
                    'exception_message' => $e->getMessage(),
                ]);

                return response()->json([
                    'error' => [
                        'code' => $e->publicCode(),
                        'message' => trans($e->translationKey()),
                    ],
                ], 503);
            }
        });

        // R2-F4 — a correcting-entry document that cannot be posted. Typed for the
        // same reason as the CF period lock above: the FE branches on WHY (a
        // missing link, an unknown account and "this does not rebalance the
        // document" have completely different remedies). Registered BEFORE the
        // generic DomainException handler, which it extends — Laravel 11 matches
        // render callbacks in REGISTRATION ORDER, so below it the typed code would
        // collapse into BUSINESS_ERROR.
        $exceptions->render(function (UnpostableCorrectingEntryException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => $e->refusalCode->value,
                        'message' => $e->getMessage(),
                        'document_number' => $e->documentNumber,
                    ],
                ], 422);
            }
        });

        // Return JSON 422 for domain/business logic errors
        $exceptions->render(function (DomainException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'BUSINESS_ERROR',
                        'message' => $e->getMessage(),
                    ],
                ], 422);
            }
        });

        // ---------------------------------------------------------------------
        // Catch-all API renderer — MUST stay LAST (Laravel 11 matches render
        // callbacks in registration order, first match wins).
        //
        // BUG-005 / RCA B3: everything above is typed. Any other Throwable fell
        // through to Laravel's default `{"message":"Server Error"}`, while the
        // SPA interceptor dereferences `data.error.message` unconditionally —
        // producing a TypeError inside the interceptor, unusable error text and
        // a poisoned Sentry breadcrumb. Every api/* failure now carries the same
        // `{error: {code, message, request_id}}` envelope as the typed handlers.
        //
        // Two families are deliberately NOT intercepted:
        //
        //  - HttpExceptionInterface (404 / 405 / 419 / 429 …) already renders
        //    with a meaningful status; rewriting it here would turn an unknown
        //    route into a 500.
        //  - HttpResponseException carries a fully-built Response the thrower
        //    chose. It is a plain RuntimeException (NOT HttpExceptionInterface),
        //    and Laravel matches render callbacks BEFORE the Handler's own match
        //    on it (Foundation/Exceptions/Handler.php), so without this guard a
        //    HttpResponseException thrown OUTSIDE a route action — from
        //    middleware, where Illuminate\Routing\Route::run() cannot catch it —
        //    would be discarded and returned as a 500.
        //
        // AuthenticationException / ValidationException survive only because
        // their callbacks are registered earlier in this file. Keep them there.
        // ---------------------------------------------------------------------
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->expectsJson() || $request->is('api/*'))) {
                return null;
            }

            if ($e instanceof HttpExceptionInterface || $e instanceof HttpResponseException) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    // The raw message is only exposed with debug on — in
                    // production it can carry SQL, file paths or tenant data.
                    'message' => config('app.debug') === true
                        ? $e->getMessage()
                        : __('messages.server_error'),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 500);
        });
    })->create();
