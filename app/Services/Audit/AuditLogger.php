<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Tenancy\TenantContext;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditLogger
{
    public function __construct(private readonly TenantContext $context, private readonly Request $request) {}

    /** @param array<string, mixed>|null $changes */
    public function record(string $action, ?Model $subject = null, ?array $changes = null): AuditLog
    {
        $actor = $this->context->actor();

        return AuditLog::create([
            'tenant_id' => $this->context->tenantOrNull()?->getKey(),
            'actor_type' => $actor?->type,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'changes' => $changes,
            'ip' => $this->request->ip(),
            'request_id' => $this->request->header('X-Request-Id') ?? (string) Str::ulid(),
            'created_at' => now(),
        ]);
    }

    /**
     * Diff a model's changes after an update() call.
     *
     * Laravel's Model::save() calls syncOriginal() (inside finishSave()) before
     * update() returns to the caller, so by that point getOriginal() already
     * reflects the *new* value, not the pre-update one. Callers that need the
     * true "before" values must capture $model->getOriginal() themselves prior
     * to calling update() and pass it as $original here.
     *
     * @param  array<string, mixed>|null  $original  Attribute snapshot captured via getOriginal() before update() was called.
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function diff(Model $model, ?array $original = null): array
    {
        $original ??= $model->getOriginal();

        $out = [];
        foreach ($model->getChanges() as $key => $to) {
            if (in_array($key, ['updated_at'], true)) {
                continue;
            }
            $from = $original[$key] ?? null;
            if ($from instanceof BackedEnum) {
                $from = $from->value;
            }
            if ($to instanceof BackedEnum) {
                $to = $to->value;
            }
            $out[$key] = ['from' => $from, 'to' => $to];
        }

        return $out;
    }
}
