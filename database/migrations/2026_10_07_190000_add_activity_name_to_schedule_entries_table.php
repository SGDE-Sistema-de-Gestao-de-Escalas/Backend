<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Guarda em cada entrada de horário o nome que a atividade tinha quando a
     * entrada foi criada, para o histórico não mudar se a atividade for
     * renomeada. As entradas que já existem recebem o nome atual.
     */
    public function up(): void
    {
        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->string('activity_name')->nullable()->after('activity_type_id');
        });

        DB::table('schedule_entries')->update([
            'activity_name' => DB::raw(
                '(select name from activity_types where activity_types.id = schedule_entries.activity_type_id)'
            ),
        ]);
    }

    public function down(): void
    {
        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->dropColumn('activity_name');
        });
    }
};
