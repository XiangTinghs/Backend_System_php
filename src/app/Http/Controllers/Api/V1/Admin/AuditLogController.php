<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AuditLogIndexRequest;
use App\Http\Resources\Api\V1\Admin\AuditLogResource;
use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function index(AuditLogIndexRequest $request): AnonymousResourceCollection
    {
        $input = $request->validated();
        $query = AuditLog::query();
        foreach (['admin_id', 'action', 'target_type', 'target_id'] as $field) {
            if (array_key_exists($field, $input)) {
                $query->where($field, $input[$field]);
            }
        }
        if (isset($input['date_from'])) {
            $query->where('created_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $input['date_from'], 'UTC')->format('Y-m-d H:i:s'));
        }
        if (isset($input['date_to'])) {
            $query->where('created_at', '<', CarbonImmutable::createFromFormat('!Y-m-d', $input['date_to'], 'UTC')->addDay()->format('Y-m-d H:i:s'));
        }

        return AuditLogResource::collection(
            $query->orderByDesc('created_at')->orderByDesc('id')
                ->paginate((int) ($input['per_page'] ?? 20), ['*'], 'page', (int) ($input['page'] ?? 1))
                ->appends($input)
        );
    }
}
