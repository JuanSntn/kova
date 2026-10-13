<?php

namespace Tests\Feature\Fiscal;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Models\{Plan, User, TenantFiscalProfile, FiscalCertificate, TenantPacRegistration, TenantTicketSetting, Order};
use App\Services\Tickets\{TicketSettingsService, TicketService};
use App\Services\Fiscal\FiscalTicketService;
use App\Tenancy\CurrentTenant;
use Database\Seeders\{PlanSeeder, RoleSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoiceQrReadinessTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenant(bool $ready = true): array
    {
        $this->seed([PlanSeeder::class, RoleSeeder::class]);
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $tenant = app(CreateTenant::class)->handle($owner, Plan::where('code', 'free')->sole(), 'QR negocio', 'qr');
        config(['fiscal.enabled' => true, 'fiscal.encryption_keys.1' => 'base64:'.base64_encode(str_repeat('a', 32)), 'fiscal.encryption_key_version' => 1]);
        DB::table('sat_postal_codes')->where('code', '42501')->update(['state' => 'HID', 'timezone_description' => 'Tiempo del Centro']);
        app(CurrentTenant::class)->run($tenant, function () use ($tenant, $ready) {
            \App\Models\TenantBusinessProfile::updateOrCreate(['tenant_id' => $tenant->id], ['phone' => '5555555555', 'public_email' => 'qr@example.test']);
            if (! $ready) return;
            $this->enableTenantFeature($tenant, 'allows_invoicing');
            $tenant->branches()->update(['postal_code' => '42501', 'timezone' => 'America/Mexico_City', 'city' => 'Ciudad', 'neighborhood' => 'Centro', 'street' => 'Principal', 'exterior_number' => '1']);
            $profile = TenantFiscalProfile::create(['rfc' => 'EKU9003173C9', 'legal_name' => 'ESCUELA KEMPER URGATE', 'tax_regime_code' => '601', 'postal_code' => '42501', 'fiscal_email' => 'qr@example.test', 'default_product_service_code' => '01010101', 'default_unit_code' => 'H87', 'default_tax_object_code' => '02', 'status' => 'ready']);
            FiscalCertificate::create(['tenant_fiscal_profile_id' => $profile->id, 'serial_number' => '123', 'rfc' => $profile->rfc, 'valid_from' => now()->subDay(), 'valid_until' => now()->addYear(), 'sha256_fingerprint' => str_repeat('a', 64), 'certificate_path' => 'test', 'private_key_path' => 'test', 'encrypted_password' => 'test', 'encryption_key_version' => 1, 'status' => 'active']);
            TenantPacRegistration::create(['tenant_fiscal_profile_id' => $profile->id, 'provider' => config('fiscal.provider'), 'environment' => config('fiscal.environment'), 'remote_identifier' => $profile->rfc, 'status' => 'active']);
        });
        $this->actingAs($owner)->withHeader('X-Tenant-ID', $tenant->public_id);
        $payload = app(CurrentTenant::class)->run($tenant, fn () => app(TicketSettingsService::class)->setting($tenant)->getAttributes());
        $payload = array_intersect_key($payload, array_flip((new TenantTicketSetting)->getFillable()));
        unset($payload['tenant_id']);
        $payload['show_invoice_qr'] = true;
        return [$tenant, $payload];
    }

    public function test_ready_setup_enables_qr_and_incomplete_real_order_branch_removes_all_links(): void
    {
        [$tenant, $payload] = $this->setupTenant();
        $this->putJson('/api/settings/ticket', $payload)->assertOk()->assertJsonPath('meta.invoice_qr.enabled', true);
        app(CurrentTenant::class)->run($tenant, function () use ($tenant) {
            $branch = $tenant->branches()->sole();
            $order = Order::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'folio' => 'QR-1', 'order_type' => 'counter', 'status' => 'paid', 'payment_method' => 'cash', 'paid_at' => now(), 'subtotal' => 100, 'tax_base_total' => 100, 'tax_total' => 0, 'total' => 100]);
            app(FiscalTicketService::class)->issueForSale($tenant, $order);
            $this->assertTrue(app(TicketService::class)->forOrder($tenant, $order)['settings']['invoice']['show_qr']);
            $branch->update(['street' => null]);
            $invoice = app(TicketService::class)->forOrder($tenant, $order->fresh())['settings']['invoice'];
            $this->assertFalse($invoice['show_qr']);
            $this->assertNull($invoice['url']);
            $this->assertNull($invoice['access_code']);
            $this->assertNull($invoice['entry_url']);
        });
    }

    public function test_missing_requirements_reject_enable_requests(): void
    {
        [$tenant, $payload] = $this->setupTenant();
        $mutations = [
            ['tenant_fiscal_profiles', 'status', 'incomplete', 'ready', 'fiscal_incomplete'],
            ['fiscal_certificates', 'valid_until', now()->subDay(), now()->addYear(), 'certificate_unavailable'],
            ['tenant_pac_registrations', 'status', 'failed', 'active', 'provider_unavailable'],
            ['branches', 'street', null, 'Principal', 'branches_incomplete'],
            ['branches', 'timezone', 'UTC', 'America/Mexico_City', 'branches_incomplete'],
            ['branches', 'postal_code', '00000', '42501', 'branches_incomplete'],
            ['tenant_fiscal_profiles', 'default_unit_code', 'INVALID', 'H87', 'fiscal_incomplete'],
        ];
        foreach ($mutations as [$table, $field, $bad, $good, $code]) {
            DB::table($table)->where('tenant_id', $tenant->id)->update([$field => $bad]);
            $this->putJson('/api/settings/ticket', $payload)->assertUnprocessable()->assertJsonPath('code', 'invoice_qr_not_ready')->assertJsonPath('invoice_qr.code', $code);
            DB::table($table)->where('tenant_id', $tenant->id)->update([$field => $good]);
        }
    }

    public function test_free_and_forged_authority_fields_are_rejected(): void
    {
        [$tenant, $payload] = $this->setupTenant(false);
        $this->getJson('/api/settings/ticket')->assertOk()->assertJsonPath('meta.capabilities.invoice_qr', false);
        $this->putJson('/api/settings/ticket', $payload)->assertUnprocessable()->assertJsonPath('code', 'invoice_qr_not_ready');
        foreach (['tenant_id', 'plan', 'allows_invoicing', 'fiscal_ready', 'branch_ready'] as $field) {
            $this->putJson('/api/settings/ticket', [...$payload, $field => 'forged'])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    public function test_downgrade_preserves_preference_and_upgrade_restores_effective_qr(): void
    {
        [$tenant, $payload] = $this->setupTenant();
        $this->putJson('/api/settings/ticket', $payload)->assertOk();
        $feature = DB::table('features')->where('code', 'allows_invoicing')->value('id');
        $version = DB::table('plan_subscriptions')->where('current_tenant_id', $tenant->id)->value('plan_version_id');
        DB::table('plan_entitlements')->where('feature_id', $feature)->where('plan_version_id', $version)->update(['value' => 0]);
        $this->getJson('/api/settings/ticket')->assertOk()->assertJsonPath('data.invoice.show_qr', false);
        $this->assertDatabaseHas('tenant_ticket_settings', ['tenant_id' => $tenant->id, 'show_invoice_qr' => true]);
        $this->enableTenantFeature($tenant, 'allows_invoicing');
        $this->getJson('/api/settings/ticket')->assertOk()->assertJsonPath('data.invoice.show_qr', true);
    }

    public function test_only_operable_branches_block_global_activation(): void
    {
        [$tenant, $payload] = $this->setupTenant();
        $branch = app(CurrentTenant::class)->run($tenant, fn () => $tenant->branches()->create(['name' => 'Otra', 'code' => 'OTRA', 'is_active' => false, 'billing_enabled' => true]));
        $this->putJson('/api/settings/ticket', $payload)->assertOk();
        DB::table('branches')->where('id', $branch->id)->update(['is_active' => true]);
        $this->putJson('/api/settings/ticket', $payload)->assertUnprocessable()->assertJsonPath('invoice_qr.missing_branches.0.id', $branch->public_id);
        DB::table('branches')->where('id', $branch->id)->update(['billing_enabled' => false]);
        $this->putJson('/api/settings/ticket', $payload)->assertOk();
    }

    public function test_ticket_configuration_is_global_even_when_legacy_branch_overrides_exist(): void
    {
        [$tenant] = $this->setupTenant(false);

        app(CurrentTenant::class)->run($tenant, function () use ($tenant): void {
            $branch = $tenant->branches()->sole();
            app(TicketSettingsService::class)->setting($tenant)->update([
                'footer_text' => 'Gracias por visitar nuestro negocio.',
            ]);
            DB::table('branch_ticket_settings')->insert([
                'branch_id' => $branch->id,
                'overrides' => json_encode(['footer_text' => 'Texto local anterior']),
                'schema_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $effective = app(TicketSettingsService::class)->setting($tenant, $branch);

            $this->assertSame('Gracias por visitar nuestro negocio.', $effective->footer_text);
        });

        $branch = app(CurrentTenant::class)->run($tenant, fn () => $tenant->branches()->sole());
        $this->getJson('/api/settings/branches/'.$branch->public_id.'/ticket')->assertNotFound();
    }

    public function test_ticket_uses_the_global_business_contact_not_a_branch_phone(): void
    {
        [$tenant] = $this->setupTenant(false);

        app(CurrentTenant::class)->run($tenant, function () use ($tenant): void {
            $branch = $tenant->branches()->sole();
            $branch->update(['phone' => '4420000000']);

            $meta = app(TicketSettingsService::class)->meta($tenant, $branch);

            $this->assertSame('5555555555', $meta['business']['phone']);
            $this->assertSame('4420000000', $meta['branch']['phone']);
        });
    }
}
