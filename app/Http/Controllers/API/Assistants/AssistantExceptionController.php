<?php

namespace App\Http\Controllers\API\Assistants;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assistants\StoreAssistantExceptionRequest;
use App\Http\Requests\Assistants\UpdateAssistantExceptionRequest;
use App\Http\Resources\Assistants\AssistantExceptionResource;
use App\Models\Assistants\AssistantException;
use App\Services\AssistantExceptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AssistantExceptionController extends Controller
{
    public function __construct(
        private readonly AssistantExceptionService $exceptionService
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', AssistantException::class);

        $schoolId = $request->header('X-School-ID');
        $assistantId = $request->query('assistant_id');
        $user = $request->user();

        $query = AssistantException::query()
            ->with('assistant');

        // Se for assistente (não-admin), só lista as suas próprias exceções
        if ($user && ! $user->isAdmin()) {
            $assistant = $user->assistant;
            $query->where('assistant_id', $assistant?->id ?? '00000000-0000-0000-0000-000000000000');
        } else {
            $query->when($schoolId, function ($q) use ($schoolId) {
                $q->whereHas('assistant', fn ($aq) => $aq->where('school_id', $schoolId));
            })
            ->when($assistantId, function ($q) use ($assistantId) {
                $q->where('assistant_id', $assistantId);
            });
        }

        $exceptions = $query->orderByDesc('valid_from')->get();

        return AssistantExceptionResource::collection($exceptions);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAssistantExceptionRequest $request): JsonResponse
    {
        Gate::authorize('create', AssistantException::class);

        $exception = $this->exceptionService->create($request->validated());

        return (new AssistantExceptionResource($exception))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(AssistantException $assistantException): AssistantExceptionResource
    {
        Gate::authorize('view', $assistantException);

        return new AssistantExceptionResource($assistantException->load('assistant'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAssistantExceptionRequest $request, AssistantException $assistantException): AssistantExceptionResource
    {
        Gate::authorize('update', $assistantException);

        $updated = $this->exceptionService->update($assistantException, $request->validated());

        return new AssistantExceptionResource($updated);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AssistantException $assistantException): JsonResponse
    {
        Gate::authorize('delete', $assistantException);

        $this->exceptionService->delete($assistantException);

        return response()->json([
            'message' => 'Exceção eliminada com sucesso.',
        ]);
    }
}
