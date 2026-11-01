<?php

namespace App\Http\Requests\Api\V1\Admin;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:50', 'regex:/\A[A-Za-z0-9._-]+\z/', 'unique:admins,username'],
            'name' => ['required', 'string', 'max:100'],
            'password' => [
                'required', 'string', 'min:12', 'confirmed',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
                        $fail('The password must not exceed 72 bytes or contain null bytes.');
                    }
                },
            ],
            'password_confirmation' => ['required', 'string'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field is not allowed.');
            }
        }];
    }
}
