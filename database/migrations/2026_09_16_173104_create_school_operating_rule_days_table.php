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
        Schema::create('school_operating_rule_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_operating_rule_id')->constrained('school_operating_rules');
            $table->unsignedTinyInteger('day_of_week');
            $table->time('opening_time');
            $table->time('closing_time');
            $table->time('lunch_start');
            $table->time('lunch_end');
            $table->unsignedSmallInteger('lunch_duration_minutes');
            $table->timestamps();

            $table->unique(['school_operating_rule_id', 'day_of_week'], 'rule_days_rule_day_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('school_operating_rule_days');
    }
};
