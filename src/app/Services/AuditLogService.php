<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\UnsignedBigInt;
use InvalidArgumentException;

class AuditLogService
{
    // Fixed snapshot schema: never accept caller-supplied allowlists or arbitrary nested payloads.
    private const SNAPSHOT_FIELDS = ['username', 'name', 'is_active', 'role', 'roles', 'code', 'status', 'issuer', 'kid', 'fingerprint', 'valid_from', 'valid_until', 'revoked_at', 'permission', 'ticket'];

    public function record(
        ?int $adminId,
        string $action,
        string $targetType,
        int|string|null $targetId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $ipAddress = null,
        ?string $connection = null
    ): AuditLog {
        if (is_string($targetId) && ! UnsignedBigInt::valid($targetId)) {
            throw new InvalidArgumentException('Invalid unsigned BIGINT target ID.');
        }

        $log = new AuditLog();
        // Use the caller's connection when its transaction uses a non-default connection.
        $log->setConnection($connection);
        $log->fill([
            'admin_id' => $adminId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'old_values' => $this->sanitize($oldValues),
            'new_values' => $this->sanitize($newValues),
            'ip_address' => $ipAddress,
        ]);
        $log->save();

        return $log;
    }

    private function sanitize(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $safe = [];
        foreach (self::SNAPSHOT_FIELDS as $field) {
            if (! array_key_exists($field, $values)) {
                continue;
            }

            $value = $values[$field];
            if ($value === null && $field !== 'status') {
                $safe[$field] = null;
            } elseif ($field === 'status' && is_int($value) && in_array($value, [0, 1], true)) {
                $safe[$field] = $value;
            } elseif ($field === 'is_active' && is_bool($value)) {
                $safe[$field] = $value;
            } elseif (in_array($field, ['username', 'name', 'role', 'code', 'issuer', 'kid', 'fingerprint', 'valid_from', 'valid_until', 'revoked_at', 'permission', 'ticket'], true) && is_string($value)) {
                $safe[$field] = $value;
            } elseif (
                $field === 'roles' && is_array($value) && array_is_list($value)
                && count(array_filter($value, 'is_string')) === count($value)
            ) {
                $safe[$field] = $value;
            }
        }

        return $safe;
    }
}
