<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\LoginRequest;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $admin = Admin::where(
            'username',
            $validated['username']
        )->first();

        if (
            ! $admin ||
            ! Hash::check($validated['password'], $admin->password) ||
            ! $admin->is_active
        ) {
            return response()->json([
                'message' => '帳號或密碼錯誤',
            ], 401);
        }

        $token = $admin->createToken('admin-api')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'admin' => [
                    'id' => $admin->id,
                    'username' => $admin->username,
                    'name' => $admin->name,
                ],
            ],
        ], 200);
    }

    public function me(Request $request): JsonResponse
    {
        $admin = $request->user();

        if (! $admin instanceof Admin) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (! $admin->is_active) {
            return response()->json([
                'message' => 'Administrator account is disabled.',
            ], 403);
        }

        return response()->json([
            'data' => [
                'id' => $admin->id,
                'username' => $admin->username,
                'name' => $admin->name,
                'is_active' => $admin->is_active,
            ],
        ]);
    }


    public function logout(Request $request): JsonResponse
    {
        $admin = $request->user();

        $admin->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout successful.',
        ]);
    }

}
