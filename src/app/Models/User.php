<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class User extends Model
{
    use HasFactory;

    protected $fillable = ['platform_id', 'external_user_id', 'name', 'status', 'last_login_at'];

    protected function casts(): array
    {
        return ['status' => 'integer', 'last_login_at' => 'datetime'];
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }
}
