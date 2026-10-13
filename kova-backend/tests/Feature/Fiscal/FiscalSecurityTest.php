<?php

namespace Tests\Feature\Fiscal;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Plan;
use App\Models\TenantFiscalProfile;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Fiscal\CsdVault;
use App\Services\Fiscal\InvoiceDocumentStore;
use App\Tenancy\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class FiscalSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_ticket_token_is_not_enumerable(): void
    {
        $this->getJson('/api/public/factura/'.str_repeat('x', 64))->assertNotFound();
    }

    public function test_public_invoice_does_not_validate_or_process_data_for_an_unknown_ticket(): void
    {
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/public/factura/'.str_repeat('x', 64), [
            'rfc' => 'EKU9003173C9',
            'legal_name' => 'ESCUELA KEMPER URGATE',
            'tax_regime_code' => '999',
            'postal_code' => '42501',
            'email' => 'fiscal@example.test',
            'cfdi_use_code' => 'AAA',
        ])->assertNotFound();
    }

    public function test_encrypted_fiscal_documents_keep_their_key_version(): void
    {
        config([
            'fiscal.encryption_keys.1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'fiscal.encryption_keys.2' => 'base64:'.base64_encode(str_repeat('b', 32)),
            'fiscal.encryption_key_version' => 1,
        ]);
        $vault = app(CsdVault::class);
        $encrypted = $vault->encryptSecret('contenido fiscal');

        config(['fiscal.encryption_key_version' => 2]);

        $this->assertStringStartsWith('v1:', $encrypted);
        $this->assertSame('contenido fiscal', $vault->decryptSecret($encrypted));
    }

    public function test_profile_ignores_client_tenant_and_status_fields(): void
    {
        $this->seed([PlanSeeder::class, RoleSeeder::class]);
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $otherOwner = User::factory()->create(['email_verified_at' => now()]);
        $plan = Plan::query()->where('code', 'free')->firstOrFail();
        $tenant = app(CreateTenant::class)->handle($owner, $plan, 'Uno', 'uno');
        $other = app(CreateTenant::class)->handle($otherOwner, $plan, 'Dos', 'dos');
        $this->enableTenantFeature($tenant, 'allows_invoicing');

        $this->actingAs($owner)->withHeader('X-Tenant-ID', $tenant->public_id)->putJson('/api/settings/fiscal/profile', [
            'tenant_id' => $other->public_id,
            'status' => 'ready',
            'rfc' => 'EKU9003173C9',
            'legal_name' => 'ESCUELA KEMPER URGATE',
            'tax_regime_code' => '626',
            'postal_code' => '42501',
            'fiscal_email' => 'fiscal@example.test',
            'default_product_service_code' => '01010101',
            'default_unit_code' => 'H87',
            'default_tax_object_code' => '02',
        ])->assertOk()->assertJsonPath('data.status', 'pending_validation');

        $this->assertDatabaseHas('tenant_fiscal_profiles', ['tenant_id' => $tenant->id, 'status' => 'pending_validation']);
        $this->assertDatabaseMissing('tenant_fiscal_profiles', ['tenant_id' => $other->id]);
    }

    public function test_tenant_cannot_read_another_tenants_fiscal_profile(): void
    {
        $this->seed([PlanSeeder::class, RoleSeeder::class]);
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $otherOwner = User::factory()->create(['email_verified_at' => now()]);
        $plan = Plan::query()->where('code', 'free')->firstOrFail();
        $tenant = app(CreateTenant::class)->handle($owner, $plan, 'Uno', 'uno');
        $other = app(CreateTenant::class)->handle($otherOwner, $plan, 'Dos', 'dos');
        $this->enableTenantFeature($tenant, 'allows_invoicing');
        app(CurrentTenant::class)->run($other, fn () => TenantFiscalProfile::query()->create(['rfc' => 'EKU9003173C9', 'status' => 'ready']));

        $response = $this->actingAs($owner)->withHeader('X-Tenant-ID', $tenant->public_id)->getJson('/api/settings/fiscal/profile')->assertOk();
        $response->assertJsonPath('data.rfc', null);
    }

    public function test_cancelled_invoice_can_be_replaced_without_mutating_history(): void
    {
        $this->seed([PlanSeeder::class, RoleSeeder::class]);
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $plan = Plan::query()->where('code', 'free')->firstOrFail();
        $tenant = app(CreateTenant::class)->handle($owner, $plan, 'Uno', 'uno');

        Storage::fake('local');
        $signedDownload = app(CurrentTenant::class)->run($tenant, function () use ($tenant): string {
            $order = Order::query()->create([
                'tenant_id' => $tenant->id,
                'branch_id' => $tenant->branches()->value('id'),
                'folio' => 'V-1',
                'order_type' => 'dine_in',
                'status' => 'paid',
                'subtotal' => 100,
                'tax_base_total' => 100,
                'tax_total' => 16,
                'total' => 116,
                'payment_method' => 'cash',
                'paid_at' => now(),
            ]);
            $ticket = Ticket::query()->create([
                'order_id' => $order->id,
                'token_hash' => hash('sha256', 'ticket-one'),
                'encrypted_token' => 'encrypted-for-schema-test',
                'fiscal_eligible' => true,
                'expires_at' => now()->addDay(),
            ]);
            $first = $this->invoice($order, $ticket, 'first');
            $first->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $replacement = $this->invoice($order, $ticket, 'replacement', $first);

            $this->assertSame($first->id, $replacement->replaces->id);
            $this->assertCount(2, $order->invoices);
            $this->assertSame($replacement->id, $ticket->invoice->id);

            $path = app(InvoiceDocumentStore::class)->put($replacement, 'stamped', '<cfdi>contenido</cfdi>');
            $replacement->update([
                'status' => 'stamped',
                'fiscal_uuid' => (string) Str::uuid(),
                'stamped_xml_path' => $path,
                'issued_at' => now(),
            ]);

            return URL::temporarySignedRoute('public.fiscal.factura.download', now()->addMinute(), [
                'token' => 'ticket-one',
                'format' => 'xml',
            ]);
        });

        $this->get($signedDownload)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml')
            ->assertSee('<cfdi>contenido</cfdi>', false);
        $this->get('/api/public/factura/ticket-one/download/xml')->assertForbidden();
    }

    private function invoice(Order $order, Ticket $ticket, string $key, ?Invoice $replaces = null): Invoice
    {
        return Invoice::query()->create([
            'order_id' => $order->id,
            'ticket_id' => $ticket->id,
            'replaces_invoice_id' => $replaces?->id,
            'provider' => 'finkok',
            'series' => 'A',
            'folio' => Invoice::query()->count() + 1,
            'issuer_snapshot' => ['rfc' => 'EKU9003173C9'],
            'receiver_snapshot' => ['rfc' => 'XAXX010101000'],
            'subtotal' => 100,
            'tax_total' => 16,
            'total' => 116,
            'currency' => 'MXN',
            'status' => 'queued',
            'idempotency_key_hash' => hash('sha256', $key),
        ]);
    }
}
