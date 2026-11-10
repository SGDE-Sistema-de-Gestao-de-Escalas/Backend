<?php

namespace App\Http\Controllers\API\Auth;

use App\Models\Auth\Role;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class RoleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Role::class);

        $roles = Role::query()
            ->whereIn('slug', ['admin', 'staff'])
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description']);

        return response()->json([
            'data' => $roles,
        ]);
    }
}
