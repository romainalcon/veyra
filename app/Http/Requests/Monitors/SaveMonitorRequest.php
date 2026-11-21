<?php

namespace App\Http\Requests\Monitors;

use App\Domain\Monitoring\DTOs\ExpectedStatuses;
use App\Domain\Monitoring\DTOs\HttpTargetData;
use App\Domain\Monitoring\DTOs\MonitorData;
use App\Domain\Monitoring\DTOs\TcpTargetData;
use App\Domain\Monitoring\Enums\HttpMethod;
use App\Domain\Monitoring\Enums\MonitorInterval;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Probes\DestinationGuard;
use App\Domain\Monitoring\Probes\DestinationRejected;
use App\Domain\Monitoring\Probes\ProbeSettings;
use App\Domain\Monitoring\Probes\UrlPolicy;
use App\Domain\Organizations\Models\Organization;
use App\Rules\AllowedMonitorDestination;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * Validates monitor creation and updates. Authorization is enforced in the
 * controller through MonitorPolicy. The type is chosen on creation and is
 * immutable afterwards; only the fields of that type are validated.
 */
class SaveMonitorRequest extends FormRequest
{
    public const MAX_HEADERS = 10;

    public const MAX_HEADER_VALUE_LENGTH = 1024;

    /**
     * Headers controlled by the probe or hop-by-hop headers.
     */
    public const FORBIDDEN_HEADERS = [
        'host', 'content-length', 'transfer-encoding', 'connection', 'upgrade',
        'te', 'trailer', 'keep-alive', 'expect', 'user-agent',
    ];

    /**
     * Types that can be created from the form (heartbeats arrive later in Sprint 7).
     *
     * @var list<MonitorType>
     */
    public const CREATABLE_TYPES = [MonitorType::Http, MonitorType::Tcp];

    protected function prepareForValidation(): void
    {
        $monitor = $this->route('monitor');

        $this->merge([
            'type' => $monitor instanceof Monitor ? $monitor->type->value : ($this->input('type') ?? MonitorType::Http->value),
            'host' => is_string($this->input('host')) ? trim($this->input('host')) : $this->input('host'),
            'url' => is_string($this->input('url')) ? trim($this->input('url')) : $this->input('url'),
            'follow_redirects' => $this->boolean('follow_redirects'),
            'headers' => array_values(array_filter(
                (array) $this->input('headers', []),
                fn (mixed $header): bool => is_array($header) && trim((string) ($header['name'] ?? '')) !== '',
            )),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $common = [
            'type' => ['required', Rule::enum(MonitorType::class)->only(self::CREATABLE_TYPES)],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'interval' => ['required', 'integer', Rule::enum(MonitorInterval::class)],
            'timeout' => ['required', 'integer', 'min:1', 'max:30'],
            'notification_channels_submitted' => ['nullable', 'boolean'],
            'notification_channel_ids' => ['array', 'max:50'],
            'notification_channel_ids.*' => [
                'string',
                'distinct',
                Rule::exists('notification_channels', 'id')->where('organization_id', $this->organization()->id),
            ],
        ];

        return match ($this->monitorType()) {
            MonitorType::Tcp => [
                ...$common,
                'host' => ['required', 'string', 'max:255'],
                'port' => ['required', 'integer', Rule::in(app(ProbeSettings::class)->tcpAllowedPorts)],
            ],
            default => [
                ...$common,
                'url' => ['required', 'string', 'max:'.UrlPolicy::MAX_LENGTH, app(AllowedMonitorDestination::class)],
                'method' => ['required', Rule::enum(HttpMethod::class)],
                'expected_statuses' => ['required', 'string', 'max:200'],
                'follow_redirects' => ['boolean'],
                'headers_mode' => [$this->isUpdate() ? 'required' : 'nullable', Rule::in(['keep', 'replace'])],
                'headers' => ['array', 'max:'.self::MAX_HEADERS],
                'headers.*.name' => ['required', 'string', 'max:100', 'regex:/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/'],
                'headers.*.value' => ['required', 'string', 'max:'.self::MAX_HEADER_VALUE_LENGTH, 'regex:/^[^\r\n\x00]*$/'],
            ],
        };
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ((int) $this->input('timeout') >= (int) $this->input('interval')) {
                    $validator->errors()->add('timeout', 'The timeout must be shorter than the interval.');
                }

                match ($this->monitorType()) {
                    MonitorType::Tcp => $this->validateTcpEndpoint($validator),
                    default => $this->validateHttpTarget($validator),
                };
            },
        ];
    }

