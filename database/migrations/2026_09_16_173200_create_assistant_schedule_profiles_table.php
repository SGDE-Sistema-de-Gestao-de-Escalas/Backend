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
        Schema::create('assistant_schedule_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignuuid('assistant_id')->constrained('assistants');
            $table->enum('type', ['fixo', 'rotativo']);
            $table->enum('rotation_period', ['weekly', 'biweekly', 'monthly'])->nullable();
            $table->enum('starts_with', ['A', 'B'])->nullable();
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->timestamps();

            $table->index(['assistant_id', 'valid_from', 'valid_until'], 'asst_sched_profiles_period_index');
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assistant_schedule_profiles');
    }
};
