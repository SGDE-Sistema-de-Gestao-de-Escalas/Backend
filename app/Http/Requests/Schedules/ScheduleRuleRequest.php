<?php

namespace App\Http\Requests\Schedules;

use App\Models\Schedules\ScheduleRule;
use Illuminate\Foundation\Http\FormRequest;

class ScheduleRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $rule = $this->route('schedule_rule');
        $ability = match ($this->method()) {
            'POST' => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => $rule ? 'view' : 'viewAny',
        };
        if (! $this->user()?->can($ability, $rule ?? ScheduleRule::class)) {
            return false;
        }

        $schoolId = $this->attributes->get('school_id');
        abort_unless($schoolId, 422, 'Indique a escola através do cabeçalho X-School-ID.');
        abort_if($rule && $rule->school_id !== $schoolId, 404);

        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
