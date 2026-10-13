<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', fn (Blueprint $table) => $table->uuid('public_id')->nullable()->after('id'));
        DB::table('memberships')->orderBy('id')->each(fn ($membership) => DB::table('memberships')->where('id', $membership->id)->update(['public_id' => (string) Str::uuid()]));
        Schema::table('memberships', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable(false)->change();
            $table->unique('public_id');
        });

        Schema::create('branch_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['membership_id', 'branch_id']);
        });
        Schema::create('branch_product_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_available')->default(true);
            $table->decimal('price_override', 12, 2)->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'product_id']);
        });
        Schema::create('branch_ticket_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('overrides');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->timestamps();
        });
        Schema::create('branch_fiscal_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_series_id')->constrained('fiscal_series')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('place_of_issue_postal_code', 5)->nullable()->after('issuer_snapshot');
            $table->string('issued_timezone', 64)->nullable()->after('place_of_issue_postal_code');
            $table->json('branch_snapshot')->nullable()->after('issued_timezone');
        });

        $ownerRoleIds = DB::table('roles')->where('name', 'owner')->pluck('id');
        DB::table('memberships')->where('status', 'active')->orderBy('id')->each(function ($membership) use ($ownerRoleIds): void {
            $isOwner = DB::table('model_has_roles')->whereIn('role_id', $ownerRoleIds)
                ->where('model_type', User::class)->where('model_id', $membership->user_id)
                ->where('tenant_id', $membership->tenant_id)->exists();
            if ($isOwner) {
                return;
            }
            $now = now();
            $rows = DB::table('branches')->where('tenant_id', $membership->tenant_id)->pluck('id')->map(fn ($branchId) => [
                'membership_id' => $membership->id, 'branch_id' => $branchId, 'created_at' => $now, 'updated_at' => $now,
            ])->all();
            if ($rows !== []) {
                DB::table('branch_memberships')->insertOrIgnore($rows);
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['place_of_issue_postal_code', 'issued_timezone', 'branch_snapshot']));
        Schema::dropIfExists('branch_fiscal_settings');
        Schema::dropIfExists('branch_ticket_settings');
        Schema::dropIfExists('branch_product_settings');
        Schema::dropIfExists('branch_memberships');
        Schema::table('memberships', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
