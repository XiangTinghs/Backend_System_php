<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Support\UnsignedBigInt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlatformKeyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => UnsignedBigInt::jsonValue($this->id),
            'platform_id' => UnsignedBigInt::jsonValue($this->platform_id),
            'issuer' => $this->issuer->issuer,
            'kid' => $this->kid,
            'fingerprint' => $this->fingerprint,
            'is_active' => $this->is_active,
            'valid_from' => $this->valid_from->utc()->toISOString(),
            'valid_until' => $this->valid_until->utc()->toISOString(),
            'revoked_at' => $this->revoked_at?->utc()->toISOString(),
            'created_at' => $this->created_at?->utc()->toISOString(),
            'updated_at' => $this->updated_at?->utc()->toISOString(),
        ];
    }
}
