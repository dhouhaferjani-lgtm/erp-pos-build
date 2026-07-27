<?php

declare(strict_types=1);

namespace App\Rules;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationContext;
use App\Modules\Identity\Domain\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validation rule to check if user has access to a location.
 *
 * Usage in Form Requests:
 *
 * public function rules(): array
 * {
 *     return [
 *         'location_id' => ['required', 'uuid', new ValidLocationAccess($locationContext, $companyContext)],
 *         'from_location_id' => ['required', 'uuid', new ValidLocationAccess($locationContext, $companyContext)],
 *         'to_location_id' => ['required', 'uuid', new ValidLocationAccess($locationContext, $companyContext)],
 *     ];
 * }
 *
 * Or with custom company ID:
 *
 * public function rules(): array
 * {
 *     return [
 *         'location_id' => ['required', 'uuid', new ValidLocationAccess($locationContext, $companyContext, $this->company_id)],
 *     ];
 * }
 *
 * This rule validates:
 * - Location exists
 * - User has an active membership in the company
 * - User's membership allows access to this location
 */
class ValidLocationAccess implements ValidationRule
{
    public function __construct(
        private readonly LocationContext $locationContext,
        private readonly CompanyContext $companyContext,
        private readonly ?string $companyId = null,
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Value must be a string (UUID)
        if (! is_string($value)) {
            $fail("The {$attribute} must be a valid location ID.");

            return;
        }

        /** @var User|null $user */
        $user = Auth::user();

        if (! $user instanceof User) {
            $fail('Authentication required to validate location access.');

            return;
        }

        $companyId = $this->companyId ?? ($this->companyContext->hasCompany()
            ? $this->companyContext->requireCompanyId()
            : null);

        if ($companyId === null) {
            $fail('Company context is required to validate location access.');

            return;
        }

        // Validate access using LocationContext
        if (! $this->locationContext->canAccessLocation($value, $companyId, $user)) {
            $fail('You do not have permission to access this location.');
        }
    }
}
