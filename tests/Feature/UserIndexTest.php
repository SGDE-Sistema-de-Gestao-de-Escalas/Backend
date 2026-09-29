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
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.remember_token');
    }
}