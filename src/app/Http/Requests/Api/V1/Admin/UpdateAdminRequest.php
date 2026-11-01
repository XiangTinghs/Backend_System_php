<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Admin;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateAdminRequest extends StoreAdminRequest
{
    private Admin $targetAdmin;

    public function authorize(): bool
    {
        $id = (string) $this->route('id');

        if (
            preg_match('/\A[1-9][0-9]*\z/', $id) !== 1
            || strlen($id) > 20
            || (strlen($id) === 20 && strcmp($id, '18446744073709551615') > 0)
        ) {
            throw new HttpResponseException(response()->json(['message' => 'Administrator not found.'], 404));
        }

        $target = Admin::find($id);

        if ($target === null) {
            throw new HttpResponseException(response()->json(['message' => 'Administrator not found.'], 404));
        }

        if ($target->is($this->user('sanctum'))) {
            throw new HttpResponseException(response()->json(['message' => 'Forbidden.'], 403));
        }

        $this->targetAdmin = $target;

        return true;
    }

    public function targetAdmin(): Admin
    {
        return $this->targetAdmin;
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['username'] = [
            'required', 'string', 'max:50', 'regex:/\A[A-Za-z0-9._-]+\z/',
            Rule::unique('admins', 'username')->ignore($this->targetAdmin),
        ];
        array_unshift($rules['password'], 'sometimes');
        $rules['password_confirmation'] = [
            'required_with:password', Rule::prohibitedIf(! $this->has('password')), 'string',
        ];

        return $rules;
    }
}
