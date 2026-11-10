<?php

namespace Tests\Feature;

use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schedules\ScheduleRule;
use App\Models\Schools\School;
use App\Models\System\ActivityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScheduleRuleDeleteTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private School $otherSchool;
    private User $admin;
    private User $staff;
    private array $assistantIds = [];
    private string $activityId;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrador']);
        $staffRole = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Assistente Operacional']);

        $this->school = School::create(['name' => 'Escola A', 'acronym' => 'EA', 'active' => true]);
        $this->otherSchool = School::create(['name' => 'Escola B', 'acronym' => 'EB', 'active' => true]);
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);
        $this->staff = User::factory()->create(['role_id' => $staffRole->id, 'is_active' => true]);

        $this->activityId = ActivityType::create([
            'school_id' => $this->school->id, 'name' => 'Acolhimento', 'color' => '#F59E0B', 'active' => true,
        ])->id;

        foreach (['001', '002', '003'] as $number) {
            $user = User::factory()->create(['role_id' => $staffRole->id, 'is_active' => true]);
            $this->assistantIds[] = Assistant::create([
                'school_id' => $this->school->id, 'user_id' => $user->id, 'internal_number' => $number,
                'available_for_transfer' => false,
            ])->id;
        }
    }

    private function createRuleThroughApi(array $assistantIds): string
    {
        Sanctum::actingAs($this->admin);

        return $this->withHeader('X-School-ID', $this->school->id)->postJson('/api/schedule-rules', [
            'activity_type_id' => $this->activityId,
            'period_start' => '08:00',
            'period_end' => '12:00',
            'min' => 2,
            'type' => 'mandatory',
            'valid_from' => '2030-01-07',
            'assistant_ids' => $assistantIds,
            'days_of_week' => [1, 3, 5],
        ])->assertCreated()->json('data.id');
    }

    public function test_admin_can_delete_a_rule_that_has_assistants(): void
    {
        $ruleId = $this->createRuleThroughApi($this->assistantIds);
        $this->assertDatabaseCount('schedule_rule_assistant', 3);

        Sanctum::actingAs($this->admin);
        $this->withHeader('X-School-ID', $this->school->id)
            ->deleteJson("/api/schedule-rules/{$ruleId}")
            ->assertOk()
            ->assertJsonPath('message', 'Regra removida com sucesso.');

        $this->assertDatabaseMissing('schedule_rules', ['id' => $ruleId]);
        $this->assertDatabaseCount('schedule_rule_assistant', 0);
    }

    public function test_deleting_a_rule_keeps_the_assistants(): void
    {
        $ruleId = $this->createRuleThroughApi($this->assistantIds);

        Sanctum::actingAs($this->admin);
        $this->withHeader('X-School-ID', $this->school->id)
            ->deleteJson("/api/schedule-rules/{$ruleId}")
            ->assertOk();

        foreach ($this->assistantIds as $id) {
            $this->assertDatabaseHas('assistants', ['id' => $id, 'deleted_at' => null]);
        }
    }

    public function test_deleting_one_rule_does_not_touch_the_assistants_of_another_rule(): void
    {
        $first = $this->createRuleThroughApi([$this->assistantIds[0], $this->assistantIds[1]]);
        $second = $this->createRuleThroughApi([$this->assistantIds[1], $this->assistantIds[2]]);

        Sanctum::actingAs($this->admin);
        $this->withHeader('X-School-ID', $this->school->id)
            ->deleteJson("/api/schedule-rules/{$first}")
            ->assertOk();

        $this->assertDatabaseMissing('schedule_rules', ['id' => $first]);
        $this->assertDatabaseHas('schedule_rules', ['id' => $second]);
        $this->assertDatabaseCount('schedule_rule_assistant', 2);
        $this->assertDatabaseHas('schedule_rule_assistant', [
            'schedule_rule_id' => $second, 'assistant_id' => $this->assistantIds[1],
        ]);
        $this->assertDatabaseHas('schedule_rule_assistant', [
            'schedule_rule_id' => $second, 'assistant_id' => $this->assistantIds[2],
        ]);
    }

    public function test_staff_cannot_delete_a_rule(): void
    {
        $ruleId = $this->createRuleThroughApi($this->assistantIds);

        Sanctum::actingAs($this->staff);
        $this->withHeader('X-School-ID', $this->school->id)
            ->deleteJson("/api/schedule-rules/{$ruleId}")
            ->assertForbidden();

        $this->assertDatabaseHas('schedule_rules', ['id' => $ruleId]);
        $this->assertDatabaseCount('schedule_rule_assistant', 3);
    }

    public function test_a_rule_of_another_school_is_not_found_and_stays_intact(): void
    {
        $ruleId = $this->createRuleThroughApi($this->assistantIds);

        Sanctum::actingAs($this->admin);
        $this->withHeader('X-School-ID', $this->otherSchool->id)
            ->deleteJson("/api/schedule-rules/{$ruleId}")
            ->assertNotFound();

        $this->assertDatabaseHas('schedule_rules', ['id' => $ruleId]);
        $this->assertDatabaseCount('schedule_rule_assistant', 3);
    }

    public function test_deleting_a_rule_twice_returns_not_found(): void
    {
        $ruleId = $this->createRuleThroughApi($this->assistantIds);

        Sanctum::actingAs($this->admin);
        $this->withHeader('X-School-ID', $this->school->id)->deleteJson("/api/schedule-rules/{$ruleId}")->assertOk();
        $this->withHeader('X-School-ID', $this->school->id)->deleteJson("/api/schedule-rules/{$ruleId}")->assertNotFound();
    }
}
