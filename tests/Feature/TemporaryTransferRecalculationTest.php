<?php

namespace Tests\Feature;

use App\Models\Assistants\Assistant;
use App\Models\Assistants\AssistantTemporaryAssignments;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schedules\AssistantScheduleProfile;
use App\Models\Schedules\AssistantScheduleShift;
use App\Models\Schedules\AssistantScheduleShiftDay;
use App\Models\Schools\School;
use App\Models\Schools\SchoolOperatingRule;
use App\Models\Schools\SchoolOperatingRuleDay;
use App\Models\System\ActivityType;
use App\Models\System\Notification;
use App\Services\Schedules\ScheduleIntegrityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemporaryTransferRecalculationTest extends TestCase
{
    use RefreshDatabase;

    private School $destination;
    private School $origin;
    private User $admin;
    private CarbonImmutable $monday;

    protected function setUp(): void
    {
        parent::setUp();

        $this->monday = CarbonImmutable::today()->next('monday');

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrador']);
        Role::firstOrCreate(['slug' => 'assistant'], ['name' => 'Assistente Operacional']);

        $this->destination = School::create(['name' => 'Escola Destino', 'acronym' => 'ED', 'active' => true]);
        $this->origin = School::create(['name' => 'Escola Origem', 'acronym' => 'EO', 'active' => true]);
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);

        foreach ([$this->destination, $this->origin] as $school) {
            $operatingRule = SchoolOperatingRule::create(['school_id' => $school->id, 'valid_from' => '2026-01-01']);
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

            ActivityType::create([
                'school_id' => $school->id,
                'name' => 'Apoio Escola',
                'color' => '#64748B',
                'active' => true,
                'is_fallback' => true,
            ]);
        }
    }

    private function createAssistant(School $school, string $name, bool $availableForTransfer = true): Assistant
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
            'internal_number' => 'N'.random_int(1000, 9999),
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

    private function transfer(Assistant $assistant, School $origin, School $destination, string $from, string $until, ?string $start = null, ?string $end = null): AssistantTemporaryAssignments
    {
        return AssistantTemporaryAssignments::create([
            'assistant_id' => $assistant->id,
            'origin_school_id' => $origin->id,
            'destination_school_id' => $destination->id,
            'created_by_user_id' => $this->admin->id,
            'validated_by_user_id' => $this->admin->id,
            'valid_from' => $from,
            'valid_until' => $until,
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }

    private function generate(School $school, string $start, string $end, array $extra = [])
    {
        return $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $school->id)
            ->postJson('/api/schedules/generate', array_merge([
                'start_date' => $start,
                'end_date' => $end,
            ], $extra));
    }

    public function test_recalculating_the_destination_removes_only_incoming_transfers_of_the_period(): void
    {
        $assistant = $this->createAssistant($this->origin, 'Externa');
        $day = $this->monday->toDateString();

        $incoming = $this->transfer($assistant, $this->origin, $this->destination, $day, $day);
        $outgoing = $this->transfer($assistant, $this->destination, $this->origin, $day, $day);

        $this->generate($this->destination, $day, $day)->assertStatus(200);

        $this->assertDatabaseMissing('assistant_temporary_assignments', ['id' => $incoming->id]);
        $this->assertDatabaseHas('assistant_temporary_assignments', ['id' => $outgoing->id]);
    }

    public function test_a_transfer_longer_than_the_period_is_split_keeping_only_days_outside(): void
    {
        $assistant = $this->createAssistant($this->origin, 'Externa');
        $mon = $this->monday;

        $transfer = $this->transfer($assistant, $this->origin, $this->destination, $mon->toDateString(), $mon->addDays(4)->toDateString());

        $this->generate($this->destination, $mon->addDay()->toDateString(), $mon->addDays(2)->toDateString())->assertStatus(200);

        $head = $transfer->fresh();
        $this->assertSame($mon->toDateString(), $head->valid_until->format('Y-m-d'));

        $tail = AssistantTemporaryAssignments::where('id', '!=', $transfer->id)->first();
        $this->assertNotNull($tail);
        $this->assertSame($mon->addDays(3)->toDateString(), $tail->valid_from->format('Y-m-d'));
        $this->assertSame($mon->addDays(4)->toDateString(), $tail->valid_until->format('Y-m-d'));
        $this->assertSame($this->origin->id, $tail->origin_school_id);
        $this->assertSame($this->destination->id, $tail->destination_school_id);
    }

    public function test_a_transfer_overlapping_the_period_edges_is_trimmed(): void
    {
        $assistant = $this->createAssistant($this->origin, 'Externa');
        $mon = $this->monday;

        $startsBefore = $this->transfer($assistant, $this->origin, $this->destination, $mon->toDateString(), $mon->addDay()->toDateString());
        $endsAfter = $this->transfer($assistant, $this->origin, $this->destination, $mon->addDays(2)->toDateString(), $mon->addDays(4)->toDateString());

        $this->generate($this->destination, $mon->addDay()->toDateString(), $mon->addDays(2)->toDateString())->assertStatus(200);

        $this->assertSame($mon->toDateString(), $startsBefore->fresh()->valid_until->format('Y-m-d'));
        $this->assertSame($mon->addDays(3)->toDateString(), $endsAfter->fresh()->valid_from->format('Y-m-d'));
    }

    public function test_removing_a_transfer_notifies_the_origin_school_to_recalculate(): void
    {
        $assistant = $this->createAssistant($this->origin, 'Externa');
        $day = $this->monday->toDateString();
        $this->transfer($assistant, $this->origin, $this->destination, $day, $day, '10:00', '13:00');

        $this->generate($this->destination, $day, $day)->assertStatus(200);

        $notification = Notification::where('event', 'schedule.recalculation_required')
            ->where('school_id', $this->origin->id)
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('remoção da cedência temporária', $notification->body);
        $this->assertSame([$day], $notification->data['affected_dates']);
    }

    public function test_creating_a_transfer_notifies_the_origin_school_to_recalculate(): void
    {
        $assistant = $this->createAssistant($this->origin, 'Externa');
        $day = $this->monday->toDateString();

        $this->generate($this->destination, $day, $day, [
            'transfers' => [[
                'assistant_id' => $assistant->id,
                'date' => $day,
                'start_time' => '10:00',
                'end_time' => '13:00',
            ]],
        ])->assertStatus(200);

        $this->assertDatabaseHas('assistant_temporary_assignments', [
            'assistant_id' => $assistant->id,
            'destination_school_id' => $this->destination->id,
        ]);

        $notification = Notification::where('event', 'schedule.recalculation_required')
            ->where('school_id', $this->origin->id)
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('cedência temporária', $notification->body);
        $this->assertStringNotContainsString('remoção', $notification->body);
    }

    public function test_integrity_flags_the_origin_day_until_it_is_recalculated(): void
    {
        $assistant = $this->createAssistant($this->origin, 'Externa');
        $day = $this->monday->toDateString();

        $this->generate($this->origin, $day, $day)->assertStatus(200);

        $this->transfer($assistant, $this->origin, $this->destination, $day, $day, '10:00', '13:00');

        $integrity = app(ScheduleIntegrityService::class);

        $impact = $integrity->checkImpact($this->origin->id, $day, $day, 'transfer');
        $this->assertTrue($impact['requires_recalculation']);
        $this->assertSame([$day], $impact['affected_dates']);
        $this->assertSame('assigned_assistant_transferred', $impact['conflicts'][$day][0]['type']);

        $this->generate($this->origin, $day, $day)->assertStatus(200);

        $after = $integrity->checkImpact($this->origin->id, $day, $day, 'transfer');
        $this->assertFalse($after['requires_recalculation']);
    }

    public function test_integrity_flags_the_origin_day_when_the_destination_recalculation_removes_the_transfer(): void
    {
        $assistant = $this->createAssistant($this->origin, 'Externa');
        $day = $this->monday->toDateString();

        $this->transfer($assistant, $this->origin, $this->destination, $day, $day, '10:00', '13:00');
        $this->generate($this->origin, $day, $day)->assertStatus(200);

        $integrity = app(ScheduleIntegrityService::class);
        $this->assertFalse($integrity->checkImpact($this->origin->id, $day, $day, 'transfer')['requires_recalculation']);

        $this->generate($this->destination, $day, $day)->assertStatus(200);

        $impact = $integrity->checkImpact($this->origin->id, $day, $day, 'transfer');
        $this->assertTrue($impact['requires_recalculation']);
        $this->assertSame([$day], $impact['affected_dates']);
        $this->assertSame('transfer_removed_recalculation', $impact['conflicts'][$day][0]['type']);
        $this->assertStringContainsString('Escola Destino', $impact['conflicts'][$day][0]['message']);

        $pending = $integrity->getPendingRecalculations($this->origin->id);
        $this->assertContains($day, $pending['affected_dates']);

        $this->generate($this->origin, $day, $day)->assertStatus(200);

        $this->assertFalse($integrity->checkImpact($this->origin->id, $day, $day, 'transfer')['requires_recalculation']);
        $this->assertDatabaseCount('schedule_recalculation_flags', 0);
    }
}
