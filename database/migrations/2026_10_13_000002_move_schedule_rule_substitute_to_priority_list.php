<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::transaction(function () {
            DB::table('schedule_rules')->whereNotNull('substitute_assistant_id')->orderBy('id')->chunkById(200, function ($rules) {
                foreach ($rules as $rule) {
                    $pivot = DB::table('schedule_rule_assistant')->where('schedule_rule_id', $rule->id);
                    if (! (clone $pivot)->where('assistant_id', $rule->substitute_assistant_id)->exists()) {
                        $priority = (int) $pivot->max('priority') + 1;
                        DB::table('schedule_rule_assistant')->insert([
                            'schedule_rule_id' => $rule->id,
                            'assistant_id' => $rule->substitute_assistant_id,
                            'priority' => $priority,
                        ]);
                    }
                }
            });
        });
        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('schedule_rules', function (Blueprint $table) {
                $table->dropConstrainedForeignId('substitute_assistant_id');
            });
        });
    }

    public function down(): void
    {
        // Keep all selections in the pivot; do not guess which legacy field to restore.
        Schema::table('schedule_rules', function (Blueprint $table) {
            $table->foreignUuid('substitute_assistant_id')->nullable()->constrained('assistants')->nullOnDelete();
        });
    }
};
