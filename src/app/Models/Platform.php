<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class Platform extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'is_active'];

    protected function performUpdate(Builder $query): bool
    {
        // Enforce the invariant even when model events are disabled by saveQuietly().
        if ($this->isDirty('code')) {
            throw new LogicException('Platform code cannot be changed after creation.');
        }

        return parent::performUpdate($query);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function jwtIssuer(): HasOne
    {
        return $this->hasOne(PlatformJwtIssuer::class);
    }

    public function jwtKeys(): HasMany
    {
        return $this->hasMany(PlatformJwtKey::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
