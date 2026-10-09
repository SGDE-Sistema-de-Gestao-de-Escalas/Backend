<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O horário fixo da escola passa a ter versões e horas diferentes por dia da
 * semana:
 *  - `school_operating_rules` fica só com `school_id` e `valid_from` (a versão
 *    vale até a seguinte começar, por isso já não tem data de fim);
 *  - as horas passam para `school_operating_rule_days`, uma linha por dia.
 *
 * Também elimina a coluna `valid_untill` (com erro de escrita) ou, se a
 * migração original já tiver sido corrigida, `valid_until`.
 */
return new class extends Migration
{
    private const TIME_COLUMNS = ['opening_time', 'closing_time', 'lunch_start', 'lunch_end'];

    public function up(): void
    {
        Schema::table('school_operating_rule_days', function (Blueprint $table) {
            foreach (self::TIME_COLUMNS as $column) {
                $table->time($column)->nullable();
            }
            $table->unsignedSmallInteger('lunch_duration_minutes')->nullable();
        });

        // Copia as horas da regra para cada um dos seus dias (se já houver dados).
        DB::table('school_operating_rules')->get()->each(function ($rule) {
            DB::table('school_operating_rule_days')
                ->where('school_operating_rule_id', $rule->id)
                ->update([
                    'opening_time' => $rule->opening_time,
                    'closing_time' => $rule->closing_time,
                    'lunch_start' => $rule->lunch_start,
                    'lunch_end' => $rule->lunch_end,
                    'lunch_duration_minutes' => $rule->lunch_duration_minutes,
                ]);
        });

        Schema::table('school_operating_rule_days', function (Blueprint $table) {
            foreach (self::TIME_COLUMNS as $column) {
                $table->time($column)->nullable(false)->change();
            }
            $table->unsignedSmallInteger('lunch_duration_minutes')->nullable(false)->change();

            $table->unique(['school_operating_rule_id', 'day_of_week'], 'rule_days_rule_day_unique');
        });

        Schema::table('school_operating_rules', function (Blueprint $table) {
            $table->dropColumn(array_merge(
                self::TIME_COLUMNS,
                ['lunch_duration_minutes'],
                $this->validUntilColumns()
            ));
        });

        Schema::table('school_operating_rules', function (Blueprint $table) {
            $table->unique(['school_id', 'valid_from'], 'operating_rules_school_valid_from_unique');
        });
    }

    public function down(): void
    {
        Schema::table('school_operating_rules', function (Blueprint $table) {
            $table->dropUnique('operating_rules_school_valid_from_unique');
        });

        Schema::table('school_operating_rules', function (Blueprint $table) {
            foreach (self::TIME_COLUMNS as $column) {
                $table->time($column)->nullable();
            }
            $table->unsignedSmallInteger('lunch_duration_minutes')->nullable();
            $table->date('valid_until')->nullable();
        });

        // Devolve à regra as horas de um dos seus dias (a versão antiga só
        // tinha um conjunto de horas por regra).
        DB::table('school_operating_rules')->get()->each(function ($rule) {
            $day = DB::table('school_operating_rule_days')
                ->where('school_operating_rule_id', $rule->id)
                ->orderBy('day_of_week')
                ->first();

            DB::table('school_operating_rules')->where('id', $rule->id)->update([
                'opening_time' => $day->opening_time ?? '08:00',
                'closing_time' => $day->closing_time ?? '18:00',
                'lunch_start' => $day->lunch_start ?? '12:00',
                'lunch_end' => $day->lunch_end ?? '13:00',
                'lunch_duration_minutes' => $day->lunch_duration_minutes ?? 60,
            ]);
        });

        Schema::table('school_operating_rules', function (Blueprint $table) {
            foreach (self::TIME_COLUMNS as $column) {
                $table->time($column)->nullable(false)->change();
            }
            $table->unsignedSmallInteger('lunch_duration_minutes')->nullable(false)->change();
        });

        Schema::table('school_operating_rule_days', function (Blueprint $table) {
            $table->dropUnique('rule_days_rule_day_unique');
        });

        Schema::table('school_operating_rule_days', function (Blueprint $table) {
            $table->dropColumn(array_merge(self::TIME_COLUMNS, ['lunch_duration_minutes']));
        });
    }

    /**
     * @return list<string>
     */
    private function validUntilColumns(): array
    {
        return array_values(array_filter(
            ['valid_untill', 'valid_until'],
            fn (string $column) => Schema::hasColumn('school_operating_rules', $column)
        ));
    }
};
