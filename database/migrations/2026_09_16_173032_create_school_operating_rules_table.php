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
            $table->time('opening_time');
            $table->time('closing_time');
            $table->time('lunch_start');
            $table->time('lunch_end');
            $table->unsignedSmallInteger('lunch_duration_minutes');
            $table->date('valid_from');
            $table->date('valid_untill')->nullable();
            $table->timestamps();
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