    /**
     * Early feedback only: the probe re-validates the endpoint before every
     * check, since DNS answers can change.
     */
    private function validateTcpEndpoint(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['host', 'port'])) {
            return;
        }

        try {
            app(DestinationGuard::class)->inspectEndpoint((string) $this->input('host'), (int) $this->input('port'));
        } catch (DestinationRejected $exception) {
            $validator->errors()->add('host', $exception->getMessage());
        }
    }

    private function validateHttpTarget(Validator $validator): void
    {
        try {
            ExpectedStatuses::parse((string) $this->input('expected_statuses'));
        } catch (InvalidArgumentException $exception) {
            $validator->errors()->add('expected_statuses', $exception->getMessage());
        }

        $seen = [];

        foreach ((array) $this->input('headers', []) as $index => $header) {
            $name = strtolower(trim((string) ($header['name'] ?? '')));

            if (in_array($name, self::FORBIDDEN_HEADERS, true) || str_starts_with($name, 'proxy-')) {
                $validator->errors()->add("headers.{$index}.name", "The {$name} header cannot be set.");
            }

            if (isset($seen[$name])) {
                $validator->errors()->add("headers.{$index}.name", 'Each header may only be set once.');
            }

            $seen[$name] = true;
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'headers.*.name' => 'header name',
            'headers.*.value' => 'header value',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'port.in' => 'This port is not allowed for TCP monitors on this instance.',
        ];
    }

    public function toData(): MonitorData
    {
        $type = $this->monitorType();

        return new MonitorData(
            type: $type,
            name: trim((string) $this->validated('name')),
            interval: MonitorInterval::from((int) $this->validated('interval')),
            timeoutSeconds: (int) $this->validated('timeout'),
            http: $type === MonitorType::Http ? $this->httpTarget() : null,
            tcp: $type === MonitorType::Tcp ? new TcpTargetData((string) $this->validated('host'), (int) $this->validated('port')) : null,
            notificationChannelIds: $this->boolean('notification_channels_submitted')
                ? array_values(array_map('strval', (array) $this->validated('notification_channel_ids', [])))
                : null,
        );
    }

    private function httpTarget(): HttpTargetData
    {
        $headers = null;

        if (! $this->isUpdate() || $this->input('headers_mode') === 'replace') {
            $headers = [];

            /** @var array<int, array{name: string, value: string}> $validatedHeaders */
            $validatedHeaders = $this->validated('headers', []);

            foreach ($validatedHeaders as $header) {
                $headers[trim($header['name'])] = $header['value'];
            }
        }

        return new HttpTargetData(
            url: (string) $this->validated('url'),
            method: HttpMethod::from((string) $this->validated('method')),
            expectedStatuses: ExpectedStatuses::parse((string) $this->validated('expected_statuses')),
            followRedirects: (bool) $this->validated('follow_redirects'),
            headers: $headers,
        );
    }

    /**
     * The stored type on update; the submitted one (default HTTP) on creation.
     */
    private function monitorType(): MonitorType
    {
        $monitor = $this->route('monitor');

        if ($monitor instanceof Monitor) {
            return $monitor->type;
        }

        return MonitorType::tryFrom((string) $this->input('type')) ?? MonitorType::Http;
    }

    private function organization(): Organization
    {
        /** @var Organization $organization */
        $organization = $this->route('organization');

        return $organization;
    }

    private function isUpdate(): bool
    {
        return $this->route('monitor') instanceof Monitor;
    }
}
