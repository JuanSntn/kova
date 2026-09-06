<?php

namespace App\Domain\Audit;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use InvalidArgumentException;

class AuditRecorder
{
    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function record(
        Request $request,
        string $action,
        ?Model $auditable = null,
        array $oldValues = [],
        array $newValues = [],
        ?string $reason = null,
        ?string $employeePublicId = null,
    ): AuditEvent {
        if ($action === '' || strlen($action) > 100) {
            throw new InvalidArgumentException('The audit action is invalid.');
        }

        $auditablePublicId = $auditable?->getAttribute('public_id');

        if ($auditable !== null && ! is_string($auditablePublicId)) {
            throw new InvalidArgumentException('Auditable models must expose a public_id.');
        }

        return AuditEvent::query()->create([
            'actor_user_id' => $request->user()?->getKey(),
            'employee_public_id' => $employeePublicId,
            'action' => $action,
            'auditable_type' => $auditable ? $auditable::class : null,
            'auditable_public_id' => $auditablePublicId,
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'reason' => $reason,
            'request_id' => $request->attributes->get('request_id'),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1024),
        ]);
    }
}
