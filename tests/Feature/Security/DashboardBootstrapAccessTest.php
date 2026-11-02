<?php

namespace Tests\Feature\Security;

use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schools\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F-24: o /dashboard/bootstrap devolve os dados pessoais de TODOS os assistentes
 * da escola (NIF, segurança social, contactos...). Só o administrador o pode usar.
 */
class DashboardBootstrapAccessTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrador']);
        $staffRole = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Assistente Operacional']);

        $this->school = School::create(['name' => 'Escola A', 'acronym' => 'EA', 'active' => true]);
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);

        $this->staff = User::factory()->create(['role_id' => $staffRole->id, 'is_active' => true]);
        Assistant::create([
            'school_id' => $this->school->id,
            'user_id' => $this->staff->id,
            'internal_number' => 'A001',
            'nif' => '111111111',
            'available_for_transfer' => false,
        ]);

        $colleague = User::factory()->create(['role_id' => $staffRole->id, 'is_active' => true]);
        Assistant::create([
            'school_id' => $this->school->id,
            'user_id' => $colleague->id,
            'internal_number' => 'A002',
            'nif' => '222222222',
            'available_for_transfer' => false,
        ]);
    }

    private function bootstrap(User $user)
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-School-ID', $this->school->id)
            ->getJson('/api/dashboard/bootstrap');
    }

    public function test_admin_still_receives_the_dashboard_data(): void
    {
        $response = $this->bootstrap($this->admin);

        $response->assertOk();
        $this->assertCount(2, $response->json('data.assistants.data'));
    }

    public function test_staff_cannot_read_the_dashboard_bootstrap(): void
    {
        $response = $this->bootstrap($this->staff);

        $response->assertForbidden();
        $this->assertStringNotContainsString('222222222', $response->getContent());
    }

    public function test_guest_cannot_read_the_dashboard_bootstrap(): void
    {
        $this->withHeader('X-School-ID', $this->school->id)
            ->getJson('/api/dashboard/bootstrap')
            ->assertUnauthorized();
    }
}
