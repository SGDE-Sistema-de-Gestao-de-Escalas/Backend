<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class ActivityTypeDeletionConflictException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'CONFLICT',
            'message' => $this->getMessage(),
        ], 409);
    }
}
