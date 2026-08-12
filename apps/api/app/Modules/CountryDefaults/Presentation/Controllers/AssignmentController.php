<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SuperAdmin;
use App\Modules\CountryDefaults\Application\Services\StaticCountryCatalogProvider;
use App\Modules\CountryDefaults\Application\Services\TemplateAssignmentService;
use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Infrastructure\Models\CountryTemplateAssignment;
use App\Modules\CountryDefaults\Presentation\Requests\AssignTemplateRequest;
use App\Modules\CountryDefaults\Presentation\Requests\ListAssignmentsRequest;
use App\Modules\CountryDefaults\Presentation\Resources\CountryTemplateAssignmentResource;
use App\Shared\Architecture\CrossTenantRoute;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use LogicException;

final class AssignmentController extends Controller
{
    public function __construct(
        private readonly TemplateAssignmentService $assignments,
        private readonly StaticCountryCatalogProvider $countries,
    ) {}

    #[CrossTenantRoute(reason: 'Builds the central ISO country-to-template assignment matrix without querying any tenant catalog.')]
    public function index(ListAssignmentsRequest $request): JsonResponse
    {
        $domain = TemplateDomain::from($request->validated('domain'));
        $assignments = CountryTemplateAssignment::query()
            ->with('template')
            ->where('domain', $domain->value)
            ->get()
            ->keyBy('country_code');
        $matrix = [];
        foreach ($this->countries->countries() as $country) {
            if ($country['country_code'] === '*') {
                $country['name'] = trans('country_defaults.catalog.generic_fallback');
            }
            $assignment = $assignments->get($country['country_code']);
            $matrix[] = [
                ...$country,
                'domain' => $domain->value,
                'assignment_id' => $assignment?->id,
                'template_id' => $assignment?->template_id,
                'template' => $assignment === null
                    ? null
                    : (new CountryTemplateAssignmentResource($assignment))->resolve($request)['template'],
            ];
        }

        return response()->json([
            'data' => $matrix,
            'meta' => ['catalog_version' => StaticCountryCatalogProvider::VERSION],
        ]);
    }

    #[CrossTenantRoute(reason: 'Assigns or re-points one central country/domain row through the locked audited lifecycle service.')]
    public function update(AssignTemplateRequest $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof SuperAdmin) {
            throw new LogicException('Country Defaults routes require a central administrator.');
        }

        try {
            $assignment = $this->assignments->assign(
                $request->validated('country_code'),
                TemplateDomain::from($request->validated('domain')),
                $request->validated('template_id'),
                $actor,
            );

            return response()->json(['data' => (new CountryTemplateAssignmentResource($assignment->load('template')))->resolve($request)]);
        } catch (DomainException|QueryException $exception) {
            return response()->json(['error' => [
                'code' => 'ASSIGNMENT_CONFLICT',
                'message' => trans('country_defaults.errors.assignment_conflict'),
            ]], 409);
        }
    }
}
