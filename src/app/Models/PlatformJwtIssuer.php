<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatformJwtIssuer extends Model
{
    protected $primaryKey = 'platform_id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['platform_id', 'issuer'];

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function keys(): HasMany
    {
        return $this->hasMany(PlatformJwtKey::class, 'platform_id', 'platform_id');
    }
}
