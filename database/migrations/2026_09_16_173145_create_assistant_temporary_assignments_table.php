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
        Schema::create('assistant_temporary_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assistant_id')->constrained('assistants');
            $table->foreignId('origin_school_id')->constrained('schools');
            $table->foreignId('destination_school_id')->constrained('schools');
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('validated_by_user_id')->nullable(); //check
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assistant_temporary_assignments');
    }
};
