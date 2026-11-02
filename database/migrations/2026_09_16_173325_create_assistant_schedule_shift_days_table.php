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
        Schema::create('assistant_schedule_shift_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('assistant_schedule_shift_id')
                  ->constrained(
                      table: 'assistant_schedule_shifts',
                      indexName: 'fk_shift_days_shift_id'
                  );
            $table->unsignedTinyInteger('day_of_week');
            $table->timestamps();

            $table->unique(['assistant_schedule_shift_id', 'day_of_week'], 'asst_shift_days_shift_dow_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assistant_schedule_shift_days');
    }
};
