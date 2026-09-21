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
            $table->id();
            $table->foreignId('school_operating_rule_id')->constrained('school_operating_rules');
            $table->unsignedTinyInteger('day_of_week'); 
            $table->timestamps();
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
