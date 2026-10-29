<?php

namespace Tests\Feature;

use App\Mail\AbsenceJustificationSubmittedMail;
use App\Mail\AbsenceRegisteredMail;
use App\Mail\AbsenceStatusUpdatedMail;
use App\Mail\CriminalRecordExpiryAlertMail;
use App\Mail\SchedulePublishedMail;
use App\Models\Absences\Absence;
use App\Models\Absences\AbsenceType;
use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schedules\AssistantScheduleProfile;
use App\Models\Schedules\AssistantScheduleShift;
use App\Models\Schedules\AssistantScheduleShiftDay;
use App\Models\Schedules\ScheduleRule;
use App\Models\Schools\School;
use App\Models\Schools\SchoolOperatingRule;
use App\Models\Schools\SchoolOperatingRuleDay;
use App\Models\System\ActivityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssistantEmailNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private Assistant $assistant;
    private User $assistantUser;
    private AbsenceType $absenceType;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(
            ['slug' => 'admin'],
            ['name' => 'Administrador', 'description' => 'Admin']
        );
        $staffRole = Role::firstOrCreate(
            ['slug' => 'staff'],
            ['name' => 'Assistente Operacional', 'description' => 'Staff']
        );

        $this->school = School::create([
            'name' => 'EB1 Quinta das Flores',
            'acronym' => 'EB1QF',
            'active' => true,
        ]);

        $operatingRule = SchoolOperatingRule::create([
            'school_id' => $this->school->id,
            'valid_from' => '2026-01-01',
        ]);

        for ($d = 0; $d <= 4; $d++) {
            SchoolOperatingRuleDay::create([
                'school_operating_rule_id' => $operatingRule->id,
                'day_of_week' => $d,
                'opening_time' => '08:00:00',
                'closing_time' => '18:00:00',
                'lunch_start' => '12:00:00',
                'lunch_end' => '14:30:00',
                'lunch_duration_minutes' => 60,
            ]);
        }

        $portaria = ActivityType::create([
            'school_id' => $this->school->id,
            'name' => 'Portaria',
            'color' => '#EF4444',
            'active' => true,
            'is_fallback' => false,
        ]);

        ActivityType::create([
            'school_id' => $this->school->id,
            'name' => 'Apoio Escola',
            'color' => '#9CA3AF',
            'active' => true,
            'is_fallback' => true,
        ]);

        $this->admin = User::create([
            'first_name' => 'Admin',
            'last_name' => 'Sistema',
            'email' => 'admin.notif@escola.pt',
            'password' => Hash::make('password123'),
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $this->assistantUser = User::create([
            'first_name' => 'Mariana',
            'last_name' => 'Costa',
            'email' => 'mariana.costa@escola.pt',
            'password' => Hash::make('password123'),
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        $this->assistant = Assistant::create([
            'user_id' => $this->assistantUser->id,
            'school_id' => $this->school->id,
            'internal_number' => 'AO-901',
            'weekly_hours' => 35,
            'daily_hours' => 7,
            'available_for_transfer' => true,
        ]);

        $profile = AssistantScheduleProfile::create([
            'assistant_id' => $this->assistant->id,
            'type' => 'fixo',
            'rotation_period' => null,
            'starts_with' => null,
            'valid_from' => '2026-01-01',
            'valid_until' => null,
        ]);

        $shift = AssistantScheduleShift::create([
            'assistant_schedule_profile_id' => $profile->id,
            'shift_label' => 'A',
            'entry_time' => '08:00:00',
            'exit_time' => '16:00:00',
            'lunch_enabled' => true,
            'lunch_start' => '12:00:00',
            'lunch_end' => '14:00:00',
            'lunch_duration_minutes' => 60,
        ]);

        foreach ([0, 1, 2, 3, 4] as $day) {
            AssistantScheduleShiftDay::create([
                'assistant_schedule_shift_id' => $shift->id,
                'day_of_week' => $day,
            ]);
        }

        $rule = ScheduleRule::create([
            'school_id' => $this->school->id,
            'activity_type_id' => $portaria->id,
            'period_start' => '08:00',
            'period_end' => '10:00',
            'min' => 1,
            'type' => 'mandatory',
            'valid_from' => '2026-01-01',
            'valid_until' => null,
            'days_of_week' => [0, 1, 2, 3, 4],
        ]);
        $rule->assistants()->attach($this->assistant->id, ['priority' => 1]);

        $this->absenceType = AbsenceType::create([
            'code' => 'DOENCA',
            'name' => 'Baixa Médica / Doença',
            'requires_document' => true,
        ]);
    }

    public function test_publishing_multiple_days_sends_single_consolidated_email_with_sgde_template(): void
    {
        Mail::fake();

        $startDate = now()->addWeek()->startOfWeek()->format('Y-m-d');
        $endDate = now()->addWeek()->startOfWeek()->addDays(4)->format('Y-m-d');

        // 1. Gerar escala para 5 dias
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/schedules/generate', [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ])
            ->assertStatus(200);

        // 2. Publicar os 5 dias de uma só vez
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/schedules/publish', [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ])
            ->assertStatus(200)
            ->assertJsonPath('status', 'published');

        // Deve ter enviado exatamente 1 email consolidado para a assistente
        Mail::assertSent(SchedulePublishedMail::class, 1);

        Mail::assertSent(SchedulePublishedMail::class, function (SchedulePublishedMail $mail) use ($startDate, $endDate) {
            $html = $mail->render();

            return $mail->hasTo($this->assistantUser->email)
                && $mail->startDate === $startDate
                && $mail->endDate === $endDate
                && $mail->isUpdate === false
                && str_contains($html, 'EB1 Quinta das Flores')
                && str_contains($html, '/staff/schedule')
                && str_contains($html, 'SGDE');
        });
    }

    public function test_republishing_after_recalculation_sends_schedule_update_email(): void
    {
        Mail::fake();

        $date = now()->addWeek()->startOfWeek()->format('Y-m-d');

        // 1. Primeira geração e publicação
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/schedules/generate', [
                'start_date' => $date,
                'end_date' => $date,
            ])
            ->assertStatus(200);

        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/schedules/publish', [
                'start_date' => $date,
                'end_date' => $date,
            ])
            ->assertStatus(200);

        // 2. Recalcular a escala já publicada (reverte para draft preservando histórico de publicação)
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/schedules/generate', [
                'start_date' => $date,
                'end_date' => $date,
                'preserve_locked' => false,
            ])
            ->assertStatus(200);

        // 3. Voltar a publicar
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/schedules/publish', [
                'start_date' => $date,
                'end_date' => $date,
            ])
            ->assertStatus(200)
            ->assertJsonPath('published_schedules_count', 1);

        Mail::assertSent(SchedulePublishedMail::class, 2);

        Mail::assertSent(SchedulePublishedMail::class, function (SchedulePublishedMail $mail) {
            return $mail->hasTo($this->assistantUser->email) && $mail->isUpdate === true;
        });
    }

    public function test_admin_registering_absence_sends_email_to_assistant(): void
    {
        Mail::fake();

        $absenceDate = now()->addDays(3)->format('Y-m-d');

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/absences', [
                'assistant_id' => $this->assistant->id,
                'absence_type_id' => $this->absenceType->id,
                'valid_from' => $absenceDate,
                'valid_until' => $absenceDate,
                'notes' => 'Ausência comunicada por telefone à secretaria.',
            ]);

        $response->assertStatus(201);

        Mail::assertSent(AbsenceRegisteredMail::class, 1);

        Mail::assertSent(AbsenceRegisteredMail::class, function (AbsenceRegisteredMail $mail) {
            $html = $mail->render();

            return $mail->hasTo($this->assistantUser->email)
                && str_contains($html, 'Baixa Médica / Doença')
                && str_contains($html, 'EB1 Quinta das Flores')
                && str_contains($html, '/staff/absences');
        });
    }

    public function test_admin_updating_absence_status_to_justified_or_unjustified_sends_email(): void
    {
        Mail::fake();

        $absence = Absence::create([
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->absenceType->id,
            'created_by_user_id' => $this->assistantUser->id,
            'valid_from' => now()->addDays(2)->format('Y-m-d'),
            'valid_until' => now()->addDays(2)->format('Y-m-d'),
            'total_days' => 1,
            'status' => 'pending',
        ]);

        // 1. Admin aprova como Justificada
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->patchJson("/api/absences/{$absence->id}/status", [
                'status' => 'justified',
                'notes' => 'Atestado médico validado com sucesso.',
            ])
            ->assertStatus(200);

        Mail::assertSent(AbsenceStatusUpdatedMail::class, function (AbsenceStatusUpdatedMail $mail) {
            $html = $mail->render();

            return $mail->hasTo($this->assistantUser->email)
                && $mail->absence->status === 'justified'
                && str_contains($html, 'Justificada')
                && str_contains($html, 'Atestado médico validado com sucesso.')
                && str_contains($html, '/staff/absences');
        });

        // 2. Admin altera para Injustificada
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->patchJson("/api/absences/{$absence->id}/status", [
                'status' => 'unjustified',
                'notes' => 'Documento fora da validade.',
            ])
            ->assertStatus(200);

        Mail::assertSent(AbsenceStatusUpdatedMail::class, function (AbsenceStatusUpdatedMail $mail) {
            $html = $mail->render();

            return $mail->hasTo($this->assistantUser->email)
                && $mail->absence->status === 'unjustified'
                && str_contains($html, 'Injustificada')
                && str_contains($html, 'Documento fora da validade.');
        });
    }

    public function test_assistant_submitting_justification_or_document_sends_email_to_admins(): void
    {
        Mail::fake();
        \Illuminate\Support\Facades\Storage::fake(config('filesystems.documents_disk'));

        $absence = Absence::create([
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->absenceType->id,
            'created_by_user_id' => $this->admin->id,
            'valid_from' => now()->addDays(2)->format('Y-m-d'),
            'valid_until' => now()->addDays(2)->format('Y-m-d'),
            'total_days' => 1,
            'status' => 'pending',
        ]);

        $file = \Illuminate\Http\UploadedFile::fake()->create('atestado_medico.pdf', 120, 'application/pdf');

        $this->actingAs($this->assistantUser)
            ->withHeader('X-School-ID', $this->school->id)
            ->post("/api/absences/{$absence->id}/document", [
                'justification' => 'Consulta hospitalar de urgência comprovada.',
                'document' => $file,
            ])
            ->assertStatus(200);

        Mail::assertSent(AbsenceJustificationSubmittedMail::class, 1);

        Mail::assertSent(AbsenceJustificationSubmittedMail::class, function (AbsenceJustificationSubmittedMail $mail) {
            $html = $mail->render();

            return $mail->hasTo($this->admin->email)
                && str_contains($html, 'Mariana Costa (AO-901)')
                && str_contains($html, 'atestado_medico.pdf')
                && str_contains($html, 'Consulta hospitalar de urgência comprovada.')
                && str_contains($html, '/absences');
        });
    }

    public function test_expiring_criminal_records_command_sends_alerts_only_for_30_7_and_0_day_milestones(): void
    {
        Mail::fake();

        $staffRole = Role::where('slug', 'staff')->firstOrFail();

        // Assistente 1: expira em 30 dias (marco)
        $this->assistant->update([
            'criminal_record_expiry' => now()->addDays(30)->format('Y-m-d'),
        ]);

        // Assistente 2: expira em 7 dias (marco)
        $user7 = User::create([
            'first_name' => 'Rui',
            'last_name' => 'Almeida',
            'email' => 'rui.almeida@escola.pt',
            'password' => Hash::make('password123'),
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);
        Assistant::create([
            'user_id' => $user7->id,
            'school_id' => $this->school->id,
            'internal_number' => 'AO-902',
            'weekly_hours' => 35,
            'daily_hours' => 7,
            'criminal_record_expiry' => now()->addDays(7)->format('Y-m-d'),
        ]);

        // Assistente 3: expira hoje (0 dias - marco crítico)
        $user0 = User::create([
            'first_name' => 'Sofia',
            'last_name' => 'Martins',
            'email' => 'sofia.martins@escola.pt',
            'password' => Hash::make('password123'),
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);
        Assistant::create([
            'user_id' => $user0->id,
            'school_id' => $this->school->id,
            'internal_number' => 'AO-903',
            'weekly_hours' => 35,
            'daily_hours' => 7,
            'criminal_record_expiry' => now()->format('Y-m-d'),
        ]);

        // Assistente 4: expira em 15 dias (NÃO é marco -> não deve enviar email)
        $user15 = User::create([
            'first_name' => 'Pedro',
            'last_name' => 'Gomes',
            'email' => 'pedro.gomes@escola.pt',
            'password' => Hash::make('password123'),
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);
        Assistant::create([
            'user_id' => $user15->id,
            'school_id' => $this->school->id,
            'internal_number' => 'AO-904',
            'weekly_hours' => 35,
            'daily_hours' => 7,
            'criminal_record_expiry' => now()->addDays(15)->format('Y-m-d'),
        ]);

        $this->artisan('app:notify-expiring-criminal-records')
            ->assertExitCode(0);

        // Deve enviar exatamente 3 alertas (30, 7 e 0 dias) e ignorar o de 15 dias
        Mail::assertSent(CriminalRecordExpiryAlertMail::class, 3);

        Mail::assertSent(CriminalRecordExpiryAlertMail::class, function (CriminalRecordExpiryAlertMail $mail) {
            return $mail->hasTo($this->admin->email)
                && $mail->daysRemaining === 0
                && str_contains($mail->render(), 'Sofia Martins (AO-903)')
                && str_contains($mail->render(), '/assistants');
        });
    }
}
