<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {   
        if (!Schema::hasColumn('users', 'name')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
        });

        DB::table('users')
        ->select('id', 'name')
        ->chunkById(100, function ($users) {
            foreach ($users as $user) {
                $parts = preg_split('/\s+/', trim($user->name), 2);

                DB::table('users')
                    ->where('id', $user->id)
                    ->update([
                        'first_name' => $parts[0],
                        'last_name' => $parts[1] ?? '',
                    ]);
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable(false)->change();
            $table->string('last_name')->nullable(false)->change();
            $table->dropColumn('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException(
            'Esta conversão de nomes exige uma reversão manual com preservação dos dados.'
        );
    }
};
