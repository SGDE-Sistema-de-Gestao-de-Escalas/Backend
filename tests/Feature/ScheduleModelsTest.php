<?php

namespace Tests\Feature;

use App\Models\Absences\Absence;
use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schedules\AssistantScheduleProfile;
use App\Models\Schedules\AssistantScheduleShift;
use App\Models\Schedules\AssistantScheduleShiftDay;
use App\Models\Schedules\Schedule;
use App\Models\Schools\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ScheduleModelsTest extends TestCase
{
    use RefreshDatabase;

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();
        $school = School::create(['name' => 'Escola', 'acronym' => 'ET']);
        $role = Role::create(['name' => 'Assistant', 'slug' => 'assistant']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $this->assistant = Assistant::create([
            'school_id' => $school->id, 'user_id' => $user->id, 'internal_number' => '001',
        ]);
    }

    private function profile(): AssistantScheduleProfile
    {
        return AssistantScheduleProfile::create([
            'assistant_id' => $this->assistant->id,
            'type' => 'rotativo', 'rotation_period' => 'weekly', 'starts_with' => 'A',
            'valid_from' => '2026-11-01', 'valid_until' => null,
        ]);
    }

    public function test_profile_shift_and_days_persist_with_actual_schema_fields(): void
    {
        $profile = $this->profile();
        $profile->update(['valid_until' => '2027-06-30']);
        $shift = AssistantScheduleShift::create([
            'assistant_schedule_profile_id' => $profile->id, 'shift_label' => 'A',
            'entry_time' => '08:00:00', 'exit_time' => '17:00:00',
            'lunch_enabled' => true, 'lunch_start' => '12:00:00', 'lunch_end' => '13:00:00',
            'lunch_duration_minutes' => 60,
        ]);
        $day = AssistantScheduleShiftDay::create(['assistant_schedule_shift_id' => $shift->id, 'day_of_week' => 1]);
        $shift->update(['exit_time' => '16:00:00']);
        $this->assertSame('2026-11-01', $profile->fresh()->toArray()['valid_from']);
        $this->assertSame('2027-06-30', $profile->fresh()->toArray()['valid_until']);
        $this->assertTrue($profile->assistant->is($this->assistant));
        $this->assertTrue($profile->shifts->first()->is($shift));
        $this->assertTrue($shift->assistantScheduleProfile->is($profile));
        $this->assertTrue($shift->shiftDays->first()->is($day));
        $this->assertTrue($day->assistantScheduleShift->is($shift));
        $this->assertSame(1, $day->fresh()->day_of_week);
        $this->assertSame(60, $shift->fresh()->lunch_duration_minutes);
        $this->assertTrue($shift->fresh()->lunch_enabled);
        $this->assertDatabaseHas('assistant_schedule_shifts', ['id' => $shift->id, 'exit_time' => '16:00:00']);
    }

    public function test_schedule_dates_and_relations_and_explicit_lifecycle_updates(): void
    {
        $profile = $this->profile();
        $schedule = Schedule::create([
            'assistant_id' => $this->assistant->id, 'school_id' => $this->assistant->school_id,
            'assistant_schedule_profile_id' => $profile->id,
            'week_start' => '2026-11-02', 'week_end' => '2026-11-08',
        ]);
        $this->assertSame('draft', $schedule->fresh()->status);
        $schedule->status = 'published';
        $schedule->generated_at = now();
        $schedule->published_at = now();
        $schedule->save();
        $schedule->refresh();
        $this->assertSame('2026-11-02', $schedule->toArray()['week_start']);
        $this->assertSame('2026-11-08', $schedule->toArray()['week_end']);
        $this->assertTrue($schedule->assistant->is($this->assistant));
        $this->assertSame($this->assistant->school_id, $schedule->school->id);
        $this->assertTrue($schedule->assistantScheduleProfile->is($profile));
        $this->assertSame('published', $schedule->status);
        $this->assertNotNull($schedule->published_at?->toIso8601String());
        foreach (['status', 'generated_at', 'published_at'] as $field) {
            $this->assertFalse($schedule->isFillable($field));
        }
    }

    public function test_absence_fields_persist_and_audit_fields_stay_guarded(): void
    {
        $typeId = (string) Str::uuid();
        DB::table('absence_types')->insert(['id' => $typeId, 'name' => 'Férias']);
        $absence = Absence::create([
            'assistant_id' => $this->assistant->id, 'absence_type_id' => $typeId,
            'valid_from' => '2026-11-02', 'valid_until' => null, 'total_days' => 1,
            'justification' => 'Documento recebido', 'document_path' => 'absences/test.pdf', 'notes' => 'Teste',
        ]);
        $absence->update(['valid_until' => '2026-11-03', 'total_days' => 2]);
        $absence->created_by_user_id = $this->assistant->user_id;
        $absence->justified_by_user_id = $this->assistant->user_id;
        $absence->status = 'justified';
        $absence->save();
        $absence->refresh();
        $this->assertSame('2026-11-02', $absence->toArray()['valid_from']);
        $this->assertSame('2026-11-03', $absence->toArray()['valid_until']);
        $this->assertSame(2, $absence->total_days);
        $this->assertTrue($absence->assistant->is($this->assistant));
        $this->assertSame($typeId, $absence->absenceType->id);
        $this->assertSame($this->assistant->user_id, $absence->createdByUser->id);
        $this->assertSame($this->assistant->user_id, $absence->justifiedByUser->id);
    }
}
