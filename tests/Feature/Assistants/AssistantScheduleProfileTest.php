<?php

namespace Tests\Feature\Assistants;

use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schools\School;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantScheduleProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrador']);
        Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Assistente Operacional']);

        $this->school = School::create([
            'name' => 'Escola Secundária Teste',
            'code' => 'EST-01',
            'acronym' => 'EST',
            'address' => 'Rua das Escolas',
            'phone' => '210000000',
            'active' => true,
        ]);

        $this->admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);
    }

    public function test_can_create_fixed_schedule_profile_directly_for_assistant(): void
    {
        $assistant = $this->createAssistant('AST-FIXED-1');

        $payload = [
            'assistant_id' => $assistant->id,
            'type' => 'fixo',
            'valid_from' => '2026-09-01',
            'valid_until' => '2027-06-30',
            'shifts' => [
                [
                    'shift_label' => 'A',
                    'entry_time' => '08:00',
                    'exit_time' => '16:00',
                    'lunch_enabled' => true,
                    'lunch_start' => '12:00',
                    'lunch_end' => '13:00',
                    'lunch_duration_minutes' => 60,
                    'days' => [0, 1, 2, 3, 4],
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/assistant-schedule-profiles', $payload);

        $response->assertCreated();
        $response->assertJsonPath('data.type', 'fixo');
        $response->assertJsonPath('data.valid_from', '2026-09-01');
        $response->assertJsonPath('data.shifts.0.shift_label', 'A');
        $response->assertJsonPath('data.shifts.0.entry_time', '08:00');
        $response->assertJsonPath('data.shifts.0.days', [0, 1, 2, 3, 4]);

        $this->assertDatabaseHas('assistant_schedule_profiles', [
            'assistant_id' => $assistant->id,
            'type' => 'fixo',
            'valid_from' => '2026-09-01',
        ]);

        $this->assertDatabaseHas('assistant_schedule_shifts', [
            'shift_label' => 'A',
            'entry_time' => '08:00',
            'exit_time' => '16:00',
        ]);

        $this->assertDatabaseCount('assistant_schedule_shift_days', 5);
    }

    private function createAssistant(string $internalNumber = 'AST-100'): Assistant
    {
        $staffRole = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Assistente Operacional']);
        $user = User::factory()->create([
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        return Assistant::create([
            'user_id' => $user->id,
            'school_id' => $this->school->id,
            'internal_number' => $internalNumber,
        ]);
    }

    public function test_can_view_assistant_with_schedule_profiles(): void
    {
        $assistant = $this->createAssistant('AST-VIEW-1');

        $profile = $assistant->assistantScheduleProfiles()->create([
            'type' => 'fixo',
            'valid_from' => '2026-09-01',
            'valid_until' => null,
        ]);

        $shift = $profile->shifts()->create([
            'shift_label' => 'A',
            'entry_time' => '08:30',
            'exit_time' => '16:30',
            'lunch_enabled' => true,
        ]);

        $shift->shiftDays()->create(['day_of_week' => 0]);
        $shift->shiftDays()->create(['day_of_week' => 1]);

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->getJson("/api/assistants/{$assistant->id}");

        $response->assertOk();
        $response->assertJsonPath('data.schedule_profiles.0.type', 'fixo');
        $response->assertJsonPath('data.schedule_profiles.0.valid_from', '2026-09-01');
        $response->assertJsonPath('data.schedule_profiles.0.shifts.0.shift_label', 'A');
        $response->assertJsonPath('data.schedule_profiles.0.shifts.0.entry_time', '08:30');
        $response->assertJsonPath('data.schedule_profiles.0.shifts.0.days', [0, 1]);
    }

    public function test_can_create_schedule_profile_directly_for_assistant(): void
    {
        $assistant = $this->createAssistant('AST-DIRECT-1');

        $payload = [
            'assistant_id' => $assistant->id,
            'type' => 'rotativo',
            'rotation_period' => 'weekly',
            'starts_with' => 'A',
            'valid_from' => '2026-11-01',
            'valid_until' => '2027-01-31',
            'shifts' => [
                [
                    'shift_label' => 'A',
                    'entry_time' => '07:00',
                    'exit_time' => '15:00',
                    'lunch_enabled' => true,
                    'days' => [0, 1, 2, 3, 4],
                ],
                [
                    'shift_label' => 'B',
                    'entry_time' => '11:00',
                    'exit_time' => '19:00',
                    'lunch_enabled' => true,
                    'days' => [0, 1, 2, 3, 4],
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/assistant-schedule-profiles', $payload);

        $response->assertCreated();
        $response->assertJsonPath('data.type', 'rotativo');
        $response->assertJsonPath('data.rotation_period', 'weekly');
        $response->assertJsonPath('data.assistant_id', $assistant->id);
        $response->assertJsonCount(2, 'data.shifts');

        $this->assertDatabaseHas('assistant_schedule_profiles', [
            'assistant_id' => $assistant->id,
            'type' => 'rotativo',
            'rotation_period' => 'weekly',
        ]);
    }

    public function test_in_force_schedule_profile_cannot_be_edited_or_deleted(): void
    {
        $assistant = $this->createAssistant('AST-CURRENT-1');

        $currentProfile = $assistant->assistantScheduleProfiles()->create([
            'type' => 'fixo',
            'valid_from' => Carbon::today()->subMonths(2)->format('Y-m-d'),
            'valid_until' => null,
        ]);

        // Consulta resource do perfil em vigor
        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->getJson("/api/assistant-schedule-profiles/{$currentProfile->id}");

        $response->assertOk()
            ->assertJsonPath('data.status', 'current')
            ->assertJsonPath('data.can_edit', false)
            ->assertJsonPath('data.can_change_end_date', false)
            ->assertJsonPath('data.can_delete', false);

        // Tentativa de edição rejeitada com 422
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->putJson("/api/assistant-schedule-profiles/{$currentProfile->id}", [
                'type' => 'rotativo',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['profile']);

        // Tentativa de eliminação rejeitada com 422
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->deleteJson("/api/assistant-schedule-profiles/{$currentProfile->id}")
            ->assertStatus(422);
    }

    public function test_can_create_upcoming_schedule_profile_and_automatically_closes_current(): void
    {
        $assistant = $this->createAssistant('AST-UPCOMING-1');

        // 1. Horário em vigor inicial (em aberto)
        $currentProfile = $assistant->assistantScheduleProfiles()->create([
            'type' => 'fixo',
            'valid_from' => Carbon::today()->subMonths(1)->format('Y-m-d'),
            'valid_until' => null,
        ]);

        $futureDate = Carbon::today()->addDays(10);

        // 2. Criação de horário futuro com início a D
        $payload = [
            'assistant_id' => $assistant->id,
            'type' => 'fixo',
            'valid_from' => $futureDate->format('Y-m-d'),
            'valid_until' => null,
            'shifts' => [
                [
                    'shift_label' => 'A',
                    'entry_time' => '08:00',
                    'exit_time' => '16:00',
                    'days' => [0, 1, 2, 3, 4],
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/assistant-schedule-profiles', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'upcoming')
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.can_delete', true);

        // Horário anterior agora termina em D - 1 dia
        $expectedPreviousEnd = $futureDate->copy()->subDay()->format('Y-m-d');
        $this->assertEquals($expectedPreviousEnd, $currentProfile->fresh()->valid_until);
    }

    public function test_updating_upcoming_start_date_adjusts_previous_profile_valid_until(): void
    {
        $assistant = $this->createAssistant('AST-SYNC-1');

        $initialStartDate = Carbon::today()->addDays(10);
        $currentProfile = $assistant->assistantScheduleProfiles()->create([
            'type' => 'fixo',
            'valid_from' => Carbon::today()->subMonths(1)->format('Y-m-d'),
            'valid_until' => $initialStartDate->copy()->subDay()->format('Y-m-d'),
        ]);

        $upcomingProfile = $assistant->assistantScheduleProfiles()->create([
            'type' => 'fixo',
            'valid_from' => $initialStartDate->format('Y-m-d'),
            'valid_until' => null,
        ]);

        $newFutureDate = Carbon::today()->addDays(15);

        // Atualiza a data de início para D_nova
        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->putJson("/api/assistant-schedule-profiles/{$upcomingProfile->id}", [
                'valid_from' => $newFutureDate->format('Y-m-d'),
            ]);

        $response->assertOk()
            ->assertJsonPath('data.valid_from', $newFutureDate->format('Y-m-d'));

        // Horário anterior sincroniza automaticamente para D_nova - 1 dia
        $expectedPreviousEnd = $newFutureDate->copy()->subDay()->format('Y-m-d');
        $this->assertEquals($expectedPreviousEnd, $currentProfile->fresh()->valid_until);
    }

    public function test_deleting_upcoming_schedule_profile_reopens_previous_profile(): void
    {
        $assistant = $this->createAssistant('AST-REOPEN-1');

        $upcomingDate = Carbon::today()->addDays(10);
        $currentProfile = $assistant->assistantScheduleProfiles()->create([
            'type' => 'fixo',
            'valid_from' => Carbon::today()->subMonths(1)->format('Y-m-d'),
            'valid_until' => $upcomingDate->copy()->subDay()->format('Y-m-d'),
        ]);

        $upcomingProfile = $assistant->assistantScheduleProfiles()->create([
            'type' => 'fixo',
            'valid_from' => $upcomingDate->format('Y-m-d'),
            'valid_until' => null,
        ]);

        // Elimina o perfil futuro agendado
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->deleteJson("/api/assistant-schedule-profiles/{$upcomingProfile->id}")
            ->assertOk();

        // Horário anterior volta a ter vigência aberta (valid_until = null)
        $this->assertNull($currentProfile->fresh()->valid_until);
    }

    public function test_can_create_and_read_rotational_profile_with_shifts_lunch_configurations(): void
    {
        $assistant = $this->createAssistant('AST-ROT-1');

        $payload = [
            'assistant_id' => $assistant->id,
            'type' => 'rotativo',
            'rotation_period' => 'weekly',
            'starts_with' => 'A',
            'valid_from' => '2026-10-01',
            'valid_until' => null,
            'shifts' => [
                [
                    'shift_label' => 'A',
                    'entry_time' => '08:00',
                    'exit_time' => '16:00',
                    'lunch_enabled' => true,
                    'lunch_start' => '12:00',
                    'lunch_end' => '13:00',
                    'lunch_duration_minutes' => 60,
                    'days' => [0, 1, 2, 3, 4],
                ],
                [
                    'shift_label' => 'B',
                    'entry_time' => '10:00',
                    'exit_time' => '18:00',
                    'lunch_enabled' => true,
                    'lunch_start' => '13:00',
                    'lunch_end' => '14:00',
                    'lunch_duration_minutes' => 60,
                    'days' => [0, 1, 2, 3, 4],
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/assistant-schedule-profiles', $payload);

        $response->assertCreated();
        $response->assertJsonPath('data.type', 'rotativo');
        $response->assertJsonPath('data.rotation_period', 'weekly');
        $response->assertJsonPath('data.starts_with', 'A');
        $response->assertJsonCount(2, 'data.shifts');

        // Shift A
        $response->assertJsonPath('data.shifts.0.shift_label', 'A');
        $response->assertJsonPath('data.shifts.0.lunch_enabled', true);
        $response->assertJsonPath('data.shifts.0.lunch_start', '12:00');
        $response->assertJsonPath('data.shifts.0.lunch_end', '13:00');
        $response->assertJsonPath('data.shifts.0.lunch_duration_minutes', 60);

        // Shift B
        $response->assertJsonPath('data.shifts.1.shift_label', 'B');
        $response->assertJsonPath('data.shifts.1.lunch_enabled', true);
        $response->assertJsonPath('data.shifts.1.lunch_start', '13:00');
        $response->assertJsonPath('data.shifts.1.lunch_end', '14:00');
        $response->assertJsonPath('data.shifts.1.lunch_duration_minutes', 60);

        $this->assertDatabaseHas('assistant_schedule_shifts', [
            'shift_label' => 'A',
            'lunch_enabled' => true,
            'lunch_duration_minutes' => 60,
        ]);

        $this->assertDatabaseHas('assistant_schedule_shifts', [
            'shift_label' => 'B',
            'lunch_enabled' => true,
            'lunch_duration_minutes' => 60,
        ]);

        // GET index
        $indexResponse = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->getJson('/api/assistant-schedule-profiles?assistant_id=' . $assistant->id);

        $indexResponse->assertOk();
        $indexResponse->assertJsonPath('data.0.type', 'rotativo');
        $indexResponse->assertJsonPath('data.0.shifts.0.lunch_enabled', true);
        $indexResponse->assertJsonPath('data.0.shifts.0.lunch_start', '12:00');
        $indexResponse->assertJsonPath('data.0.shifts.1.lunch_enabled', true);
        $indexResponse->assertJsonPath('data.0.shifts.1.lunch_start', '13:00');
    }
}
