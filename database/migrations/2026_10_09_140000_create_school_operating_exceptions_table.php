<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Exceções ao horário fixo de uma escola: um período (valid_from a
     * valid_until, ambos obrigatórios) em que a escola fecha (is_closed) ou
     * funciona com um horário diferente, igual em todos os dias do período.
     * Quando a escola fecha as horas ficam a null.
     */
    public function up(): void
    {
        Schema::create('school_operating_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->string('name');
            $table->boolean('is_closed')->default(false);
            $table->time('opening_time')->nullable();
            $table->time('closing_time')->nullable();
            $table->time('lunch_start')->nullable();
            $table->time('lunch_end')->nullable();
            $table->unsignedSmallInteger('lunch_duration_minutes')->nullable();
            $table->date('valid_from');
            $table->date('valid_until');
            $table->timestamps();

            $table->index(['school_id', 'valid_from', 'valid_until'], 'school_operating_exceptions_period_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('school_operating_exceptions');
    }
};
