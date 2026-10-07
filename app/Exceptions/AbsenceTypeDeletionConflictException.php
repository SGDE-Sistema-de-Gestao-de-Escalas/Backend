<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class AbsenceTypeDeletionConflictException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'CONFLICT',
            'message' => $this->getMessage(),
        ], 409);
    }
}
