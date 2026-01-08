<?php

declare(strict_types=1);

namespace App\Rules;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationContext;
use App\Modules\Identity\Domain\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;

/**
 * Validation rule to check if user has access to a location.
 *
 * Usage in Form Requests:
 *
 * public function rules(): array
 * {
 *     return [
 *         'location_id' => ['required', 'uuid', new ValidLocationAccess()],
 *         'from_location_id' => ['required', 'uuid', new ValidLocationAccess()],
 *         'to_location_id' => ['required', 'uuid', new ValidLocationAccess()],
 *     ];
 * }
 *
 * Or with custom company ID:
 *
 * public function rules(): array
 * {
 *     return [
 *         'location_id' => ['required', 'uuid', new ValidLocationAccess($this->company_id)],
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
    private ?string $companyId;

    /**
     * Create a new rule instance.
     *
     * @param  string|null  $companyId  Optional company ID (defaults to current company context)
     */
    public function __construct(?string $companyId = null)
    {
        $this->companyId = $companyId;
    }

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
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

        // Get company ID from constructor or current context
        $companyId = $this->companyId;
        if ($companyId === null) {
            $companyContext = app(CompanyContext::class);
            if (! $companyContext->hasCompany()) {
                $fail('Company context is required to validate location access.');

                return;
            }
            $companyId = $companyContext->requireCompanyId();
        }

        // Validate access using LocationContext
        $locationContext = app(LocationContext::class);
        if (! $locationContext->canAccessLocation($value, $companyId, $user)) {
            $fail('You do not have permission to access this location.');
        }
    }
}
