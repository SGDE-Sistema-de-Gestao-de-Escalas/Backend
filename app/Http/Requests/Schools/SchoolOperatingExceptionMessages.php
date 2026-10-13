<?php

namespace App\Http\Requests\Schools;

/**
 * Mensagens de validação partilhadas pelos pedidos de criação e atualização
 * das exceções ao horário fixo.
 */
class SchoolOperatingExceptionMessages
{
    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            'school_id.required' => 'Indique a escola através do cabeçalho X-School-ID.',
            'name.required' => 'O nome da exceção é obrigatório.',
            'name.string' => 'O nome da exceção deve ser texto.',
            'name.max' => 'O nome da exceção não pode ter mais de 255 caracteres.',
            'is_closed.required' => 'Indique se a escola fecha neste período.',
            'is_closed.boolean' => 'O campo "escola fechada" deve ser verdadeiro ou falso.',
            'opening_time.required' => 'A hora de abertura é obrigatória quando a escola não fecha.',
            'opening_time.date_format' => 'A hora de abertura deve estar no formato HH:MM.',
            'closing_time.required' => 'A hora de fecho é obrigatória quando a escola não fecha.',
            'closing_time.date_format' => 'A hora de fecho deve estar no formato HH:MM.',
            'lunch_start.required' => 'O início do almoço é obrigatório quando a escola não fecha.',
            'lunch_start.date_format' => 'O início do almoço deve estar no formato HH:MM.',
            'lunch_end.required' => 'O fim do almoço é obrigatório quando a escola não fecha.',
            'lunch_end.date_format' => 'O fim do almoço deve estar no formato HH:MM.',
            'lunch_duration_minutes.required' => 'A duração do almoço é obrigatória quando a escola não fecha.',
            'lunch_duration_minutes.integer' => 'A duração do almoço deve ser um número de minutos.',
            'lunch_duration_minutes.min' => 'A duração do almoço deve ser de pelo menos 1 minuto.',
            'lunch_duration_minutes.max' => 'A duração do almoço não pode exceder 1440 minutos.',
            'valid_from.required' => 'A data de início é obrigatória.',
            'valid_from.date_format' => 'A data de início deve estar no formato AAAA-MM-DD.',
            'valid_until.required' => 'A data de fim é obrigatória.',
            'valid_until.date_format' => 'A data de fim deve estar no formato AAAA-MM-DD.',
        ];
    }
}
