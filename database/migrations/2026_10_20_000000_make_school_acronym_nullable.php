<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A sigla da escola passa a ser opcional. O índice único mantém-se: continua
 * a não poder haver duas escolas com a mesma sigla, mas várias podem não ter
 * sigla (NULL não conta como valor repetido).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('acronym')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Antes de voltar a exigir sigla, dá-se uma provisória às escolas que não têm.
        DB::table('schools')->whereNull('acronym')->orderBy('id')->each(function ($school) {
            DB::table('schools')->where('id', $school->id)->update([
                'acronym' => strtoupper(substr(str_replace('-', '', (string) $school->id), 0, 8)),
            ]);
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->string('acronym')->nullable(false)->change();
        });
    }
};
