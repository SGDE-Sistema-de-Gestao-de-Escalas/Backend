<?php

namespace App\Http\Controllers\API\Schools;

use App\Models\School;
use App\Http\Requests\Schools\StoreSchoolRequest;
use App\Http\Requests\Schools\UpdateSchoolRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\SchoolResource;
use App\Services\SchoolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class SchoolController extends Controller
{
    public function __construct(
        private readonly SchoolService $schoolService
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', School::class);

        $schools = School::withCount([
                'assistants',
                'schedules' => fn ($query) => $query->where('status', '!=', 'archived'),
            ])
            ->orderBy('name')
            ->paginate();

        return SchoolResource::collection($schools);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSchoolRequest $request): JsonResponse
    {
        $school = $this->schoolService->create($request->validated());

        return (new SchoolResource($school))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(School $school): SchoolResource
    {
        Gate::authorize('view', $school);

        $school->loadCount([
            'assistants',
            'schedules' => fn ($query) => $query->where('status', '!=', 'archived'),
        ]);

        return new SchoolResource($school);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSchoolRequest $request, School $school): SchoolResource
    {
        $updatedSchool = $this->schoolService->update($school, $request->validated());

        return new SchoolResource($updatedSchool);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(School $school): JsonResponse
    {
        Gate::authorize('delete', $school);

        $blockReason = $this->schoolService->deletionBlockReason($school);

        if ($blockReason !== null) {
            return response()->json([
                'error' => 'CONFLICT',
                'message' => $blockReason,
            ], 409);
        }

        $this->schoolService->delete($school);

        return response()->json(['message' => 'Escola removida com sucesso.']);
    }
}
