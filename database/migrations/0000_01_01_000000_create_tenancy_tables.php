<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Control-plane mínimo de tenancy. Publicable con:
 *   php artisan vendor:publish --tag=tenancy-migrations
 *
 * Vive en la MISMA base que las tablas de negocio para que `tenant_id` pueda ser FK real
 * (MySQL no soporta FK entre esquemas). La app puede agregarle sus propias columnas/tablas
 * (módulos contratados, plan, etc.) en migraciones propias.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();          // subdominio: <slug>.<base_domain>
            $table->boolean('is_active')->default(true);
            $table->json('data')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });

        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('domain')->unique();        // host completo que resuelve al tenant
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
        Schema::dropIfExists('tenants');
    }
};
