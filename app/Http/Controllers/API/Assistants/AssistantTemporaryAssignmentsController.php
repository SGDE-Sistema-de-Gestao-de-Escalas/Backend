<?php

namespace App\Http\Controllers\API\Assistants;

use App\Http\Controllers\Controller;
use App\Models\Assistants\AssistantTemporaryAssignments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantTemporaryAssignmentsController extends Controller
{
    /**
     * Display a listing of temporary assignments for the active school / date range.
     */
    public function index(Request $request): JsonResponse
    {
        $schoolId = $request->header('X-School-ID') ?? $request->query('school_id');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $query = AssistantTemporaryAssignments::query()
            ->with([
                'assistant.user',
                'originSchool',
                'destinationSchool',
            ]);

        if ($schoolId) {
            $query->where(function ($q) use ($schoolId) {
                $q->where('origin_school_id', $schoolId)
                    ->orWhere('destination_school_id', $schoolId);
            });
        }

        if ($startDate && $endDate) {
            $query->whereDate('valid_from', '<=', $endDate)
                ->where(function ($q) use ($startDate) {
                    $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $startDate);
                });
        } elseif ($startDate) {
            $query->where(function ($q) use ($startDate) {
                $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $startDate);
            });
        }

        $assignments = $query->orderBy('valid_from')->get();

        return response()->json([
            'data' => $assignments,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(AssistantTemporaryAssignments $assistantTemporaryAssignment): JsonResponse
    {
        $assistantTemporaryAssignment->load(['assistant.user', 'originSchool', 'destinationSchool']);
        return response()->json([
            'data' => $assistantTemporaryAssignment,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AssistantTemporaryAssignments $assistantTemporaryAssignment): JsonResponse
    {
        $assistantTemporaryAssignment->delete();
        return response()->json(['message' => 'Transferência temporária removida com sucesso.']);
    }
}
