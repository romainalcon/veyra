<?php

namespace App\Http\Requests\Organizations;

use App\Domain\Organizations\Enums\OrganizationRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = OrganizationRole::tryFrom((string) $this->input('role'));

        if ($role === null) {
            return $this->user()?->can('manageMembers', $this->route('organization')) === true;
        }

        return $this->user()?->can('updateMember', [
            $this->route('organization'),
            $this->route('member'),
            $role,
        ]) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(OrganizationRole::class)],
        ];
    }

    public function role(): OrganizationRole
    {
        return OrganizationRole::from((string) $this->validated('role'));
    }
}
