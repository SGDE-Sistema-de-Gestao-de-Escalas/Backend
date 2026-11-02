<?php

namespace Tests\Feature\Absences;

use App\Models\Absences\AbsenceType;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AbsenceTypeFeatureTest extends TestCase
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

    public function test_guest_and_staff_cannot_manage_absence_types(): void
    {
        $this->getJson('/api/absence-types')->assertUnauthorized();

        $this->actingAs($this->staff)
            ->getJson('/api/absence-types')
            ->assertForbidden();
    }

    public function test_admin_can_create_and_list_absence_types(): void
    {
        $payload = [
            'name' => 'Falta por Doença',
            'requires_document' => true,
        ];

        $this->actingAs($this->admin)
            ->postJson('/api/absence-types', $payload)
            ->assertCreated()
            ->assertJsonPath('data.name', 'Falta por Doença')
            ->assertJsonPath('data.requires_document', true);

        $this->actingAs($this->admin)
            ->getJson('/api/absence-types')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_can_update_and_delete_absence_type(): void
    {
        $type = AbsenceType::create([
            'name' => 'Falta Justificada',
            'requires_document' => false,
        ]);

        $this->actingAs($this->admin)
            ->putJson("/api/absence-types/{$type->id}", [
                'name' => 'Falta Justificada com Comprovativo',
                'requires_document' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Falta Justificada com Comprovativo');

        $this->actingAs($this->admin)
            ->deleteJson("/api/absence-types/{$type->id}")
            ->assertOk();

        $this->assertDatabaseMissing('absence_types', ['id' => $type->id]);
    }
}
