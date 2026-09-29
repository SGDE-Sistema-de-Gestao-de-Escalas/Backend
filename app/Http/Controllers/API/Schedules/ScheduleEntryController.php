<?php

namespace App\Http\Controllers\API\Schedules;

use App\Models\Schedules\ScheduleEntry;
use App\Http\Requests\Schedules\StoreScheduleEntryRequest;
use App\Http\Requests\Schedules\UpdateScheduleEntryRequest;

class ScheduleEntryController extends Controller
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
    public function store(StoreScheduleEntryRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(ScheduleEntry $scheduleEntry)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateScheduleEntryRequest $request, ScheduleEntry $scheduleEntry)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ScheduleEntry $scheduleEntry)
    {
        //
    }
}
