<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformJwtKey extends Model
{
    protected $keyType = 'string';
    protected $fillable = ['platform_id', 'kid', 'public_key', 'fingerprint', 'is_active', 'valid_from', 'valid_until'];
    protected $hidden = ['public_key'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(PlatformJwtIssuer::class, 'platform_id', 'platform_id');
    }
}
