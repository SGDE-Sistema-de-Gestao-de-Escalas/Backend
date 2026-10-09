<?php

namespace Tests\Feature\Schools;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schools\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolCrudFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $staffRole = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Staff']);

        $this->admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $this->staff = User::factory()->create([
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);
    }

    public function test_guest_and_staff_cannot_manage_schools(): void
    {
        $this->getJson('/api/schools')->assertUnauthorized();

        $this->actingAs($this->staff)
            ->getJson('/api/schools')
            ->assertForbidden();
    }

    public function test_admin_can_list_and_create_schools(): void
    {
        $createPayload = [
            'name' => 'Escola Secundária Nova',
            'code' => 'ESN-01',
            'acronym' => 'ESN',
            'address' => 'Avenida Principal',
            'phone' => '219999999',
            'active' => true,
        ];

        $this->actingAs($this->admin)
            ->postJson('/api/schools', $createPayload)
            ->assertCreated()
            ->assertJsonPath('data.name', 'Escola Secundária Nova');

        $this->actingAs($this->admin)
            ->getJson('/api/schools')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_can_show_and_update_school(): void
    {
        $school = School::create([
            'name' => 'Escola Básica 1',
            'code' => 'EB1',
            'acronym' => 'EB',
            'address' => 'Rua 1',
            'phone' => '210000001',
            'active' => true,
        ]);

        $this->actingAs($this->admin)
            ->getJson("/api/schools/{$school->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Escola Básica 1');

        $this->actingAs($this->admin)
            ->putJson("/api/schools/{$school->id}", [
                'name' => 'Escola Básica 1 Atualizada',
                'code' => 'EB1-A',
                'acronym' => 'EB1',
                'address' => 'Rua Atualizada',
                'phone' => '210000002',
                'active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Escola Básica 1 Atualizada');
    }
}

