<?php

namespace App\Http\Requests\Api\V1\Admin;

class UpdatePlatformStatusRequest extends UpdatePlatformRequest
{
    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }
}
