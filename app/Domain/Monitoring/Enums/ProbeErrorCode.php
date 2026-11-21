<?php

namespace App\Domain\Monitoring\Enums;

enum ProbeErrorCode: string
{
    case InvalidUrl = 'invalid_url';
    case ForbiddenDestination = 'forbidden_destination';
    case DnsFailure = 'dns_failure';
    case ConnectionFailed = 'connection_failed';
    case Timeout = 'timeout';
    case TlsError = 'tls_error';
    case TooManyRedirects = 'too_many_redirects';
    case ResponseTooLarge = 'response_too_large';
    case UnexpectedStatus = 'unexpected_status';
    case UnexpectedError = 'unexpected_error';
    case HeartbeatFailed = 'heartbeat_failed';
    case HeartbeatMissed = 'heartbeat_missed';

    public function label(): string
    {
        return match ($this) {
            self::InvalidUrl => 'Invalid URL',
            self::ForbiddenDestination => 'Destination not allowed',
            self::DnsFailure => 'DNS resolution failed',
            self::ConnectionFailed => 'Connection failed',
            self::Timeout => 'Timed out',
            self::TlsError => 'TLS error',
            self::TooManyRedirects => 'Too many redirects',
            self::ResponseTooLarge => 'Response too large',
            self::UnexpectedStatus => 'Unexpected status code',
            self::UnexpectedError => 'Unexpected error',
            self::HeartbeatFailed => 'Failure reported by the job',
            self::HeartbeatMissed => 'No ping received in time',
        };
    }
}
