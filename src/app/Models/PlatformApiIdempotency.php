<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformApiIdempotency extends Model
{
    protected $keyType = 'string';
    protected $fillable = ['platform_id', 'endpoint', 'idempotency_key', 'request_hash', 'state', 'player_id',
        'response_status', 'response_body', 'expires_at'];

    protected function casts(): array
    {
        return ['player_id' => 'string', 'response_status' => 'integer', 'expires_at' => 'immutable_datetime'];
    }
}
