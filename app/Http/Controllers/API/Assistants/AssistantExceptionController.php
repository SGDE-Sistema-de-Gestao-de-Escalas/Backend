<?php

namespace App\Http\Controllers\API\Assistants;

use App\Http\Controllers\Controller;
use App\Models\Assistants\AssistantException;
use App\Http\Requests\Assistants\StoreAssistantExceptionRequest;
use App\Http\Requests\Assistants\UpdateAssistantExceptionRequest;
use App\Http\Resources\Assistants\AssistantExceptionResource;

class AssistantExceptionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $exceptions = AssistantException::with('assistant')->get();
        return AssistantExceptionResource::collection($exceptions);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAssistantExceptionRequest $request)
    {
        $exception = AssistantException::create($request->validated());
        return new AssistantExceptionResource($exception);
    }

    /**
     * Display the specified resource.
     */
    public function show(AssistantException $assistantException)
    {
        return new AssistantExceptionResource($assistantException);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAssistantExceptionRequest $request, AssistantException $assistantException)
    {
        $assistantException->update($request->validated());
        return new AssistantExceptionResource($assistantException);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AssistantException $assistantException)
    {
        $assistantException->delete();
        return response()->noContent();
    }
}
