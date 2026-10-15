<?php

namespace Tests\Feature;

use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schedules\ScheduleRule;
use App\Models\Schools\School;
use App\Models\System\ActivityType;
use App\Services\ScheduleRuleService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScheduleRuleApiTest extends TestCase
{
    use DatabaseMigrations;

    private School $school;

    private array $payload;

    private string $substituteId;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => 'Administrator', 'slug' => 'admin']);
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id, 'is_active' => true]));
        $this->school = School::create(['name' => 'Escola', 'acronym' => 'ET']);
        $activity = ActivityType::create([
            'school_id' => $this->school->id, 'name' => 'Acolhimento', 'color' => '#F59E0B',
        ]);
        $ids = [];
        foreach (['Maria', 'João', 'Ana', 'Suplente'] as $index => $name) {
            $user = User::factory()->create(['role_id' => $role->id, 'first_name' => $name, 'last_name' => 'Silva']);
            $ids[] = Assistant::create([
                'school_id' => $this->school->id, 'user_id' => $user->id, 'internal_number' => (string) $index,
            ])->id;
        }
        $this->substituteId = array_pop($ids);
        $this->payload = [
            'activity_type_id' => $activity->id, 'period_start' => '08:00', 'period_end' => '12:00',
            'min' => 2, 'type' => 'mandatory', 'valid_from' => '2026-11-01', 'assistant_ids' => array_slice($ids, 0, 2),
            'days_of_week' => [1, 3, 5],
        ];
        $this->withHeader('X-School-ID', $this->school->id);
    }

    private function createRule(): string
    {
        if ($this->payload['type'] === 'optional') {
            $this->payload['assistant_ids'] = array_slice($this->payload['assistant_ids'], 0, $this->payload['min']);
        }

        return $this->postJson('/api/schedule-rules', $this->payload)->assertCreated()->json('data.id');
    }

    public function test_validity_dates_can_be_created_updated_preserved_and_cleared(): void
    {
        $id = $this->createRule();
        $url = '/api/schedule-rules/'.$id;
        $this->getJson($url)->assertOk()->assertJsonPath('data.valid_from', '2026-11-01')
            ->assertJsonPath('data.valid_until', null)->assertJsonMissingPath('data.start');
        $this->patchJson($url, ['valid_until' => '2026-11-01'])->assertOk()
            ->assertJsonPath('data.valid_until', '2026-11-01');
        $this->patchJson($url, ['period_end' => '13:00'])->assertOk()
            ->assertJsonPath('data.valid_until', '2026-11-01');
        $this->patchJson($url, ['valid_from' => '2026-11-02'])->assertUnprocessable()->assertJsonValidationErrors('valid_until');
        $this->patchJson($url, ['valid_until' => '2026-10-31'])->assertUnprocessable()->assertJsonValidationErrors('valid_until');
        $this->patchJson($url, ['valid_from' => '2026-11-02', 'valid_until' => null])->assertOk()
            ->assertJsonPath('data.valid_from', '2026-11-02')->assertJsonPath('data.valid_until', null);
        $this->postJson('/api/schedule-rules', $this->payload + ['valid_until' => '2026-11-01'])
            ->assertCreated()->assertJsonPath('data.valid_until', '2026-11-01');
        $this->getJson('/api/schedule-rules')->assertOk()->assertJsonPath('data.0.valid_from', '2026-11-01');
    }

    public function test_invalid_validity_dates_are_rejected(): void
    {
        $id = $this->createRule();
        foreach (['2026-10-31', '2026-02-30', '01/11/2026'] as $until) {
            $this->postJson('/api/schedule-rules', $this->payload + ['valid_until' => $until])
                ->assertUnprocessable()->assertJsonValidationErrors('valid_until');
            $this->patchJson('/api/schedule-rules/'.$id, ['valid_until' => $until])
                ->assertUnprocessable()->assertJsonValidationErrors('valid_until');
        }
        $payload = $this->payload;
        unset($payload['valid_from']);
        $this->postJson('/api/schedule-rules', $payload)->assertUnprocessable()->assertJsonValidationErrors('valid_from');
        $this->patchJson('/api/schedule-rules/'.$id, ['valid_from' => null])->assertUnprocessable();
        $this->patchJson('/api/schedule-rules/'.$id, ['start' => '2026-11-02'])->assertUnprocessable()->assertJsonValidationErrors('start');
    }

    public function test_validity_migration_preserves_existing_dates_and_assistants(): void
    {
        $id = $this->createRule();
        $migration = require database_path('migrations/2026_10_15_000000_add_schedule_rule_validity_dates.php');
        $migration->down();
        $this->assertDatabaseHas('schedule_rules', ['id' => $id, 'start' => '2026-11-01']);
        $migration->up();
        $this->getJson('/api/schedule-rules/'.$id)->assertOk()
            ->assertJsonPath('data.valid_from', '2026-11-01')->assertJsonPath('data.valid_until', null)
            ->assertJsonPath('data.assistant_ids', $this->payload['assistant_ids']);
    }

    public function test_service_rechecks_validity_against_current_persisted_dates(): void
    {
        $id = $this->createRule();
        $stale = ScheduleRule::findOrFail($id);
        DB::table('schedule_rules')->where('id', $id)->update(['valid_until' => '2026-11-02']);
        try {
            app(ScheduleRuleService::class)->update($stale, ['valid_from' => '2026-11-03']);
            $this->fail('Invalid interval was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('valid_until', $exception->errors());
        }
        $this->getJson('/api/schedule-rules/'.$id)->assertOk()->assertJsonPath('data.valid_from', '2026-11-01');
    }

    public function test_both_rule_types_can_be_created_read_updated_and_deleted(): void
    {
        foreach (['mandatory', 'optional'] as $type) {
            $this->payload['type'] = $type;
            $response = $this->postJson('/api/schedule-rules', $this->payload)
                ->assertCreated()->assertJsonPath('data.type', $type);
            $id = $response->json('data.id');
            $url = '/api/schedule-rules/'.$id;
            $this->assertDatabaseHas('schedule_rules', ['id' => $id, 'type' => $type]);
            $this->getJson($url)->assertOk()->assertJsonPath('data.type', $type);
            $this->getJson('/api/schedule-rules')->assertOk()->assertJsonPath('data.0.type', $type);
            $this->patchJson($url, ['period_end' => '13:00'])->assertOk()->assertJsonPath('data.type', $type);

            $otherType = $type === 'mandatory' ? 'optional' : 'mandatory';
            $this->patchJson($url, ['type' => $otherType])->assertOk()->assertJsonPath('data.type', $otherType)
                ->assertJsonPath('data.assistant_ids', $this->payload['assistant_ids']);
            $this->assertDatabaseHas('schedule_rules', ['id' => $id, 'type' => $otherType]);
            $this->putJson($url, $this->payload)->assertOk()->assertJsonPath('data.type', $type);
            $this->deleteJson($url)->assertOk();
            $this->getJson($url)->assertNotFound();
        }
    }

    public function test_invalid_type_update_does_not_change_existing_rule(): void
    {
        $this->payload['type'] = 'optional';
        $id = $this->createRule();
        foreach (['unknown', '', null] as $type) {
            $this->patchJson('/api/schedule-rules/'.$id, ['type' => $type])
                ->assertUnprocessable()->assertJsonValidationErrors('type');
        }
        $this->assertDatabaseHas('schedule_rules', ['id' => $id, 'type' => 'optional']);
    }

    public function test_selected_days_are_saved_returned_and_preserved_when_omitted(): void
    {
        $this->payload['days_of_week'] = [5, '1', 3];
        $id = $this->createRule();
        $url = '/api/schedule-rules/'.$id;
        $this->getJson($url)->assertOk()->assertJsonPath('data.days_of_week', [1, 3, 5]);
        $this->getJson('/api/schedule-rules')->assertOk()->assertJsonPath('data.0.days_of_week', [1, 3, 5]);
        $this->patchJson($url, ['period_end' => '13:00'])->assertOk()->assertJsonPath('data.days_of_week', [1, 3, 5]);
        $this->patchJson($url, ['days_of_week' => [6, 0]])->assertOk()->assertJsonPath('data.days_of_week', [0, 6]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.days_of_week', [0, 6]);
    }

    public function test_days_are_required_on_creation_and_invalid_selections_are_rejected(): void
    {
        $payload = $this->payload;
        unset($payload['days_of_week']);
        $this->postJson('/api/schedule-rules', $payload)->assertUnprocessable()->assertJsonValidationErrors('days_of_week');
        $id = $this->createRule();
        foreach ([[], null, [-1], [7], [1, 1], [1, '1'], ['Monday'], [1.5], ['day' => 1]] as $days) {
            $this->postJson('/api/schedule-rules', array_replace($this->payload, ['days_of_week' => $days]))->assertUnprocessable();
            $this->patchJson('/api/schedule-rules/'.$id, ['days_of_week' => $days])->assertUnprocessable();
        }
        $this->getJson('/api/schedule-rules/'.$id)->assertOk()->assertJsonPath('data.days_of_week', [1, 3, 5]);
        $this->assertDatabaseCount('schedule_rules', 1);
    }

    public function test_crud_preserves_priority_and_ignores_school_from_body(): void
    {
        $this->payload['school_id'] = fake()->uuid();
        $id = $this->createRule();
        $url = '/api/schedule-rules/'.$id;
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.school_id', $this->school->id)
            ->assertJsonPath('data.assistant_ids', $this->payload['assistant_ids'])
            ->assertJsonPath('data.assistants.0.name', 'Maria Silva')
            ->assertJsonPath('data.assistants.0.priority', 1);

        $ids = array_reverse($this->payload['assistant_ids']);
        $this->putJson($url, ['assistant_ids' => $ids])->assertOk()
            ->assertJsonPath('data.assistant_ids', $ids)->assertJsonPath('data.assistants.1.priority', 2);
        $this->patchJson($url, ['period_end' => '13:00'])->assertOk()
            ->assertJsonPath('data.assistant_ids', $ids)->assertJsonPath('data.period_end', '13:00');
        $this->getJson('/api/schedule-rules')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.assistant_ids', $ids);
        $this->patchJson($url, ['min' => 1, 'assistant_ids' => array_slice($ids, 0, 1)])->assertOk()
            ->assertJsonCount(1, 'data.assistants');
        $this->assertDatabaseCount('schedule_rule_assistant', 1);
        $this->deleteJson($url)->assertOk();
        $this->assertDatabaseCount('schedule_rules', 0);
        $this->assertDatabaseCount('schedule_rule_assistant', 0);
        $this->assertDatabaseCount('assistants', 4);
    }

    public function test_school_context_is_required_and_other_school_rules_are_inaccessible(): void
    {
        $id = $this->createRule();
        $this->withHeader('X-School-ID', '');
        $this->getJson('/api/schedule-rules')->assertUnprocessable();
        $this->postJson('/api/schedule-rules', $this->payload)->assertUnprocessable();
        $other = School::create(['name' => 'Outra', 'acronym' => 'OT']);
        $this->withHeader('X-School-ID', $other->id);
        $this->getJson('/api/schedule-rules')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/schedule-rules/'.$id)->assertNotFound();
        $this->patchJson('/api/schedule-rules/'.$id, ['min' => 1])->assertNotFound();
        $this->deleteJson('/api/schedule-rules/'.$id)->assertNotFound();
        $this->postJson('/api/schedule-rules', $this->payload)->assertUnprocessable()
            ->assertJsonValidationErrors(['activity_type_id', 'assistant_ids.0']);
        $this->assertDatabaseCount('schedule_rules', 1);
    }

    public function test_invalid_payloads_do_not_create_rules(): void
    {
        $cases = [
            ['assistant_ids' => [$this->payload['assistant_ids'][0], $this->payload['assistant_ids'][0]]],
            ['assistant_ids' => []],
            ['assistant_ids' => ['key' => $this->payload['assistant_ids'][0]]],
            ['assistant_ids' => ['invalid']],
            ['min' => 4], ['min' => 0], ['type' => 'unknown'],
            ['period_end' => '07:00'], ['period_start' => '25:00'], ['valid_from' => '2026-02-30'],
        ];
        foreach ($cases as $changes) {
            $this->postJson('/api/schedule-rules', array_replace($this->payload, $changes))->assertUnprocessable();
        }
        $this->assertDatabaseCount('schedule_rules', 0);
        $this->assertDatabaseCount('schedule_rule_assistant', 0);
    }

    public function test_unavailable_assistants_and_inactive_activity_are_rejected(): void
    {
        $this->payload['assistant_ids'][] = $this->substituteId;
        $this->payload['min'] = 3;
        $assistants = Assistant::whereIn('id', $this->payload['assistant_ids'])->get()->keyBy('id');
        $assistants[$this->payload['assistant_ids'][0]]->delete();
        $assistants[$this->payload['assistant_ids'][1]]->update(['is_anonymized' => true]);
        $assistants[$this->payload['assistant_ids'][2]]->user->forceFill(['is_active' => false])->save();
        ActivityType::whereKey($this->payload['activity_type_id'])->update(['active' => false]);
        $this->postJson('/api/schedule-rules', $this->payload)->assertUnprocessable()
            ->assertJsonValidationErrors(['activity_type_id', 'assistant_ids.0', 'assistant_ids.1', 'assistant_ids.2']);
        $this->assertDatabaseCount('schedule_rules', 0);
    }

    public function test_partial_updates_validate_against_existing_values_without_mutating_data(): void
    {
        $id = $this->createRule();
        $url = '/api/schedule-rules/'.$id;
        foreach ([['period_start' => '13:00'], ['period_end' => '07:00'], ['min' => 4], ['assistant_ids' => [$this->payload['assistant_ids'][0]]]] as $changes) {
            $this->patchJson($url, $changes)->assertUnprocessable();
        }
        $this->getJson($url)->assertOk()->assertJsonPath('data.period_start', '08:00')
            ->assertJsonPath('data.min', 2)->assertJsonPath('data.assistant_ids', $this->payload['assistant_ids']);
    }

    public function test_non_admin_cannot_manage_rules(): void
    {
        $id = $this->createRule();
        $role = Role::create(['name' => 'Assistant', 'slug' => 'assistant']);
        $assistant = Assistant::findOrFail($this->payload['assistant_ids'][0]);
        $assistant->user->update(['role_id' => $role->id]);
        Sanctum::actingAs($assistant->user->fresh());
        $this->getJson('/api/schedule-rules')->assertForbidden();
        $this->postJson('/api/schedule-rules', $this->payload)->assertForbidden();
        $this->getJson('/api/schedule-rules/'.$id)->assertForbidden();
        $this->patchJson('/api/schedule-rules/'.$id, ['min' => 1])->assertForbidden();
        $this->deleteJson('/api/schedule-rules/'.$id)->assertForbidden();
    }

    public function test_activity_used_by_rule_cannot_be_deleted(): void
    {
        $this->createRule();
        $this->deleteJson('/api/activity-types/'.$this->payload['activity_type_id'])->assertStatus(409);
        $this->assertDatabaseHas('activity_types', ['id' => $this->payload['activity_type_id']]);
    }

    public function test_both_types_require_exactly_the_entered_minimum(): void
    {
        foreach (['mandatory' => 2, 'optional' => 2] as $type => $expected) {
            foreach ([1, 2, 3, 4] as $count) {
                $payload = array_replace($this->payload, [
                    'type' => $type,
                    'assistant_ids' => Assistant::orderBy('internal_number')->limit($count)->pluck('id')->all(),
                ]);
                $response = $this->postJson('/api/schedule-rules', $payload);
                if ($count === $expected) {
                    $response->assertCreated()->assertJsonPath('data.min', 2)
                        ->assertJsonCount($expected, 'data.assistant_ids')->assertJsonMissingPath('data.substitute_assistant_id');
                } else {
                    $response->assertUnprocessable()->assertJsonValidationErrors('assistant_ids');
                }
            }
        }
    }

    public function test_type_and_min_changes_require_matching_selection(): void
    {
        $id = $this->createRule();
        $url = '/api/schedule-rules/'.$id;
        $this->patchJson($url, ['type' => 'optional'])->assertOk();
        $this->patchJson($url, ['min' => 1])->assertUnprocessable();
        $this->patchJson($url, ['type' => 'optional', 'assistant_ids' => array_slice($this->payload['assistant_ids'], 0, 2)])
            ->assertOk()->assertJsonPath('data.min', 2);
        $this->patchJson($url, ['type' => 'mandatory'])->assertOk();
        $this->patchJson($url, ['type' => 'mandatory', 'assistant_ids' => $this->payload['assistant_ids']])
            ->assertOk()->assertJsonPath('data.min', 2);
        $this->patchJson($url, ['substitute_assistant_id' => $this->substituteId])->assertUnprocessable();
    }

    public function test_mandatory_rule_with_minimum_one_needs_only_one_assistant(): void
    {
        $this->payload['min'] = 1;
        $this->payload['assistant_ids'] = [$this->payload['assistant_ids'][0]];
        $id = $this->createRule();
        $this->getJson('/api/schedule-rules/'.$id)->assertOk()
            ->assertJsonPath('data.type', 'mandatory')->assertJsonPath('data.min', 1)
            ->assertJsonCount(1, 'data.assistant_ids');
    }

    public function test_migration_preserves_previous_substitute_in_priority_list(): void
    {
        $id = $this->createRule();
        $migration = require database_path('migrations/2026_10_13_000002_move_schedule_rule_substitute_to_priority_list.php');
        $migration->down();
        DB::table('schedule_rules')->where('id', $id)->update(['substitute_assistant_id' => $this->substituteId]);
        $migration->up();
        $this->getJson('/api/schedule-rules/'.$id)->assertOk()
            ->assertJsonPath('data.assistant_ids', [...$this->payload['assistant_ids'], $this->substituteId])
            ->assertJsonPath('data.days_of_week', $this->payload['days_of_week'])
            ->assertJsonMissingPath('data.substitute_assistant_id');
    }
}
