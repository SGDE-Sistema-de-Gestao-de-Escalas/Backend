<?php

namespace App\Http\Controllers\API\Schools;

use App\Http\Controllers\Controller;
use App\Models\Schools\SchoolOperatingRule;
use App\Services\OperatingHoursResolver;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OperatingCalendarController extends Controller
{
    private const MAX_DAYS = 366;

    public function __construct(
        private readonly OperatingHoursResolver $resolver
    ) {}

    /**
     * Calendário de funcionamento da escola do cabeçalho X-School-ID, dia a
     * dia, entre `from` e `to` (inclusivos, no máximo 366 dias). Cada dia diz
     * se a escola funciona, em que horas, e a origem da decisão: feriado,
     * exceção, horário fixo ou nenhuma.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', SchoolOperatingRule::class);

        $schoolId = $request->attributes->get('school_id');

        abort_if($schoolId === null, 422, 'Indique a escola através do cabeçalho X-School-ID.');

        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [
            'from.required' => 'A data inicial é obrigatória.',
            'from.date_format' => 'A data inicial deve estar no formato AAAA-MM-DD.',
            'to.required' => 'A data final é obrigatória.',
            'to.date_format' => 'A data final deve estar no formato AAAA-MM-DD.',
            'to.after_or_equal' => 'A data final não pode ser anterior à data inicial.',
        ]);

        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->startOfDay();

        abort_if(
            $from->diffInDays($to) + 1 > self::MAX_DAYS,
            422,
            'O intervalo não pode ter mais de '.self::MAX_DAYS.' dias.'
        );

        return response()->json([
            'data' => $this->resolver->forRange($schoolId, $from, $to),
        ]);
    }
}
