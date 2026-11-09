<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Support\UnsignedBigInt;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IndexPlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return $this->query->all();
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'required', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
            'platform_id' => ['sometimes', 'required', function (string $attribute, mixed $value, Closure $fail): void {
                if (! UnsignedBigInt::valid($value)) {
                    $fail('The platform id must be a positive unsigned BIGINT.');
                }
            }],
            'external_user_id' => ['sometimes', 'required', 'string', 'max:100'],
            'status' => ['sometimes', 'required', Rule::in(['0', '1'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', 'required', Rule::in(['id', 'platform_id', 'external_user_id', 'name', 'status', 'last_login_at', 'created_at', 'updated_at'])],
            'direction' => ['sometimes', 'required', Rule::in(['asc', 'desc'])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->query->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This query parameter is not allowed.');
            }
        }];
    }
}
