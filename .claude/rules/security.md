# Security rules
- Every tenant-owned query and broadcast must be authorized for its organization.
- HTTP probes must reject loopback, private, link-local, multicast and metadata IP ranges, including IPv6.
- Recheck every redirect and DNS result; prevent DNS rebinding by binding the validated destination to the actual connection.
- Restrict outbound ports, methods, redirects, timeouts and response size; enforce network-level egress restrictions.
- Encrypt notification credentials and sensitive request headers; redact logs and UI responses.
- Rate-limit monitor creation and manual checks; do not allow arbitrary internal scanning.
