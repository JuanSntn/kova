<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_webhook_events', function (Blueprint $table): void {
            $table->timestamp('locked_at')->nullable()->after('status');
            $table->uuid('lock_token')->nullable()->after('locked_at');
        });
    }

    public function down(): void
    {
        Schema::table('billing_webhook_events', function (Blueprint $table): void {
            $table->dropColumn(['locked_at', 'lock_token']);
        });
    }
};
