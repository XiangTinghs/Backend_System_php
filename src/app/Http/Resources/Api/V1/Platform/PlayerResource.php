<?php

namespace App\Http\Resources\Api\V1\Platform;

use App\Support\UnsignedBigInt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlayerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => UnsignedBigInt::jsonValue($this->resource->getRawOriginal('id')),
            'external_user_id' => $this->external_user_id, 'name' => $this->name, 'status' => $this->status];
    }
}
