<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Request;

class AuditLogger
{
    /**
     * Keys that must never be recorded in audit logs.
     */
    protected const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'token',
        'access_token',
        'bearer_token',
        'secret',
        'x-callback-token',
        'api_key',
        'credit_card',
        'cvv',
    ];

    /**
     * Static helper to log an audit entry.
     */
    public static function record(
        ?User $actor,
        string $action,
        string $entityType,
        string|int|null $entityId = null,
        array $metadata = [],
        ?array $before = null,
        ?array $after = null
    ): AuditLog {
        return app(self::class)->log(
            actor: $actor,
            action: $action,
            entityType: $entityType,
            entityId: $entityId,
            metadata: $metadata,
            before: $before,
            after: $after
        );
    }

    /**
     * Record an immutable audit log entry.
     */
    public function log(
        ?User $actor,
        string $action,
        string $entityType,
        string|int|null $entityId = null,
        array $metadata = [],
        ?array $before = null,
        ?array $after = null
    ): AuditLog {
        if ($before !== null) {
            $metadata['before'] = $before;
        }

        if ($after !== null) {
            $metadata['after'] = $after;
        }

        $sanitizedMetadata = $this->sanitize($metadata);

        return AuditLog::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId !== null ? (string) $entityId : null,
            'metadata' => !empty($sanitizedMetadata) ? $sanitizedMetadata : null,
            'ip_address' => Request::ip(),
            'user_agent' => Request::header('User-Agent'),
            'created_at' => now(),
        ]);
    }

    /**
     * Recursively sanitize metadata to remove credentials and secrets.
     */
    public function sanitize(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);

            if (in_array($lowerKey, self::SENSITIVE_KEYS, true)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitize($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
