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
        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->foreignUuid('schedule_rule_id')
                ->nullable()
                ->after('activity_name')
                ->constrained('schedule_rules')
                ->nullOnDelete();

            $table->foreignUuid('replaced_assistant_id')
                ->nullable()
                ->after('is_locked')
                ->constrained('assistants')
                ->nullOnDelete();

            $table->string('replacement_reason', 64)
                ->nullable()
                ->after('replaced_assistant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('schedule_rule_id');
            $table->dropConstrainedForeignId('replaced_assistant_id');
            $table->dropColumn('replacement_reason');
        });
    }
};
