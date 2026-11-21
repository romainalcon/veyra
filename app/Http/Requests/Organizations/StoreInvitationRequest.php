<?php

namespace App\Http\Requests\Organizations;

use App\Domain\Organizations\DTOs\InvitationData;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = OrganizationRole::tryFrom((string) $this->input('role'));

        if ($role === null) {
            // Let validation report the invalid role to authorized managers.
            return $this->user()?->can('manageMembers', $this->organization()) === true;
        }

        return $this->user()?->can('invite', [$this->organization(), $role]) === true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::enum(OrganizationRole::class)],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $alreadyMember = $this->organization()->users()
                    ->where('users.email', $this->input('email'))
                    ->exists();

                if ($alreadyMember) {
                    $validator->errors()->add('email', __('This person is already a member of the organization.'));
                }
            },
        ];
    }

    public function organization(): Organization
    {
        /** @var Organization $organization */
        $organization = $this->route('organization');

        return $organization;
    }

    public function toData(): InvitationData
    {
        /** @var array{email: string, role: string} $validated */
        $validated = $this->validated();

        return InvitationData::fromArray($validated);
    }
}
