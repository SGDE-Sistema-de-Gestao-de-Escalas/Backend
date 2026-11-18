<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_recalculation_flags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('assistant_id')->constrained('assistants')->cascadeOnDelete();
            $table->date('date');
            $table->string('reason', 40);
            $table->text('message')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'assistant_id', 'date', 'reason'], 'sched_recalc_flags_unique');
            $table->index(['school_id', 'date'], 'sched_recalc_flags_school_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_recalculation_flags');
    }
};
