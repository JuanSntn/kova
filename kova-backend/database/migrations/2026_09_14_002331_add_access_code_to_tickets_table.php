<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'tickets',
            function (Blueprint $table): void {
                /*
                 * Código manual de autofacturación.
                 *
                 * El valor real será algo como:
                 *
                 * K7PT4M9X2QW8
                 *
                 * y únicamente se presentará visualmente:
                 *
                 * K7PT-4M9X-2QW8
                 *
                 * Igual que el token largo actual,
                 * no almacenamos el secreto directamente.
                 */

                $table
                    ->string(
                        'access_code_hash',
                        64
                    )
                    ->nullable()
                    ->unique()
                    ->after(
                        'encrypted_token'
                    );

                $table
                    ->text(
                        'encrypted_access_code'
                    )
                    ->nullable()
                    ->after(
                        'access_code_hash'
                    );
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'tickets',
            function (Blueprint $table): void {
                $table->dropColumn([
                    'access_code_hash',
                    'encrypted_access_code',
                ]);
            }
        );
    }
};
