<?php
use App\Http\Controllers\API\Absences\AbsenceController;
use App\Http\Controllers\API\Absences\AbsenceTypeController;
use App\Http\Controllers\API\Assistants\AssistantController;
use App\Http\Controllers\API\Auth\RoleController;
use App\Http\Controllers\API\Schedules\HolidayController;
use App\Http\Controllers\API\Schedules\ScheduleController;
use App\Http\Controllers\API\Schools\SchoolController;
use App\Http\Controllers\API\Schools\SchoolOperatingRuleController;
use App\Http\Controllers\API\System\ActivityTypeController;
use App\Http\Controllers\API\Auth\AuthController;
use App\Http\Controllers\API\Auth\UserController;
use App\Http\Controllers\API\Assistants\AssistantExceptionController;
use App\Http\Controllers\API\Auth\PrivacyController;
use App\Models\Schedules\Schedule;
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
    ->middleware('throttle:5,1')
    ->name('password.email');

// Repor Password (utilizado após receber o email)
Route::post('/password/reset', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:5,1')
    ->name('password.update');

// Rotas OAuth
Route::get('/auth/{provider}/redirect', [\App\Http\Controllers\API\Auth\OAuthController::class, 'redirect'])
    ->name('oauth.redirect');
Route::get('/auth/{provider}/callback', [\App\Http\Controllers\API\Auth\OAuthController::class, 'externalLoginCallback'])
    ->name('oauth.callback');

// Exige autenticação e uma conta ativa em todas as rotas deste grupo.
// Estas rotas são globais (não dependem de uma escola) e NÃO usam o
// school.context: um X-School-ID antigo ou inválido não as pode bloquear.
Route::middleware(['auth:sanctum', 'active'])->group(function () {

    // Devolve os dados do utilizador autenticado.
    Route::get('/me', [AuthController::class, 'me'])
        ->name('auth.me');
    Route::match(['put', 'patch'], '/me', [AuthController::class, 'updateMe'])
        ->name('auth.me.update');
    Route::delete('/me', [AuthController::class, 'destroyMe'])
        ->name('auth.me.destroy');

    // Termina a sessão do utilizador autenticado.
    Route::post('/logout', [AuthController::class, 'logout'])
        ->name('auth.logout');

    Route::post('/update-password', [AuthController::class, 'updatePassword'])
        ->name('password.change');

    // Pedidos de Privacidade e RGPD (qualquer utilizador autenticado)
    Route::get('/me/export', [PrivacyController::class, 'export'])
        ->name('privacy.me.export');
    Route::post('/privacy/request-deactivation', [PrivacyController::class, 'requestDeactivation'])
        ->name('privacy.request-deactivation');

    // Gestão de utilizadores (autorização granular via UserPolicy e FormRequests)
    Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate'])
        ->name('users.deactivate');
    Route::apiResource('users', UserController::class)->withTrashed(['show', 'destroy', 'update']);

    // Feriados globais: permissões via HolidayPolicy e FormRequests.
    Route::apiResource('holidays', HolidayController::class);

    // Restringe as restantes operações de gestão aos administradores.
    Route::middleware('can:manage-system')->group(function () {
        Route::apiResource('roles', RoleController::class)->only(['index']);
        Route::apiResource('schools', SchoolController::class);
        Route::get('/assistants/{assistant}/can-anonymize', [AssistantController::class, 'canAnonymize'])
            ->withTrashed()
            ->name('assistants.can-anonymize');
        Route::post('/assistants/{assistant}/anonymize', [AssistantController::class, 'anonymize'])
            ->withTrashed()
            ->name('assistants.anonymize');
        Route::apiResource('absence-types', AbsenceTypeController::class);
    });
});

// Rotas que dependem de uma escola. O school.context lê o cabeçalho opcional
// X-School-ID e valida a existência e o acesso (ver SetSchoolContext).
Route::middleware(['auth:sanctum', 'active', 'school.context'])->group(function () {

    // Horário de funcionamento da escola: o administrador gere, os assistentes
    // só consultam. As permissões vêm da SchoolOperatingRulePolicy e das
    // FormRequests (por isso sem o can:manage-system).
    Route::apiResource('operating-rules', SchoolOperatingRuleController::class)
        ->parameters(['operating-rules' => 'operating_rule']);

    // Restringe a gestão destes recursos aos administradores.
    Route::middleware('can:manage-system')->group(function () {
        Route::apiResource('assistants', AssistantController::class)
            ->withTrashed(['show', 'update', 'destroy']);
        Route::apiResource('assistant-schedule-profiles', \App\Http\Controllers\API\Schedules\AssistantScheduleProfileController::class);
        Route::apiResource('assistant-exceptions', AssistantExceptionController::class);
        Route::apiResource('absences', AbsenceController::class);
        Route::apiResource('activity-types', ActivityTypeController::class);
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
