<?php

namespace App\Rules;

use App\Domain\StatusPages\Support\DomainName;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A public host name for a status page: no IP literal, no local or internal
 * suffix, and never the Veyra instance itself or one of its subdomains.
 */
class CustomDomain implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $domain = is_string($value) ? DomainName::normalize($value) : null;

        if ($domain === null) {
            $fail(__('Enter a host name such as status.example.com.'));

            return;
        }

        if (DomainName::isForbidden($domain, DomainName::appHost())) {
            $fail(__('This domain cannot be used for a status page.'));
        }
    }
}
