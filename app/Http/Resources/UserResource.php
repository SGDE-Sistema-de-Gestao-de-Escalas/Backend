<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Services\UserService;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {   

        $userService = app(UserService::class);

        if (! $request->attributes->has('users.active_admin_count')) {
            $request->attributes->set(
                'users.active_admin_count',
                $userService->activeAdminCount()
            );
        }

        $cannotDeleteReason = $userService->cannotDeleteReason(
            $request->user(),
            $this->resource,
            $request->attributes->get('users.active_admin_count')
        );

        $deleteAction = $cannotDeleteReason === null
            ? $userService->deleteAction($this->resource)
            : null;

        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'can_delete' => $cannotDeleteReason === null,
            'cannot_delete_reason' => $cannotDeleteReason,
            'delete_action' => $deleteAction,
            'delete_message' => match ($deleteAction) {
                'hard_delete' => 'A conta será apagada definitivamente.',
                'anonymize' => 'Os dados pessoais serão anonimizados e o histórico será mantido.',
                default => null,
            },
            'role' => $this->whenLoaded('role', function () {
                return $this->role->slug;
            }),
        ];
    }
}
