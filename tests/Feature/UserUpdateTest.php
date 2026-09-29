<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_only_user_name(): void
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
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        $originalPassword = $staff->password;

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/users/{$staff->id}", [
            'name' => 'Ana Santos',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $staff->id)
            ->assertJsonPath('data.name', 'Ana Santos')
            ->assertJsonPath('data.email', 'ana@example.com')
            ->assertJsonPath('data.role', 'staff')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonMissingPath('data.password');

        $staff->refresh();

        $this->assertSame('Ana Santos', $staff->name);
        $this->assertSame('ana@example.com', $staff->email);
        $this->assertEquals($staffRole->id, $staff->role_id);
        $this->assertSame($originalPassword, $staff->password);
        $this->assertTrue($staff->is_active);

        $this->assertDatabaseCount('users', 2);
    }

    public function test_last_active_admin_cannot_be_demoted(): void
    {
        $adminRole = Role::factory()->create([
            'name' => 'Administrator',
            'slug' => 'admin',
        ]);

        $staffRole = Role::factory()->create([
            'name' => 'Staff',
            'slug' => 'staff',
        ]);

        $activeAdmin = User::factory()->create([
            'name' => 'Administrador Ativo',
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => false,
        ]);

        Sanctum::actingAs($activeAdmin);

        $response = $this->patchJson(
            "/api/users/{$activeAdmin->id}",
            [
                'name' => 'Nome Alterado',
                'role_id' => $staffRole->id,
            ]
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role_id']);

        $activeAdmin->refresh();

        $this->assertEquals($adminRole->id, $activeAdmin->role_id);
        $this->assertSame('Administrador Ativo', $activeAdmin->name);
        $this->assertTrue($activeAdmin->is_active);
    }

    public function test_admin_can_demote_another_admin_when_one_remains_active(): void
    {
        $adminRole = Role::factory()->create([
            'name' => 'Administrator',
            'slug' => 'admin',
        ]);

        $staffRole = Role::factory()->create([
            'name' => 'Staff',
            'slug' => 'staff',
        ]);

        $actingAdmin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $targetAdmin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($actingAdmin);

        $response = $this->patchJson(
            "/api/users/{$targetAdmin->id}",
            [
                'role_id' => $staffRole->id,
            ]
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $targetAdmin->id)
            ->assertJsonPath('data.role', 'staff')
            ->assertJsonPath('data.is_active', true);

        $targetAdmin->refresh();
        $actingAdmin->refresh();

        $this->assertEquals($staffRole->id, $targetAdmin->role_id);
        $this->assertTrue($targetAdmin->is_active);

        $this->assertEquals($adminRole->id, $actingAdmin->role_id);
        $this->assertTrue($actingAdmin->is_active);
    }
}