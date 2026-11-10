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
        Schema::create('activity_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignuuid('school_id')->constrained('schools');
            $table->string('name');
            $table->string('color', 7);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_fallback')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['school_id', 'name'], 'activity_types_school_id_name_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_types');
    }
};
