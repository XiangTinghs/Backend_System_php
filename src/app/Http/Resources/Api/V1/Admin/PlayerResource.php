<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Support\UnsignedBigInt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlayerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => UnsignedBigInt::jsonValue($this->resource->getRawOriginal('id')),
            'platform_id' => UnsignedBigInt::jsonValue($this->resource->getRawOriginal('platform_id')),
            'external_user_id' => $this->external_user_id,
            'name' => $this->name,
            'status' => $this->status,
            'last_login_at' => $this->last_login_at?->utc()->toISOString(),
            'created_at' => $this->created_at?->utc()->toISOString(),
            'updated_at' => $this->updated_at?->utc()->toISOString(),
        ];
    }
}
