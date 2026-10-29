<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Base propia por tenant, opcional.
 *
 * NULL = el tenant vive en la base compartida, separado por `tenant_id` (lo de siempre). Con valor,
 * el contexto conecta a esa base al entrar el tenant. `tenant_id` se conserva en los dos casos, así
 * que el código de negocio no distingue — es lo que permite ofrecer las dos cosas con un solo
 * camino de código.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('database', 64)->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('database');
        });
    }
};
