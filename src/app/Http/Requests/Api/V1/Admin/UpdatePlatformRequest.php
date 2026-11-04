<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Platform;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdatePlatformRequest extends StorePlatformRequest
{
    private Platform $targetPlatform;

    public function authorize(): bool
    {
        $id = (string) $this->route('id');
        if (
            preg_match('/\A[1-9][0-9]*\z/', $id) !== 1
            || strlen($id) > 20
            || (strlen($id) === 20 && strcmp($id, '18446744073709551615') > 0)
        ) {
            throw new HttpResponseException(response()->json(['message' => 'Platform not found.'], 404));
        }

        $target = Platform::find($id);
        if ($target === null) {
            throw new HttpResponseException(response()->json(['message' => 'Platform not found.'], 404));
        }
        $this->targetPlatform = $target;

        return true;
    }

    public function targetPlatform(): Platform
    {
        return $this->targetPlatform;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100']];
    }
}
