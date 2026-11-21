<?php

namespace App\Http\Requests\Monitors;

use App\Domain\Entitlements\Services\Quota;
use App\Domain\Monitoring\DTOs\ExpectedStatuses;
use App\Domain\Monitoring\DTOs\HeartbeatTargetData;
use App\Domain\Monitoring\DTOs\HttpTargetData;
use App\Domain\Monitoring\DTOs\InvalidExpectedStatuses;
use App\Domain\Monitoring\DTOs\MonitorData;
use App\Domain\Monitoring\DTOs\TcpTargetData;
use App\Domain\Monitoring\Enums\HeartbeatPeriod;
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

/**
 * Validates monitor creation and updates. Authorization (MonitorPolicy) runs
 * before validation, so unauthorized users never trigger DNS lookups or see
 * validation errors; the controller re-checks it. The type is chosen on creation and is
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
     * @var list<MonitorType>
     */
    public const CREATABLE_TYPES = [MonitorType::Http, MonitorType::Tcp, MonitorType::Heartbeat];

    public const MAX_GRACE_MINUTES = 1440;

    /**
     * Heartbeats are not probed; this placeholder keeps the column meaningful.
     */
    public const HEARTBEAT_TIMEOUT_SECONDS = 10;

    public function authorize(): bool
    {
        $monitor = $this->route('monitor');

        return $monitor instanceof Monitor
            ? $this->user()?->can('update', [$monitor, $this->organization()]) === true
            : $this->user()?->can('create', [Monitor::class, $this->organization()]) === true;
    }

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
            'notification_channels_submitted' => ['nullable', 'boolean'],
            'notification_channel_ids' => ['array', 'max:50'],
            'notification_channel_ids.*' => [
                'string',
                'distinct',
                Rule::exists('notification_channels', 'id')->where('organization_id', $this->organization()->id),
            ],
        ];

        $probed = [
            'interval' => ['required', 'integer', Rule::enum(MonitorInterval::class)],
            'timeout' => ['required', 'integer', 'min:1', 'max:30'],
        ];

        return match ($this->monitorType()) {
            MonitorType::Heartbeat => [
                ...$common,
                'interval' => ['required', 'integer', Rule::enum(HeartbeatPeriod::class)],
                'grace_minutes' => ['required', 'integer', 'min:1', 'max:'.self::MAX_GRACE_MINUTES],
            ],
            MonitorType::Tcp => [
                ...$common,
                ...$probed,
                'host' => ['required', 'string', 'max:255'],
                'port' => ['required', 'integer', Rule::in(app(ProbeSettings::class)->tcpAllowedPorts)],
            ],
            MonitorType::Http => [
                ...$common,
                ...$probed,
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
                if ($this->monitorType() !== MonitorType::Heartbeat && (int) $this->input('timeout') >= (int) $this->input('interval')) {
                    $validator->errors()->add('timeout', __('The timeout must be shorter than the interval.'));
                }

                if ($this->monitorType() !== MonitorType::Heartbeat && ! $validator->errors()->has('interval')) {
                    $this->validateMinimumInterval($validator);
                }

                match ($this->monitorType()) {
                    MonitorType::Tcp => $this->validateTcpEndpoint($validator),
                    MonitorType::Http => $this->validateHttpTarget($validator),
                    MonitorType::Heartbeat => null,
                };
            },
        ];
    }

    /**
     * Organization minimum for probed monitors (ADR-029). An edited monitor
     * may keep an interval below a lowered minimum, but not move further below it.
     */
    private function validateMinimumInterval(Validator $validator): void
    {
        $minimum = app(Quota::class)->minIntervalSeconds($this->organization());
        $interval = (int) $this->input('interval');
        $monitor = $this->route('monitor');

        if ($minimum === null || $interval >= $minimum) {
            return;
        }

        if ($monitor instanceof Monitor && $interval >= $monitor->interval_seconds) {
            return;
        }

        $validator->errors()->add('interval', __('The interval must be at least :seconds seconds for this organization.', ['seconds' => $minimum]));
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
            $validator->errors()->add('host', $exception->translatedMessage());
        }
    }

    private function validateHttpTarget(Validator $validator): void
    {
        try {
            ExpectedStatuses::parse((string) $this->input('expected_statuses'));
        } catch (InvalidExpectedStatuses $exception) {
            $validator->errors()->add('expected_statuses', $exception->translatedMessage());
        }

        $seen = [];

        foreach ((array) $this->input('headers', []) as $index => $header) {
            $name = strtolower(trim((string) ($header['name'] ?? '')));

            if (in_array($name, self::FORBIDDEN_HEADERS, true) || str_starts_with($name, 'proxy-')) {
                $validator->errors()->add("headers.{$index}.name", "The {$name} header cannot be set.");
            }

            if (isset($seen[$name])) {
                $validator->errors()->add("headers.{$index}.name", __('Each header may only be set once.'));
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
            'port.in' => __('This port is not allowed for TCP monitors on this instance.'),
        ];
    }

    public function toData(): MonitorData
    {
        $type = $this->monitorType();

        return new MonitorData(
            type: $type,
            name: trim((string) $this->validated('name')),
            intervalSeconds: (int) $this->validated('interval'),
            timeoutSeconds: $type === MonitorType::Heartbeat ? self::HEARTBEAT_TIMEOUT_SECONDS : (int) $this->validated('timeout'),
            http: $type === MonitorType::Http ? $this->httpTarget() : null,
            tcp: $type === MonitorType::Tcp ? new TcpTargetData((string) $this->validated('host'), (int) $this->validated('port')) : null,
            heartbeat: $type === MonitorType::Heartbeat ? new HeartbeatTargetData((int) $this->validated('grace_minutes') * 60) : null,
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
