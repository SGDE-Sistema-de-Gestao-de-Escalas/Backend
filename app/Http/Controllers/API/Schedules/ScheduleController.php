<?php

namespace App\Http\Controllers\API\Schedules;

use App\Models\Schedules\Schedule;
use App\Http\Requests\Schedules\StoreScheduleRequest;
use App\Http\Requests\Schedules\UpdateScheduleRequest;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class ScheduleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = $request->user();

        $query = Schedule::query();
        
        // Limita a listagem às escalas do próprio assistente.
        if (! $user->isAdmin()) {
            $query->whereHas('assistant', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            });
        }

    return $query->paginate(20);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreScheduleRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Schedule $schedule)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateScheduleRequest $request, Schedule $schedule)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Schedule $schedule)
    {
        //
    }
}
