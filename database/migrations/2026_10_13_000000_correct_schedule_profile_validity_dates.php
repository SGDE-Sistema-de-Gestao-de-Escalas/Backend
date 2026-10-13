<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A stored time cannot establish the original intended calendar date.
        $this->ensureEmptyProfiles();

        Schema::table('assistant_schedule_profiles', function (Blueprint $table) {
            $table->date('valid_from')->nullable(false)->change();
            $table->date('valid_until')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rolling back populated dates would also lose information.
        $this->ensureEmptyProfiles();

        Schema::table('assistant_schedule_profiles', function (Blueprint $table) {
            $table->time('valid_from')->nullable(false)->change();
            $table->time('valid_until')->nullable()->change();
        });
    }

    private function ensureEmptyProfiles(): void
    {
        if (DB::table('assistant_schedule_profiles')->exists()) {
            throw new RuntimeException(
                'Existem perfis de horário. É necessário preparar uma migração de dados com as datas de validade corretas antes de alterar o tipo das colunas. Nenhum perfil foi alterado.'
            );
        }
    }
};
