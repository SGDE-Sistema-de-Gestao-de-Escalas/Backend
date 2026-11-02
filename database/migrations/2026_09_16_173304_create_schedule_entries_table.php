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
        Schema::create('schedule_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('schedule_id')->constrained('schedules');
            $table->foreignUuid('activity_type_id')->constrained('activity_types');
            $table->string('activity_name')->nullable();
            $table->foreignUuid('schedule_rule_id')->nullable()->constrained('schedule_rules')->nullOnDelete();
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('is_locked')->default(false);
            $table->foreignUuid('replaced_assistant_id')->nullable()->constrained('assistants')->nullOnDelete();
            $table->string('replacement_reason', 64)->nullable();
            $table->timestamps();

            $table->index(['schedule_id', 'date']);
            $table->index(['date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_entries');
    }
};
