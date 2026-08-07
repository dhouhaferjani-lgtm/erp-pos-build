<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Presentation\Middleware;

use App\Modules\SupportAccess\Domain\Services\SensitiveResponseMasker;
use App\Shared\Contracts\SupportAccess\ImpersonationContextProvider;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ImpersonationResponseMasking
{
    public function __construct(
        private readonly ImpersonationContextProvider $context,
        private readonly SensitiveResponseMasker $masker,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($this->context->current() === null) {
            return $response;
        }

        if (! $response instanceof JsonResponse) {
            return response()->json([
                'error' => [
                    'code' => 'IMPERSONATION_EXPORT_BLOCKED',
                    'message' => 'File and binary exports are unavailable during support access.',
                ],
            ], 403);
        }

        $payload = $response->getData(true);
        if (is_array($payload)) {
            $response->setData($this->masker->mask($payload));
        }

        return $response;
    }
}
