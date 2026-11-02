<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Older installations ran the original migration before this field existed.
        if (! Schema::hasColumn('assistants', 'is_anonymized')) {
            Schema::table('assistants', function (Blueprint $table) {
                $table->boolean('is_anonymized')->default(false);
            });
        }

        DB::table('assistants')->whereIn('user_id', function ($query) {
            $query->select('id')->from('users')->whereNotNull('anonymized_at');
        })->update(['is_anonymized' => true]);
    }

    public function down(): void
    {
        // Preserve privacy state and the column also defined by the original migration.
    }
};
