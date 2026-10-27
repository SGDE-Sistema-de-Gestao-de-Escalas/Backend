<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `type` continua a ser a categoria (alert | absence | expiry | info).
     * Acrescenta: escola (para filtrar pelo X-School-ID), código do evento e chave anti-duplicado.
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignUuid('school_id')->nullable()->after('user_id')->constrained('schools')->nullOnDelete();
            $table->string('event', 64)->nullable()->after('type');
            $table->string('dedupe_key', 191)->nullable()->after('data');

            // Um utilizador não recebe duas vezes a mesma notificação periódica (NULL não conta).
            $table->unique(['user_id', 'dedupe_key']);
            $table->index(['user_id', 'read_at', 'created_at']);
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'dedupe_key']);
            $table->dropIndex(['user_id', 'read_at', 'created_at']);
            $table->dropIndex(['user_id', 'type']);
            $table->dropConstrainedForeignId('school_id');
            $table->dropColumn(['event', 'dedupe_key']);
        });
    }
};
