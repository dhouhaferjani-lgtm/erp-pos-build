<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SuperAdmin;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\CountryDefaults\Application\Services\CanonicalCoaSerializer;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplateAccount;
use App\Modules\CountryDefaults\Presentation\Requests\UpsertTemplateRowsRequest;
use App\Modules\CountryDefaults\Presentation\Resources\TemplateResource;
use App\Shared\Architecture\CrossTenantRoute;
use App\Services\AdminAuditService;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use LogicException;

final class TemplateRowController extends Controller
{
    public function __construct(
        private readonly CanonicalCoaSerializer $serializer,
        private readonly AdminAuditService $audit,
    ) {}

    #[CrossTenantRoute(reason: 'Atomically reconciles central template rows and their central audit evidence; no tenant data is accessed.')]
    public function update(UpsertTemplateRowsRequest $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof SuperAdmin) {
            throw new LogicException('Country Defaults routes require a central administrator.');
        }

        try {
            $rows = array_values($request->validated('rows'));
            $template = $this->replaceDraftRows($request->validated('id'), $rows, $actor);

            return response()->json(['data' => (new TemplateResource($template->load('accounts')))->resolve($request)]);
        } catch (DomainException|LogicException|QueryException $exception) {
            return response()->json(['error' => [
                'code' => 'TEMPLATE_ROWS_CONFLICT',
                'message' => trans('country_defaults.errors.template_conflict'),
            ]], 409);
        }
    }

    /**
     * @param  list<array{id?: string, code: string, name: string, type: string, parent_code?: string|null, system_purpose?: string|null, is_system: bool, sort_order: int}>  $rows
     */
    private function replaceDraftRows(string $templateId, array $rows, SuperAdmin $actor): AdminTemplate
    {
        return $this->centralConnection()->transaction(function () use ($templateId, $rows, $actor): AdminTemplate {
            $template = AdminTemplate::query()->lockForUpdate()->findOrFail($templateId);
            if ($template->status !== TemplateStatus::Draft) {
                throw new DomainException('Only draft template rows can be edited.');
            }
            $existing = $template->accounts()->orderBy('sort_order')->lockForUpdate()->get()->keyBy('id');
            $beforeHash = $this->serializer->hash($this->canonicalRows(array_values($existing->all())));
            foreach ($rows as $row) {
                $id = $row['id'] ?? null;
                if ($id !== null && ! $existing->has($id)) {
                    throw new DomainException('A template row id does not belong to the locked template.');
                }
            }
            foreach (array_reverse($this->rowDeletionOrder(array_values($existing->all()))) as $account) {
                $account->delete();
            }
            foreach ($this->rowInsertionOrder($rows) as $row) {
                $account = new AdminTemplateAccount;
                if (isset($row['id'])) {
                    $account->id = $row['id'];
                }
                $account->fill([
                    'template_id' => $template->id,
                    'code' => trim($row['code']),
                    'name' => trim($row['name']),
                    'type' => AccountType::from($row['type']),
                    'parent_code' => isset($row['parent_code']) ? trim($row['parent_code']) : null,
                    'system_purpose' => isset($row['system_purpose']) ? SystemAccountPurpose::from($row['system_purpose']) : null,
                    'is_system' => $row['is_system'],
                    'sort_order' => $row['sort_order'],
                ]);
                $account->save();
            }
            $afterRows = array_values($template->accounts()->orderBy('sort_order')->get()->all());
            $this->audit->log(
                admin: $actor,
                action: 'country_defaults.template.rows_updated',
                entityType: 'admin_template',
                entityId: $template->id,
                oldValues: ['canonical_hash' => $beforeHash, 'row_count' => $existing->count()],
                newValues: [
                    'canonical_hash' => $this->serializer->hash($this->canonicalRows($afterRows)),
                    'row_count' => count($afterRows),
                ],
            );

            return $template->fresh('accounts') ?? $template;
        });
    }

    /**
     * @param  list<AdminTemplateAccount>  $accounts
     * @return list<array<string, \BackedEnum|bool|int|string|null>>
     */
    private function canonicalRows(array $accounts): array
    {
        return array_map(static fn (AdminTemplateAccount $account): array => [
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type,
            'parent_code' => $account->parent_code,
            'system_purpose' => $account->system_purpose,
            'is_system' => $account->is_system,
            'sort_order' => $account->sort_order,
        ], $accounts);
    }

    /**
     * @param  list<AdminTemplateAccount>  $rows
     * @return list<AdminTemplateAccount>
     */
    private function rowDeletionOrder(array $rows): array
    {
        $byCode = [];
        foreach ($rows as $row) {
            $byCode[$row->code] = $row;
        }
        $ordered = [];
        $visiting = [];
        $visited = [];
        $visit = function (AdminTemplateAccount $row) use (&$visit, &$ordered, &$visiting, &$visited, $byCode): void {
            if (isset($visited[$row->code])) {
                return;
            }
            if (isset($visiting[$row->code])) {
                throw new DomainException('Template row parents must not contain cycles.');
            }
            $visiting[$row->code] = true;
            if ($row->parent_code !== null && isset($byCode[$row->parent_code])) {
                $visit($byCode[$row->parent_code]);
            }
            unset($visiting[$row->code]);
            $visited[$row->code] = true;
            $ordered[] = $row;
        };
        foreach ($rows as $row) {
            $visit($row);
        }

        return $ordered;
    }

    /**
     * @param  list<array{id?: string, code: string, name: string, type: string, parent_code?: string|null, system_purpose?: string|null, is_system: bool, sort_order: int}>  $rows
     * @return list<array{id?: string, code: string, name: string, type: string, parent_code?: string|null, system_purpose?: string|null, is_system: bool, sort_order: int}>
     */
    private function rowInsertionOrder(array $rows): array
    {
        $remaining = [];
        foreach ($rows as $row) {
            $remaining[$row['code']] = $row;
        }
        $ordered = [];
        $inserted = [];
        while ($remaining !== []) {
            $progress = false;
            foreach ($remaining as $code => $row) {
                $parentCode = $row['parent_code'] ?? null;
                if ($parentCode !== null && ! isset($inserted[$parentCode])) {
                    continue;
                }
                $ordered[] = $row;
                $inserted[$code] = true;
                unset($remaining[$code]);
                $progress = true;
            }
            if (! $progress) {
                throw new DomainException('Template row parents must resolve without cycles inside the submitted bulk set.');
            }
        }

        return $ordered;
    }

    private function centralConnection(): ConnectionInterface
    {
        return DB::connection((new AdminTemplate)->getConnectionName());
    }
}
