<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

class DestroyAdminRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = $this->route('role');

        if (
            ! is_string($role) || strlen($role) > 50
            || preg_match('/\A[a-z][a-z0-9_]*\z/', $role) !== 1
            || ! in_array($role, config('rbac.known_roles', []), true)
        ) {
            throw new HttpResponseException(response()->json(['message' => 'Role not found.'], 404));
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
            // Accept only an absent or empty JSON body; query parameters are not supported either.
            if ($this->all() !== [] || ! in_array(trim($this->getContent()), ['', '{}', '[]'], true)) {
                $validator->errors()->add('body', 'Request body and query parameters are not allowed.');
            }
        }];
    }
}
