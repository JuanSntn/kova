<?php

namespace Tests\Feature\Audit;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Plan;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use LogicException;
use Tests\TestCase;

class AuditRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_encrypted_append_only_events_inside_a_tenant(): void
    {
        $this->seed(RoleSeeder::class);

        $owner = User::factory()->create();
        $tenant = app(CreateTenant::class)->handle(
            $owner,
            Plan::factory()->create(),
            'Café Central',
            'cafe-central',
        );
        $branch = app(CurrentTenant::class)->run(
            $tenant,
            fn () => Branch::query()->sole(),
        );
        $request = Request::create('/api/branches/'.$branch->public_id, 'PATCH');
        $request->setUserResolver(fn () => $owner);
        $request->attributes->set('request_id', '11111111-1111-4111-8111-111111111111');

        app(CurrentTenant::class)->run($tenant, function () use ($request, $branch): void {
            $event = app(AuditRecorder::class)->record(
                request: $request,
                action: 'branch.updated',
                auditable: $branch,
                oldValues: ['name' => 'Anterior'],
                newValues: ['name' => 'Nueva'],
                reason: 'Corrección autorizada',
            );

            $this->assertSame('Nueva', $event->new_values['name']);
            $this->assertSame($branch->public_id, $event->auditable_public_id);

            $wasRejected = false;

            try {
                $event->update(['action' => 'tampered']);
            } catch (LogicException) {
                $wasRejected = true;
            }

            $this->assertTrue($wasRejected);
        });

        $rawEvent = AuditEvent::withoutGlobalScopes()->where('action', 'branch.updated')->sole();
        $this->assertStringNotContainsString('Nueva', (string) $rawEvent->getRawOriginal('new_values'));
        $this->assertStringNotContainsString(
            'Corrección autorizada',
            (string) $rawEvent->getRawOriginal('reason'),
        );
    }
}
