<?php

namespace App\Http\Controllers\API\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportRequest;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function index(ReportRequest $request, ReportService $reports): JsonResponse
    {
        $dates = $request->validated();

        return response()->json(['data' => $reports->build(
            (string) $request->attributes->get('school_id'),
            $dates['start_date'],
            $dates['end_date'],
        )])->header('Cache-Control', 'private, no-store');
    }
}
