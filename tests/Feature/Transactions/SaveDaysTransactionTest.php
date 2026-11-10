<?php

namespace Tests\Feature\Transactions;

use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schedules\Schedule;
use App\Models\Schedules\ScheduleEntry;
use App\Models\Schools\School;
use App\Models\System\ActivityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaveDaysTransactionTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private Assistant $assistant;
    private ActivityType $activity;
    private Schedule $schedule;
    private string $firstDay;
    private string $secondDay;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrador']);
        $staffRole = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Assistente Operacional']);

        $this->school = School::create(['name' => 'Escola A', 'acronym' => 'EA', 'active' => true]);
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);
        $staff = User::factory()->create(['role_id' => $staffRole->id, 'is_active' => true]);
        $this->assistant = Assistant::create([
            'school_id' => $this->school->id,
            'user_id' => $staff->id,
            'internal_number' => 'A001',
            'available_for_transfer' => false,
        ]);
        $this->activity = ActivityType::create([
            'school_id' => $this->school->id,
            'name' => 'Portaria',
            'color' => '#6B7280',
            'active' => true,
        ]);

        $monday = now()->addWeek()->startOfWeek();
        $this->firstDay = $monday->copy()->toDateString();
        $this->secondDay = $monday->copy()->addDay()->toDateString();

        $this->schedule = new Schedule([
            'school_id' => $this->school->id,
            'assistant_id' => $this->assistant->id,
            'week_start' => $monday->toDateString(),
            'week_end' => $monday->copy()->endOfWeek()->toDateString(),
        ]);
        $this->schedule->status = 'draft';
        $this->schedule->generated_at = now();
        $this->schedule->save();

        foreach ([$this->firstDay, $this->secondDay] as $day) {
            ScheduleEntry::create([
                'schedule_id' => $this->schedule->id,
                'activity_type_id' => $this->activity->id,
                'activity_name' => 'Original',
                'date' => $day,
                'start_time' => '08:00',
                'end_time' => '10:00',
            ]);
        }
    }

    private function entry(string $activityTypeId, string $name, string $start = '09:00', string $end = '11:00'): array
    {
        return [
            'assistant_id' => $this->assistant->id,
            'activity_type_id' => $activityTypeId,
            'activity_name' => $name,
            'start_time' => $start,
            'end_time' => $end,
        ];
    }

    private function save(array $days)
    {
        return $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/schedules/save-days', ['days' => $days]);
    }

    public function test_saving_replaces_the_entries_of_every_day(): void
    {
        $this->save([
            ['date' => $this->firstDay, 'entries' => [$this->entry($this->activity->id, 'Nova A')]],
            ['date' => $this->secondDay, 'entries' => [$this->entry($this->activity->id, 'Nova B')]],
        ])->assertOk()->assertJsonPath('saved_days', 2);

        $this->assertSame(['Nova A'], ScheduleEntry::where('date', $this->firstDay)->pluck('activity_name')->all());
        $this->assertSame(['Nova B'], ScheduleEntry::where('date', $this->secondDay)->pluck('activity_name')->all());
    }

    public function test_a_failure_on_the_second_day_leaves_the_first_day_untouched(): void
    {
        $this->save([
            ['date' => $this->firstDay, 'entries' => [$this->entry($this->activity->id, 'Nova A')]],
            ['date' => $this->secondDay, 'entries' => [$this->entry((string) Str::uuid(), 'Atividade que nao existe')]],
        ])->assertStatus(500);

        $this->assertSame(['Original'], ScheduleEntry::where('date', $this->firstDay)->pluck('activity_name')->all());
        $this->assertSame(['Original'], ScheduleEntry::where('date', $this->secondDay)->pluck('activity_name')->all());
    }

    public function test_a_failure_halfway_through_a_day_does_not_erase_that_day(): void
    {
        $this->save([
            ['date' => $this->firstDay, 'entries' => [
                $this->entry($this->activity->id, 'Valida', '09:00', '10:00'),
                $this->entry((string) Str::uuid(), 'Invalida', '10:00', '11:00'),
            ]],
        ])->assertStatus(500);

        $this->assertSame(['Original'], ScheduleEntry::where('date', $this->firstDay)->pluck('activity_name')->all());
    }

    public function test_a_failed_save_does_not_revert_a_published_schedule_to_draft(): void
    {
        $this->schedule->status = 'published';
        $this->schedule->published_at = now();
        $this->schedule->save();

        $this->save([
            ['date' => $this->firstDay, 'entries' => [$this->entry((string) Str::uuid(), 'Invalida')]],
        ])->assertStatus(500);

        $this->assertSame('published', $this->schedule->fresh()->status);
        $this->assertNotNull($this->schedule->fresh()->published_at);
    }

    public function test_a_failed_save_does_not_create_new_schedules(): void
    {
        $otherStaff = User::factory()->create([
            'role_id' => Role::where('slug', 'staff')->value('id'),
            'is_active' => true,
        ]);
        $other = Assistant::create([
            'school_id' => $this->school->id,
            'user_id' => $otherStaff->id,
            'internal_number' => 'A002',
            'available_for_transfer' => false,
        ]);

        $before = Schedule::count();

        $this->save([
            ['date' => $this->firstDay, 'entries' => [
                ['assistant_id' => $other->id] + $this->entry($this->activity->id, 'Para o outro'),
                $this->entry((string) Str::uuid(), 'Invalida'),
            ]],
        ])->assertStatus(500);

        $this->assertSame($before, Schedule::count());
    }
}
