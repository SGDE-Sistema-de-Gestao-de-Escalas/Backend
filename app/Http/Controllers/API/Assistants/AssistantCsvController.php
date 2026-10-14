<?php

namespace App\Http\Controllers\API\Assistants;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assistants\ImportAssistantsRequest;
use App\Models\Assistants\Assistant;
use App\Models\Schools\School;
use App\Services\AssistantExportService;
use App\Services\AssistantImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssistantCsvController extends Controller
{
    public function __construct(
        private readonly AssistantExportService $exportService,
        private readonly AssistantImportService $importService
    ) {}

    /**
     * Exporta os assistentes da escola do cabeçalho X-School-ID para CSV
     * (separador ";", UTF-8 com BOM). Sem assistentes, devolve só o
     * cabeçalho: o modelo vazio para a importação.
     *
     * Filtro opcional: ?status=active (por omissão) | inactive | all.
     */
    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('viewAny', Assistant::class);

        $filters = $request->validate([
            'status' => ['sometimes', Rule::in([
                AssistantExportService::STATUS_ACTIVE,
                AssistantExportService::STATUS_INACTIVE,
                AssistantExportService::STATUS_ALL,
            ])],
        ]);

        $schoolId = $request->attributes->get('school_id');

        abort_if($schoolId === null, 422, 'Indique a escola através do cabeçalho X-School-ID.');

        $school = School::findOrFail($schoolId);
        $status = $filters['status'] ?? AssistantExportService::STATUS_ACTIVE;
        $filename = 'assistentes-'.Str::slug($school->acronym ?: $school->name).'-'.today()->toDateString().'.csv';

        return response()->streamDownload(function () use ($schoolId, $status) {
            $stream = fopen('php://output', 'w');
            $this->exportService->write($stream, $schoolId, $status);
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Importa assistentes de um CSV para a escola do cabeçalho X-School-ID.
     *
     * Campos: `file` (CSV) e `on_duplicate` = skip (por omissão, ignora os
     * números mecanográficos que já existem) | overwrite (atualiza-os).
     * As linhas válidas são guardadas; as inválidas vêm listadas em `errors`.
     */
    public function import(ImportAssistantsRequest $request): JsonResponse
    {
        $onDuplicate = $request->validated('on_duplicate') ?? AssistantImportService::ON_DUPLICATE_SKIP;

        $result = $this->importService->import(
            (string) file_get_contents($request->file('file')->getRealPath()),
            $request->validated('school_id'),
            $onDuplicate
        );

        $summary = $result['summary'];

        return response()->json([
            'message' => sprintf(
                'Importação concluída: %d criados, %d atualizados, %d ignorados, %d com erros.',
                $summary['created'], $summary['updated'], $summary['skipped'], $summary['failed']
            ),
            'on_duplicate' => $onDuplicate,
        ] + $result);
    }
}
