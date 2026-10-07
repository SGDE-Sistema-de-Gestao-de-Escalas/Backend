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
        Schema::create('assistant_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignuuid('assistant_id')->constrained('assistants');
            $table->string('type');
            $table->text('description')->nullable();
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assistant_exceptions');
    }
};
