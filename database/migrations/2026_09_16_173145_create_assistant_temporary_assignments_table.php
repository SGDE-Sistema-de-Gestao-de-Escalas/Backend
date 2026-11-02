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
            $table->uuid('id')->primary();
            $table->foreignuuid('assistant_id')->constrained('assistants');
            $table->foreignuuid('origin_school_id')->constrained('schools');
            $table->foreignuuid('destination_school_id')->constrained('schools');
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->foreignUuid('validated_by_user_id')->nullable()->constrained('users');  
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['destination_school_id', 'valid_from', 'valid_until'], 'asst_temp_dest_period_index');
            $table->index(['origin_school_id', 'valid_from', 'valid_until'], 'asst_temp_origin_period_index');
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
