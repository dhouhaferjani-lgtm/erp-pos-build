<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SuperAdmin;
use App\Modules\CountryDefaults\Application\Services\TemplatePublishingService;
use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Domain\ValueObjects\CertificationScope;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\CountryDefaults\Presentation\Requests\CloneTemplateRequest;
use App\Modules\CountryDefaults\Presentation\Requests\CreateTemplateRequest;
use App\Modules\CountryDefaults\Presentation\Requests\ListTemplatesRequest;
use App\Modules\CountryDefaults\Presentation\Requests\PublishTemplateRequest;
use App\Modules\CountryDefaults\Presentation\Requests\ShowTemplateRequest;
use App\Modules\CountryDefaults\Presentation\Requests\UpdateTemplateRequest;
use App\Modules\CountryDefaults\Presentation\Requests\ValidateTemplateRequest;
use App\Modules\CountryDefaults\Presentation\Resources\TemplateResource;
use App\Modules\CountryDefaults\Presentation\Resources\TemplateSummaryResource;
use App\Modules\CountryDefaults\Presentation\Resources\TemplateValidationReportResource;
use App\Shared\Architecture\CrossTenantRoute;
use App\Shared\Contracts\CountryDefaults\CountryAccountingCapabilities;
use App\Services\AdminAuditService;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

final class TemplateController extends Controller
{
    public function __construct(
        private readonly TemplatePublishingService $publishing,
        private readonly CountryAccountingCapabilities $capabilities,
        private readonly AdminAuditService $audit,
    ) {}

    #[CrossTenantRoute(reason: 'Lists central Country Defaults templates for an authorized central administrator; no tenant data is read.')]
    public function index(ListTemplatesRequest $request): JsonResponse
    {
        $domain = TemplateDomain::from($request->validated('domain'));
        $templates = AdminTemplate::query()->where('domain', $domain->value)->orderByDesc('created_at')->get();

        return response()->json(['data' => TemplateSummaryResource::collection($templates)->resolve($request)]);
    }

    #[CrossTenantRoute(reason: 'Creates an audited central Country Defaults draft for an authorized central administrator.')]
    public function store(CreateTemplateRequest $request): JsonResponse
    {
        $actor = $this->actor($request);
        $description = $request->validated('description');
        if (! is_string($description) && $description !== null) {
            throw new LogicException('Template description must be a string or null.');
        }
        $template = $this->createDraft(
            TemplateDomain::from($request->validated('domain')),
            $request->validated('name'),
            $description,
            $actor,
        );

        return response()->json(['data' => (new TemplateResource($template->load('accounts')))->resolve($request)], 201);
    }

    #[CrossTenantRoute(reason: 'Clones locked central template content to an audited draft; no tenant data is accessed.')]
    public function clone(CloneTemplateRequest $request): JsonResponse
    {
        return $this->attempt($request, function () use ($request): JsonResponse {
            $template = $this->publishing->cloneToDraft(
                $request->validated('id'),
                $request->validated('name'),
                $this->actor($request),
            );

            return response()->json(['data' => (new TemplateResource($template->load('accounts')))->resolve($request)], 201);
        });
    }

    #[CrossTenantRoute(reason: 'Reads one central Country Defaults template and its central account rows.')]
    public function show(ShowTemplateRequest $request): JsonResponse
    {
        $template = AdminTemplate::query()->with('accounts')->findOrFail($request->validated('id'));

        return response()->json(['data' => (new TemplateResource($template))->resolve($request)]);
    }

    #[CrossTenantRoute(reason: 'Updates audited draft metadata in the central Country Defaults library.')]
    public function update(UpdateTemplateRequest $request): JsonResponse
    {
        return $this->attempt($request, function () use ($request): JsonResponse {
            $attributes = $request->safe()->only(['name', 'description', 'standard_ref']);
            $template = $this->updateDraft($request->validated('id'), $attributes, $this->actor($request));

            return response()->json(['data' => (new TemplateResource($template->load('accounts')))->resolve($request)]);
        });
    }

    #[CrossTenantRoute(reason: 'Deletes an unassigned central Country Defaults draft through the audited lifecycle service.')]
    public function destroy(Request $request, string $id): JsonResponse
    {
        return $this->attempt($request, function () use ($request, $id): JsonResponse {
            $this->publishing->delete($id, $this->actor($request));

            return response()->json([], 204);
        });
    }

