<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Scheduling\Domain\Bay;
use App\Modules\Scheduling\Domain\Contracts\BayRepositoryInterface;
use App\Modules\Scheduling\Domain\Enums\BayType;
use App\Modules\Scheduling\Presentation\Requests\StoreBayRequest;
use App\Modules\Scheduling\Presentation\Requests\UpdateBayRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Staff CRUD for physical bays.
 *
 * - index / show: `scheduling.bays.view`
 * - store / update / destroy: `scheduling.bays.manage`
 */
final class BayController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly BayRepositoryInterface $bays,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->can('scheduling.bays.view')) {
            abort(403);
        }

        $companyId = $this->companyContext->requireCompanyId();
        $bays = $this->bays->findByCompany($companyId);

        return response()->json([
            'data' => array_map(fn (Bay $bay): array => $this->serialize($bay), $bays),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->can('scheduling.bays.view')) {
            abort(403);
        }
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $bay = $this->bays->findById($id);
        if ($bay === null || $bay->company_id !== $this->companyContext->requireCompanyId()) {
            abort(404);
        }

        return response()->json(['data' => $this->serialize($bay)]);
    }

    public function store(StoreBayRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $companyId = $this->companyContext->requireCompanyId();

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $bay = new Bay;
        $bay->id = (string) Str::uuid();
        $bay->tenant_id = $company->tenant_id;
        $bay->company_id = $companyId;
        $bay->location_id = (string) $data['location_id'];
        $bay->code = (string) $data['code'];
        $bay->name = (string) $data['name'];
        $bay->bay_type = BayType::from((string) $data['bay_type']);
        $bay->display_order = isset($data['display_order']) ? (int) $data['display_order'] : 0;
        $bay->operating_hours = is_array($data['operating_hours']) ? $data['operating_hours'] : [];
        $bay->notes = isset($data['notes']) && is_string($data['notes']) ? $data['notes'] : null;
        $bay->is_active = isset($data['is_active']) ? (bool) $data['is_active'] : true;

        $bay = $this->bays->save($bay);

        return response()->json(['data' => $this->serialize($bay)], 201);
    }

    public function update(UpdateBayRequest $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $companyId = $this->companyContext->requireCompanyId();
        $bay = $this->bays->findById($id);
        if ($bay === null || $bay->company_id !== $companyId) {
            abort(404);
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        if (isset($data['code']) && is_string($data['code'])) {
            $bay->code = $data['code'];
        }
        if (isset($data['name']) && is_string($data['name'])) {
            $bay->name = $data['name'];
        }
        if (isset($data['bay_type']) && is_string($data['bay_type'])) {
            $bay->bay_type = BayType::from($data['bay_type']);
        }
        if (isset($data['display_order'])) {
            $bay->display_order = (int) $data['display_order'];
        }
        if (isset($data['operating_hours']) && is_array($data['operating_hours'])) {
            $bay->operating_hours = $data['operating_hours'];
        }
        if (array_key_exists('notes', $data)) {
            $bay->notes = is_string($data['notes']) ? $data['notes'] : null;
        }
        if (isset($data['is_active'])) {
            $bay->is_active = (bool) $data['is_active'];
        }

        $bay = $this->bays->save($bay);

        return response()->json(['data' => $this->serialize($bay)]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->can('scheduling.bays.manage')) {
            abort(403);
        }
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $bay = $this->bays->findById($id);
        if ($bay === null || $bay->company_id !== $this->companyContext->requireCompanyId()) {
            abort(404);
        }

        $bay->delete();

        return response()->json(['data' => ['id' => $id, 'deleted' => true]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Bay $bay): array
    {
        return [
            'id' => $bay->id,
            'tenant_id' => $bay->tenant_id,
            'company_id' => $bay->company_id,
            'location_id' => $bay->location_id,
            'code' => $bay->code,
            'name' => $bay->name,
            'bay_type' => $bay->bay_type->value,
            'display_order' => $bay->display_order,
            'operating_hours' => $bay->operating_hours,
            'notes' => $bay->notes,
            'is_active' => $bay->is_active,
            'created_at' => $bay->created_at->toIso8601String(),
            'updated_at' => $bay->updated_at->toIso8601String(),
        ];
    }
}
