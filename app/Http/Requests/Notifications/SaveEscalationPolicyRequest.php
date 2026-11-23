<?php

namespace App\Http\Requests\Notifications;

use App\Domain\Notifications\DTOs\EscalationPolicyData;
use App\Domain\Notifications\Models\EscalationPolicy;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates an escalation policy (ADR-045): 1–5 steps, delays of 1 to 1440
 * minutes strictly increasing with the position, 1–10 channels of the
 * organization per step.
 */
class SaveEscalationPolicyRequest extends FormRequest
{
    public const MAX_POLICIES = 20;

    public const MAX_CHANNELS_PER_STEP = 10;

    public function authorize(): bool
    {
        $organization = $this->organization();

        if ($organization === null) {
            return false;
        }

        $policy = $this->policy();

        return $policy === null
            ? $this->user()?->can('create', [EscalationPolicy::class, $organization]) === true
            : $this->user()?->can('update', [$policy, $organization]) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'steps' => is_array($this->input('steps')) ? array_values($this->input('steps')) : $this->input('steps'),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $organization = $this->organization();

        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'is_default' => ['boolean'],
            'steps' => ['required', 'array', 'min:1', 'max:'.EscalationPolicy::MAX_STEPS],
            'steps.*' => ['array:delay_minutes,channel_ids'],
            'steps.*.delay_minutes' => ['required', 'integer', 'min:1', 'max:'.EscalationPolicy::MAX_DELAY_MINUTES],
            'steps.*.channel_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_CHANNELS_PER_STEP],
            // No `distinct`: on nested wildcards it compares across steps, and
            // a channel may belong to several steps. Duplicates are dropped.
            'steps.*.channel_ids.*' => [
                'string',
                'uuid',
                Rule::exists('notification_channels', 'id')->where('organization_id', $organization?->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'steps.*.delay_minutes' => __('delay'),
            'steps.*.channel_ids' => __('channels'),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $organization = $this->organization();

                if ($organization !== null && $this->policy() === null
                    && $organization->escalationPolicies()->count() >= self::MAX_POLICIES) {
                    $validator->errors()->add('name', __('An organization may have at most :count escalation policies.', ['count' => self::MAX_POLICIES]));
                }

                $steps = $this->input('steps');

                if (! is_array($steps) || $validator->errors()->has('steps.*')) {
                    return;
                }

                $previous = 0;

                foreach (array_values($steps) as $index => $step) {
                    $delay = is_array($step) ? (int) ($step['delay_minutes'] ?? 0) : 0;

                    if ($delay <= $previous) {
                        $validator->errors()->add("steps.{$index}.delay_minutes", __('Each step must wait longer than the previous one.'));
                    }

                    $previous = $delay;
                }
            },
        ];
    }

    public function payload(): EscalationPolicyData
    {
        /** @var list<array{delay_minutes: int|string, channel_ids: list<string>}> $steps */
        $steps = array_values((array) $this->validated('steps'));

        return new EscalationPolicyData(
            name: (string) $this->validated('name'),
            isDefault: $this->boolean('is_default'),
            steps: array_map(fn (array $step): array => [
                'delay_minutes' => (int) $step['delay_minutes'],
                'channel_ids' => array_values(array_unique(array_map('strval', $step['channel_ids']))),
            ], $steps),
        );
    }

    public function policy(): ?EscalationPolicy
    {
        $policy = $this->route('escalationPolicy');

        return $policy instanceof EscalationPolicy ? $policy : null;
    }

    private function organization(): ?Organization
    {
        $organization = $this->route('organization');

        return $organization instanceof Organization ? $organization : null;
    }
}
