<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_rules', function (Blueprint $table) {
            $table->foreignUuid('substitute_assistant_id')->nullable()->constrained('assistants')->nullOnDelete();
        });
        $this->shiftDays(-1);
    }

    public function down(): void
    {
        $this->shiftDays(1);
        Schema::table('schedule_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('substitute_assistant_id');
        });
    }

    private function shiftDays(int $offset): void
    {
        DB::transaction(function () use ($offset) {
            DB::table('schedule_rules')->whereNotNull('days_of_week')->orderBy('id')->chunkById(200, function ($rules) use ($offset) {
                foreach ($rules as $rule) {
                    $days = json_decode($rule->days_of_week, true, 512, JSON_THROW_ON_ERROR);
                    DB::table('schedule_rules')->where('id', $rule->id)->update([
                        'days_of_week' => json_encode(array_map(fn ($day) => (int) $day + $offset, $days), JSON_THROW_ON_ERROR),
                    ]);
                }
            });
        });
    }
};
