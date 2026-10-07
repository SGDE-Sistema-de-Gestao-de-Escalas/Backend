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

    public function test_guest_cannot_export_personal_data(): void
    {
        $response = $this->getJson('/api/me/export');

        $response->assertUnauthorized();
    }

    public function test_authenticated_user_can_export_personal_data_without_assistant(): void
    {
        $adminRole = Role::factory()->create(['name' => 'Administrator', 'slug' => 'admin']);

        $admin = User::factory()->create([
            'first_name' => 'Carlos',
            'last_name'  => 'Ramos',
            'email'      => 'carlos.ramos@example.com',
            'role_id'    => $adminRole->id,
            'is_active'  => true,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/me/export');

        $response
            ->assertOk()
            ->assertHeader('Content-Disposition')
            ->assertJsonPath('perfil.first_name', 'Carlos')
            ->assertJsonPath('perfil.last_name', 'Ramos')
            ->assertJsonPath('perfil.email', 'carlos.ramos@example.com')
            ->assertJsonPath('perfil.role', 'admin')
            ->assertJsonPath('perfil.is_active', true)
            ->assertJsonMissingPath('assistente');

        $this->assertStringContainsString(
            'dados-pessoais-' . $admin->id,
            $response->headers->get('Content-Disposition')
        );
    }

    public function test_staff_with_assistant_exports_personal_data_with_school_and_no_sensitive_fields(): void
    {
        $adminRole = Role::factory()->create(['name' => 'Administrator', 'slug' => 'admin']);
        $staffRole = Role::factory()->create(['name' => 'Staff', 'slug' => 'staff']);

        $staff = User::factory()->create([
            'first_name' => 'Maria',
            'last_name'  => 'Lopes',
            'email'      => 'maria.lopes@example.com',
            'role_id'    => $staffRole->id,
            'is_active'  => true,
        ]);

        $school = School::create([
            'name'    => 'Escola Básica Exportação',
            'acronym' => 'EB-EXP',
            'active'  => true,
        ]);

        Assistant::create([
            'user_id'                => $staff->id,
            'school_id'              => $school->id,
            'internal_number'        => 'AST-EXPORT',
            'phone'                  => '915000000',
            'birth_date'             => '1990-06-15',
            'address_street'         => 'Avenida Principal 10',
            'address_zip_code'       => '4000-123',
            'available_for_transfer' => false,
        ]);

        Sanctum::actingAs($staff);

        $response = $this->getJson('/api/me/export');

        $response
            ->assertOk()
            ->assertJsonPath('perfil.first_name', 'Maria')
            ->assertJsonPath('perfil.email', 'maria.lopes@example.com')
            ->assertJsonPath('perfil.role', 'staff')
            ->assertJsonPath('assistente.internal_number', 'AST-EXPORT')
            ->assertJsonPath('assistente.phone', '915000000')
            ->assertJsonPath('assistente.birth_date', '1990-06-15')
            ->assertJsonPath('assistente.address_street', 'Avenida Principal 10')
            ->assertJsonPath('assistente.school', 'Escola Básica Exportação')
            // Campos sensíveis NIF e NISS não devem aparecer no export
            ->assertJsonMissingPath('assistente.nif')
            ->assertJsonMissingPath('assistente.social_security_number');
    }
}
