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
        Schema::create('assistant_schedule_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assistant_schedule_profile_id')->constrained('assistant_schedule_profiles');
            $table->enum('shift_label', ['A', 'B']);
            $table->time('entry_time');
            $table->time('exit_time');
            $table->boolean('lunch_enabled')->default(true);
            $table->time('lunch_start')->nullable();
            $table->time('lunch_end')->nullable();
            $table->unsignedSmallInteger('lunch_duration_minutes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assistant_schedule_shifts');
    }
};
