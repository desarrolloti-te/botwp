<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lead_profiles', function (Blueprint $table) {
        $table->id();
        $table->string('user_number')->unique(); // El teléfono es el ID principal
        $table->string('type')->default('unknown'); // 'prospect' o 'client'
        
        // Datos para CLIENTES EXISTENTES
        $table->string('full_name')->nullable();
        $table->string('role')->nullable(); // Puesto
        $table->string('company')->nullable();
        $table->string('current_system')->nullable(); // Sistema que usa
        
        // Datos para PROSPECTOS (NUEVOS)
        $table->string('interest_service')->nullable(); // Qué le interesa (Bancos, Nube, etc)
        $table->string('company_size')->nullable(); // Tamaño empresa
        $table->boolean('has_erp_experience')->default(false);
        $table->text('pain_point')->nullable(); // Su problema principal
        
        $table->timestamps();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_profiles');
    }
};
