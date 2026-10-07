<?php

namespace Tests\Feature;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_only_user_last_name(): void
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
            'first_name' => 'Ana',
            'last_name' => 'Silva',
            'email' => 'ana@example.com',
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        $originalPassword = $staff->password;

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/users/{$staff->id}", [
            'last_name' => 'Santos',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $staff->id)
            ->assertJsonPath('data.first_name', 'Ana')
            ->assertJsonPath('data.last_name', 'Santos')
            ->assertJsonPath('message', 'Dados do utilizador atualizados com sucesso.')
            ->assertJsonPath('data.email', 'ana@example.com')
            ->assertJsonPath('data.role', 'staff')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonMissingPath('data.password');

        $staff->refresh();

        $this->assertSame('Ana', $staff->first_name);
        $this->assertSame('Santos', $staff->last_name);
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
            'first_name' => 'Administrador',
            'last_name' => 'Ativo',
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
                'first_name' => 'Nome',
                'last_name' => 'Alterado',
                'role_id' => $staffRole->id,
            ]
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role_id']);

        $activeAdmin->refresh();

        $this->assertEquals($adminRole->id, $activeAdmin->role_id);
        $this->assertSame('Administrador', $activeAdmin->first_name);
        $this->assertSame('Ativo', $activeAdmin->last_name);
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

    public function test_admin_can_reactivate_inactive_user(): void
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
            'is_active' => false,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/users/{$target->id}", [
            'is_active' => true,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('message', 'Utilizador ativado com sucesso.')
            ->assertJsonPath('data.is_active', true);

        $this->assertTrue($target->refresh()->is_active);
        $this->assertSame($adminRole->id, $target->role_id);
    }

    public function test_admin_cannot_update_anonymized_user(): void
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
            'first_name' => 'Utilizador',
            'last_name' => 'Anonimizado',
            'role_id' => $adminRole->id,
            'is_active' => false,
        ]);

        $target->anonymized_at = now();
        $target->save();

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/users/{$target->id}", [
            'first_name' => 'Novo Nome',
            'is_active' => true,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user']);

        $target->refresh();

        $this->assertFalse($target->is_active);
        $this->assertSame('Utilizador', $target->first_name);
        $this->assertNotNull($target->anonymized_at);
    }

    public function test_staff_can_update_own_profile_via_put_me(): void
    {
        Role::factory()->create(['name' => 'Administrator', 'slug' => 'admin']);
        $staffRole = Role::factory()->create(['name' => 'Staff', 'slug' => 'staff']);
        $staff = User::factory()->create([
            'first_name' => 'Carlos',
            'last_name' => 'Ferreira',
            'email' => 'carlos@example.com',
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($staff);

        $response = $this->putJson('/api/me', [
            'first_name' => 'Carlos Daniel',
            'last_name' => 'Ferreira Santos',
            'email' => 'carlos.santos@example.com',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Carlos Daniel')
            ->assertJsonPath('data.last_name', 'Ferreira Santos')
            ->assertJsonPath('data.email', 'carlos.santos@example.com')
            ->assertJsonPath('message', 'Perfil atualizado com sucesso.');

        $staff->refresh();
        $this->assertSame('Carlos Daniel', $staff->first_name);
        $this->assertSame('carlos.santos@example.com', $staff->email);
    }

    public function test_staff_can_update_own_profile_via_put_users_id(): void
    {
        Role::factory()->create(['name' => 'Administrator', 'slug' => 'admin']);
        $staffRole = Role::factory()->create(['name' => 'Staff', 'slug' => 'staff']);
        $staff = User::factory()->create([
            'first_name' => 'Maria',
            'last_name' => 'Sousa',
            'email' => 'maria@example.com',
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($staff);

        $response = $this->putJson("/api/users/{$staff->id}", [
            'first_name' => 'Maria João',
            'last_name' => 'Sousa',
            'email' => 'maria.joao@example.com',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Maria João')
            ->assertJsonPath('data.email', 'maria.joao@example.com');

        $staff->refresh();
        $this->assertSame('Maria João', $staff->first_name);
    }

    public function test_staff_cannot_update_another_user_via_put_users_id(): void
    {
        $staffRole = Role::factory()->create(['name' => 'Staff', 'slug' => 'staff']);
        $staff = User::factory()->create([
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        $anotherUser = User::factory()->create([
            'first_name' => 'Outro',
            'last_name' => 'Utilizador',
            'email' => 'outro@example.com',
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($staff);

        $response = $this->putJson("/api/users/{$anotherUser->id}", [
            'first_name' => 'Hacked',
            'last_name' => 'User',
            'email' => 'hacked@example.com',
        ]);

        $response
            ->assertForbidden()
            ->assertJsonPath('message', 'Não tem permissão para alterar dados de outros utilizadores.');

        $anotherUser->refresh();
        $this->assertSame('Outro', $anotherUser->first_name);
    }

    public function test_staff_cannot_change_role_or_active_status_or_school(): void
    {
        $adminRole = Role::factory()->create(['name' => 'Admin', 'slug' => 'admin']);
        $staffRole = Role::factory()->create(['name' => 'Staff', 'slug' => 'staff']);
        $staff = User::factory()->create([
            'first_name' => 'Pedro',
            'last_name' => 'Alves',
            'email' => 'pedro@example.com',
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($staff);

        $response = $this->putJson('/api/me', [
            'first_name' => 'Pedro',
            'last_name' => 'Alves',
            'email' => 'pedro@example.com',
            'role_id' => $adminRole->id,
            'is_active' => true,
            'school_id' => 123,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role_id', 'is_active', 'school_id']);

        $staff->refresh();
        $this->assertSame($staffRole->id, $staff->role_id);
    }

    public function test_put_requires_first_name_last_name_and_email(): void
    {
        $staffRole = Role::factory()->create(['name' => 'Staff', 'slug' => 'staff']);
        $staff = User::factory()->create([
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($staff);

        $response = $this->putJson('/api/me', []);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'last_name', 'email']);
    }

}
