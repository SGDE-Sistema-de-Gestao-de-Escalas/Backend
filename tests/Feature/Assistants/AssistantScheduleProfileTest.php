<?php

namespace Tests\Feature\Assistants;

use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schools\School;
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
                    'days' => [1, 2, 3, 4, 5],
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
        $response->assertJsonPath('data.shifts.0.days', [1, 2, 3, 4, 5]);

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

        $shift->shiftDays()->create(['day_of_week' => 1]);
        $shift->shiftDays()->create(['day_of_week' => 2]);

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->getJson("/api/assistants/{$assistant->id}");

        $response->assertOk();
        $response->assertJsonPath('data.schedule_profiles.0.type', 'fixo');
        $response->assertJsonPath('data.schedule_profiles.0.valid_from', '2026-09-01');
        $response->assertJsonPath('data.schedule_profiles.0.shifts.0.shift_label', 'A');
        $response->assertJsonPath('data.schedule_profiles.0.shifts.0.entry_time', '08:30');
        $response->assertJsonPath('data.schedule_profiles.0.shifts.0.days', [1, 2]);
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
                    'days' => [1, 2, 3, 4, 5],
                ],
                [
                    'shift_label' => 'B',
                    'entry_time' => '11:00',
                    'exit_time' => '19:00',
                    'lunch_enabled' => true,
                    'days' => [1, 2, 3, 4, 5],
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

    public function test_can_update_schedule_profile_and_recreate_shifts(): void
    {
        $assistant = $this->createAssistant('AST-UPD-PROF');

        $profile = $assistant->assistantScheduleProfiles()->create([
            'type' => 'fixo',
            'valid_from' => '2026-09-01',
        ]);

        $shift = $profile->shifts()->create([
            'shift_label' => 'A',
            'entry_time' => '08:00',
            'exit_time' => '16:00',
            'lunch_enabled' => true,
        ]);
        $shift->shiftDays()->create(['day_of_week' => 1]);

        $updatePayload = [
            'valid_until' => '2027-07-31',
            'shifts' => [
                [
                    'shift_label' => 'A',
                    'entry_time' => '08:30',
                    'exit_time' => '16:30',
                    'lunch_enabled' => true,
                    'lunch_start' => '12:30',
                    'lunch_end' => '13:30',
                    'lunch_duration_minutes' => 60,
                    'days' => [1, 2, 3, 4, 5],
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->putJson("/api/assistant-schedule-profiles/{$profile->id}", $updatePayload);

        $response->assertOk();
        $response->assertJsonPath('data.valid_until', '2027-07-31');
        $response->assertJsonPath('data.shifts.0.entry_time', '08:30');
        $response->assertJsonPath('data.shifts.0.days', [1, 2, 3, 4, 5]);
    }

    public function test_can_delete_schedule_profile_cleanly(): void
    {
        $assistant = $this->createAssistant('AST-DEL-PROF');

        $profile = $assistant->assistantScheduleProfiles()->create([
            'type' => 'fixo',
            'valid_from' => '2026-09-01',
        ]);

        $shift = $profile->shifts()->create([
            'shift_label' => 'A',
            'entry_time' => '08:00',
            'exit_time' => '16:00',
        ]);
        $shift->shiftDays()->create(['day_of_week' => 1]);

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->deleteJson("/api/assistant-schedule-profiles/{$profile->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('assistant_schedule_profiles', ['id' => $profile->id]);
        $this->assertDatabaseMissing('assistant_schedule_shifts', ['id' => $shift->id]);
    }
}
