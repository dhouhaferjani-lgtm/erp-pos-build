<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SuperAdmin;
use App\Modules\CountryDefaults\Application\DTOs\AssignmentMatrixData;
use App\Modules\CountryDefaults\Application\DTOs\AssignmentMatrixMetaData;
use App\Modules\CountryDefaults\Application\DTOs\AssignmentMatrixRowData;
use App\Modules\CountryDefaults\Application\DTOs\TemplateSummaryData;
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
            $matrix[] = new AssignmentMatrixRowData(
                country_code: $country['country_code'],
                name: $country['name'],
                pinned: $country['pinned'],
                domain: $domain,
                assignment_id: $assignment?->id,
                template_id: $assignment?->template_id,
                template: $assignment === null ? null : TemplateSummaryData::fromNullableModel($assignment->template),
            );
        }

        $payload = new AssignmentMatrixData(
            data: $matrix,
            meta: new AssignmentMatrixMetaData(
                catalog_version: StaticCountryCatalogProvider::VERSION,
            ),
        );

        return response()->json($payload->toArray());
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
        } catch (DomainException) {
            return response()->json(['error' => [
                'code' => 'ASSIGNMENT_CONFLICT',
                'message' => trans('country_defaults.errors.assignment_conflict'),
            ]], 409);
        } catch (QueryException $exception) {
            if (! $this->isAssignmentUniqueConflict($exception)) {
                throw $exception;
            }

            return response()->json(['error' => [
                'code' => 'ASSIGNMENT_CONFLICT',
                'message' => trans('country_defaults.errors.assignment_conflict'),
            ]], 409);
        }
    }

    private function isAssignmentUniqueConflict(QueryException $exception): bool
    {
        $message = $exception->getMessage();
        $sqlState = $exception->errorInfo[0] ?? $exception->getCode();
        if ($sqlState === '23505') {
            return str_contains($message, 'country_template_assignments_country_domain_unique');
        }

        return $sqlState === '23000'
            && str_contains($message, 'UNIQUE constraint failed')
            && str_contains($message, 'country_template_assignments.country_code')
            && str_contains($message, 'country_template_assignments.domain');
    }
}
