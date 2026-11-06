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
        Schema::create('school_operating_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignuuid('school_id')->constrained('schools');
            $table->date('valid_from');
            $table->timestamps();

            $table->unique(['school_id', 'valid_from'], 'operating_rules_school_valid_from_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('school_operating_rules');
    }
};
