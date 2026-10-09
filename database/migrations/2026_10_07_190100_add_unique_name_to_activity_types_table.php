<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uma escola não pode ter duas atividades com o mesmo nome.
     */
    public function up(): void
    {
        Schema::table('activity_types', function (Blueprint $table) {
            $table->unique(['school_id', 'name'], 'activity_types_school_id_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('activity_types', function (Blueprint $table) {
            $table->dropUnique('activity_types_school_id_name_unique');
        });
    }
};
