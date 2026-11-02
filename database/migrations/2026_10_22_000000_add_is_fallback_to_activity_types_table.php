<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('activity_types', function (Blueprint $table) {
            $table->boolean('is_fallback')->default(false)->after('is_system');
        });

        DB::table('activity_types')
            ->where('is_system', true)
            ->where('name', 'Apoio Escola')
            ->update([
                'is_fallback' => true,
                'active' => true,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_types', function (Blueprint $table) {
            $table->dropColumn('is_fallback');
        });
    }
};
