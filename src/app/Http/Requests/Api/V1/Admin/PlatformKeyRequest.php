<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Platform;
use App\Models\PlatformJwtKey;
use App\Support\UnsignedBigInt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

class PlatformKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $id = $this->route('id');
        if (! UnsignedBigInt::valid($id) || ! Platform::whereKey($id)->exists()) {
            throw new HttpResponseException(response()->json(['message' => 'Platform not found.'], 404));
        }
        if ($this->route('keyId') !== null) {
            $keyId = $this->route('keyId');
            if (! UnsignedBigInt::valid($keyId) || ! PlatformJwtKey::where('platform_id', $id)->whereKey($keyId)->exists()) {
                throw new HttpResponseException(response()->json(['message' => 'Platform key not found.'], 404));
            }
        }

        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field is not allowed.');
            }
            foreach (array_keys($this->query->all()) as $field) {
                $validator->errors()->add($field, 'Query parameters are not allowed.');
            }
        }];
    }
}
