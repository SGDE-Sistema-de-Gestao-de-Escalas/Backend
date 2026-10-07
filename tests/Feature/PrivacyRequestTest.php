<?php

namespace Tests\Feature;

use App\Mail\PrivacyDeactivationRequestMail;
use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schools\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrivacyRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_request_deactivation(): void
    {
        $response = $this->postJson('/api/privacy/request-deactivation', [
            'reason' => 'Algum motivo',
        ]);

        $response->assertUnauthorized();
    }

    public function test_staff_with_school_can_request_deactivation_and_email_is_sent_to_active_admins(): void
    {
        Mail::fake();

        $adminRole = Role::factory()->create(['name' => 'Administrator', 'slug' => 'admin']);
        $staffRole = Role::factory()->create(['name' => 'Staff', 'slug' => 'staff']);

        $admin1 = User::factory()->create([
            'email' => 'admin1@example.com',
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $admin2 = User::factory()->create([
            'email' => 'admin2@example.com',
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $inactiveAdmin = User::factory()->create([
            'email' => 'inactive@example.com',
            'role_id' => $adminRole->id,
            'is_active' => false,
        ]);

        $school = School::create([
            'name' => 'Escola Básica de Teste',
            'acronym' => 'EB-TESTE',
            'active' => true,
        ]);

        $staff = User::factory()->create([
            'first_name' => 'Joana',
            'last_name' => 'Pereira',
            'email' => 'joana.pereira@example.com',
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        Assistant::create([
            'user_id' => $staff->id,
            'school_id' => $school->id,
            'internal_number' => 'AST-99999',
            'phone' => '912345678',
            'available_for_transfer' => false,
        ]);

        Sanctum::actingAs($staff);

        $response = $this->postJson('/api/privacy/request-deactivation', [
            'reason' => 'Mudança de funções e pedido de eliminação dos dados.',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Pedido de desativação submetido com sucesso. Os administradores foram notificados por email.'
            );

        Mail::assertSent(PrivacyDeactivationRequestMail::class, function (PrivacyDeactivationRequestMail $mail) use ($admin1, $admin2, $inactiveAdmin, $staff) {
            $hasAdmin1 = $mail->hasTo($admin1->email);
            $hasAdmin2 = $mail->hasTo($admin2->email);
            $hasInactive = $mail->hasTo($inactiveAdmin->email);

            return $hasAdmin1
                && $hasAdmin2
                && ! $hasInactive
                && $mail->user->id === $staff->id
                && $mail->schoolName === 'Escola Básica de Teste'
                && $mail->reason === 'Mudança de funções e pedido de eliminação dos dados.';
        });
    }

    public function test_user_without_assistant_can_request_deactivation_without_reason(): void
    {
        Mail::fake();

        $adminRole = Role::factory()->create(['name' => 'Administrator', 'slug' => 'admin']);
        $staffRole = Role::factory()->create(['name' => 'Staff', 'slug' => 'staff']);

        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $staff = User::factory()->create([
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($staff);

        $response = $this->postJson('/api/privacy/request-deactivation', []);

        $response
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Pedido de desativação submetido com sucesso. Os administradores foram notificados por email.'
            );

        Mail::assertSent(PrivacyDeactivationRequestMail::class, function (PrivacyDeactivationRequestMail $mail) use ($admin, $staff) {
            return $mail->hasTo($admin->email)
                && $mail->user->id === $staff->id
                && $mail->schoolName === 'Geral / Sem Escola Específica'
                && $mail->reason === null;
        });
    }
}
