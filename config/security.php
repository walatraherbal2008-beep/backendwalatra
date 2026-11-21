<?php

return [
    'bridge_secret' => env('FRONTEND_BRIDGE_SECRET', ''),
    'otp_request_phone_limit' => (int) env('OTP_REQUEST_PHONE_LIMIT', 10),
    'otp_request_ip_limit' => (int) env('OTP_REQUEST_IP_LIMIT', 20),
    'otp_verify_ip_limit' => (int) env('OTP_VERIFY_IP_LIMIT', 30),
    'external_log_retention_days' => (int) env('EXTERNAL_LOG_RETENTION_DAYS', 30),
    'api_request_audit_enabled' => (bool) env('API_REQUEST_AUDIT_ENABLED', true),
    'api_request_audit_retention_days' => (int) env('API_REQUEST_AUDIT_RETENTION_DAYS', 90),
    'api_request_audit_payload_max_bytes' => (int) env('API_REQUEST_AUDIT_PAYLOAD_MAX_BYTES', 32768),
    'webhook_max_bytes' => (int) env('PAYMENT_WEBHOOK_MAX_BYTES', 16384),
];
