<?php

namespace App\Http\Requests\Organizations;

use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Only Owners change the requirement (ADR-040), and an Owner can only turn it
 * on with a confirmed second factor of their own, so nobody locks themself out.
 */
class UpdateTwoFactorRequirementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('organization');

        return $organization instanceof Organization
            && $this->user()?->can('requireTwoFactor', $organization) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'require_two_factor' => [
                'required',
                'boolean',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $user = $this->user();

                    if (filter_var($value, FILTER_VALIDATE_BOOLEAN) && $user instanceof User && ! $user->hasEnabledTwoFactorAuthentication()) {
                        $fail(__('Enable two-factor authentication on your own account first.'));
                    }
                },
            ],
        ];
    }
}
