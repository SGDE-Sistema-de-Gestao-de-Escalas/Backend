<?php

namespace Tests\Feature\Security;

use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schedules\AssistantScheduleProfile;
use App\Models\Schools\School;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F-01: ver ou apagar o perfil de horário de outra pessoa.
 * Regra: o administrador vê e apaga; o assistente só vê o seu e nunca apaga.
 */
class ScheduleProfileAccessTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private User $staff;
    private User $colleague;
    private AssistantScheduleProfile $ownProfile;
    private AssistantScheduleProfile $colleagueProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrador']);
        $staffRole = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Assistente Operacional']);

        $this->school = School::create(['name' => 'Escola A', 'acronym' => 'EA', 'active' => true]);
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);

        $this->staff = User::factory()->create(['role_id' => $staffRole->id, 'is_active' => true]);
        $this->colleague = User::factory()->create(['role_id' => $staffRole->id, 'is_active' => true]);

        $this->ownProfile = $this->futureProfileFor($this->staff, 'A001');
        $this->colleagueProfile = $this->futureProfileFor($this->colleague, 'A002');
    }

    private function futureProfileFor(User $user, string $internalNumber): AssistantScheduleProfile
    {
        $assistant = Assistant::create([
            'school_id' => $this->school->id,
            'user_id' => $user->id,
            'internal_number' => $internalNumber,
            'available_for_transfer' => false,
        ]);

        // Perfil futuro: é o único que a regra de negócio deixa eliminar.
        return $assistant->assistantScheduleProfiles()->create([
            'type' => 'fixo',
            'valid_from' => Carbon::today()->addDays(30)->toDateString(),
            'valid_until' => null,
        ]);
    }

    private function as(User $user)
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-School-ID', $this->school->id);
    }

    public function test_staff_can_view_their_own_profile(): void
    {
        $this->as($this->staff)
            ->getJson("/api/assistant-schedule-profiles/{$this->ownProfile->id}")
            ->assertOk();
    }

    public function test_staff_cannot_view_a_colleagues_profile(): void
    {
        $this->as($this->staff)
            ->getJson("/api/assistant-schedule-profiles/{$this->colleagueProfile->id}")
            ->assertForbidden();
    }

    public function test_admin_can_view_any_profile(): void
    {
        $this->as($this->admin)
            ->getJson("/api/assistant-schedule-profiles/{$this->colleagueProfile->id}")
            ->assertOk();
    }

    public function test_staff_cannot_delete_a_colleagues_profile(): void
    {
        $this->as($this->staff)
            ->deleteJson("/api/assistant-schedule-profiles/{$this->colleagueProfile->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('assistant_schedule_profiles', ['id' => $this->colleagueProfile->id]);
    }

    public function test_staff_cannot_delete_their_own_profile_either(): void
    {
        $this->as($this->staff)
            ->deleteJson("/api/assistant-schedule-profiles/{$this->ownProfile->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('assistant_schedule_profiles', ['id' => $this->ownProfile->id]);
    }

    public function test_admin_can_still_delete_an_upcoming_profile(): void
    {
        $this->as($this->admin)
            ->deleteJson("/api/assistant-schedule-profiles/{$this->colleagueProfile->id}")
            ->assertOk();

        $this->assertDatabaseMissing('assistant_schedule_profiles', ['id' => $this->colleagueProfile->id]);
    }
}
