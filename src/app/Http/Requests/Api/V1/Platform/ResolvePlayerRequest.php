<?php

namespace App\Http\Requests\Api\V1\Platform;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use JsonException;
use stdClass;

class ResolvePlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'external_user_id' => ['required', 'string', 'min:1', 'max:100', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && preg_match('/\A[\s\p{Z}]|[\s\p{Z}]\z/u', $value) === 1) {
                    $fail('The external user ID must not contain leading or trailing whitespace.');
                }
            }],
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            try {
                $body = json_decode($this->getContent(), flags: JSON_THROW_ON_ERROR);
                if (! $this->isJson() || ! $body instanceof stdClass) {
                    $validator->errors()->add('body', 'A JSON object is required.');
                }
            } catch (JsonException $exception) {
                $validator->errors()->add('body', 'A valid JSON object is required.');
            }
            foreach (array_diff(array_keys($this->json()->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field is not allowed.');
            }
            if ($this->query->all() !== []) {
                $validator->errors()->add('query', 'Query parameters are not allowed.');
            }
            $keyErrors = validator(
                ['idempotency_key' => $this->header('Idempotency-Key')],
                ['idempotency_key' => ['required', 'string', 'uuid']]
            );
            if ($keyErrors->fails()) {
                $validator->errors()->add('idempotency_key', 'A valid UUID Idempotency-Key header is required.');
            }
        }];
    }

    public function idempotencyKey(): string
    {
        return strtolower($this->header('Idempotency-Key'));
    }
}
