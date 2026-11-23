<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Monitors\SaveMonitorRequest as InterfaceSaveMonitorRequest;

/**
 * The interface's monitor validation, unchanged (ADR-044): same rules,
 * quotas, SSRF checks and policy. Only the form-specific flag is derived:
 * notification channels are replaced when `notification_channel_ids` is
 * sent and kept when it is omitted, and so are tags (ADR-047).
 */
class SaveMonitorRequest extends InterfaceSaveMonitorRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'notification_channels_submitted' => $this->has('notification_channel_ids'),
            'tags_submitted' => $this->has('tags'),
        ]);

        parent::prepareForValidation();
    }
}
