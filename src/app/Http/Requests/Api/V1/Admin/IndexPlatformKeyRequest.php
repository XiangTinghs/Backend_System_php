<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Validation\Validator;

class IndexPlatformKeyRequest extends PlatformKeyRequest
{
    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->query->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field is not allowed.');
            }
            if ($this->request->all() !== [] || $this->json()->all() !== []) {
                $validator->errors()->add('body', 'Request body is not allowed.');
            }
        }];
    }
}
