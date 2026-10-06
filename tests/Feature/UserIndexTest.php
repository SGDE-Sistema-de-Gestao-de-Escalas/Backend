<?php

namespace Tests\Feature;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_users(): void
    {
        $this->getJson('/api/users')
            ->assertUnauthorized();
    }

    public function test_staff_cannot_list_users(): void
    {
        $role = Role::factory()->create([
            'name' => 'Staff',
            'slug' => 'staff',
        ]);

        $staff = User::factory()->create([
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($staff);

        $this->getJson('/api/users')
            ->assertForbidden();
    }

    public function test_admin_can_list_users(): void
    {
        $role = Role::factory()->create([
            'name' => 'Administrator',
            'slug' => 'admin',
        ]);

        $admin = User::factory()->create([
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/users')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $admin->id)
            ->assertJsonPath('data.0.role', 'admin')
            ->assertJsonPath('data.0.is_active', true)
            ->assertJsonPath('data.0.can_delete', false)
            ->assertJsonPath('data.0.delete_action', null)
            ->assertJsonPath('data.0.delete_message', null)
            ->assertJsonPath(
                'data.0.cannot_delete_reason',
                'Não pode eliminar a sua própria conta.'
            )
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.remember_token');

        $this->getJson("/api/users/{$admin->id}")
        ->assertOk()
        ->assertJsonPath('data.can_delete', false)
        ->assertJsonPath(
            'data.cannot_delete_reason',
            'Não pode eliminar a sua própria conta.'
        );
    }

    public function test_admin_can_delete_staff_in_list_and_details(): void
    {
        $adminRole = Role::factory()->create([
            'name' => 'Administrator',
            'slug' => 'admin',
        ]);

        $staffRole = Role::factory()->create([
            'name' => 'Staff',
            'slug' => 'staff',
        ]);

        $admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $staff = User::factory()->create([
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/users');

        $response->assertOk()->assertJsonCount(2, 'data');

        $staffRow = collect($response->json('data'))
            ->firstWhere('id', $staff->id);

        $this->assertNotNull($staffRow);
        $this->assertTrue($staffRow['can_delete']);
        $this->assertNull($staffRow['cannot_delete_reason']);

        $this->assertSame('hard_delete', $staffRow['delete_action']);
        $this->assertSame('A conta será apagada definitivamente.', $staffRow['delete_message']);

        $this->getJson("/api/users/{$staff->id}")
            ->assertOk()
            ->assertJsonPath('data.can_delete', true)
            ->assertJsonPath('data.cannot_delete_reason', null)
            ->assertJsonPath('data.delete_action', 'hard_delete')
            ->assertJsonPath('data.delete_message', 'A conta será apagada definitivamente.');
    }
}
