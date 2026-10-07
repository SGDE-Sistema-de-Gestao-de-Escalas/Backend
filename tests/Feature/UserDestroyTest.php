<?php

namespace Tests\Feature;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_deactivate_staff_and_revoke_tokens(): void
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

        $staff->createToken('browser');
        $staff->createToken('mobile');

        $this->assertSame(2, $staff->tokens()->count());

        Sanctum::actingAs($admin);

        $this->postJson("/api/users/{$staff->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('message', 'Utilizador desativado com sucesso.');

        $staff->refresh();
        $admin->refresh();

        $this->assertFalse($staff->is_active);
        $this->assertEquals($staffRole->id, $staff->role_id);
        $this->assertSame(0, $staff->tokens()->count());

        $this->assertTrue($admin->is_active);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_admin_cannot_deactivate_own_account(): void
    {
        $adminRole = Role::factory()->create([
            'name' => 'Administrator',
            'slug' => 'admin',
        ]);

        $admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $token = $admin->createToken('existing-access')->accessToken;

        Sanctum::actingAs($admin);

        $this->postJson("/api/users/{$admin->id}/deactivate")
            ->assertForbidden();

        $admin->refresh();

        $this->assertTrue($admin->is_active);
        $this->assertEquals($adminRole->id, $admin->role_id);

        $this->assertTrue(
            $admin->tokens()->whereKey($token->id)->exists()
        );

        $this->assertDatabaseCount('users', 2);
    }

    public function test_staff_cannot_deactivate_another_user(): void
    {
        $staffRole = Role::factory()->create([
            'name' => 'Staff',
            'slug' => 'staff',
        ]);

        $actor = User::factory()->create([
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        $target = User::factory()->create([
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        $token = $target->createToken('existing-access')->accessToken;

        Sanctum::actingAs($actor);

        $this->postJson("/api/users/{$target->id}/deactivate")
            ->assertForbidden();

        $target->refresh();

        $this->assertTrue($target->is_active);

        $this->assertTrue(
            $target->tokens()->whereKey($token->id)->exists()
        );

        $this->assertDatabaseCount('users', 2);
    }

    public function test_admin_can_permanently_delete_user_without_history(): void
    {
        $adminRole = Role::factory()->create([
            'name' => 'Administrator',
            'slug' => 'admin',
        ]);

        $admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $target = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $token = $target->createToken('existing-access')->accessToken;

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Utilizador eliminado definitivamente com sucesso.')
            ->assertJsonPath('delete_action', 'hard_delete');

        $this->assertDatabaseMissing('users', [
            'id' => $target->id,
        ]);

        $this->assertNull(
            User::withTrashed()->find($target->id)
        );

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->id,
        ]);

        $this->assertTrue($admin->fresh()->is_active);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_admin_cannot_delete_last_active_admin_via_users_id(): void
    {
        $adminRole = Role::factory()->create([
            'name' => 'Administrator',
            'slug' => 'admin',
        ]);

        $admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->deleteJson("/api/users/{$admin->id}");

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user']);

        $this->assertTrue($admin->fresh()->is_active);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_admin_cannot_delete_self_via_delete_me_if_last_active_admin(): void
    {
        $adminRole = Role::factory()->create([
            'name' => 'Administrator',
            'slug' => 'admin',
        ]);

        $admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->deleteJson('/api/me');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user']);

        $this->assertTrue($admin->fresh()->is_active);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_staff_cannot_delete_self_via_delete_me(): void
    {
        $staffRole = Role::factory()->create([
            'name' => 'Staff',
            'slug' => 'staff',
        ]);

        $staff = User::factory()->create([
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($staff);

        $response = $this->deleteJson('/api/me');

        $response
            ->assertForbidden()
            ->assertJsonPath('message', 'Os assistentes não se podem auto-eliminar diretamente.');

        $this->assertTrue($staff->fresh()->is_active);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_staff_cannot_delete_another_user_via_delete_users_id(): void
    {
        $staffRole = Role::factory()->create([
            'name' => 'Staff',
            'slug' => 'staff',
        ]);

        $staff = User::factory()->create([
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        $anotherUser = User::factory()->create([
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($staff);

        $response = $this->deleteJson("/api/users/{$anotherUser->id}");

        $response
            ->assertForbidden()
            ->assertJsonPath('message', 'Não tem permissão para eliminar este utilizador.');

        $this->assertTrue($anotherUser->fresh()->is_active);
    }

    public function test_admin_can_delete_another_admin_when_multiple_admins_exist(): void
    {
        $adminRole = Role::factory()->create([
            'name' => 'Administrator',
            'slug' => 'admin',
        ]);

        $admin1 = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $admin2 = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin1);

        $response = $this->deleteJson("/api/users/{$admin2->id}");

        $response->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $admin2->id]);
        $this->assertTrue($admin1->fresh()->is_active);
    }

    public function test_admin_can_delete_self_via_delete_me_when_another_admin_exists(): void
    {
        $adminRole = Role::factory()->create([
            'name' => 'Administrator',
            'slug' => 'admin',
        ]);

        $admin1 = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $admin2 = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin1);

        $response = $this->deleteJson('/api/me');

        $response->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $admin1->id]);
        $this->assertTrue($admin2->fresh()->is_active);
    }
}
