<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Enums\SuperAdminRole;
use App\Models\SuperAdmin;
use App\Modules\CountryDefaults\Presentation\Requests\CreateDefaultsEditorRequest;
use App\Modules\CountryDefaults\Presentation\Requests\ListDefaultsEditorsRequest;
use App\Modules\CountryDefaults\Presentation\Requests\ResetDefaultsEditorCredentialsRequest;
use App\Modules\CountryDefaults\Presentation\Requests\UpdateDefaultsEditorRequest;
use App\Modules\CountryDefaults\Presentation\Resources\DefaultsEditorResource;
use App\Shared\Architecture\CrossTenantRoute;
use App\Services\AdminAuditService;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;

final class DefaultsEditorController extends Controller
{
    public function __construct(private readonly AdminAuditService $audit) {}

    #[CrossTenantRoute(reason: 'Lists non-super-admin central accounts for super-admin-only Country Defaults lifecycle management.')]
    public function index(ListDefaultsEditorsRequest $request): JsonResponse
    {
        $editors = SuperAdmin::query()
            ->where('role', '!=', SuperAdminRole::SuperAdmin->value)
            ->orderBy('email')
            ->get();

        return response()->json(['data' => DefaultsEditorResource::collection($editors)->resolve($request)]);
    }

    #[CrossTenantRoute(reason: 'Creates one defaults-editor central account and its audit row atomically under super-admin authorization.')]
    public function store(CreateDefaultsEditorRequest $request): JsonResponse
    {
        return $this->attempt(function () use ($request): JsonResponse {
            $password = $request->validated('password');
            if (! is_string($password) && $password !== null) {
                throw new LogicException('Editor password must be a string or null.');
            }
            $result = $this->createEditor(
                $request->validated('name'),
                $request->validated('email'),
                $password,
                $this->actor($request),
            );
            $payload = ['data' => (new DefaultsEditorResource($result['editor']))->resolve($request)];
            if ($result['generated_credential'] !== null) {
                $payload['meta'] = ['generated_credential' => $result['generated_credential']];
            }

            return response()->json($payload, 201);
        });
    }

    #[CrossTenantRoute(reason: 'Changes a non-super-admin central account lifecycle and revokes affected central tokens atomically.')]
    public function update(UpdateDefaultsEditorRequest $request): JsonResponse
    {
        return $this->attempt(function () use ($request): JsonResponse {
            $attributes = $request->safe()->only(['name', 'is_active', 'role']);
            $editor = $this->updateEditor($request->validated('editor_id'), $attributes, $this->actor($request));

            return response()->json(['data' => (new DefaultsEditorResource($editor))->resolve($request)]);
        });
    }

    #[CrossTenantRoute(reason: 'Resets central editor credentials, revokes all tokens, and records the audit atomically.')]
    public function resetCredentials(ResetDefaultsEditorCredentialsRequest $request): JsonResponse
    {
        return $this->attempt(function () use ($request): JsonResponse {
            $password = $request->validated('password');
            if (! is_string($password) && $password !== null) {
                throw new LogicException('Editor password must be a string or null.');
            }
            $result = $this->resetEditorCredentials(
                $request->validated('editor_id'),
                $password,
                $this->actor($request),
            );
            $payload = ['data' => (new DefaultsEditorResource($result['editor']))->resolve($request)];
            if ($result['generated_credential'] !== null) {
                $payload['meta'] = ['generated_credential' => $result['generated_credential']];
            }

            return response()->json($payload);
        });
    }

    private function attempt(callable $operation): JsonResponse
    {
        try {
            return $operation();
        } catch (DomainException|QueryException $exception) {
            return response()->json(['error' => [
                'code' => 'EDITOR_CONFLICT',
                'message' => trans('country_defaults.errors.editor_conflict'),
            ]], 409);
        }
    }

