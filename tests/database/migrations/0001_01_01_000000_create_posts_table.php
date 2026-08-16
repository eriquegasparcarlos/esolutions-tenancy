<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de negocio de prueba: representa a cualquier tabla con `tenant_id` de la app consumidora.
 * Es el mínimo para ejercitar BelongsToTenant + TenantScope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->timestamps();

            // Regla del paquete: todo UNIQUE de negocio es COMPUESTO con tenant_id.
            $table->unique(['tenant_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
