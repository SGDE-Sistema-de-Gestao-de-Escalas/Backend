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
            $table->id();
            $table->foreignId('assistant_id')->constrained('assistants');
            $table->enum('type', ['fixo', 'rotativo']);
            $table->enum('rotation_period', ['weekly', 'biweekly', 'monthly'])->nullable();
            $table->enum('starts_with', ['A', 'B'])->nullable();
            $table->time('valid_from');
            $table->time('valid_until')->nullable();
            $table->timestamps();
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
