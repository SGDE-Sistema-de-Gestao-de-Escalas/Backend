<?php

namespace Tests\Feature\Security;

use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schools\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScheduleExportAccessTest extends TestCase
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

        $this->school = School::create(['name' => 'Escola EB1', 'acronym' => 'EB1', 'active' => true]);
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);
        $this->staff = User::factory()->create(['role_id' => $staffRole->id, 'is_active' => true]);

        Assistant::create([
            'school_id' => $this->school->id,
            'user_id' => $this->staff->id,
            'internal_number' => 'A100',
            'available_for_transfer' => false,
        ]);
    }

    public function test_staff_cannot_export_schedules(): void
    {
        Sanctum::actingAs($this->staff);

        $response = $this->withHeader('X-School-ID', $this->school->id)
            ->get('/api/schedules/export?start_date=2026-11-02&end_date=2026-11-06&model=wall&format=pdf');

        $response->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_export_schedules(): void
    {
        $response = $this->withHeader('X-School-ID', $this->school->id)
            ->getJson('/api/schedules/export?start_date=2026-11-02&end_date=2026-11-06&model=wall&format=pdf');

        $response->assertUnauthorized();
    }

    public function test_admin_is_authorized_to_reach_schedule_export_endpoint(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->withHeader('X-School-ID', $this->school->id)
            ->get('/api/schedules/export?start_date=2026-11-02&end_date=2026-11-06&model=wall&format=pdf');

        // Admin cannot receive 403 Forbidden. Depending on existing data, it either returns 200 (file) or 422 (se não houver escalas publicadas).
        $this->assertNotEquals(403, $response->getStatusCode());
        $this->assertNotEquals(401, $response->getStatusCode());
    }
}