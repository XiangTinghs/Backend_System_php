<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Auth\PlatformContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Platform\ResolvePlayerRequest;
use App\Services\PlayerResolveService;
use Illuminate\Http\JsonResponse;

class PlayerResolveController extends Controller
{
    public function __invoke(ResolvePlayerRequest $request, PlayerResolveService $players): JsonResponse
    {
        $result = $players->resolve(
            $request->attributes->get(PlatformContext::class),
            $request->safe()->only(['external_user_id', 'name']),
            $request->idempotencyKey()
        );

        return JsonResponse::fromJsonString($result['body'], $result['status']);
    }
}
