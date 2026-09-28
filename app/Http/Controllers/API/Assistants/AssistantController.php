<?php

namespace App\Http\Controllers\API\Assistants;

use App\Models\Assistant;
use App\Http\Requests\Assistants\StoreAssistantRequest;
use App\Http\Requests\Assistants\UpdateAssistantRequest;
use App\Http\Controllers\Controller;

class AssistantController extends Controller
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
    public function store(StoreAssistantRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Assistant $assistant)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAssistantRequest $request, Assistant $assistant)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Assistant $assistant)
    {
        //
    }
}
