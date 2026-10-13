<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('account_type', 24)->default('email')->after('name');
            $table->string('email')->nullable()->change();
        });

        DB::table('users')->whereNull('account_type')->update(['account_type' => 'email']);

        Schema::table('memberships', function (Blueprint $table): void {
            $table->string('login_name', 64)->nullable()->after('user_id');
            $table->string('login_name_normalized', 64)->nullable()->after('login_name');
            $table->timestamp('last_login_at')->nullable()->after('joined_at');
            $table->unique(['tenant_id', 'login_name_normalized'], 'memberships_tenant_login_unique');
        });

        Schema::table('branches', fn (Blueprint $table) => $table->string('staff_login_code', 16)->nullable()->after('public_id'));

        DB::table('branches')->orderBy('id')->each(function (object $branch): void {
            $prefix = Str::upper(preg_replace('/[^A-Z0-9]/i', '', (string) $branch->code) ?: 'KVA');
            $prefix = str_pad(substr($prefix, 0, 3), 3, 'X');
            do {
                $code = $prefix.'-'.Str::upper(Str::random(5));
            } while (DB::table('branches')->where('staff_login_code', $code)->exists());
            DB::table('branches')->where('id', $branch->id)->update(['staff_login_code' => $code]);
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->string('staff_login_code', 16)->nullable(false)->change();
            $table->unique('staff_login_code');
        });

        Schema::table('subscription_changes', fn (Blueprint $table) => $table->json('retained_membership_ids')->nullable()->after('retained_branch_ids'));
        Schema::table('plan_subscriptions', fn (Blueprint $table) => $table->json('cancellation_membership_ids')->nullable()->after('cancellation_branch_ids'));
    }

    public function down(): void
    {
        Schema::table('plan_subscriptions', fn (Blueprint $table) => $table->dropColumn('cancellation_membership_ids'));
        Schema::table('subscription_changes', fn (Blueprint $table) => $table->dropColumn('retained_membership_ids'));
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropUnique(['staff_login_code']);
            $table->dropColumn('staff_login_code');
        });
        Schema::table('memberships', function (Blueprint $table): void {
            $table->dropUnique('memberships_tenant_login_unique');
            $table->dropColumn(['login_name', 'login_name_normalized', 'last_login_at']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('account_type');
            $table->string('email')->nullable(false)->change();
        });
    }
};
