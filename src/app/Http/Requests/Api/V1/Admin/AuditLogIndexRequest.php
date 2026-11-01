<?php

namespace App\Http\Requests\Api\V1\Admin;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AuditLogIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = function (string $attribute, mixed $value, Closure $fail): void {
            if (
                ! is_string($value) && ! is_int($value)
                || preg_match('/\A[1-9][0-9]*\z/', (string) $value) !== 1
                || strlen((string) $value) > 20
                || (strlen((string) $value) === 20 && strcmp((string) $value, '18446744073709551615') > 0)
            ) {
                $fail('The ' . $attribute . ' must be a positive unsigned BIGINT.');
            }
        };

        // Validate calendar dates before lexicographic comparisons; never parse extreme years.
        $date = function (string $attribute, mixed $value, Closure $fail): void {
            if (
                ! is_string($value)
                || preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $value, $parts) !== 1
                || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
                || strcmp($value, '1000-01-01') < 0
                || strcmp($value, '9999-12-30') > 0
            ) {
                $fail('The ' . $attribute . ' must be a valid YYYY-MM-DD date between 1000-01-01 and 9999-12-30.');
            }
        };

        return [
            'admin_id' => ['sometimes', 'required', $id],
            'target_id' => ['sometimes', 'required', $id],
            'action' => ['sometimes', 'required', 'string', 'max:50'],
            'target_type' => ['sometimes', 'required', 'string', 'max:100'],
            'date_from' => ['sometimes', 'required', $date],
            'date_to' => ['sometimes', 'required', $date],
            'page' => ['sometimes', 'required', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (
                $this->has('date_from') && $this->has('date_to')
                && ! $validator->errors()->has('date_from') && ! $validator->errors()->has('date_to')
                && strcmp($this->input('date_to'), $this->input('date_from')) < 0
            ) {
                $validator->errors()->add('date_to', 'The date to field must be a date after or equal to date from.');
            }
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This query parameter is not allowed.');
            }
        }];
    }
}
