<?php

namespace Tests\Feature;

use App\Models\Assistants\Assistant;
use App\Models\Assistants\AssistantException;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schedules\Schedule;
use App\Models\Schedules\ScheduleEntry;
use App\Models\Schools\School;
use App\Models\Schools\SchoolOperatingRule;
use App\Models\Schools\SchoolOperatingRuleDay;
use App\Models\System\ActivityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AssistantExceptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private School $school;
    private Assistant $assistant;
    private ActivityType $activityType;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $staffRole = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Staff']);

        $this->school = School::create([
            'name' => 'EB1 Parque',
            'acronym' => 'EB1P',
            'active' => true,
        ]);

        $this->activityType = ActivityType::create([
            'school_id' => $this->school->id,
            'name' => 'Apoio Escola',
            'color' => '#9CA3AF',
            'active' => true,
            'is_fallback' => true,
        ]);

        $rule = SchoolOperatingRule::create([
            'school_id' => $this->school->id,
            'valid_from' => '2026-01-01',
        ]);

        for ($d = 0; $d <= 4; $d++) {
            SchoolOperatingRuleDay::create([
                'school_operating_rule_id' => $rule->id,
                'day_of_week' => $d,
                'opening_time' => '08:00:00',
                'closing_time' => '18:00:00',
                'lunch_start' => '12:00:00',
                'lunch_end' => '14:30:00',
                'lunch_duration_minutes' => 60,
            ]);
        }

        $this->admin = User::create([
            'first_name' => 'Admin',
            'last_name' => 'Teste',
            'email' => 'admin.exc@escola.pt',
            'password' => Hash::make('password123'),
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $assistantUser = User::create([
            'first_name' => 'Helena',
            'last_name' => 'Silva',
            'email' => 'helena.silva@escola.pt',
            'password' => Hash::make('password123'),
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        $this->assistant = Assistant::create([
            'user_id' => $assistantUser->id,
            'school_id' => $this->school->id,
            'internal_number' => 'AO-501',
            'weekly_hours' => 35,
            'daily_hours' => 7,
        ]);
    }

    public function test_current_exception_can_be_fully_edited(): void
    {
        $exception = AssistantException::create([
            'assistant_id' => $this->assistant->id,
            'type' => 'custom_hours',
            'description' => 'Horário reduzido temporário',
            'valid_from' => now()->format('Y-m-d'),
            'valid_until' => now()->addDays(14)->format('Y-m-d'),
            'start_time' => '09:00',
            'end_time' => '16:00',
            'days' => [0, 1, 2, 3, 4],
            'lunch_enabled' => true,
            'lunch_start' => '12:00',
            'lunch_end' => '14:00',
            'lunch_duration_minutes' => 60,
        ]);

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->putJson("/api/assistant-exceptions/{$exception->id}", [
                'type' => 'custom_hours',
                'description' => 'Horário corrigido',
                'valid_from' => now()->format('Y-m-d'),
                'valid_until' => now()->addDays(20)->format('Y-m-d'),
                'start_time' => '08:30',
                'end_time' => '16:30',
                'days' => [0, 2, 4],
                'lunch_enabled' => true,
                'lunch_start' => '12:30',
                'lunch_end' => '14:00',
                'lunch_duration_minutes' => 60,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.start_time', '08:30')
            ->assertJsonPath('data.end_time', '16:30')
            ->assertJsonPath('data.can_edit', true);
    }

    public function test_deleting_exception_with_future_draft_or_published_schedules_succeeds_with_warning(): void
    {
        $nextMonday = now()->addWeek()->startOfWeek();
        $nextMondayStr = $nextMonday->format('Y-m-d');

        $exception = AssistantException::create([
            'assistant_id' => $this->assistant->id,
            'type' => 'custom_hours',
            'valid_from' => now()->format('Y-m-d'),
            'valid_until' => $nextMonday->copy()->addDays(4)->format('Y-m-d'),
            'start_time' => '09:00',
            'end_time' => '16:00',
            'days' => [0, 1, 2, 3, 4],
        ]);

        // Criar escala futura em draft (ou publicada) na próxima segunda-feira
        $schedule = Schedule::create([
            'school_id' => $this->school->id,
            'assistant_id' => $this->assistant->id,
            'week_start' => $nextMondayStr,
            'week_end' => $nextMonday->copy()->endOfWeek()->format('Y-m-d'),
            'status' => 'draft',
            'generated_at' => now(),
        ]);

        ScheduleEntry::create([
            'schedule_id' => $schedule->id,
            'activity_type_id' => $this->activityType->id,
            'activity_name' => 'Apoio Escola',
            'date' => $nextMondayStr,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'is_locked' => false,
        ]);

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->deleteJson("/api/assistant-exceptions/{$exception->id}");

        $response->assertStatus(200)
            ->assertJsonPath('impact.has_impact', true);

        $this->assertNotNull($response->json('warning'));
        $this->assertSoftDeleted('assistant_exceptions', ['id' => $exception->id]);
    }

    public function test_deleting_exception_with_past_schedules_is_blocked(): void
    {
        $pastMonday = now()->subWeek()->startOfWeek();
        $pastMondayStr = $pastMonday->format('Y-m-d');

        $exception = AssistantException::create([
            'assistant_id' => $this->assistant->id,
            'type' => 'custom_hours',
            'valid_from' => $pastMondayStr,
            'valid_until' => now()->addDays(7)->format('Y-m-d'),
            'start_time' => '09:00',
            'end_time' => '16:00',
            'days' => [0, 1, 2, 3, 4],
        ]);

        // Criar escala passada (< hoje) durante a vigência desta exceção
        $schedule = Schedule::create([
            'school_id' => $this->school->id,
            'assistant_id' => $this->assistant->id,
            'week_start' => $pastMondayStr,
            'week_end' => $pastMonday->copy()->endOfWeek()->format('Y-m-d'),
            'status' => 'published',
            'generated_at' => now()->subWeek(),
        ]);

        ScheduleEntry::create([
            'schedule_id' => $schedule->id,
            'activity_type_id' => $this->activityType->id,
            'activity_name' => 'Apoio Escola',
            'date' => $pastMondayStr,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'is_locked' => false,
        ]);

        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->deleteJson("/api/assistant-exceptions/{$exception->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('assistant_exceptions', [
            'id' => $exception->id,
            'deleted_at' => null,
        ]);
    }
}