    #[CrossTenantRoute(reason: 'Runs central certification validation against locked template rows without reading tenant data.')]
    public function validation(ValidateTemplateRequest $request): JsonResponse
    {
        $scopeCodes = array_values(array_filter(array_map('trim', explode(',', $request->validated('scope')))));
        $errors = [];
        try {
            $scope = new CertificationScope($scopeCodes, $this->capabilities);
            $template = AdminTemplate::query()->with('accounts')->findOrFail($request->validated('id'));
            if (! $template instanceof AdminTemplate) {
                throw new LogicException('Country Defaults template lookup did not resolve a model.');
            }
            $this->publishing->validateAccounts(array_values($template->accounts()->get()->all()), $scope);
        } catch (DomainException|\InvalidArgumentException $exception) {
            $errors[] = trans('country_defaults.errors.template_validation_detail');
        }
        $report = new TemplateValidationReportResource([
            'valid' => $errors === [],
            'scope' => array_map('strtoupper', $scopeCodes),
            'errors' => $errors,
        ]);

        return response()->json(['data' => $report->resolve($request)]);
    }

    #[CrossTenantRoute(reason: 'Publishes and certifies a locked central template with the authenticated central actor recorded atomically.')]
    public function publish(PublishTemplateRequest $request): JsonResponse
    {
        try {
            $template = $this->publishing->publish(
                $request->validated('id'),
                $request->validated('standard_ref'),
                array_values($request->validated('certified_country_codes')),
                $this->actor($request),
            );

            return response()->json(['data' => (new TemplateResource($template->load('accounts')))->resolve($request)]);
        } catch (DomainException $exception) {
            return $this->problem('TEMPLATE_VALIDATION_FAILED', trans('country_defaults.errors.template_validation'), 422);
        }
    }

    #[CrossTenantRoute(reason: 'Archives an unassigned central template through the locked audited lifecycle service.')]
    public function archive(Request $request, string $id): JsonResponse
    {
        return $this->attempt($request, function () use ($request, $id): JsonResponse {
            $template = $this->publishing->archive($id, $this->actor($request));

            return response()->json(['data' => (new TemplateResource($template->load('accounts')))->resolve($request)]);
        });
    }

    private function attempt(Request $request, callable $operation): JsonResponse
    {
        try {
            return $operation();
        } catch (DomainException|LogicException $exception) {
            return $this->problem('TEMPLATE_CONFLICT', trans('country_defaults.errors.template_conflict'), 409);
        }
    }

    private function problem(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => [
            'code' => $code,
            'message' => $message,
        ]], $status);
    }

    private function actor(Request $request): SuperAdmin
    {
        $actor = $request->user();
        if (! $actor instanceof SuperAdmin) {
            throw new LogicException('Country Defaults routes require a central administrator.');
        }

        return $actor;
    }

    private function createDraft(
        TemplateDomain $domain,
        string $name,
        ?string $description,
        SuperAdmin $actor,
    ): AdminTemplate {
        return $this->centralConnection()->transaction(function () use ($domain, $name, $description, $actor): AdminTemplate {
            $template = AdminTemplate::query()->create([
                'domain' => $domain,
                'name' => trim($name),
                'description' => $description,
                'status' => TemplateStatus::Draft,
                'created_by' => $actor->id,
            ]);
            $this->audit->log(
                admin: $actor,
                action: 'country_defaults.template.created',
                entityType: 'admin_template',
                entityId: $template->id,
                newValues: ['domain' => $domain->value, 'name' => $template->name],
            );

            return $template;
        });
    }

    /** @param array{name?: string, description?: string|null, standard_ref?: string|null} $attributes */
    private function updateDraft(string $templateId, array $attributes, SuperAdmin $actor): AdminTemplate
    {
        return $this->centralConnection()->transaction(function () use ($templateId, $attributes, $actor): AdminTemplate {
            $template = AdminTemplate::query()->lockForUpdate()->findOrFail($templateId);
            if ($template->status !== TemplateStatus::Draft) {
                throw new DomainException('Only draft templates can be edited.');
            }
            $before = [
                'name' => $template->name,
                'description' => $template->description,
                'standard_ref' => $template->standard_ref,
            ];
            $template->fill($attributes);
            $template->save();
            $this->audit->log(
                admin: $actor,
                action: 'country_defaults.template.updated',
                entityType: 'admin_template',
                entityId: $template->id,
                oldValues: $before,
                newValues: [
                    'name' => $template->name,
                    'description' => $template->description,
                    'standard_ref' => $template->standard_ref,
                ],
            );

            return $template->fresh() ?? $template;
        });
    }

    private function centralConnection(): ConnectionInterface
    {
        return DB::connection((new AdminTemplate)->getConnectionName());
    }
}
