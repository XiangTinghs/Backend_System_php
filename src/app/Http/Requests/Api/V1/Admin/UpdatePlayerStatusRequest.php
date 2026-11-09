<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\User;
use App\Support\UnsignedBigInt;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

class UpdatePlayerStatusRequest extends FormRequest
{
    private User $targetPlayer;

    public function authorize(): bool
    {
        $id = $this->route('id');
        $target = UnsignedBigInt::valid($id)
            ? User::query()->withCasts(['id' => 'string'])->find($id) : null;
        if ($target === null) {
            throw new HttpResponseException(response()->json(['message' => 'Player not found.'], 404));
        }
        $this->targetPlayer = $target;

        return true;
    }

    public function targetPlayer(): User
    {
        return $this->targetPlayer;
    }

    public function validationData(): array
    {
        return $this->isJson() ? $this->json()->all() : [];
    }

    public function rules(): array
    {
        return ['status' => ['required', function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_int($value) || ! in_array($value, [0, 1], true)) {
                $fail('The status must be a JSON integer of 0 or 1.');
            }
        }]];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->isJson()) {
                $validator->errors()->add('status', 'A JSON body is required.');
            }
            foreach (array_diff(array_keys($this->validationData()), ['status']) as $field) {
                $validator->errors()->add($field, 'This field is not allowed.');
            }
            foreach (array_keys($this->query->all()) as $field) {
                $validator->errors()->add($field, 'Query parameters are not allowed.');
            }
        }];
    }
}
