<?php

namespace App\Http\Controllers\API\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Auth\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use App\Http\Requests\Auth\StoreUserRequest;
use App\Http\Requests\Auth\UpdateUserRequest;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;


class UserController extends Controller
{   
    public function __construct(
        private readonly UserService $userService
    ) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $users = User::with('role')
            ->orderBy('id')
            ->paginate(20);

        return UserResource::collection($users);
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        $user->load('role');

        return new UserResource($user);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = $this->userService->create($data);

        return (new UserResource($user))
            ->additional(['message' => 'Utilizador criado com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $data = $request->validated();

        $wasInactive = ! $user->is_active;
        $updatedUser = $this->userService->update($user, $data);

        $message = $wasInactive && $updatedUser->is_active
            ? 'Utilizador ativado com sucesso.'
            : 'Dados do utilizador atualizados com sucesso.';

        return (new UserResource($updatedUser))
            ->additional(['message' => $message]);
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        Gate::authorize('delete', $user);

        $this->userService->deactivate(
            $request->user(),
            $user
        );

        return response()->json([
            'message' => 'Utilizador desativado com sucesso.',
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        Gate::authorize('delete', $user);

        $action = $this->userService->delete(
            $request->user(),
            $user
        );

        return response()->json([
            'message' => $action === 'anonymize'
                ? 'Utilizador anonimizado com sucesso. O histórico foi mantido.'
                : 'Utilizador eliminado definitivamente com sucesso.',
            'delete_action' => $action,
        ]);
    }
}
