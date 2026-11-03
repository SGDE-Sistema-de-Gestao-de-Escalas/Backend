<?php

namespace Tests\Feature;

use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schedules\Schedule;
use App\Models\Schedules\ScheduleEntry;
use App\Models\Schedules\ScheduleRule;
use App\Models\Schools\School;
use App\Models\System\ActivityType;
use App\Models\System\Notification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScheduleRuleDeleteImpactTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private string $activityId;
    private array $assistantIds = [];
    private string $ruleId;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrador']);
        $staffRole = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Assistente Operacional']);

        $this->school = School::create(['name' => 'Escola A', 'acronym' => 'EA', 'active' => true]);
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);

        $this->activityId = ActivityType::create([
            'school_id' => $this->school->id, 'name' => 'Acolhimento', 'color' => '#F59E0B', 'active' => true,
        ])->id;

        foreach (['001', '002'] as $number) {
            $user = User::factory()->create(['role_id' => $staffRole->id, 'is_active' => true]);
            $this->assistantIds[] = Assistant::create([
                'school_id' => $this->school->id, 'user_id' => $user->id, 'internal_number' => $number,
                'available_for_transfer' => false,
            ])->id;
        }

        Sanctum::actingAs($this->admin);
        $this->ruleId = $this->withHeader('X-School-ID', $this->school->id)->postJson('/api/schedule-rules', [
            'activity_type_id' => $this->activityId,
            'period_start' => '08:00',
            'period_end' => '12:00',
            'min' => 1,
            'type' => 'mandatory',
            'valid_from' => '2030-01-07',
            'assistant_ids' => $this->assistantIds,
            'days_of_week' => [1, 3, 5],
        ])->assertCreated()->json('data.id');
    }

    private function day(int $offset): string
    {
        return CarbonImmutable::today()->addDays($offset)->toDateString();
    }

    private function scheduleFor(int $assistantIndex, int $offset, string $status = 'published'): Schedule
    {
        $date = CarbonImmutable::today()->addDays($offset);

        return Schedule::forceCreate([
            'school_id' => $this->school->id,
            'assistant_id' => $this->assistantIds[$assistantIndex],
            'week_start' => $date->startOfWeek()->toDateString(),
            'week_end' => $date->endOfWeek()->toDateString(),
            'status' => $status,
            'published_at' => $status === 'published' ? now() : null,
            'generated_at' => now(),
        ]);
    }

    private function entry(Schedule $schedule, int $offset, ?string $ruleId): ScheduleEntry
    {
        return ScheduleEntry::create([
            'schedule_id' => $schedule->id,
            'activity_type_id' => $this->activityId,
            'schedule_rule_id' => $ruleId,
            'date' => $this->day($offset),
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);
    }

    private function destroy(string $query = '')
    {
        Sanctum::actingAs($this->admin);

        return $this->withHeader('X-School-ID', $this->school->id)
            ->deleteJson("/api/schedule-rules/{$this->ruleId}{$query}");
    }

    public function test_a_rule_used_in_a_past_schedule_cannot_be_deleted(): void
    {
        $this->entry($this->scheduleFor(0, -3), -3, $this->ruleId);

        $this->destroy()->assertUnprocessable();

        $this->assertDatabaseHas('schedule_rules', ['id' => $this->ruleId]);
        $this->assertDatabaseCount('schedule_rule_assistant', 2);
    }

    public function test_past_usage_blocks_the_deletion_even_with_confirmation(): void
    {
        $schedule = $this->scheduleFor(0, -3);
        $this->entry($schedule, -3, $this->ruleId);
        $this->entry($schedule, 2, $this->ruleId);

        $this->destroy('?confirm=1')->assertUnprocessable();

        $this->assertDatabaseHas('schedule_rules', ['id' => $this->ruleId]);
        $this->assertSame('published', $schedule->fresh()->status);
    }

    public function test_the_past_message_tells_the_admin_what_to_do(): void
    {
        $this->entry($this->scheduleFor(0, -1), -1, $this->ruleId);

        $this->destroy()
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Não é possível eliminar uma regra que já foi usada em horários passados. Defina a data de fim da regra.');
    }

    public function test_a_rule_used_from_today_on_asks_for_confirmation_and_changes_nothing(): void
    {
        $schedule = $this->scheduleFor(0, 2);
        $this->entry($schedule, 0, $this->ruleId);
        $this->entry($schedule, 2, $this->ruleId);

        $this->destroy()
            ->assertStatus(409)
            ->assertJsonPath('requires_confirmation', true)
            ->assertJsonPath('impact.requires_recalculation', true)
            ->assertJsonPath('impact.affected_dates', [$this->day(0), $this->day(2)])
            ->assertJsonPath('impact.affected_schedules', 1);

        $this->assertDatabaseHas('schedule_rules', ['id' => $this->ruleId]);
        $this->assertDatabaseCount('schedule_rule_assistant', 2);
        $this->assertSame('published', $schedule->fresh()->status);
        $this->assertSame(0, Notification::where('event', 'schedule.recalculation_required')->count());
    }

    public function test_confirming_deletes_the_rule_and_sends_the_schedules_back_to_draft(): void
    {
        $schedule = $this->scheduleFor(0, 2);
        $entry = $this->entry($schedule, 2, $this->ruleId);

        $this->destroy('?confirm=1')
            ->assertOk()
            ->assertJsonPath('message', 'Regra removida com sucesso.')
            ->assertJsonPath('impact.requires_recalculation', true)
            ->assertJsonPath('impact.affected_dates', [$this->day(2)])
            ->assertJsonPath('impact.drafted_schedules', 1);

        $this->assertDatabaseMissing('schedule_rules', ['id' => $this->ruleId]);
        $this->assertDatabaseCount('schedule_rule_assistant', 0);
        $fresh = $schedule->fresh();
        $this->assertSame('draft', $fresh->status);
        $this->assertNull($fresh->published_at);
        $this->assertDatabaseHas('schedule_entries', ['id' => $entry->id, 'schedule_rule_id' => null]);
    }

    public function test_only_the_schedules_that_use_the_rule_go_back_to_draft(): void
    {
        $using = $this->scheduleFor(0, 2);
        $this->entry($using, 2, $this->ruleId);
        $other = $this->scheduleFor(1, 2);
        $this->entry($other, 2, null);

        $this->destroy('?confirm=1')->assertOk()->assertJsonPath('impact.drafted_schedules', 1);

        $this->assertSame('draft', $using->fresh()->status);
        $this->assertSame('published', $other->fresh()->status);
    }

    public function test_confirming_notifies_the_admins_that_a_recalculation_is_needed(): void
    {
        $this->entry($this->scheduleFor(0, 2), 2, $this->ruleId);

        $this->destroy('?confirm=1')->assertOk();

        $notification = Notification::where('user_id', $this->admin->id)
            ->where('event', 'schedule.recalculation_required')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame($this->school->id, $notification->school_id);
    }

    public function test_a_draft_schedule_in_the_future_still_asks_for_confirmation(): void
    {
        $schedule = $this->scheduleFor(0, 3, 'draft');
        $this->entry($schedule, 3, $this->ruleId);

        $this->destroy()->assertStatus(409)->assertJsonPath('impact.drafted_schedules', 0);

        $this->destroy('?confirm=1')->assertOk()->assertJsonPath('impact.drafted_schedules', 0);
        $this->assertSame('draft', $schedule->fresh()->status);
    }

    public function test_a_rule_without_any_schedule_is_deleted_without_asking(): void
    {
        $this->destroy()
            ->assertOk()
            ->assertJsonMissingPath('impact');

        $this->assertDatabaseMissing('schedule_rules', ['id' => $this->ruleId]);
        $this->assertSame(0, Notification::where('event', 'schedule.recalculation_required')->count());
    }

    public function test_a_confirmation_that_is_not_a_plain_flag_does_not_count(): void
    {
        $this->entry($this->scheduleFor(0, 2), 2, $this->ruleId);

        $this->destroy('?confirm[]=1')->assertStatus(409);
        $this->destroy('?confirm=0')->assertStatus(409);
        $this->destroy('?confirm=abc')->assertStatus(409);

        $this->assertDatabaseHas('schedule_rules', ['id' => $this->ruleId]);
    }

    public function test_the_confirmation_can_also_come_in_the_request_body(): void
    {
        $this->entry($this->scheduleFor(0, 2), 2, $this->ruleId);

        Sanctum::actingAs($this->admin);
        $this->withHeader('X-School-ID', $this->school->id)
            ->deleteJson("/api/schedule-rules/{$this->ruleId}", ['confirm' => true])
            ->assertOk();

        $this->assertDatabaseMissing('schedule_rules', ['id' => $this->ruleId]);
    }
}
