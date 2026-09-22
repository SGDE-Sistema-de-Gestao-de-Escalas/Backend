<?php

namespace App\Http\Controllers\API\System;

use App\Models\ActivityType;
use App\Http\Requests\StoreActivityTypeRequest;
use App\Http\Requests\UpdateActivityTypeRequest;

class ActivityTypeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreActivityTypeRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(ActivityType $activityType)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateActivityTypeRequest $request, ActivityType $activityType)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ActivityType $activityType)
    {
        //
    }
}
