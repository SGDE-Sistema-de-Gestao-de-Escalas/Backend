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
        Schema::create('absences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignuuid('assistant_id')->constrained('assistants');
            $table->foreignuuid('absence_type_id')->constrained('absence_types');
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users');
            $table->foreignUuid('justified_by_user_id')->nullable()->constrained('users');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->unsignedSmallInteger('total_days');
            $table->text('justification')->nullable();
            $table->string('document_path')->nullable();
            $table->enum('status', ['justified', 'unjustified', 'waiting_for_document'])->default('unjustified');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('absences');
    }
};
