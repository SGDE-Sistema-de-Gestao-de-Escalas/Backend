<?php

namespace Tests\Feature;

use App\Models\Absences\Absence;
use App\Models\Assistants\Assistant;
use App\Models\Assistants\AssistantException;
use App\Models\Assistants\AssistantTemporaryAssignments;
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
use App\Models\System\Notification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private User $admin2;
    private Role $staffRole;
    private ActivityType $portaria;
    private CarbonImmutable $monday;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrador']);
        $this->staffRole = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Assistente Operacional']);

        $this->school = School::create(['name' => 'Escola Básica Central', 'acronym' => 'EBC', 'active' => true]);
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true, 'first_name' => 'Admin', 'last_name' => 'Um']);
        $this->admin2 = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true, 'first_name' => 'Admin', 'last_name' => 'Dois']);

        $rule = SchoolOperatingRule::create(['school_id' => $this->school->id, 'valid_from' => '2026-01-01']);
        for ($d = 0; $d <= 4; $d++) {
            SchoolOperatingRuleDay::create([
                'school_operating_rule_id' => $rule->id, 'day_of_week' => $d,
                'opening_time' => '08:00:00', 'closing_time' => '18:00:00',
                'lunch_start' => '12:00:00', 'lunch_end' => '14:30:00', 'lunch_duration_minutes' => 60,
            ]);
        }

        $this->portaria = ActivityType::create(['school_id' => $this->school->id, 'name' => 'Portaria', 'color' => '#EF4444', 'active' => true, 'is_fallback' => false]);
        ActivityType::create(['school_id' => $this->school->id, 'name' => 'Apoio Escola', 'color' => '#64748B', 'active' => true, 'is_fallback' => true]);

        // Segunda-feira daqui a 2 semanas (sempre no futuro, para o checkImpact e o publish)
        $this->monday = CarbonImmutable::today()->addWeeks(2)->startOfWeek();
    }

    // ------------------------------------------------------------------ helpers

    private function makeAssistant(string $name, ?string $profileUntil = null, ?School $school = null): Assistant
    {
        $user = User::factory()->create(['first_name' => $name, 'last_name' => 'Op', 'role_id' => $this->staffRole->id, 'is_active' => true]);
        $assistant = Assistant::create([
            'school_id' => ($school ?? $this->school)->id, 'user_id' => $user->id,
            'internal_number' => strtoupper(Str::random(5)), 'available_for_transfer' => false,
        ]);
        $profile = AssistantScheduleProfile::create([
            'assistant_id' => $assistant->id, 'type' => 'fixo', 'valid_from' => '2026-01-01', 'valid_until' => $profileUntil,
        ]);
        $shift = AssistantScheduleShift::create([
            'assistant_schedule_profile_id' => $profile->id, 'shift_label' => 'A',
            'entry_time' => '08:00:00', 'exit_time' => '16:00:00', 'lunch_enabled' => true,
            'lunch_start' => '12:00:00', 'lunch_end' => '14:00:00', 'lunch_duration_minutes' => 60,
        ]);
        foreach ([0, 1, 2, 3, 4] as $d) {
            AssistantScheduleShiftDay::create(['assistant_schedule_shift_id' => $shift->id, 'day_of_week' => $d]);
        }

        return $assistant->load('user');
    }

    private function absenceTypeId(): string
    {
        $id = DB::table('absence_types')->value('id');
        if (! $id) {
            $id = (string) Str::uuid();
            DB::table('absence_types')->insert(['id' => $id, 'name' => 'Doença']);
        }

        return $id;
    }

    private function as(User $user)
    {
        return $this->actingAs($user)->withHeader('X-School-ID', $this->school->id);
    }

    private function notify(User $user, array $attrs = []): Notification
    {
        return Notification::create(array_merge([
            'user_id' => $user->id, 'school_id' => $this->school->id,
            'type' => 'info', 'event' => 'schedule.generated', 'title' => 'T', 'body' => 'B',
        ], $attrs));
    }

    private function generate(CarbonImmutable $start, ?CarbonImmutable $end = null)
    {
        return $this->as($this->admin)->postJson('/api/schedules/generate', [
            'start_date' => $start->toDateString(), 'end_date' => ($end ?? $start)->toDateString(),
        ]);
    }

    // ------------------------------------------------------------------ API

    public function test_lists_only_own_notifications_with_filters_and_unread_counts(): void
    {
        $this->notify($this->admin, ['type' => 'alert', 'event' => 'schedule.generation_deficit', 'title' => 'A1']);
        $this->notify($this->admin, ['type' => 'absence', 'event' => 'absence.registered', 'title' => 'F1', 'read_at' => now()]);
        $this->notify($this->admin, ['type' => 'absence', 'event' => 'absence.registered', 'title' => 'F2']);
        $this->notify($this->admin, ['type' => 'info', 'school_id' => null, 'title' => 'Global']);
        $other = School::create(['name' => 'Outra', 'acronym' => 'OUT', 'active' => true]);
        $this->notify($this->admin, ['school_id' => $other->id, 'title' => 'Outra escola']);
        $this->notify($this->admin2, ['title' => 'Do outro admin']);

        $all = $this->as($this->admin)->getJson('/api/notifications');
        $all->assertOk()->assertJsonCount(4, 'data'); // 3 desta escola + 1 global; sem a de outra escola nem a do admin2
        $all->assertJsonPath('meta.unread_counts.all', 3)
            ->assertJsonPath('meta.unread_counts.alert', 1)
            ->assertJsonPath('meta.unread_counts.absence', 1)
            ->assertJsonPath('meta.unread_counts.expiry', 0)
            ->assertJsonPath('meta.unread_counts.info', 1);

        $this->as($this->admin)->getJson('/api/notifications?type=absence')->assertOk()->assertJsonCount(2, 'data');
        $this->as($this->admin)->getJson('/api/notifications?type=absence&unread=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'F2');
        $this->as($this->admin)->getJson('/api/notifications?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 4);
    }

    public function test_mark_read_mark_all_read_and_delete_are_personal(): void
    {
        $n1 = $this->notify($this->admin, ['type' => 'alert']);
        $n2 = $this->notify($this->admin, ['type' => 'absence']);
        $foreign = $this->notify($this->admin2);

        $this->as($this->admin)->patchJson("/api/notifications/{$n1->id}/read")->assertOk()->assertJsonPath('read', true);
        $this->assertNotNull($n1->fresh()->read_at);

        $this->as($this->admin)->patchJson("/api/notifications/{$foreign->id}/read")->assertForbidden();
        $this->as($this->admin)->getJson("/api/notifications/{$foreign->id}")->assertForbidden();
        $this->as($this->admin)->deleteJson("/api/notifications/{$foreign->id}")->assertForbidden();
        $this->assertNull($foreign->fresh()->read_at);

        $this->as($this->admin)->postJson('/api/notifications/read-all', ['type' => 'info'])->assertOk()->assertJsonPath('updated', 0);
        $this->as($this->admin)->postJson('/api/notifications/read-all')->assertOk()->assertJsonPath('updated', 1)->assertJsonPath('unread_counts.all', 0);
        $this->assertNull($foreign->fresh()->read_at, 'read-all não pode tocar nas notificações de outros utilizadores');

        $this->as($this->admin)->deleteJson("/api/notifications/{$n2->id}")->assertOk();
        $this->assertDatabaseMissing('notifications', ['id' => $n2->id]);
    }

    public function test_requires_authentication_and_anonymized_type_is_exposed_as_info(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();

        $this->notify($this->admin, ['type' => 'anonymized', 'title' => 'Notificação anonimizada', 'body' => null, 'data' => null]);
        $this->as($this->admin)->getJson('/api/notifications')->assertOk()->assertJsonPath('data.0.type', 'info');
    }

    // ------------------------------------------------------------------ eventos

    public function test_assistants_cannot_register_absences_and_nobody_is_notified_when_the_admin_does(): void
    {
        $assistant = $this->makeAssistant('Maria');
        $payload = [
            'assistant_id' => $assistant->id, 'absence_type_id' => $this->absenceTypeId(),
            'valid_from' => $this->monday->toDateString(), 'valid_until' => $this->monday->addDay()->toDateString(),
        ];

        // O assistente já não regista ausências (mudança de regras): 403 e nada é notificado.
        $this->as($assistant->user)->postJson('/api/absences', $payload)->assertForbidden();
        $this->assertSame(0, Absence::count());
        $this->assertSame(0, Notification::where('event', 'absence.registered')->count());

        // registada por um administrador -> ninguém é notificado
        $this->as($this->admin)->postJson('/api/absences', $payload)->assertCreated();
        $this->assertSame(0, Notification::where('event', 'absence.registered')->count());
    }

    public function test_absence_document_upload_by_assistant_notifies_admins(): void
    {
        $assistant = $this->makeAssistant('Rui');
        $absence = Absence::create([
            'assistant_id' => $assistant->id, 'absence_type_id' => $this->absenceTypeId(),
            'valid_from' => $this->monday->toDateString(), 'valid_until' => $this->monday->toDateString(), 'total_days' => 1,
        ]);

        \Illuminate\Support\Facades\Storage::fake(config('filesystems.documents_disk'));
        $file = \Illuminate\Http\UploadedFile::fake()->create('comprovativo.pdf', 50, 'application/pdf');

        $this->as($assistant->user)->post("/api/absences/{$absence->id}/document", ['document' => $file], ['Accept' => 'application/json'])->assertSuccessful();

        $this->assertSame(1, Notification::where('user_id', $this->admin->id)->where('event', 'absence.document_uploaded')->count());
    }

    public function test_absence_that_breaks_a_generated_schedule_raises_recalculation_alert(): void
    {
        $assistant = $this->makeAssistant('Ana');
        $rule = ScheduleRule::create([
            'school_id' => $this->school->id, 'activity_type_id' => $this->portaria->id,
            'period_start' => '08:00', 'period_end' => '10:00', 'min' => 1, 'type' => 'mandatory',
            'valid_from' => '2026-01-01', 'valid_until' => null, 'days_of_week' => [0],
        ]);
        $rule->assistants()->attach($assistant->id, ['priority' => 1]);

        $this->generate($this->monday)->assertOk();
        Notification::query()->delete();

        $this->as($this->admin)->postJson('/api/absences', [
            'assistant_id' => $assistant->id, 'absence_type_id' => $this->absenceTypeId(),
            'valid_from' => $this->monday->toDateString(), 'valid_until' => $this->monday->toDateString(),
        ])->assertCreated();

        foreach ([$this->admin, $this->admin2] as $admin) {
            $n = Notification::where('user_id', $admin->id)->where('event', 'schedule.recalculation_required')->first();
            $this->assertNotNull($n);
            $this->assertSame('alert', $n->type);
            $this->assertContains($this->monday->toDateString(), $n->data['affected_dates']);
        }
    }

    public function test_generation_with_deficit_alerts_everyone_and_clean_generation_informs_only_other_admins(): void
    {
        $assistant = $this->makeAssistant('Ana');

        // 1) sem conflitos -> info só para o outro admin (quem gerou já viu o resultado)
        $this->generate($this->monday)->assertOk()->assertJsonPath('resolution_status', 'complete_validated');
        $this->assertSame(0, Notification::where('user_id', $this->admin->id)->count());
        $info = Notification::where('user_id', $this->admin2->id)->first();
        $this->assertSame('info', $info->type);
        $this->assertSame('schedule.generated', $info->event);

        // 2) com défice: a única pessoa da regra está ausente -> alerta para todos
        Notification::query()->delete();
        $rule = ScheduleRule::create([
            'school_id' => $this->school->id, 'activity_type_id' => $this->portaria->id,
            'period_start' => '08:00', 'period_end' => '10:00', 'min' => 2, 'type' => 'mandatory',
            'valid_from' => '2026-01-01', 'valid_until' => null, 'days_of_week' => [0],
        ]);
        $rule->assistants()->attach($assistant->id, ['priority' => 1]);
        $this->generate($this->monday)->assertOk()->assertJsonPath('resolution_status', 'draft_with_deficit');

        foreach ([$this->admin, $this->admin2] as $admin) {
            $n = Notification::where('user_id', $admin->id)->where('event', 'schedule.generation_deficit')->first();
            $this->assertNotNull($n);
            $this->assertSame('alert', $n->type);
            $this->assertGreaterThan(0, $n->data['conflicts_count']);
        }
    }

    public function test_assistant_without_profile_alert_is_sent_once_per_day(): void
    {
        $this->makeAssistant('Com perfil');
        $noProfile = $this->makeAssistant('Sem perfil');
        AssistantScheduleProfile::where('assistant_id', $noProfile->id)->get()->each(function ($p) {
            $p->shifts()->each(fn ($s) => $s->shiftDays()->delete());
            $p->shifts()->delete();
            $p->delete();
        });

        $this->generate($this->monday)->assertOk();
        $this->generate($this->monday)->assertOk(); // 2.ª geração no mesmo dia: não duplica

        $this->assertSame(1, Notification::where('user_id', $this->admin->id)->where('event', 'assistant.missing_profile')->count());
        $this->assertStringContainsString('Sem perfil', Notification::where('event', 'assistant.missing_profile')->value('body'));
    }

    public function test_publishing_informs_other_admins_only(): void
    {
        $this->makeAssistant('Ana');
        $this->generate($this->monday)->assertOk();
        Notification::query()->delete();

        $this->as($this->admin)->postJson('/api/schedules/publish', [
            'start_date' => $this->monday->toDateString(), 'end_date' => $this->monday->toDateString(),
        ])->assertOk()->assertJsonPath('published_schedules_count', 1);

        $this->assertSame(0, Notification::where('user_id', $this->admin->id)->count());
        $n = Notification::where('user_id', $this->admin2->id)->first();
        $this->assertSame('schedule.published', $n->event);
        $this->assertSame('info', $n->type);
    }

    public function test_transfer_assigned_through_generation_informs_other_admins(): void
    {
        $this->makeAssistant('Local');
        $other = School::create(['name' => 'Escola Externa', 'acronym' => 'EXT', 'active' => true]);
        $external = $this->makeAssistant('Externo', null, $other);
        $external->update(['available_for_transfer' => true]);

        $this->generate($this->monday)->assertOk(); // sem transfers
        Notification::query()->delete();

        $this->as($this->admin)->postJson('/api/schedules/generate', [
            'start_date' => $this->monday->toDateString(), 'end_date' => $this->monday->toDateString(),
            'transfers' => [['assistant_id' => $external->id, 'date' => $this->monday->toDateString()]],
        ])->assertOk();

        $n = Notification::where('user_id', $this->admin2->id)->where('event', 'transfer.assigned')->first();
        $this->assertNotNull($n);
        $this->assertSame($this->school->id, $n->school_id);
        $this->assertStringContainsString('Externo Op', $n->body);
    }

    public function test_deactivation_request_alerts_all_admins(): void
    {
        Mail::fake();
        $assistant = $this->makeAssistant('Joana');

        $this->as($assistant->user)->postJson('/api/privacy/request-deactivation', ['reason' => 'Mudança de emprego'])->assertOk();

        foreach ([$this->admin, $this->admin2] as $admin) {
            $n = Notification::where('user_id', $admin->id)->where('event', 'privacy.deactivation_requested')->first();
            $this->assertNotNull($n);
            $this->assertSame('alert', $n->type);
            $this->assertStringContainsString('Joana Op', $n->body);
            $this->assertStringContainsString('Mudança de emprego', $n->body);
        }
    }

    // ------------------------------------------------------------------ comando diário

    public function test_scheduled_command_creates_expiry_and_pending_notifications_without_duplicates(): void
    {
        $today = CarbonImmutable::today();

        $a1 = $this->makeAssistant('Exc');
        AssistantException::create([
            'assistant_id' => $a1->id, 'type' => 'unavailable',
            'valid_from' => $today->subDays(10)->toDateString(), 'valid_until' => $today->addDays(5)->toDateString(),
        ]);
        AssistantException::create([ // fora da janela de 7 dias: não notifica
            'assistant_id' => $a1->id, 'type' => 'unavailable',
            'valid_from' => $today->toDateString(), 'valid_until' => $today->addDays(40)->toDateString(),
        ]);

        $closure = \App\Models\Schools\SchoolOperatingException::create([ // exceção de funcionamento (Motor) a terminar
            'school_id' => $this->school->id, 'name' => 'Obras', 'is_closed' => true,
            'valid_from' => $today->subDays(3)->toDateString(), 'valid_until' => $today->addDays(4)->toDateString(),
        ]);
        \App\Models\Schools\SchoolOperatingException::create([ // fora da janela de 7 dias: não notifica
            'school_id' => $this->school->id, 'name' => 'Natal', 'is_closed' => true,
            'valid_from' => $today->addDays(20)->toDateString(), 'valid_until' => $today->addDays(30)->toDateString(),
        ]);

        $this->makeAssistant('Perfil', $today->addDays(10)->toDateString()); // perfil a terminar, sem seguinte
        $withNext = $this->makeAssistant('PerfilComSeguinte', $today->addDays(10)->toDateString());
        AssistantScheduleProfile::create(['assistant_id' => $withNext->id, 'type' => 'fixo', 'valid_from' => $today->addDays(11)->toDateString(), 'valid_until' => null]);

        $rule = ScheduleRule::create([
            'school_id' => $this->school->id, 'activity_type_id' => $this->portaria->id,
            'period_start' => '08:00', 'period_end' => '10:00', 'min' => 1, 'type' => 'optional',
            'valid_from' => '2026-01-01', 'valid_until' => $today->addDays(12)->toDateString(), 'days_of_week' => [0],
        ]);

        $other = School::create(['name' => 'Destino', 'acronym' => 'DST', 'active' => true]);
        AssistantTemporaryAssignments::create([
            'assistant_id' => $a1->id, 'origin_school_id' => $this->school->id, 'destination_school_id' => $other->id,
            'created_by_user_id' => $this->admin->id, 'validated_by_user_id' => $this->admin->id,
            'valid_from' => $today->subDay()->toDateString(), 'valid_until' => $today->addDays(2)->toDateString(),
        ]);

        $pending = Absence::create([
            'assistant_id' => $a1->id, 'absence_type_id' => $this->absenceTypeId(),
            'valid_from' => $today->subDays(6)->toDateString(), 'valid_until' => $today->subDays(6)->toDateString(), 'total_days' => 1,
        ]);
        $pending->forceFill(['status' => 'pending', 'created_at' => $today->subDays(5)])->save();

        $this->artisan('notifications:check-expirations')->assertSuccessful();

        $byEvent = fn () => Notification::where('user_id', $this->admin->id)->pluck('event')->countBy()->sortKeys()->all();
        $expected = [
            'absence.pending_review' => 1,
            'exception.expiring' => 1,
            'operating_exception.expiring' => 1,
            'profile.expiring' => 1,
            'rule.expiring' => 1,
            'transfer.ending' => 2, // escola de origem + escola de destino
        ];
        $this->assertEquals($expected, $byEvent());
        $this->assertEquals(
            ['exception.expiring' => 'expiry', 'operating_exception.expiring' => 'expiry', 'profile.expiring' => 'expiry', 'rule.expiring' => 'expiry', 'transfer.ending' => 'expiry', 'absence.pending_review' => 'absence'],
            Notification::where('user_id', $this->admin->id)->pluck('type', 'event')->all()
        );

        // 2.ª execução no mesmo dia: não duplica
        $this->artisan('notifications:check-expirations')->assertSuccessful();
        $this->assertEquals($expected, $byEvent());
        $this->assertSame(array_sum($expected), Notification::where('user_id', $this->admin2->id)->count());
    }

    public function test_a_failure_while_notifying_never_breaks_the_main_operation(): void
    {
        // Sem a tabela de notificações, notificar falha; o envio do documento tem de continuar a funcionar.
        $assistant = $this->makeAssistant('Maria');
        $absence = Absence::create([
            'assistant_id' => $assistant->id, 'absence_type_id' => $this->absenceTypeId(),
            'valid_from' => $this->monday->toDateString(), 'valid_until' => $this->monday->toDateString(), 'total_days' => 1,
        ]);
        \Illuminate\Support\Facades\Storage::fake(config('filesystems.documents_disk'));
        \Illuminate\Support\Facades\Schema::drop('notifications');

        $file = \Illuminate\Http\UploadedFile::fake()->create('comprovativo.pdf', 50, 'application/pdf');

        $this->as($assistant->user)
            ->post("/api/absences/{$absence->id}/document", ['document' => $file], ['Accept' => 'application/json'])
            ->assertSuccessful();
    }
}
