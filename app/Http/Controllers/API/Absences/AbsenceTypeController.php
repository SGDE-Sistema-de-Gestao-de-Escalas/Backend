<?php

namespace App\Http\Controllers\API\Absences;

use App\Models\Absences\AbsenceType;
use App\Http\Requests\Absences\StoreAbsenceTypeRequest;
use App\Http\Requests\Absences\UpdateAbsenceTypeRequest;

class AbsenceTypeController extends Controller
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
    public function store(StoreAbsenceTypeRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(AbsenceType $absenceType)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAbsenceTypeRequest $request, AbsenceType $absenceType)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AbsenceType $absenceType)
    {
        //
    }
}
