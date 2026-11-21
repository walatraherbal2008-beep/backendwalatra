<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiRequestAuditLog extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'duration_ms' => 'integer',
            'request_bytes' => 'integer',
            'response_bytes' => 'integer',
            'query_keys' => 'array',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'started_at' => 'datetime',
        ];
    }
}
