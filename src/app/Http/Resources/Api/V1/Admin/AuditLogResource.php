<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'admin_id' => $this->admin_id,
            'action' => $this->action,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'old_values' => $this->safeSnapshot($this->old_values),
            'new_values' => $this->safeSnapshot($this->new_values),
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at?->utc()->toISOString(),
        ];
    }

    private function safeSnapshot(mixed $values): ?array
    {
        if ($values === null) {
            return null;
        }
        $safe = [];
        if (! is_array($values)) {
            return $safe;
        }
        foreach (['username', 'name', 'is_active', 'role', 'roles', 'code'] as $field) {
            if (! array_key_exists($field, $values)) {
                continue;
            }
            $value = $values[$field];
            if (
                $value === null
                || ($field === 'is_active' && is_bool($value))
                || (in_array($field, ['username', 'name', 'role', 'code'], true) && is_string($value))
                || ($field === 'roles' && is_array($value) && array_is_list($value)
                    && count(array_filter($value, 'is_string')) === count($value))
            ) {
                $safe[$field] = $value;
            }
        }

        return $safe;
    }
}
