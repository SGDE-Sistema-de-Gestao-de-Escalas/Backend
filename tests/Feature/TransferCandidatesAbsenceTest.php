<?php

namespace Tests\Feature;

use App\Models\Absences\Absence;
use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schedules\AssistantScheduleProfile;
use App\Models\Schedules\AssistantScheduleShift;
use App\Models\Schedules\AssistantScheduleShiftDay;
use App\Models\Schedules\ScheduleRule;
use App\Models\Schools\School;
use App\Models\Schools\SchoolOperatingRule;
use App\Models\Schools\SchoolOperatingRuleDay;
use App\Models\System\ActivityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransferCandidatesAbsenceTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-11-02';

    private School $school;
    private School $otherSchool;
    private User $admin;
    private ActivityType $portaria;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrador']);
        Role::firstOrCreate(['slug' => 'assistant'], ['name' => 'Assistente Operacional']);

        $this->school = School::create(['name' => 'Escola Básica Central', 'acronym' => 'EBC', 'active' => true]);
        $this->otherSchool = School::create(['name' => 'Escola Básica Secundária', 'acronym' => 'EBS', 'active' => true]);
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);

        $operatingRule = SchoolOperatingRule::create(['school_id' => $this->school->id, 'valid_from' => '2026-01-01']);
        for ($weekday = 0; $weekday <= 4; $weekday++) {
            SchoolOperatingRuleDay::create([
                'school_operating_rule_id' => $operatingRule->id,
                'day_of_week' => $weekday,
                'opening_time' => '08:00:00',
                'closing_time' => '18:00:00',
                'lunch_start' => '12:00:00',
                'lunch_end' => '14:30:00',
                'lunch_duration_minutes' => 60,
            ]);
        }

        $this->portaria = ActivityType::create([
            'school_id' => $this->school->id,
            'name' => 'Portaria',
            'color' => '#EF4444',
            'active' => true,
            'is_fallback' => false,
        ]);
        ActivityType::create([
            'school_id' => $this->school->id,
            'name' => 'Apoio Escola',
            'color' => '#64748B',
            'active' => true,
            'is_fallback' => true,
        ]);
    }

    private function createAssistant(string $name, string $number, School $school, bool $availableForTransfer): Assistant
    {
        $user = User::factory()->create([
            'first_name' => $name,
            'last_name' => 'Operacional',
            'role_id' => Role::where('slug', 'assistant')->value('id'),
            'is_active' => true,
        ]);

        $assistant = Assistant::create([
            'school_id' => $school->id,
            'user_id' => $user->id,
            'internal_number' => $number,
            'available_for_transfer' => $availableForTransfer,
        ]);

        $profile = AssistantScheduleProfile::create([
            'assistant_id' => $assistant->id,
            'type' => 'fixo',
            'rotation_period' => null,
            'starts_with' => null,
            'valid_from' => '2026-01-01',
            'valid_until' => null,
        ]);

        $shift = AssistantScheduleShift::create([
            'assistant_schedule_profile_id' => $profile->id,
            'shift_label' => 'A',
            'entry_time' => '08:00:00',
            'exit_time' => '16:00:00',
            'lunch_enabled' => true,
            'lunch_start' => '12:00:00',
            'lunch_end' => '13:00:00',
            'lunch_duration_minutes' => 60,
        ]);
        AssistantScheduleShiftDay::create(['assistant_schedule_shift_id' => $shift->id, 'day_of_week' => 0]);

        return $assistant;
    }

    private function absentFullDay(Assistant $assistant): void
    {
        $typeId = (string) Str::uuid();
        DB::table('absence_types')->insert(['id' => $typeId, 'name' => 'Férias']);

        Absence::create([
            'assistant_id' => $assistant->id,
            'absence_type_id' => $typeId,
            'valid_from' => self::DAY,
            'valid_until' => self::DAY,
            'total_days' => 1,
            'status' => 'justified',
        ]);
    }

    private function generateWithDeficit(): array
    {
        $absent = $this->createAssistant('Assistente Ausente', 'A001', $this->school, false);
        $this->absentFullDay($absent);

        $rule = ScheduleRule::create([
            'school_id' => $this->school->id,
            'activity_type_id' => $this->portaria->id,
            'period_start' => '08:00',
            'period_end' => '10:00',
            'min' => 1,
            'type' => 'mandatory',
            'valid_from' => '2026-01-01',
            'days_of_week' => [0],
        ]);
        $rule->assistants()->attach($absent->id, ['priority' => 1]);

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/schedules/generate', [
                'start_date' => self::DAY,
                'end_date' => self::DAY,
            ]);

        $response->assertStatus(200);

        return collect($response->json('conflicts.0.transfer_candidates'))
            ->keyBy('assistant_id')
            ->all();
    }

    public function test_a_candidate_without_absences_does_not_break_the_suggestions(): void
    {
        $free = $this->createAssistant('Externo Livre', 'E001', $this->otherSchool, true);

        $candidates = $this->generateWithDeficit();

        $this->assertArrayHasKey($free->id, $candidates);
        $this->assertFalse($candidates[$free->id]['absence_overlaps']);
        $this->assertTrue($candidates[$free->id]['works_today']);
    }

    public function test_the_absence_of_one_candidate_does_not_leak_into_the_next(): void
    {
        $first = $this->createAssistant('Externo Um', 'E001', $this->otherSchool, true);
        $second = $this->createAssistant('Externo Dois', 'E002', $this->otherSchool, true);

        [$processedFirst, $processedSecond] = strcmp((string) $first->id, (string) $second->id) < 0
            ? [$first, $second]
            : [$second, $first];

        $this->absentFullDay($processedFirst);

        $candidates = $this->generateWithDeficit();

        $this->assertTrue($candidates[$processedFirst->id]['absence_overlaps']);
        $this->assertFalse($candidates[$processedSecond->id]['absence_overlaps']);
    }
}
