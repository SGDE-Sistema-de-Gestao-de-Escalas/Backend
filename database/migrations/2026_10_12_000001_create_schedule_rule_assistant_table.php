<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_rule_assistant', function (Blueprint $table) {
            $table->foreignUuid('schedule_rule_id')->constrained('schedule_rules')->cascadeOnDelete();
            $table->foreignUuid('assistant_id')->constrained('assistants')->cascadeOnDelete();
            $table->unsignedInteger('priority')->default(1);

            $table->primary(['schedule_rule_id', 'assistant_id']);
            $table->index(['schedule_rule_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_rule_assistant');
    }
};
