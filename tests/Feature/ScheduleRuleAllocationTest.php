<?php

namespace Tests\Feature;

use App\Models\Assistants\Assistant;
use App\Models\Assistants\AssistantException;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schedules\ScheduleRule;
use App\Models\Schools\School;
use App\Models\System\ActivityType;
use App\Services\ScheduleRuleAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ScheduleRuleAllocationTest extends TestCase
{
    use RefreshDatabase;

    private ScheduleRule $rule;

    private array $ids = [];

    private array $periods = [];

    protected function setUp(): void
    {
        parent::setUp();
        $school = School::create(['name' => 'Escola', 'acronym' => 'ET']);
        $activity = ActivityType::create(['school_id' => $school->id, 'name' => 'Acolhimento', 'color' => '#F59E0B']);
        $this->rule = ScheduleRule::create([
            'school_id' => $school->id, 'activity_type_id' => $activity->id,
            'period_start' => '08:00', 'period_end' => '12:00', 'min' => 2,
            'type' => 'mandatory', 'valid_from' => '2026-11-01',
            'days_of_week' => [0, 1, 2, 3, 4, 5, 6],
        ]);
        $role = Role::create(['name' => 'Assistant', 'slug' => 'assistant']);
        foreach (range(1, 3) as $priority) {
            $user = User::factory()->create(['role_id' => $role->id]);
            $assistant = Assistant::create([
                'school_id' => $school->id, 'user_id' => $user->id, 'internal_number' => (string) $priority,
            ]);
            $this->ids[] = $assistant->id;
            $this->periods[$assistant->id] = [['start' => '08:00', 'end' => '17:00']];
            $this->rule->assistants()->attach($assistant->id, ['priority' => $priority]);
        }
    }

    private function allocate(array $reservations = []): array
    {
        return app(ScheduleRuleAllocationService::class)->allocate($this->rule, '2026-11-02', $this->periods, $reservations);
    }

    private function absence(string $from, ?string $until): string
    {
        $type = (string) Str::uuid();
        DB::table('absence_types')->insert(['id' => $type, 'name' => 'Férias']);
        $id = (string) Str::uuid();
        DB::table('absences')->insert([
            'id' => $id, 'assistant_id' => $this->ids[0], 'absence_type_id' => $type,
            'valid_from' => $from, 'valid_until' => $until, 'total_days' => 1,
        ]);

        return $id;
    }

    private function entry(string $start, string $end): string
    {
        $schedule = (string) Str::uuid();
        DB::table('schedules')->insert([
            'id' => $schedule, 'assistant_id' => $this->ids[0], 'school_id' => $this->rule->school_id,
            'week_start' => '2026-11-02', 'week_end' => '2026-11-08',
        ]);
        DB::table('schedule_entries')->insert([
            'id' => (string) Str::uuid(), 'schedule_id' => $schedule, 'activity_type_id' => $this->rule->activity_type_id,
            'date' => '2026-11-02', 'start_time' => $start, 'end_time' => $end,
        ]);

        return $schedule;
    }

    public function test_selects_first_available_candidates_without_writing_schedules(): void
    {
        $result = $this->allocate();
        $this->assertSame(array_slice($this->ids, 0, 2), $result['assistant_ids']);
        $this->assertTrue($result['complete']);
        $this->assertSame(0, $result['missing']);
        $this->assertDatabaseCount('schedules', 0);
        $this->assertDatabaseCount('schedule_entries', 0);
    }

    public function test_last_assistant_is_only_used_to_cover_a_shortfall(): void
    {

        $this->rule->update(['days_of_week' => [0]]);
        $this->assertSame(array_slice($this->ids, 0, 2), $this->allocate()['assistant_ids']);
        $this->absence('2026-11-02', null);
        $result = $this->allocate();
        $this->assertSame([$this->ids[1], $this->ids[2]], $result['assistant_ids']);
        $this->assertSame(2, $result['required']);
        $this->assertTrue($result['complete']);
        unset($this->periods[$this->ids[2]]);
        $this->assertSame(1, $this->allocate()['missing']);
    }

    public function test_absence_dates_are_inclusive_and_deleted_absences_do_not_block(): void
    {
        $id = $this->absence('2026-11-02', '2026-11-02');
        $this->assertSame(array_slice($this->ids, 1), $this->allocate()['assistant_ids']);
        $this->assertSame('absence', $this->allocate()['skipped'][0]['reason']);
        DB::table('absences')->where('id', $id)->update(['deleted_at' => now()]);
        $this->assertSame(array_slice($this->ids, 0, 2), $this->allocate()['assistant_ids']);
    }

    public function test_open_ended_absence_blocks_but_future_absence_does_not(): void
    {
        $id = $this->absence('2026-11-03', null);
        $this->assertSame($this->ids[0], $this->allocate()['assistant_ids'][0]);
        DB::table('absences')->where('id', $id)->update(['valid_from' => '2026-11-01']);
        $this->assertSame(array_slice($this->ids, 1), $this->allocate()['assistant_ids']);
    }

    public function test_existing_overlap_blocks_and_deleted_schedule_does_not(): void
    {
        $id = $this->entry('09:00:00', '10:00:00');
        $this->assertSame(array_slice($this->ids, 1), $this->allocate()['assistant_ids']);
        $this->assertSame('overlapping_assignment', $this->allocate()['skipped'][0]['reason']);
        DB::table('schedules')->where('id', $id)->update(['deleted_at' => now()]);
        $this->assertSame($this->ids[0], $this->allocate()['assistant_ids'][0]);
    }

    public function test_touching_intervals_and_reservations_on_another_day_do_not_overlap(): void
    {
        $this->entry('07:00:00', '08:00:00');
        $this->entry('12:00', '13:00');
        $result = $this->allocate([[
            'assistant_id' => $this->ids[0], 'date' => '2026-11-03', 'start' => '08:00', 'end' => '12:00',
        ]]);
        $this->assertSame(array_slice($this->ids, 0, 2), $result['assistant_ids']);
    }

    public function test_in_memory_reservations_prevent_double_assignment(): void
    {
        $result = $this->allocate([[
            'assistant_id' => $this->ids[0], 'date' => '2026-11-02', 'start' => '10:00', 'end' => '13:00',
        ]]);
        $this->assertSame(array_slice($this->ids, 1), $result['assistant_ids']);
    }

    public function test_missing_working_hours_and_lunch_gaps_report_shortfall(): void
    {
        $this->periods[$this->ids[0]] = [['start' => '08:00', 'end' => '10:00'], ['start' => '10:30', 'end' => '17:00']];
        unset($this->periods[$this->ids[1]]);
        $result = $this->allocate();
        $this->assertSame([$this->ids[2]], $result['assistant_ids']);
        $this->assertFalse($result['complete']);
        $this->assertSame(1, $result['missing']);
        $this->assertSame(['outside_working_period', 'outside_working_period'], array_column($result['skipped'], 'reason'));
    }

    public function test_contiguous_working_periods_cover_the_rule(): void
    {
        $this->periods[$this->ids[0]] = [['start' => '10:00', 'end' => '12:00'], ['start' => '08:00', 'end' => '10:00']];
        $this->assertSame($this->ids[0], $this->allocate()['assistant_ids'][0]);
    }

    public function test_inactive_anonymized_and_deleted_assistants_are_skipped(): void
    {
        Assistant::findOrFail($this->ids[0])->user->forceFill(['is_active' => false])->save();
        Assistant::findOrFail($this->ids[1])->update(['is_anonymized' => true]);
        Assistant::findOrFail($this->ids[2])->delete();
        $result = $this->allocate();
        $this->assertSame([], $result['assistant_ids']);
        $this->assertSame(2, $result['missing']);
        $this->assertCount(3, $result['skipped']);
    }

    public function test_exception_requires_resolution_only_when_it_overlaps(): void
    {
        $exception = AssistantException::create([
            'assistant_id' => $this->ids[0], 'type' => 'Horário especial', 'valid_from' => '2026-11-02',
            'start_time' => '12:00', 'end_time' => '13:00',
        ]);
        $this->assertSame($this->ids[0], $this->allocate()['assistant_ids'][0]);
        $exception->update(['start_time' => null, 'end_time' => null]);
        $this->assertSame('unresolved_exception', $this->allocate()['skipped'][0]['reason']);
        $exception->delete();
        $this->assertSame($this->ids[0], $this->allocate()['assistant_ids'][0]);
    }

    public function test_validity_boundaries_are_inclusive_and_null_end_is_unlimited(): void
    {
        $this->rule->update(['valid_until' => '2026-11-02']);
        $service = app(ScheduleRuleAllocationService::class);
        foreach (['2026-11-01', '2026-11-02'] as $date) {
            $this->assertTrue($service->allocate($this->rule, $date, $this->periods)['complete']);
        }
        $this->rule->update(['valid_until' => null]);
        $this->assertTrue($service->allocate($this->rule, '2027-11-01', $this->periods)['complete']);
    }

    public function test_rule_after_end_is_rejected_even_with_stale_model(): void
    {
        DB::table('schedule_rules')->where('id', $this->rule->id)->update(['valid_until' => '2026-11-01']);
        $this->expectException(ValidationException::class);
        $this->allocate();
    }

    public function test_rule_before_start_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(ScheduleRuleAllocationService::class)->allocate($this->rule, '2026-10-31', $this->periods);
    }

    public function test_unselected_weekday_is_rejected(): void
    {
        $this->rule->update(['days_of_week' => [2, 3]]);
        $this->expectException(ValidationException::class);
        $this->allocate(); // 2026-11-02 is Monday (0).
    }

    public function test_sunday_uses_day_six(): void
    {
        $this->rule->update(['days_of_week' => [6]]);
        $result = app(ScheduleRuleAllocationService::class)->allocate($this->rule, '2026-11-01', $this->periods);
        $this->assertTrue($result['complete']);
    }

    public function test_existing_rule_without_selected_days_is_not_applied_automatically(): void
    {
        $this->rule->update(['days_of_week' => null]);
        $this->expectException(ValidationException::class);
        $this->allocate();
    }

    public function test_invalid_working_period_is_rejected(): void
    {
        $this->periods[$this->ids[0]] = [['start' => '12:00', 'end' => '08:00']];
        $this->expectException(ValidationException::class);
        $this->allocate();
    }

    public function test_inactive_activity_is_rejected_even_with_stale_loaded_relation(): void
    {
        $this->rule->load('activityType');
        ActivityType::whereKey($this->rule->activity_type_id)->update(['active' => false]);
        $this->expectException(ValidationException::class);
        $this->allocate();
    }

    public function test_assistant_transferred_to_another_school_is_skipped(): void
    {
        $school = School::create(['name' => 'Outra', 'acronym' => 'OT']);
        Assistant::findOrFail($this->ids[0])->update(['school_id' => $school->id]);
        $result = $this->allocate();
        $this->assertSame(array_slice($this->ids, 1), $result['assistant_ids']);
        $this->assertSame('different_school', $result['skipped'][0]['reason']);
    }
}
