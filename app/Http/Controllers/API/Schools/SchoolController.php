<?php

namespace App\Http\Controllers\API\Schools;

use App\Models\School;
use App\Http\Requests\Schools\StoreSchoolRequest;
use App\Http\Requests\Schools\UpdateSchoolRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\SchoolResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SchoolController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        return SchoolResource::collection(
            School::orderBy('name')->paginate()
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSchoolRequest $request): JsonResponse
    {
        $school = School::create($request->validated());

        return (new SchoolResource($school))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(School $school): SchoolResource
    {
        return new SchoolResource($school);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSchoolRequest $request, School $school): SchoolResource
    {
        $school->update($request->validated());

        return new SchoolResource($school);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(School $school): JsonResponse
    {
        $school->delete();

        return response()->json(['message' => 'Escola removida com sucesso.']);
    }
}