    private function actor(Request $request): SuperAdmin
    {
        $actor = $request->user();
        if (! $actor instanceof SuperAdmin) {
            throw new LogicException('Country Defaults routes require a central administrator.');
        }

        return $actor;
    }

    /** @return array{editor: SuperAdmin, generated_credential: string|null} */
    private function createEditor(string $name, string $email, ?string $password, SuperAdmin $actor): array
    {
        $credential = $password ?? Str::password(24, symbols: true);
        $editor = $this->centralConnection()->transaction(function () use ($name, $email, $credential, $actor): SuperAdmin {
            $editor = SuperAdmin::query()->create([
                'name' => trim($name),
                'email' => strtolower(trim($email)),
                'password' => Hash::make($credential),
                'role' => SuperAdminRole::DefaultsEditor->value,
                'is_active' => true,
            ]);
            $this->audit->log(
                admin: $actor,
                action: 'country_defaults.editor.created',
                entityType: 'super_admin',
                entityId: $editor->id,
                newValues: ['email' => $editor->email, 'role' => $editor->role, 'is_active' => true],
            );

            return $editor;
        });

        return ['editor' => $editor, 'generated_credential' => $password === null ? $credential : null];
    }

    /** @param array{is_active?: bool, role?: string, name?: string} $attributes */
    private function updateEditor(string $editorId, array $attributes, SuperAdmin $actor): SuperAdmin
    {
        return $this->centralConnection()->transaction(function () use ($editorId, $attributes, $actor): SuperAdmin {
            $editor = SuperAdmin::query()->lockForUpdate()->findOrFail($editorId);
            if ($editor->role === SuperAdminRole::SuperAdmin->value) {
                throw new DomainException('The editor lifecycle cannot modify a super-admin account.');
            }
            $before = ['name' => $editor->name, 'role' => $editor->role, 'is_active' => $editor->is_active];
            $editor->fill($attributes);
            $roleChanged = $editor->isDirty('role');
            $disabled = $editor->isDirty('is_active') && $editor->is_active === false;
            $enabled = $editor->isDirty('is_active') && $editor->is_active === true;
            $editor->save();
            if ($roleChanged || $disabled) {
                $editor->tokens()->delete();
            }
            $action = $roleChanged
                ? 'country_defaults.editor.role_changed'
                : ($disabled ? 'country_defaults.editor.disabled' : ($enabled ? 'country_defaults.editor.enabled' : 'country_defaults.editor.updated'));
            $this->audit->log(
                admin: $actor,
                action: $action,
                entityType: 'super_admin',
                entityId: $editor->id,
                oldValues: $before,
                newValues: ['name' => $editor->name, 'role' => $editor->role, 'is_active' => $editor->is_active],
            );

            return $editor->fresh() ?? $editor;
        });
    }

    /** @return array{editor: SuperAdmin, generated_credential: string|null} */
    private function resetEditorCredentials(string $editorId, ?string $password, SuperAdmin $actor): array
    {
        $credential = $password ?? Str::password(24, symbols: true);
        $editor = $this->centralConnection()->transaction(function () use ($editorId, $credential, $actor): SuperAdmin {
            $editor = SuperAdmin::query()->lockForUpdate()->findOrFail($editorId);
            if ($editor->role === SuperAdminRole::SuperAdmin->value) {
                throw new DomainException('The editor lifecycle cannot reset a super-admin account.');
            }
            $editor->forceFill(['password' => Hash::make($credential)])->save();
            $editor->tokens()->delete();
            $this->audit->log(
                admin: $actor,
                action: 'country_defaults.editor.credentials_reset',
                entityType: 'super_admin',
                entityId: $editor->id,
                newValues: ['tokens_revoked' => true],
            );

            return $editor->fresh() ?? $editor;
        });

        return ['editor' => $editor, 'generated_credential' => $password === null ? $credential : null];
    }

    private function centralConnection(): ConnectionInterface
    {
        return DB::connection((new SuperAdmin)->getConnectionName());
    }
}
