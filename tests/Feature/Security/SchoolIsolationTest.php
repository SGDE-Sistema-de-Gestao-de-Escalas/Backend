<?php

namespace Tests\Feature\Security;

use App\Models\Assistants\Assistant;
use App\Models\Assistants\AssistantTemporaryAssignments;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schools\School;
use App\Models\System\ActivityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F-09 (e F-31): um assistente só vê a sua escola (ou aquela para onde tem uma
 * cedência temporária em vigor), tanto nas listas como ao pedir um ID.
 */
class SchoolIsolationTest extends TestCase
{
    use RefreshDatabase;

    private School $schoolA;
    private School $schoolB;
    private User $admin;
    private User $staffA;
    private Assistant $assistantA;
    private ActivityType $activityA;
    private ActivityType $activityB;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrador']);
        $staffRole = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Assistente Operacional']);

        $this->schoolA = School::create(['name' => 'Escola A', 'acronym' => 'EA', 'active' => true]);
        $this->schoolB = School::create(['name' => 'Escola B', 'acronym' => 'EB', 'active' => true]);

        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);
        $this->staffA = User::factory()->create(['role_id' => $staffRole->id, 'is_active' => true]);

        $this->assistantA = Assistant::create([
            'school_id' => $this->schoolA->id,
            'user_id' => $this->staffA->id,
            'internal_number' => 'A001',
            'available_for_transfer' => false,
        ]);

        $this->activityA = ActivityType::create(['school_id' => $this->schoolA->id, 'name' => 'Portaria A', 'color' => '#6B7280', 'active' => true]);
        $this->activityB = ActivityType::create(['school_id' => $this->schoolB->id, 'name' => 'Portaria B', 'color' => '#6B7280', 'active' => true]);
    }

    private function as(User $user, ?School $school = null)
    {
        Sanctum::actingAs($user);

        return $school ? $this->withHeader('X-School-ID', $school->id) : $this;
    }

    // ---------------------------------------------------------------- escolas

    public function test_staff_can_read_their_own_school(): void
    {
        $this->as($this->staffA)->getJson("/api/schools/{$this->schoolA->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->schoolA->id);
    }

    public function test_staff_cannot_read_another_school_by_id(): void
    {
        $this->as($this->staffA)->getJson("/api/schools/{$this->schoolB->id}")->assertForbidden();
    }

    public function test_staff_school_list_contains_only_their_school(): void
    {
        $ids = collect($this->as($this->staffA)->getJson('/api/schools')->assertOk()->json('data'))->pluck('id');

        $this->assertSame([$this->schoolA->id], $ids->all());
    }

    public function test_user_without_an_assistant_record_sees_no_schools(): void
    {
        $role = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Assistente Operacional']);
        $orphan = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        $this->as($orphan)->getJson('/api/schools')->assertOk()->assertJsonCount(0, 'data');
        $this->as($orphan)->getJson("/api/schools/{$this->schoolA->id}")->assertForbidden();
    }

    public function test_admin_still_sees_every_school(): void
    {
        $this->as($this->admin)->getJson('/api/schools')->assertOk()->assertJsonCount(2, 'data');
        $this->as($this->admin)->getJson("/api/schools/{$this->schoolB->id}")->assertOk();
    }

    public function test_staff_can_read_the_school_they_are_temporarily_assigned_to(): void
    {
        AssistantTemporaryAssignments::create([
            'assistant_id' => $this->assistantA->id,
            'origin_school_id' => $this->schoolA->id,
            'destination_school_id' => $this->schoolB->id,
            'created_by_user_id' => $this->admin->id,
            'validated_by_user_id' => $this->admin->id,
            'valid_from' => today()->subDay()->toDateString(),
            'valid_until' => today()->addDays(3)->toDateString(),
        ]);

        $this->as($this->staffA)->getJson("/api/schools/{$this->schoolB->id}")->assertOk();

        $ids = collect($this->as($this->staffA)->getJson('/api/schools')->json('data'))->pluck('id')->sort()->values();
        $this->assertEquals(collect([$this->schoolA->id, $this->schoolB->id])->sort()->values(), $ids);
    }

    // ------------------------------------------------------------- atividades

    public function test_staff_can_read_an_activity_type_of_their_school(): void
    {
        $this->as($this->staffA)->getJson("/api/activity-types/{$this->activityA->id}")->assertOk();
    }

    public function test_staff_cannot_read_an_activity_type_of_another_school_by_id(): void
    {
        $this->as($this->staffA)->getJson("/api/activity-types/{$this->activityB->id}")->assertForbidden();
    }

    public function test_activity_list_without_school_header_only_returns_the_staff_school(): void
    {
        $names = collect($this->as($this->staffA)->getJson('/api/activity-types')->assertOk()->json('data'))->pluck('name');

        $this->assertTrue($names->contains('Portaria A'));
        $this->assertFalse($names->contains('Portaria B'));
    }

    public function test_activity_list_with_the_wrong_school_header_is_forbidden(): void
    {
        $this->as($this->staffA, $this->schoolB)->getJson('/api/activity-types')->assertForbidden();
    }

    public function test_admin_still_sees_activity_types_of_every_school(): void
    {
        $names = collect($this->as($this->admin)->getJson('/api/activity-types')->assertOk()->json('data'))->pluck('name');

        $this->assertTrue($names->contains('Portaria A'));
        $this->assertTrue($names->contains('Portaria B'));

        $this->as($this->admin)->getJson("/api/activity-types/{$this->activityB->id}")->assertOk();
    }
}
