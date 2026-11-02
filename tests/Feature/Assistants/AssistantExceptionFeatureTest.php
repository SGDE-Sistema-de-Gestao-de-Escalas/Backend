<?php

namespace Tests\Feature\Assistants;

use App\Models\Assistants\Assistant;
use App\Models\Assistants\AssistantException;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schools\School;
use App\Models\Schools\SchoolOperatingRule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantExceptionFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private School $school;
    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Staff']);

        $this->school = School::create([
            'name' => 'Escola Teste',
            'code' => 'ET-001',
            'acronym' => 'ET',
            'active' => true,
        ]);

        // Configura o horário de funcionamento da escola: Segunda a Sexta, 08:00 às 18:00
        $rule = SchoolOperatingRule::create([
            'school_id' => $this->school->id,
            'valid_from' => '2026-01-01',
        ]);
        for ($day = 0; $day <= 4; $day++) {
            $rule->schoolOperatingRuleDays()->create([
                'day_of_week' => $day,
                'opening_time' => '08:00',
                'closing_time' => '18:00',
                'lunch_start' => '12:00',
                'lunch_end' => '13:00',
                'lunch_duration_minutes' => 60,
            ]);
        }

        $this->admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $staffUser = User::factory()->create([
            'role_id' => Role::where('slug', 'staff')->first()->id,
            'is_active' => true,
        ]);

        $this->assistant = Assistant::create([
            'user_id' => $staffUser->id,
            'school_id' => $this->school->id,
            'internal_number' => 'AST-EXC-1',
        ]);
    }

    public function test_admin_can_create_exception(): void
    {
        // Escolhe a próxima quarta-feira para garantir que a escola está aberta
        $nextWednesday = Carbon::now()->next(Carbon::WEDNESDAY)->format('Y-m-d');

        $payload = [
            'assistant_id' => $this->assistant->id,
            'type' => 'specific_schedule',
            'valid_from' => $nextWednesday,
            'valid_until' => $nextWednesday,
            'start_time' => '09:00',
            'end_time' => '13:00',
            'description' => 'Consulta médica agendada',
        ];

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/assistant-exceptions', $payload);

        $response->assertCreated();
        $this->assertDatabaseHas('assistant_exceptions', [
            'assistant_id' => $this->assistant->id,
            'description' => 'Consulta médica agendada',
        ]);
    }

    public function test_past_exception_cannot_be_edited_or_deleted(): void
    {
        $pastException = AssistantException::create([
            'assistant_id' => $this->assistant->id,
            'type' => 'unavailable',
            'valid_from' => Carbon::today()->subDays(10)->format('Y-m-d'),
            'valid_until' => Carbon::today()->subDays(5)->format('Y-m-d'),
            'description' => 'Baixa médica passada',
        ]);

        // Consulta e valida flags do resource
        $showResponse = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->getJson("/api/assistant-exceptions/{$pastException->id}");

        $showResponse->assertOk()
            ->assertJsonPath('data.status', 'superseded')
            ->assertJsonPath('data.can_edit', false)
            ->assertJsonPath('data.can_change_end_date', false)
            ->assertJsonPath('data.can_delete', false);

        $this->assertNotNull($showResponse->json('data.cannot_edit_reason'));
        $this->assertNotNull($showResponse->json('data.cannot_delete_reason'));

        // Tentativa de edição rejeitada com 422
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->putJson("/api/assistant-exceptions/{$pastException->id}", [
                'description' => 'Tentativa de alterar o passado',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['valid_until']);

        // Tentativa de eliminação rejeitada com 422
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->deleteJson("/api/assistant-exceptions/{$pastException->id}")
            ->assertStatus(422);
    }

    public function test_current_exception_can_only_change_valid_until(): void
    {
        // Próxima sexta-feira
        $nextFriday = Carbon::now()->next(Carbon::FRIDAY)->format('Y-m-d');

        $currentException = AssistantException::create([
            'assistant_id' => $this->assistant->id,
            'type' => 'unavailable',
            'valid_from' => Carbon::today()->subDays(2)->format('Y-m-d'),
            'valid_until' => $nextFriday,
            'description' => 'Licença em curso',
        ]);

        // Consulta e valida flags do resource
        $showResponse = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->getJson("/api/assistant-exceptions/{$currentException->id}");

        $showResponse->assertOk()
            ->assertJsonPath('data.status', 'current')
            ->assertJsonPath('data.can_edit', false)
            ->assertJsonPath('data.can_change_end_date', true)
            ->assertJsonPath('data.can_delete', false);

        // Alterar apenas valid_until (prolongar licença para a sexta seguinte) é permitido
        $extendedFriday = Carbon::parse($nextFriday)->addWeek()->format('Y-m-d');
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->putJson("/api/assistant-exceptions/{$currentException->id}", [
                'valid_until' => $extendedFriday,
            ])
            ->assertOk()
            ->assertJsonPath('data.valid_until', $extendedFriday);

        // Tentar alterar valid_from retroativamente é rejeitado com 422
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->putJson("/api/assistant-exceptions/{$currentException->id}", [
                'valid_from' => Carbon::today()->subDays(5)->format('Y-m-d'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['valid_from']);

        // Eliminar exceção em vigor é proibido
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->deleteJson("/api/assistant-exceptions/{$currentException->id}")
            ->assertStatus(422);
    }

    public function test_upcoming_exception_can_be_edited_and_deleted(): void
    {
        $start = Carbon::now()->next(Carbon::TUESDAY)->addWeek();
        $end = $start->copy()->addDays(2);

        $upcoming = AssistantException::create([
            'assistant_id' => $this->assistant->id,
            'type' => 'unavailable',
            'valid_from' => $start->format('Y-m-d'),
            'valid_until' => $end->format('Y-m-d'),
            'description' => 'Formação Futura',
        ]);

        // Consulta e valida flags do resource
        $showResponse = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->getJson("/api/assistant-exceptions/{$upcoming->id}");

        $showResponse->assertOk()
            ->assertJsonPath('data.status', 'upcoming')
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.can_change_end_date', true)
            ->assertJsonPath('data.can_delete', true);

        // Editar todos os dados dentro das horas de funcionamento da escola (08:00 às 18:00)
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->putJson("/api/assistant-exceptions/{$upcoming->id}", [
                'type' => 'specific_schedule',
                'description' => 'Horário Específico em Formação',
                'start_time' => '09:00',
                'end_time' => '17:00',
            ])
            ->assertOk()
            ->assertJsonPath('data.type', 'specific_schedule')
            ->assertJsonPath('data.description', 'Horário Específico em Formação');

        // Eliminar a exceção agendada
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->deleteJson("/api/assistant-exceptions/{$upcoming->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Exceção eliminada com sucesso.');

        $this->assertSoftDeleted('assistant_exceptions', ['id' => $upcoming->id]);
    }

    public function test_fails_if_school_has_no_operating_hours_in_range(): void
    {
        // Próximo domingo (a escola está encerrada ao fim de semana)
        $nextSunday = Carbon::now()->next(Carbon::SUNDAY)->format('Y-m-d');

        $payload = [
            'assistant_id' => $this->assistant->id,
            'type' => 'unavailable',
            'valid_from' => $nextSunday,
            'valid_until' => $nextSunday,
        ];

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/assistant-exceptions', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['valid_from']);
    }

    public function test_fails_if_exception_hours_exceed_school_opening_or_closing(): void
    {
        $nextMonday = Carbon::now()->next(Carbon::MONDAY)->addWeek()->format('Y-m-d');

        // 1. Tentar hora de início anterior à abertura (07:30 < 08:00)
        $responseStart = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/assistant-exceptions', [
                'assistant_id' => $this->assistant->id,
                'type' => 'specific_schedule',
                'valid_from' => $nextMonday,
                'valid_until' => $nextMonday,
                'start_time' => '07:30',
                'end_time' => '16:00',
            ]);

        $responseStart->assertStatus(422)
            ->assertJsonValidationErrors(['start_time']);

        // 2. Tentar hora de fim posterior ao fecho (18:30 > 18:00)
        $responseEnd = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/assistant-exceptions', [
                'assistant_id' => $this->assistant->id,
                'type' => 'specific_schedule',
                'valid_from' => $nextMonday,
                'valid_until' => $nextMonday,
                'start_time' => '09:00',
                'end_time' => '18:30',
            ]);

        $responseEnd->assertStatus(422)
            ->assertJsonValidationErrors(['end_time']);
    }

    public function test_can_create_and_view_exception_with_days_and_lunch_break(): void
    {
        $nextMonday = Carbon::now()->next(Carbon::MONDAY)->addWeek()->format('Y-m-d');
        $nextFriday = Carbon::now()->next(Carbon::FRIDAY)->addWeeks(2)->format('Y-m-d');

        $payload = [
            'assistant_id' => $this->assistant->id,
            'type' => 'specific_schedule',
            'description' => 'Horário especial com almoço',
            'valid_from' => $nextMonday,
            'valid_until' => $nextFriday,
            'start_time' => '09:00',
            'end_time' => '17:00',
            'days' => [0, 1, 2, 3, 4],
            'lunch_enabled' => true,
            'lunch_start' => '12:30',
            'lunch_end' => '13:30',
            'lunch_duration_minutes' => 60,
        ];

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/assistant-exceptions', $payload);

        $response->assertCreated();
        $response->assertJsonPath('data.days', [0, 1, 2, 3, 4]);
        $response->assertJsonPath('data.lunch_enabled', true);
        $response->assertJsonPath('data.lunch_start', '12:30');
        $response->assertJsonPath('data.lunch_end', '13:30');
        $response->assertJsonPath('data.lunch_duration_minutes', 60);

        $this->assertDatabaseHas('assistant_exceptions', [
            'assistant_id' => $this->assistant->id,
            'lunch_enabled' => true,
            'lunch_duration_minutes' => 60,
        ]);

        // Consulta pelo index
        $indexResponse = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->getJson('/api/assistant-exceptions?assistant_id=' . $this->assistant->id);

        $indexResponse->assertOk();
        $indexResponse->assertJsonPath('data.0.days', [0, 1, 2, 3, 4]);
        $indexResponse->assertJsonPath('data.0.lunch_enabled', true);
        $indexResponse->assertJsonPath('data.0.lunch_start', '12:30');
        $indexResponse->assertJsonPath('data.0.lunch_end', '13:30');
        $indexResponse->assertJsonPath('data.0.lunch_duration_minutes', 60);
    }

    public function test_fails_if_exception_days_have_no_school_operating_hours(): void
    {
        $nextMonday = Carbon::now()->next(Carbon::MONDAY)->addWeek()->format('Y-m-d');
        $nextFriday = Carbon::now()->next(Carbon::FRIDAY)->addWeeks(2)->format('Y-m-d');

        // Escola funciona apenas de 0 a 4 (Segunda a Sexta). Sábado e Domingo (5 e 6) estão fechados
        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/assistant-exceptions', [
                'assistant_id' => $this->assistant->id,
                'type' => 'weekend_shift',
                'valid_from' => $nextMonday,
                'valid_until' => $nextFriday,
                'start_time' => '09:00',
                'end_time' => '17:00',
                'days' => [5, 6],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['days']);
    }

    public function test_fails_if_exception_lunch_hours_are_outside_exception_start_or_end(): void
    {
        $nextMonday = Carbon::now()->next(Carbon::MONDAY)->addWeek()->format('Y-m-d');

        // Almoço a começar antes da hora de entrada (08:30 < 09:00)
        $responseStart = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/assistant-exceptions', [
                'assistant_id' => $this->assistant->id,
                'type' => 'specific_schedule',
                'valid_from' => $nextMonday,
                'valid_until' => $nextMonday,
                'start_time' => '09:00',
                'end_time' => '17:00',
                'lunch_enabled' => true,
                'lunch_start' => '08:30',
                'lunch_end' => '09:30',
            ]);

        $responseStart->assertStatus(422)
            ->assertJsonValidationErrors(['lunch_start']);

        // Almoço a terminar depois da hora de saída (17:30 > 17:00)
        $responseEnd = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/assistant-exceptions', [
                'assistant_id' => $this->assistant->id,
                'type' => 'specific_schedule',
                'valid_from' => $nextMonday,
                'valid_until' => $nextMonday,
                'start_time' => '09:00',
                'end_time' => '17:00',
                'lunch_enabled' => true,
                'lunch_start' => '16:00',
                'lunch_end' => '17:30',
            ]);

        $responseEnd->assertStatus(422)
            ->assertJsonValidationErrors(['lunch_end']);
    }

    public function test_cannot_modify_days_or_lunch_on_in_force_exception(): void
    {
        $exception = AssistantException::create([
            'assistant_id' => $this->assistant->id,
            'type' => 'breastfeeding',
            'valid_from' => Carbon::now()->subWeek()->format('Y-m-d'),
            'valid_until' => Carbon::now()->addMonth()->format('Y-m-d'),
            'start_time' => '09:00',
            'end_time' => '17:00',
            'days' => [0, 1, 2, 3, 4],
            'lunch_enabled' => true,
            'lunch_start' => '12:00',
            'lunch_end' => '13:00',
            'lunch_duration_minutes' => 60,
        ]);

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->putJson("/api/assistant-exceptions/{$exception->id}", [
                'days' => [0, 1, 2],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['days']);
    }
}
