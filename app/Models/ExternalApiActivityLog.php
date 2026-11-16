<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalApiActivityLog extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_payload' => 'array',
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
