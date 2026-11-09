<?php

namespace App\Http\Requests\Api\V1\Admin;

class UpdatePlatformKeyStatusRequest extends PlatformKeyRequest
{
    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }
}
