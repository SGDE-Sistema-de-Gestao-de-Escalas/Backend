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
 * F-04: um assistente não pode decidir o estado (justificada/injustificada) de
 * uma falta, e desde a mudança de regras nem sequer as cria: só o administrador.
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

    public static function statusesSentByStaff(): array
    {
        return [
            'sem estado' => [null],
            'pending (o que o antigo formulário enviava)' => ['pending'],
            'justified' => ['justified'],
            'unjustified' => ['unjustified'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statusesSentByStaff')]
    public function test_staff_cannot_create_an_absence_in_any_state(?string $status): void
    {
        $extra = $status === null ? [] : ['status' => $status];

        $this->create($this->staff, $extra)->assertForbidden();

        $this->assertSame(0, Absence::count());
    }

    public function test_staff_cannot_create_an_absence_for_a_colleague_either(): void
    {
        $this->create($this->staff, ['assistant_id' => $this->assistant->id])->assertForbidden();

        $this->assertSame(0, Absence::count());
    }

    public function test_admin_can_still_register_a_justified_absence(): void
    {
        $this->create($this->admin, ['status' => 'justified'])->assertCreated();

        $absence = Absence::firstOrFail();
        $this->assertSame('justified', $absence->status);
        $this->assertSame($this->admin->id, $absence->justified_by_user_id);
    }
}
