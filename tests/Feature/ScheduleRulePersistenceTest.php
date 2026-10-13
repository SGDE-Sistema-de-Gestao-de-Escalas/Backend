<?php

namespace Tests\Feature;

use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schedules\ScheduleRule;
use App\Models\Schools\School;
use App\Models\System\ActivityType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleRulePersistenceTest extends TestCase
{
    use RefreshDatabase;

    private function createRule(): ScheduleRule
    {
        $school = School::create(['name' => 'Escola Teste', 'acronym' => 'ET']);
        $activity = ActivityType::create([
            'school_id' => $school->id,
            'name' => 'Acolhimento',
            'color' => '#F59E0B',
        ]);

        return ScheduleRule::create([
            'school_id' => $school->id,
            'activity_type_id' => $activity->id,
            'period_start' => '08:00',
            'period_end' => '12:00',
            'min' => 2,
            'type' => 'mandatory',
            'start' => '2026-11-01',
        ]);
    }

    private function createAssistant(ScheduleRule $rule, string $number): Assistant
    {
        $role = Role::firstOrCreate(['slug' => 'assistant'], ['name' => 'Assistant']);
        $user = User::factory()->create(['role_id' => $role->id]);

        return Assistant::create([
            'school_id' => $rule->school_id,
            'user_id' => $user->id,
            'internal_number' => $number,
        ]);
    }

    public function test_assistants_are_loaded_by_priority_and_can_be_reordered(): void
    {
        $rule = $this->createRule();
        $first = $this->createAssistant($rule, '001');
        $second = $this->createAssistant($rule, '002');
        $rule->assistants()->sync([
            $first->id => ['priority' => 2],
            $second->id => ['priority' => 1],
        ]);

        $this->assertSame([$second->id, $first->id], $rule->fresh()->assistants->modelKeys());
        $this->assertEquals([1, 2], $rule->fresh()->assistants->pluck('pivot.priority')->all());
        $this->assertTrue($rule->school->scheduleRules->contains($rule));
        $this->assertTrue($rule->activityType->scheduleRules->contains($rule));
        $this->assertTrue($first->scheduleRules->contains($rule));
        $this->assertSame('2026-11-01', $rule->fresh()->start->format('Y-m-d'));

        $rule->assistants()->sync([
            $first->id => ['priority' => 1],
            $second->id => ['priority' => 2],
        ]);

        $this->assertSame([$first->id, $second->id], $rule->fresh()->assistants->modelKeys());
        $this->assertDatabaseCount('schedule_rule_assistant', 2);
    }

    public function test_same_assistant_cannot_be_attached_twice_to_a_rule(): void
    {
        $rule = $this->createRule();
        $assistant = $this->createAssistant($rule, '001');
        $rule->assistants()->attach($assistant->id, ['priority' => 1]);

        $this->expectException(QueryException::class);

        $rule->assistants()->attach($assistant->id, ['priority' => 2]);
    }

    public function test_deleting_rule_removes_pivot_without_deleting_assistant(): void
    {
        $rule = $this->createRule();
        $assistant = $this->createAssistant($rule, '001');
        $rule->assistants()->attach($assistant->id, ['priority' => 1]);

        $rule->delete();

        $this->assertDatabaseCount('schedule_rule_assistant', 0);
        $this->assertModelExists($assistant);
    }
}
