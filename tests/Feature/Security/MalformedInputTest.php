<?php

namespace Tests\Feature\Security;

use App\Models\Absences\AbsenceType;
use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schools\School;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * F-12: listas ou números enormes onde se espera texto ou um número pequeno
 * davam erro 500 em vez de 422, e duas criações simultâneas com o mesmo email
 * também. O servidor nunca deve responder 5xx a dados mal formados.
 */
class MalformedInputTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrador']);
        $staffRole = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Assistente Operacional']);

        $this->school = School::create(['name' => 'Escola A', 'acronym' => 'EA', 'active' => true]);
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);

        $staff = User::factory()->create(['role_id' => $staffRole->id, 'is_active' => true]);
        $this->assistant = Assistant::create([
            'school_id' => $this->school->id,
            'user_id' => $staff->id,
            'internal_number' => 'A001',
            'available_for_transfer' => false,
        ]);
    }

    private function asAdmin(): static
    {
        Sanctum::actingAs($this->admin);

        return $this->withHeader('X-School-ID', $this->school->id);
    }

    private function assertNeverServerError($response): void
    {
        $this->assertLessThan(
            500,
            $response->getStatusCode(),
            'O servidor respondeu '.$response->getStatusCode().': '.substr((string) $response->getContent(), 0, 300)
        );
    }

    public static function arraysInAssistantFields(): array
    {
        return [
            'nif' => ['nif'],
            'segurança social' => ['social_security_number'],
            'email' => ['email'],
            'telefone' => ['phone'],
            'número interno' => ['internal_number'],
            'primeiro nome' => ['first_name'],
            'apelido' => ['last_name'],
            'código postal' => ['address_zip_code'],
            'data de nascimento' => ['birth_date'],
        ];
    }

    #[DataProvider('arraysInAssistantFields')]
    public function test_a_list_in_an_assistant_field_is_a_422_on_create(string $field): void
    {
        $payload = array_merge([
            'first_name' => 'Ana',
            'last_name' => 'Silva',
            'email' => 'ana@example.com',
            'internal_number' => 'A900',
        ], [$field => ['x', 'y']]);

        $this->asAdmin()->postJson('/api/assistants', $payload)->assertStatus(422);

        $this->assertSame(1, Assistant::count());
    }

    #[DataProvider('arraysInAssistantFields')]
    public function test_a_list_in_an_assistant_field_is_never_a_500_on_update(string $field): void
    {
        $response = $this->asAdmin()->putJson("/api/assistants/{$this->assistant->id}", [
            'first_name' => 'Ana',
            'last_name' => 'Silva',
            'email' => 'ana@example.com',
            $field => ['x', 'y'],
        ]);

        $this->assertNeverServerError($response);
    }

    public static function listsInQueryString(): array
    {
        return [
            'faltas: pesquisa' => ['/api/absences?search[]=a'],
            'faltas: estado' => ['/api/absences?status[]=pending'],
            'faltas: assistente' => ['/api/absences?assistant_id[]=x'],
            'faltas: data de início' => ['/api/absences?start_date[]=2027-01-01'],
            'faltas: data de fim' => ['/api/absences?end_date[]=2027-01-01'],
            'faltas: direção' => ['/api/absences?sort_direction[]=asc'],
            'faltas: por página' => ['/api/absences?per_page[]=5'],
            'faltas: página' => ['/api/absences?page[]=1'],
            'assistentes: pesquisa' => ['/api/assistants?search[]=a'],
            'assistentes: estado' => ['/api/assistants?status[]=active'],
            'assistentes: ordenar por' => ['/api/assistants?sort_by[]=name'],
            'assistentes: direção' => ['/api/assistants?sort_direction[]=desc'],
            'assistentes: por página' => ['/api/assistants?per_page[]=5'],
            'notificações: tipo' => ['/api/notifications?type[]=alert'],
            'notificações: por página' => ['/api/notifications?per_page[]=5'],
        ];
    }

    #[DataProvider('listsInQueryString')]
    public function test_a_list_in_the_query_string_is_never_a_500(string $uri): void
    {
        $this->assertNeverServerError($this->asAdmin()->getJson($uri));
    }

    public function test_marking_all_notifications_as_read_with_a_list_as_type_is_never_a_500(): void
    {
        $this->assertNeverServerError(
            $this->asAdmin()->postJson('/api/notifications/read-all', ['type' => ['alert', 'info']])
        );
    }

    public function test_marking_all_notifications_as_read_still_accepts_a_valid_type(): void
    {
        $this->asAdmin()->postJson('/api/notifications/read-all', ['type' => 'alert'])->assertOk();
        $this->asAdmin()->postJson('/api/notifications/read-all')->assertOk();
    }

    public function test_marking_all_notifications_as_read_with_an_unknown_type_is_a_422(): void
    {
        $this->asAdmin()->postJson('/api/notifications/read-all', ['type' => 'inventado'])->assertStatus(422);
    }

    public static function arraysInAbsenceFields(): array
    {
        return [
            'assistente' => ['assistant_id'],
            'tipo' => ['absence_type_id'],
            'início' => ['valid_from'],
            'fim' => ['valid_until'],
            'hora de início' => ['start_time'],
            'total de dias' => ['total_days'],
            'justificação' => ['justification'],
            'notas' => ['notes'],
            'estado' => ['status'],
        ];
    }

    #[DataProvider('arraysInAbsenceFields')]
    public function test_a_list_in_an_absence_field_is_a_422(string $field): void
    {
        $absenceType = AbsenceType::create(['name' => 'Assuntos Pessoais', 'requires_document' => false]);

        $payload = array_merge([
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $absenceType->id,
            'valid_from' => now()->addDays(10)->toDateString(),
            'valid_until' => now()->addDays(10)->toDateString(),
        ], [$field => ['x']]);

        $this->asAdmin()->postJson('/api/absences', $payload)->assertStatus(422);
    }

    public static function malformedExceptionFields(): array
    {
        return [
            'assistente' => ['assistant_id', ['x']],
            'tipo' => ['type', ['x']],
            'descrição' => ['description', ['x']],
            'início' => ['valid_from', ['x']],
            'fim' => ['valid_until', ['x']],
            'hora de início' => ['start_time', ['x']],
            'dias como texto' => ['days', 'segunda'],
            'dia como lista' => ['days', [[1]]],
            'início de almoço' => ['lunch_start', ['x']],
            'duração do almoço enorme' => ['lunch_duration_minutes', 99999999999],
            'duração do almoço como lista' => ['lunch_duration_minutes', [1]],
        ];
    }

    #[DataProvider('malformedExceptionFields')]
    public function test_malformed_data_in_an_exception_is_a_422(string $field, mixed $value): void
    {
        $payload = array_merge([
            'assistant_id' => $this->assistant->id,
            'type' => 'Licença',
            'valid_from' => now()->addDays(10)->toDateString(),
        ], [$field => $value]);

        $this->asAdmin()->postJson('/api/assistant-exceptions', $payload)->assertStatus(422);
    }

    public static function malformedScheduleRuleFields(): array
    {
        return [
            'atividade' => ['activity_type_id', ['x']],
            'início do período' => ['period_start', ['x']],
            'fim do período' => ['period_end', ['x']],
            'mínimo como lista' => ['min', [1]],
            'mínimo enorme' => ['min', 99999999999999],
            'tipo' => ['type', ['mandatory']],
            'início de validade' => ['valid_from', ['x']],
            'dias da semana' => ['days_of_week', [[1]]],
            'assistentes' => ['assistant_ids', [['x']]],
            'assistentes como texto' => ['assistant_ids', 'x'],
        ];
    }

    #[DataProvider('malformedScheduleRuleFields')]
    public function test_malformed_data_in_a_schedule_rule_is_a_422(string $field, mixed $value): void
    {
        $payload = array_merge([
            'activity_type_id' => (string) Str::uuid(),
            'period_start' => '09:00',
            'period_end' => '10:00',
            'min' => 1,
            'type' => 'mandatory',
            'valid_from' => now()->addDays(10)->toDateString(),
            'days_of_week' => [0],
            'assistant_ids' => [$this->assistant->id],
        ], [$field => $value]);

        $this->asAdmin()->postJson('/api/schedule-rules', $payload)->assertStatus(422);
    }

    public static function malformedGenerateFields(): array
    {
        return [
            'preservar bloqueados' => ['preserve_locked', ['x']],
            'chave de idempotência' => ['idempotency_key', ['x']],
            'cedências como texto' => ['transfers', 'x'],
            'cedência como texto' => ['transfers', ['x']],
            'cedência: assistente como lista' => ['transfers', [['assistant_id' => ['x'], 'date' => '2027-03-01']]],
            'cedência: data como lista' => ['transfers', [['assistant_id' => 'a', 'date' => ['x']]]],
        ];
    }

    #[DataProvider('malformedGenerateFields')]
    public function test_malformed_data_when_generating_schedules_is_a_422(string $field, mixed $value): void
    {
        $payload = array_merge(['start_date' => '2027-03-01', 'end_date' => '2027-03-02'], [$field => $value]);

        $this->asAdmin()->postJson('/api/schedules/generate', $payload)->assertStatus(422);
    }

    public static function methodsThatDoNotExist(): array
    {
        return [
            'criar' => ['postJson', '/api/assistant-temporary-assignments'],
            'substituir' => ['putJson', '/api/assistant-temporary-assignments/00000000-0000-0000-0000-000000000000'],
            'alterar' => ['patchJson', '/api/assistant-temporary-assignments/00000000-0000-0000-0000-000000000000'],
        ];
    }

    #[DataProvider('methodsThatDoNotExist')]
    public function test_temporary_assignment_routes_without_a_controller_method_are_not_a_500(string $method, string $uri): void
    {
        $response = $this->asAdmin()->{$method}($uri, []);

        $this->assertContains($response->getStatusCode(), [404, 405]);
    }

    public function test_a_unique_violation_caught_by_the_database_is_a_422_in_portuguese(): void
    {
        Route::middleware('api')->post('/api/_testes/duplicado', function () {
            throw new UniqueConstraintViolationException(
                'sqlite',
                'insert into "users" ("email") values (?)',
                ['ana@example.com'],
                new PDOException('UNIQUE constraint failed: users.email')
            );
        });

        $this->asAdmin()->postJson('/api/_testes/duplicado')
            ->assertStatus(422)
            ->assertJsonStructure(['message']);
    }
}
