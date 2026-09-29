<?php
use App\Http\Controllers\API\Absences\AbsenceController;
use App\Http\Controllers\API\Assistants\AssistantController;
use App\Http\Controllers\API\Auth\RoleController;
use App\Http\Controllers\API\Schedules\ScheduleController;
use App\Http\Controllers\API\Schools\SchoolController;
use App\Http\Controllers\API\Auth\AuthController;
use App\Http\Controllers\API\Auth\UserController;
use App\Models\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

Route::get('/teste', function () {
    return response()->json([
        'status' => 'success',
        'message' => 'API is working!'
        ]);
});

// Login público, limitado a 5 pedidos por minuto.
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1')
    ->name('auth.login');

Route::post('/password/forgot', [AuthController::class, 'requestPasswordReset'])
    ->name('password.email');

// Repor Password (utilizado após receber o email)
Route::post('/password/reset', [AuthController::class, 'resetPassword'])
    ->name('password.update');

// Rotas OAuth
Route::get('/auth/{provider}/redirect', [\App\Http\Controllers\API\Auth\OAuthController::class, 'redirect'])
    ->name('oauth.redirect');
Route::get('/auth/{provider}/callback', [\App\Http\Controllers\API\Auth\OAuthController::class, 'externalLoginCallback'])
    ->name('oauth.callback');

// Exige autenticação em todas as rotas deste grupo.
Route::middleware('auth:sanctum')->group(function () {

    // Devolve os dados do utilizador autenticado.
    Route::get('/me', [AuthController::class, 'me'])
        ->name('auth.me');

    // Termina a sessão do utilizador autenticado.
    Route::post('/logout', [AuthController::class, 'logout'])
        ->name('auth.logout');

    Route::post('/update-password', [AuthController::class, 'updatePassword'])
        ->name('password.change');

    // Restringe as operações de gestão aos administradores.
    Route::middleware('can:manage-system')->group(function () {
        Route::apiResource('users', UserController::class);
        Route::apiResource('roles', RoleController::class);
        Route::apiResource('schools', SchoolController::class);
        Route::apiResource('assistants', AssistantController::class);
        Route::apiResource('absences', AbsenceController::class);
    });

    // Autoriza a listagem de escalas; o controller deve filtrar os resultados.
    Route::get('/schedules', [ScheduleController::class, 'index'])
        ->can('viewAny', Schedule::class)
        ->name('schedules.index');

    // Verifica a permissão para consultar a escala pedida.
    Route::get('/schedules/{schedule}', [ScheduleController::class, 'show'])
        ->can('view', 'schedule')
        ->name('schedules.show');
});
