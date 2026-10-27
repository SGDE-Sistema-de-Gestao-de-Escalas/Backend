<?php

namespace Tests\Feature;

use App\Models\Absences\Absence;
use App\Models\Absences\AbsenceType;
use App\Models\Assistants\Assistant;
use App\Models\Assistants\AssistantException;
use App\Models\Assistants\AssistantTemporaryAssignments;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schedules\AssistantScheduleProfile;
use App\Models\Schedules\AssistantScheduleShift;
use App\Models\Schedules\AssistantScheduleShiftDay;
use App\Models\Schedules\Schedule;
use App\Models\Schedules\ScheduleEntry;
use App\Models\Schedules\ScheduleRule;
use App\Models\Schools\School;
use App\Models\Schools\SchoolOperatingRule;
use App\Models\Schools\SchoolOperatingRuleDay;
use App\Models\System\ActivityType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportApiTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Assistant $assistant;

    private ActivityType $activity;

    private ScheduleRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $adminRole = Role::create(['name' => 'Administrador', 'slug' => 'admin']);
        Sanctum::actingAs(User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]));
        $this->school = School::create(['name' => 'Escola de Teste', 'acronym' => 'ET', 'active' => true]);
        $this->withHeader('X-School-ID', $this->school->id);
        $staffRole = Role::create(['name' => 'Assistente', 'slug' => 'assistant']);
        $staff = User::factory()->create(['role_id' => $staffRole->id, 'first_name' => 'Ana', 'last_name' => 'Silva']);
        $this->assistant = Assistant::create(['school_id' => $this->school->id, 'user_id' => $staff->id, 'internal_number' => '1']);
        $operating = SchoolOperatingRule::create(['school_id' => $this->school->id, 'valid_from' => '2026-01-01']);
        $profile = AssistantScheduleProfile::create([
            'assistant_id' => $this->assistant->id, 'type' => 'fixo', 'valid_from' => '2026-01-01',
        ]);
        $shift = AssistantScheduleShift::create([
            'assistant_schedule_profile_id' => $profile->id, 'shift_label' => 'A',
            'entry_time' => '08:00', 'exit_time' => '16:00', 'lunch_enabled' => true,
            'lunch_start' => '12:00', 'lunch_end' => '14:00', 'lunch_duration_minutes' => 60,
        ]);
        for ($weekday = 0; $weekday < 5; $weekday++) {
            SchoolOperatingRuleDay::create([
                'school_operating_rule_id' => $operating->id, 'day_of_week' => $weekday,
                'opening_time' => '08:00', 'closing_time' => '18:00',
                'lunch_start' => '12:00', 'lunch_end' => '14:00', 'lunch_duration_minutes' => 60,
            ]);
            AssistantScheduleShiftDay::create(['assistant_schedule_shift_id' => $shift->id, 'day_of_week' => $weekday]);
        }
        $this->activity = ActivityType::create(['school_id' => $this->school->id, 'name' => 'Portaria', 'active' => true, 'color' => '#123456']);
        $this->rule = ScheduleRule::create([
            'school_id' => $this->school->id, 'activity_type_id' => $this->activity->id,
            'period_start' => '08:00', 'period_end' => '10:00', 'min' => 2, 'type' => 'mandatory',
            'valid_from' => '2026-01-01', 'days_of_week' => [0, 1, 2, 3, 4],
        ]);
    }

    private function url(string $from = '2026-11-02', string $to = '2026-11-02'): string
    {
        return "/api/reports?start_date={$from}&end_date={$to}";
    }

    private function schedule(string $status = 'published', ?string $schoolId = null): Schedule
    {
        $schedule = Schedule::create([
            'school_id' => $schoolId ?? $this->school->id, 'assistant_id' => $this->assistant->id,
            'week_start' => '2026-11-02', 'week_end' => '2026-11-08',
        ]);
        $schedule->status = $status;
        $schedule->save();

        return $schedule;
    }

    private function entry(Schedule $schedule, string $start, string $end): void
    {
        ScheduleEntry::create([
            'schedule_id' => $schedule->id, 'activity_type_id' => $this->activity->id,
            'schedule_rule_id' => $this->rule->id, 'date' => '2026-11-02',
            'start_time' => $start, 'end_time' => $end,
        ]);
    }

    private function absence(array $attributes = []): Absence
    {
        $type = AbsenceType::firstOrCreate(['name' => 'Doença'], ['requires_document' => true]);

        return Absence::create(array_merge([
            'assistant_id' => $this->assistant->id, 'absence_type_id' => $type->id,
            'valid_from' => '2026-10-30', 'valid_until' => '2026-11-03', 'status' => 'justified',
        ], $attributes));
    }

    public function test_coverage_counts_staff_minutes_without_duplicate_assistants_and_does_not_write(): void
    {
        $schedule = $this->schedule();
        $this->entry($schedule, '08:00', '09:00');
        $this->entry($schedule, '08:30', '09:30');
        $before = DB::table('schedule_entries')->get()->toJson();

        $response = $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.summary.required_minutes', 240)
            ->assertJsonPath('data.summary.covered_minutes', 90)
            ->assertJsonPath('data.summary.uncovered_minutes', 150)
            ->assertJsonPath('data.summary.coverage_rate', 37.5)
            ->assertJsonPath('data.workload_by_assistant.0.assigned_minutes', 90)
            ->assertJsonPath('data.coverage_gaps.0.start_time', '08:00')
            ->assertJsonPath('data.coverage_gaps.0.end_time', '09:30')
            ->assertJsonPath('data.coverage_gaps.1.missing_assistants', 2);

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($before, DB::table('schedule_entries')->get()->toJson());
        $this->assertSame(1, Schedule::count());
    }

    public function test_drafts_and_other_schools_do_not_count_and_unpublished_is_not_zero_coverage(): void
    {
        $this->entry($this->schedule('draft'), '08:00', '10:00');
        $other = School::create(['name' => 'Outra', 'acronym' => 'OT']);
        $this->entry($this->schedule('published', $other->id), '08:00', '10:00');
        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.daily_coverage.0.status', 'no_published_schedule')
            ->assertJsonPath('data.daily_coverage.0.coverage_rate', null)
            ->assertJsonPath('data.summary.coverage_rate', null)
            ->assertJsonPath('data.summary.open_days_without_published_schedule', 1)
            ->assertJsonCount(0, 'data.coverage_gaps')
            ->assertJsonPath('data.workload_by_assistant.0.assigned_minutes', 0);
    }

    public function test_absences_are_clipped_to_inclusive_dates_and_working_days_and_lunch(): void
    {
        $this->absence();
        $this->getJson($this->url('2026-11-01', '2026-11-02'))->assertOk()
            ->assertJsonPath('data.summary.absence_minutes', 420)
            ->assertJsonPath('data.summary.affected_assistants', 1)
            ->assertJsonPath('data.absences.0.affected_workdays', 1)
            ->assertJsonPath('data.absences.0.absence_minutes', 420)
            ->assertJsonPath('data.absences_by_type.0.absence_minutes', 420)
            ->assertJsonPath('data.daily_coverage.0.status', 'closed');
    }

    public function test_overlapping_partial_absences_are_unioned_in_summary(): void
    {
        $this->absence(['start_time' => '11:00', 'end_time' => '13:30']);
        $this->absence(['start_time' => '13:00', 'end_time' => '15:00']);
        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.summary.absence_minutes', 180)
            ->assertJsonPath('data.absences.0.has_estimated_lunch', true)
            ->assertJsonPath('data.workload_by_assistant.0.absence_minutes', 180);
    }

    public function test_legacy_exception_date_column_can_be_read_without_schema_repair(): void
    {
        AssistantException::create([
            'assistant_id' => $this->assistant->id, 'type' => 'unavailable',
            'valid_from' => '2026-11-02', 'valid_until' => '2026-11-02',
        ]);
        Schema::table('assistant_exceptions', function (Blueprint $table) {
            $table->renameColumn('valid_until', 'valid_untill');
        });
        try {
            $this->getJson($this->url())->assertOk()->assertJsonPath('data.summary.assistants_with_exceptions', 1);
            $this->getJson($this->url('2026-11-03', '2026-11-03'))->assertOk()->assertJsonPath('data.summary.assistants_with_exceptions', 0);
            $this->assertTrue(Schema::hasColumn('assistant_exceptions', 'valid_untill'));
        } finally {
            Schema::table('assistant_exceptions', fn (Blueprint $table) => $table->renameColumn('valid_untill', 'valid_until'));
        }
    }

    public function test_missing_profiles_are_explicit_instead_of_silently_claiming_complete_absence_totals(): void
    {
        $this->absence();
        AssistantScheduleShiftDay::query()->delete();
        AssistantScheduleShift::query()->delete();
        AssistantScheduleProfile::query()->delete();
        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.summary.absence_records_without_profile', 1)
            ->assertJsonPath('data.absences.0.days_without_profile', 1);
    }

    public function test_validates_dates_and_requires_school_context(): void
    {
        $this->getJson('/api/reports')->assertUnprocessable()->assertJsonValidationErrors(['start_date', 'end_date']);
        $this->getJson($this->url('2026-11-02', '2026-11-01'))->assertUnprocessable();
        $this->getJson($this->url('invalid', '2026-11-01'))->assertUnprocessable();
        $this->getJson($this->url('2025-01-01', '2026-01-02'))->assertUnprocessable();
        $this->withHeader('X-School-ID', '')->getJson($this->url())->assertUnprocessable()->assertJsonValidationErrors('school_id');
    }

    public function test_only_active_administrators_can_read_reports(): void
    {
        Sanctum::actingAs($this->assistant->user);
        $this->getJson($this->url())->assertForbidden();
        $admin = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id'), 'is_active' => false]);
        Sanctum::actingAs($admin);
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_inaccessible_school_is_rejected(): void
    {
        $this->withHeader('X-School-ID', '00000000-0000-4000-8000-000000000001')
            ->getJson($this->url())->assertForbidden();
    }

    public function test_incoming_transfer_limits_workload_and_absence_rows_to_school_assignment_dates(): void
    {
        $origin = School::create(['name' => 'Origem', 'acronym' => 'OR']);
        $this->assistant->update(['school_id' => $origin->id]);
        AssistantTemporaryAssignments::create([
            'assistant_id' => $this->assistant->id,
            'created_by_user_id' => $this->assistant->user_id,
            'origin_school_id' => $origin->id, 'destination_school_id' => $this->school->id,
            'valid_from' => '2026-11-02', 'valid_until' => '2026-11-02',
        ]);
        $this->absence(['valid_from' => '2026-11-03', 'valid_until' => '2026-11-03']);
        $this->getJson($this->url('2026-11-02', '2026-11-03'))->assertOk()
            ->assertJsonPath('data.workload_by_assistant.0.expected_minutes', 420)
            ->assertJsonCount(0, 'data.absences');
    }

    public function test_expected_workload_uses_monthly_rotation(): void
    {
        $profile = AssistantScheduleProfile::where('assistant_id', $this->assistant->id)->firstOrFail();
        $profile->update(['type' => 'rotativo', 'valid_from' => '2026-10-01', 'rotation_period' => 'monthly', 'starts_with' => 'A']);
        $shift = AssistantScheduleShift::create([
            'assistant_schedule_profile_id' => $profile->id, 'shift_label' => 'B',
            'entry_time' => '09:00', 'exit_time' => '12:00', 'lunch_enabled' => false,
        ]);
        AssistantScheduleShiftDay::create(['assistant_schedule_shift_id' => $shift->id, 'day_of_week' => 0]);
        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.workload_by_assistant.0.expected_minutes', 180);
    }

    public function test_coverage_respects_rule_validity_school_hours_and_required_headcount_cap(): void
    {
        $this->rule->update(['period_start' => '06:00', 'period_end' => '09:00', 'valid_until' => '2026-11-02', 'min' => 1]);
        $this->entry($this->schedule(), '08:00', '10:00');
        $this->getJson($this->url('2026-11-02', '2026-11-03'))->assertOk()
            ->assertJsonPath('data.summary.required_minutes', 60)
            ->assertJsonPath('data.summary.covered_minutes', 60)
            ->assertJsonPath('data.summary.coverage_rate', 100)
            ->assertJsonPath('data.daily_coverage.1.required_minutes', 0)
            ->assertJsonPath('data.daily_coverage.1.coverage_rate', null)
            ->assertJsonCount(0, 'data.coverage_gaps');
    }

    public function test_empty_school_returns_empty_collections_and_null_coverage(): void
    {
        $empty = School::create(['name' => 'Sem dados', 'acronym' => 'SD']);
        $this->withHeader('X-School-ID', $empty->id)->getJson($this->url())->assertOk()
            ->assertJsonPath('data.summary.coverage_rate', null)
            ->assertJsonPath('data.summary.absence_minutes', 0)
            ->assertJsonCount(0, 'data.absences')
            ->assertJsonCount(0, 'data.workload_by_assistant')
            ->assertJsonCount(0, 'data.coverage_gaps');
    }
}
