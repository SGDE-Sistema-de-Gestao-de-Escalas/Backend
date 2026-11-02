<?php

namespace App\Http\Controllers\API\Schedules;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedules\StoreHolidayRequest;
use App\Http\Requests\Schedules\UpdateHolidayRequest;
use App\Http\Resources\HolidayResource;
use App\Models\Schedules\Holiday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class HolidayController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Holiday::class);

        $filters = $request->validate([
            'year' => ['sometimes', 'integer', 'between:1,9999'],
            'month' => ['sometimes', 'integer', 'between:1,12'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Holiday::query()->orderBy('date');

        if (isset($filters['year'])) {
            $query->whereYear('date', $filters['year']);
        }

        if (isset($filters['month'])) {
            $query->whereMonth('date', $filters['month']);
        }

        return HolidayResource::collection(
            $query->paginate($filters['per_page'] ?? 15)->withQueryString()
        );
    }

    public function store(StoreHolidayRequest $request): JsonResponse
    {
        $holiday = Holiday::create($request->validated());

        return (new HolidayResource($holiday))
            ->additional(['message' => 'Feriado criado com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Holiday $holiday): HolidayResource
    {
        Gate::authorize('view', $holiday);

        return new HolidayResource($holiday);
    }

    public function update(UpdateHolidayRequest $request, Holiday $holiday): HolidayResource
    {
        $holiday->update($request->validated());

        return (new HolidayResource($holiday))
            ->additional(['message' => 'Feriado atualizado com sucesso.']);
    }

    public function destroy(Holiday $holiday): JsonResponse
    {
        Gate::authorize('delete', $holiday);

        $holiday->delete();

        return response()->json(['message' => 'Feriado removido com sucesso.']);
    }
}
