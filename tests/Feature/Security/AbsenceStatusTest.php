<?php

namespace Tests\Feature\Security;

use App\Models\Absences\Absence;
use App\Models\Absences\AbsenceType;
use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schools\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F-04: um assistente não pode decidir o estado (justificada/injustificada)
 * da sua própria falta ao criá-la. Só o administrador o decide.
 */
class AbsenceStatusTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private User $staff;
    private Assistant $assistant;
    private AbsenceType $absenceType;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.documents_disk'));

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrador']);
        $staffRole = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Assistente Operacional']);

        $this->school = School::create(['name' => 'Escola A', 'acronym' => 'EA', 'active' => true]);
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);
        $this->staff = User::factory()->create(['role_id' => $staffRole->id, 'is_active' => true]);

        $this->assistant = Assistant::create([
            'school_id' => $this->school->id,
            'user_id' => $this->staff->id,
            'internal_number' => 'A001',
            'available_for_transfer' => false,
        ]);

        $this->absenceType = AbsenceType::create(['name' => 'Assuntos Pessoais', 'requires_document' => false]);
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->absenceType->id,
            'valid_from' => now()->addDays(10)->toDateString(),
            'valid_until' => now()->addDays(10)->toDateString(),
        ], $extra);
    }

    private function create(User $user, array $extra = [])
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/absences', $this->payload($extra));
    }

    public function test_staff_cannot_create_an_absence_already_justified(): void
    {
        $this->create($this->staff, ['status' => 'justified'])->assertCreated();

        $absence = Absence::firstOrFail();
        $this->assertSame('pending', $absence->status);
        $this->assertNull($absence->justified_by_user_id);
    }

    public function test_staff_cannot_create_an_absence_already_unjustified(): void
    {
        $this->create($this->staff, ['status' => 'unjustified'])->assertCreated();

        $this->assertSame('pending', Absence::firstOrFail()->status);
    }

    public function test_staff_absence_without_status_is_pending(): void
    {
        $this->create($this->staff)->assertCreated();

        $this->assertSame('pending', Absence::firstOrFail()->status);
    }

    public function test_the_ui_value_pending_keeps_working_for_staff(): void
    {
        // O frontend do assistente envia sempre status = "pending".
        $this->create($this->staff, ['status' => 'pending'])->assertCreated();

        $this->assertSame('pending', Absence::firstOrFail()->status);
    }

    public function test_admin_can_still_register_a_justified_absence(): void
    {
        $this->create($this->admin, ['status' => 'justified'])->assertCreated();

        $absence = Absence::firstOrFail();
        $this->assertSame('justified', $absence->status);
        $this->assertSame($this->admin->id, $absence->justified_by_user_id);
    }
}
