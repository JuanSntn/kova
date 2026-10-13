<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->string('name', 100);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('cash_shifts', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('cash_register_id')->constrained()->restrictOnDelete();
            $t->foreignId('responsible_user_id')->constrained('users')->restrictOnDelete();
            $t->string('status', 10);
            $t->date('business_date');
            $t->string('opened_timezone', 64);
            $t->unsignedBigInteger('activity_version')->default(0);
            // Nullable slots retain portable physical uniqueness after closing.
            $t->foreignId('open_register_id')->nullable()->unique()->constrained('cash_registers');
            $t->foreignId('open_user_id')->nullable()->constrained('users');
            $t->unique(['tenant_id', 'open_user_id']);
            $t->decimal('opening_cash', 16, 2);
            foreach (['cash_sales_total', 'cash_refunds_total', 'cash_in_total', 'cash_out_total', 'card_sales_total', 'transfer_sales_total', 'expected_cash', 'counted_cash', 'difference'] as $column) {
                $t->decimal($column, 16, 2)->nullable();
            }
            $t->text('difference_reason')->nullable();
            $t->text('intervention_reason')->nullable();
            $t->json('denominations')->nullable();
            $t->unsignedInteger('sales_count')->nullable();
            $t->timestamp('opened_at');
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('closed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['tenant_id', 'branch_id', 'business_date']);
        });
        Schema::create('cash_movements', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('cash_shift_id')->constrained()->restrictOnDelete();
            $t->string('type', 3);
            $t->decimal('amount', 16, 2);
            $t->text('reason');
            $t->text('intervention_reason')->nullable();
            $t->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
        });
        Schema::create('cash_operations', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->uuid('idempotency_key');
            $t->unique(['tenant_id', 'idempotency_key']);
            $t->string('operation_type', 32);
            $t->string('request_fingerprint', 64);
            $t->string('resource_type', 32);
            $t->unsignedBigInteger('resource_id');
            $t->json('response_snapshot');
            $t->timestamp('created_at');
        });
        Schema::table('orders', fn (Blueprint $t) => $t->foreignId('cash_shift_id')->nullable()->constrained()->restrictOnDelete());
        if (DB::getDriverName() === 'sqlite') {
            // Defend the slot invariant even against writes bypassing the service.
            foreach (['INSERT', 'UPDATE'] as $event) {
                DB::unprepared('CREATE TRIGGER cash_shift_slots_'.strtolower($event)." BEFORE $event ON cash_shifts WHEN NOT ((NEW.status = 'OPEN' AND NEW.open_register_id IS NEW.cash_register_id AND NEW.open_user_id IS NEW.responsible_user_id) OR (NEW.status = 'CLOSED' AND NEW.open_register_id IS NULL AND NEW.open_user_id IS NULL)) BEGIN SELECT RAISE(ABORT, 'Invalid cash shift slots'); END");
            }
            DB::unprepared("CREATE TRIGGER cash_shift_closed_immutable BEFORE UPDATE ON cash_shifts WHEN OLD.status = 'CLOSED' BEGIN SELECT RAISE(ABORT, 'Closed shift is immutable'); END");
            foreach (['cash_movements', 'cash_operations'] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE ON $table BEGIN SELECT RAISE(ABORT, 'Cash history is immutable'); END");
            }
            foreach (['cash_shifts', 'cash_movements', 'cash_operations'] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON $table BEGIN SELECT RAISE(ABORT, 'Cash history cannot be deleted'); END");
            }
        }
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropConstrainedForeignId('cash_shift_id'));
        Schema::dropIfExists('cash_operations');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_shifts');
        Schema::dropIfExists('cash_registers');
    }
};
