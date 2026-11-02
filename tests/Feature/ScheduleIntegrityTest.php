<?php

namespace Tests\Feature;

use App\Models\Absences\Absence;
use App\Models\Absences\AbsenceType;
use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schedules\Schedule;
use App\Models\Schedules\ScheduleEntry;
use App\Models\Schedules\ScheduleRule;
use App\Models\Schools\School;
use App\Models\System\ActivityType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScheduleIntegrityTest extends TestCase
{
    use DatabaseMigrations;

    private School $school;
    private Assistant $assistant;
    private ActivityType $activity;
    private ScheduleRule $rule;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => 'Administrator', 'slug' => 'admin']);
        $admin = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        Sanctum::actingAs($admin);

        $this->school = School::create(['name' => 'Escola Teste', 'acronym' => 'ET']);

        $user = User::factory()->create([
            'role_id' => $role->id,
            'first_name' => 'Maria',
            'last_name' => 'Joao',
        ]);

        $this->assistant = Assistant::create([
            'school_id' => $this->school->id,
            'user_id' => $user->id,
            'internal_number' => 'A100',
        ]);

        $this->activity = ActivityType::create([
            'school_id' => $this->school->id,
            'name' => 'Intervalo',
            'color' => '#3B82F6',
            'active' => true,
        ]);

        $this->rule = ScheduleRule::create([
            'school_id' => $this->school->id,
            'activity_type_id' => $this->activity->id,
            'period_start' => '15:00',
            'period_end' => '15:30',
            'min' => 1,
            'type' => 'mandatory',
            'valid_from' => CarbonImmutable::today()->toDateString(),
            'days_of_week' => [0, 1, 2, 3, 4],
        ]);

        $this->rule->assistants()->attach($this->assistant->id, ['priority' => 1]);

        $this->withHeader('X-School-ID', $this->school->id);
    }

    public function test_pending_recalculations_returns_clean_when_no_schedules(): void
    {
        $response = $this->getJson('/api/schedules/pending-recalculations');

        $response->assertOk()
            ->assertJson([
                'has_pending' => false,
                'affected_dates' => [],
            ]);
    }

    public function test_detects_conflict_when_absence_overlaps_schedule_entry(): void
    {
        $today = CarbonImmutable::today()->toDateString();

        $schedule = Schedule::create([
            'school_id' => $this->school->id,
            'assistant_id' => $this->assistant->id,
            'week_start' => $today,
            'week_end' => CarbonImmutable::today()->addDays(4)->toDateString(),
        ]);

        ScheduleEntry::create([
            'schedule_id' => $schedule->id,
            'activity_type_id' => $this->activity->id,
            'activity_name' => 'Intervalo',
            'schedule_rule_id' => $this->rule->id,
            'date' => $today,
            'start_time' => '15:00:00',
            'end_time' => '15:30:00',
            'is_locked' => false,
        ]);

        $absenceType = AbsenceType::create([
            'name' => 'Doenca',
            'code' => 'DOE',
            'is_active' => true,
        ]);

        // Criar ausencia para a assistente no mesmo dia
        Absence::create([
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $absenceType->id,
            'valid_from' => $today,
            'valid_until' => $today,
            'start_time' => '15:00',
            'end_time' => '15:30',
            'total_days' => 1,
            'status' => 'justified',
        ]);

        $response = $this->getJson('/api/schedules/pending-recalculations');

        $response->assertOk()
            ->assertJson([
                'has_pending' => true,
                'affected_dates' => [$today],
            ]);

        $this->assertArrayHasKey($today, $response->json('conflicts'));
    }

    public function test_creating_absence_returns_impact_when_schedules_exist(): void
    {
        $today = CarbonImmutable::today()->toDateString();

        $schedule = Schedule::create([
            'school_id' => $this->school->id,
            'assistant_id' => $this->assistant->id,
            'week_start' => $today,
            'week_end' => CarbonImmutable::today()->addDays(4)->toDateString(),
        ]);

        ScheduleEntry::create([
            'schedule_id' => $schedule->id,
            'activity_type_id' => $this->activity->id,
            'activity_name' => 'Intervalo',
            'schedule_rule_id' => $this->rule->id,
            'date' => $today,
            'start_time' => '15:00:00',
            'end_time' => '15:30:00',
            'is_locked' => false,
        ]);

        $absenceType = AbsenceType::create([
            'name' => 'Doenca',
            'code' => 'DOE',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/absences', [
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $absenceType->id,
            'valid_from' => $today,
            'valid_until' => $today,
            'start_time' => '15:00',
            'end_time' => '15:30',
            'status' => 'pending',
        ]);

        $response->assertCreated();
        $this->assertTrue((bool) $response->json('impact.requires_recalculation'));
        $this->assertEquals([$today], $response->json('impact.affected_dates'));
    }
}
