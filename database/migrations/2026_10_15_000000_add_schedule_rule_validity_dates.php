<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_rules', function (Blueprint $table) {
            $table->renameColumn('start', 'valid_from');
        });
        Schema::table('schedule_rules', function (Blueprint $table) {
            $table->date('valid_until')->nullable()->after('valid_from');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_rules', function (Blueprint $table) {
            $table->dropColumn('valid_until');
            $table->renameColumn('valid_from', 'start');
        });
    }
};
