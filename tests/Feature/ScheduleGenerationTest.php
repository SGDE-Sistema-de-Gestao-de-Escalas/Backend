<?php

namespace Tests\Feature;

use App\Models\Absences\Absence;
use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schedules\AssistantScheduleProfile;
use App\Models\Schedules\AssistantScheduleShift;
use App\Models\Schedules\AssistantScheduleShiftDay;
use App\Models\Schedules\Schedule;
use App\Models\Schedules\ScheduleEntry;
use App\Models\Schedules\ScheduleRule;
use App\Models\Schools\School;
use App\Models\Schools\SchoolOperatingRule;
use App\Models\Schools\SchoolOperatingRuleDay;
use App\Models\System\ActivityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScheduleGenerationTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private ActivityType $portaria;
    private ActivityType $vigilancia;
    private ActivityType $biblioteca;
    private ActivityType $apoioEscola;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrador']);
        Role::firstOrCreate(['slug' => 'assistant'], ['name' => 'Assistente Operacional']);

        $this->school = School::create([
            'name' => 'Escola Básica Central',
            'acronym' => 'EBC',
            'active' => true,
        ]);

        $this->admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        // Configurar horário de funcionamento da escola (0 = Segunda a 4 = Sexta: 08:00 - 18:00)
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

        $this->portaria = ActivityType::create([
            'school_id' => $this->school->id,
            'name' => 'Portaria',
            'color' => '#EF4444',
            'active' => true,
            'is_fallback' => false,
        ]);

        $this->vigilancia = ActivityType::create([
            'school_id' => $this->school->id,
            'name' => 'Vigilância Recreio',
            'color' => '#F59E0B',
            'active' => true,
            'is_fallback' => false,
        ]);

        $this->biblioteca = ActivityType::create([
            'school_id' => $this->school->id,
            'name' => 'Biblioteca',
            'color' => '#3B82F6',
            'active' => true,
            'is_fallback' => false,
        ]);

        $this->apoioEscola = ActivityType::create([
            'school_id' => $this->school->id,
            'name' => 'Apoio Escola',
            'color' => '#64748B',
            'active' => true,
            'is_fallback' => true,
        ]);
    }

    private function createAssistantWithShift(
        string $name,
        string $internalNumber,
        string $entryTime = '08:00:00',
        string $exitTime = '16:00:00',
        bool $lunchEnabled = true,
        ?string $lunchStart = '12:00:00',
        ?string $lunchEnd = '14:00:00',
        ?int $lunchDuration = 60,
        array $days = [0, 1, 2, 3, 4],
        ?School $school = null,
        bool $availableForTransfer = false
    ): Assistant {
        $assistantRole = Role::where('slug', 'assistant')->first();
        $user = User::factory()->create([
            'first_name' => $name,
            'last_name' => 'Operacional',
            'role_id' => $assistantRole->id,
            'is_active' => true,
        ]);

        $targetSchool = $school ?? $this->school;

        $assistant = Assistant::create([
            'school_id' => $targetSchool->id,
            'user_id' => $user->id,
            'internal_number' => $internalNumber,
            'available_for_transfer' => $availableForTransfer,
        ]);

        $profile = AssistantScheduleProfile::create([
            'assistant_id' => $assistant->id,
            'type' => 'fixo',
            'rotation_period' => null,
            'starts_with' => null,
            'valid_from' => '2026-01-01',
            'valid_until' => null,
        ]);

        $shift = AssistantScheduleShift::create([
            'assistant_schedule_profile_id' => $profile->id,
            'shift_label' => 'A',
            'entry_time' => $entryTime,
            'exit_time' => $exitTime,
            'lunch_enabled' => $lunchEnabled,
            'lunch_start' => $lunchStart,
            'lunch_end' => $lunchEnd,
            'lunch_duration_minutes' => $lunchDuration,
        ]);

        foreach ($days as $dayOfWeek) {
            AssistantScheduleShiftDay::create([
                'assistant_schedule_shift_id' => $shift->id,
                'day_of_week' => $dayOfWeek,
            ]);
        }

        return $assistant;
    }

    public function test_auto_seeds_fallback_activity_when_missing_and_blocks_invalid_lunch_window(): void
    {
        $this->apoioEscola->delete();

        // Criar assistente com janela de almoço inválida (menor que a duração) para testar bloqueio pré-validação
        $this->createAssistantWithShift(
            name: 'Ana Silva',
            internalNumber: 'A001',
            entryTime: '08:00:00',
            exitTime: '16:00:00',
            lunchEnabled: true,
            lunchStart: '12:00:00',
            lunchEnd: '12:30:00',
            lunchDuration: 60
        );

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/schedules/generate', [
                'start_date' => '2026-11-02',
                'end_date' => '2026-11-02',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['pre_validation']);

        // E verificar que o seeder recriou automaticamente a atividade fallback "Apoio Escola"
        $this->assertDatabaseHas('activity_types', [
            'school_id' => $this->school->id,
            'is_fallback' => true,
        ]);
    }

    public function test_generates_complete_schedule_with_mandatory_optional_lunch_and_fallback(): void
    {
        $assistant = $this->createAssistantWithShift(
            name: 'Ana Silva',
            internalNumber: 'A001',
            entryTime: '08:00:00',
            exitTime: '16:00:00',
            lunchEnabled: true,
            lunchStart: '12:00:00',
            lunchEnd: '13:00:00',
            lunchDuration: 60,
            days: [0] // Segunda-feira
        );

        // Regra obrigatória: Portaria 08:00 - 10:00
        $mandatoryRule = ScheduleRule::create([
            'school_id' => $this->school->id,
            'activity_type_id' => $this->portaria->id,
            'period_start' => '08:00',
            'period_end' => '10:00',
            'min' => 1,
            'type' => 'mandatory',
            'valid_from' => '2026-01-01',
            'valid_until' => null,
            'days_of_week' => [0],
        ]);
        $mandatoryRule->assistants()->attach($assistant->id, ['priority' => 1]);

        // Regra opcional: Biblioteca 10:00 - 12:00
        $optionalRule = ScheduleRule::create([
            'school_id' => $this->school->id,
            'activity_type_id' => $this->biblioteca->id,
            'period_start' => '10:00',
            'period_end' => '12:00',
            'min' => 1,
            'type' => 'optional',
            'valid_from' => '2026-01-01',
            'valid_until' => null,
            'days_of_week' => [0],
        ]);
        $optionalRule->assistants()->attach($assistant->id, ['priority' => 1]);

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/schedules/generate', [
                'start_date' => '2026-11-02', // Segunda-feira (weekday = 0)
                'end_date' => '2026-11-02',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('resolution_status', 'complete_validated')
            ->assertJsonCount(0, 'conflicts')
            ->assertJsonPath('hours_accounting.0.assistant_id', $assistant->id)
            ->assertJsonPath('hours_accounting.0.contracted_minutes', 420)
            ->assertJsonPath('hours_accounting.0.assigned_specific_minutes', 240)
            ->assertJsonPath('hours_accounting.0.assigned_fallback_minutes', 180)
            ->assertJsonPath('hours_accounting.0.lunch_minutes', 60)
            ->assertJsonPath('validations.post.all_passed', true);

        // Verificar blocos persistidos na BD:
        // 08:00 - 10:00: Portaria (mandatory)
        // 10:00 - 12:00: Biblioteca (optional)
        // [12:00 - 13:00: pausa de almoço posicionada em lunch_blocks]
        // 13:00 - 16:00: Apoio Escola (fallback)
        $entries = ScheduleEntry::where('date', '2026-11-02')
            ->orderBy('start_time')
            ->get();

        $this->assertCount(3, $entries);

        $this->assertSame('08:00', substr($entries[0]->start_time, 0, 5));
        $this->assertSame('10:00', substr($entries[0]->end_time, 0, 5));
        $this->assertSame($this->portaria->id, $entries[0]->activity_type_id);
        $this->assertSame($mandatoryRule->id, $entries[0]->schedule_rule_id);

        $this->assertSame('10:00', substr($entries[1]->start_time, 0, 5));
        $this->assertSame('12:00', substr($entries[1]->end_time, 0, 5));
        $this->assertSame($this->biblioteca->id, $entries[1]->activity_type_id);
        $this->assertSame($optionalRule->id, $entries[1]->schedule_rule_id);

        $this->assertSame('13:00', substr($entries[2]->start_time, 0, 5));
        $this->assertSame('16:00', substr($entries[2]->end_time, 0, 5));
        $this->assertSame($this->apoioEscola->id, $entries[2]->activity_type_id);
        $this->assertNull($entries[2]->schedule_rule_id);

        // Testar listagem e publicação
        $listResponse = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->getJson('/api/schedules?start_date=2026-11-02&end_date=2026-11-02');
        $listResponse->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $publishResponse = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/schedules/publish', [
                'start_date' => '2026-11-02',
                'end_date' => '2026-11-02',
            ]);
        $publishResponse->assertStatus(200)
            ->assertJsonPath('published_schedules_count', 1);

        $this->assertSame('published', Schedule::first()->status);
    }

    public function test_round_b_partitions_mandatory_rule_across_two_assistants(): void
    {
        // Assistente 1 trabalha das 08:00 às 12:00 (sem almoço)
        $a1 = $this->createAssistantWithShift(
            name: 'Manhã',
            internalNumber: 'A001',
            entryTime: '08:00:00',
            exitTime: '12:00:00',
            lunchEnabled: false,
            lunchStart: null,
            lunchEnd: null,
            lunchDuration: null,
            days: [0]
        );

        // Assistente 2 trabalha das 12:00 às 16:00 (sem almoço)
        $a2 = $this->createAssistantWithShift(
            name: 'Tarde',
            internalNumber: 'A002',
            entryTime: '12:00:00',
            exitTime: '16:00:00',
            lunchEnabled: false,
            lunchStart: null,
            lunchEnd: null,
            lunchDuration: null,
            days: [0]
        );

        // Regra obrigatória das 10:00 às 14:00 (4h contínuas -> nenhum assistente consegue fazer sozinho na Ronda A)
        $rule = ScheduleRule::create([
            'school_id' => $this->school->id,
            'activity_type_id' => $this->portaria->id,
            'period_start' => '10:00',
            'period_end' => '14:00',
            'min' => 1,
            'type' => 'mandatory',
            'valid_from' => '2026-01-01',
            'days_of_week' => [0],
        ]);
        $rule->assistants()->attach($a1->id, ['priority' => 1]);
        $rule->assistants()->attach($a2->id, ['priority' => 2]);

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/schedules/generate', [
                'start_date' => '2026-11-02',
                'end_date' => '2026-11-02',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('resolution_status', 'complete_validated')
            ->assertJsonCount(0, 'conflicts')
            ->assertJsonFragment(['type' => 'partition_sub_block']);

        // Verificar que os sub-blocos foram gravados em 10:00-12:00 (A1) e 12:00-14:00 (A2)
        $portariaEntries = ScheduleEntry::where('date', '2026-11-02')
            ->where('schedule_rule_id', $rule->id)
            ->orderBy('start_time')
            ->get();

        $this->assertCount(2, $portariaEntries);
        $this->assertSame('10:00', substr($portariaEntries[0]->start_time, 0, 5));
        $this->assertSame('12:00', substr($portariaEntries[0]->end_time, 0, 5));
        $this->assertSame('12:00', substr($portariaEntries[1]->start_time, 0, 5));
        $this->assertSame('14:00', substr($portariaEntries[1]->end_time, 0, 5));
    }

    public function test_round_c1_atomic_mandatory_swap_resolves_contention(): void
    {
        $a1 = $this->createAssistantWithShift(
            name: 'Polivalente',
            internalNumber: 'A001',
            entryTime: '08:00:00',
            exitTime: '12:00:00',
            lunchEnabled: false,
            lunchStart: null,
            lunchEnd: null,
            lunchDuration: null,
            days: [0]
        );

        $a2 = $this->createAssistantWithShift(
            name: 'Colega Disponível',
            internalNumber: 'A002',
            entryTime: '08:00:00',
            exitTime: '12:00:00',
            lunchEnabled: false,
            lunchStart: null,
            lunchEnd: null,
            lunchDuration: null,
            days: [0]
        );

        // Regra 1: Portaria 08:00-10:00 (apenas A1 na lista -> grau de restrição 0, corre primeiro se id menor)
        $rule1 = ScheduleRule::create([
            'school_id' => $this->school->id,
            'activity_type_id' => $this->portaria->id,
            'period_start' => '08:00',
            'period_end' => '10:00',
            'min' => 1,
            'type' => 'mandatory',
            'valid_from' => '2026-01-01',
            'days_of_week' => [0],
        ]);
        $rule1->assistants()->attach($a1->id, ['priority' => 1]);

        // Regra 2: Vigilância 08:00-10:00 (também apenas A1 na lista -> quando corre, A1 já está na Regra 1;
        // C1 troca A1 para Regra 2 e coloca A2 na Regra 1, ou C2 usa A2 diretamente)
        $rule2 = ScheduleRule::create([
            'school_id' => $this->school->id,
            'activity_type_id' => $this->vigilancia->id,
            'period_start' => '08:00',
            'period_end' => '10:00',
            'min' => 1,
            'type' => 'mandatory',
            'valid_from' => '2026-01-01',
            'days_of_week' => [0],
        ]);
        $rule2->assistants()->attach($a1->id, ['priority' => 1]);

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/schedules/generate', [
                'start_date' => '2026-11-02',
                'end_date' => '2026-11-02',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('resolution_status', 'complete_validated')
            ->assertJsonCount(0, 'conflicts')
            ->assertJsonFragment(['type' => 'atomic_mandatory_swap']);
    }

    public function test_round_c2_universal_internal_fallback_covers_absent_specialist_and_tracks_replacement(): void
    {
        // A1 está na fila da Portaria mas está ausente na segunda-feira 2026-11-02
        $a1 = $this->createAssistantWithShift(
            name: 'Especialista Portaria',
            internalNumber: 'A001',
            entryTime: '08:00:00',
            exitTime: '12:00:00',
            lunchEnabled: false,
            lunchStart: null,
            lunchEnd: null,
            lunchDuration: null,
            days: [0]
        );

        $absenceTypeId = (string) Str::uuid();
        DB::table('absence_types')->insert(['id' => $absenceTypeId, 'name' => 'Doença']);
        $absence = Absence::create([
            'assistant_id' => $a1->id,
            'absence_type_id' => $absenceTypeId,
            'valid_from' => '2026-11-02',
            'valid_until' => '2026-11-02',
            'total_days' => 1,
        ]);
        $absence->status = 'justified';
        $absence->save();

        // A2 está presente na escola mas NÃO faz parte da lista de preferências da Portaria
        $a2 = $this->createAssistantWithShift(
            name: 'Assistente Geral',
            internalNumber: 'A002',
            entryTime: '08:00:00',
            exitTime: '12:00:00',
            lunchEnabled: false,
            lunchStart: null,
            lunchEnd: null,
            lunchDuration: null,
            days: [0]
        );

        $rule = ScheduleRule::create([
            'school_id' => $this->school->id,
            'activity_type_id' => $this->portaria->id,
            'period_start' => '08:00',
            'period_end' => '10:00',
            'min' => 1,
            'type' => 'mandatory',
            'valid_from' => '2026-01-01',
            'days_of_week' => [0],
        ]);
        $rule->assistants()->attach($a1->id, ['priority' => 1]);

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/schedules/generate', [
                'start_date' => '2026-11-02',
                'end_date' => '2026-11-02',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('resolution_status', 'complete_validated')
            ->assertJsonCount(0, 'conflicts')
            ->assertJsonFragment(['type' => 'internal_universal_fallback']);

        // Verificar que A2 ficou alocado à Portaria com registo de substituição de A1
        $entry = ScheduleEntry::where('date', '2026-11-02')
            ->where('schedule_rule_id', $rule->id)
            ->firstOrFail();

        $this->assertSame($a1->id, $entry->replaced_assistant_id);
        $this->assertSame('internal_universal_fallback', $entry->replacement_reason);
    }

    public function test_detects_invalid_lock_when_assistant_becomes_absent(): void
    {
        $a1 = $this->createAssistantWithShift(
            name: 'Assistente Com Lock',
            internalNumber: 'A001',
            entryTime: '08:00:00',
            exitTime: '12:00:00',
            lunchEnabled: false,
            lunchStart: null,
            lunchEnd: null,
            lunchDuration: null,
            days: [0]
        );

        $profile = $a1->assistantScheduleProfiles()->first();
        $schedule = Schedule::create([
            'assistant_id' => $a1->id,
            'school_id' => $this->school->id,
            'assistant_schedule_profile_id' => $profile->id,
            'week_start' => '2026-11-02',
            'week_end' => '2026-11-08',
        ]);

        // Bloco manual bloqueado (is_locked = true)
        ScheduleEntry::create([
            'schedule_id' => $schedule->id,
            'activity_type_id' => $this->portaria->id,
            'activity_name' => 'Portaria',
            'date' => '2026-11-02',
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
            'is_locked' => true,
        ]);

        // Falta superveniente no próprio dia 2026-11-02
        $absenceTypeId = (string) Str::uuid();
        DB::table('absence_types')->insert(['id' => $absenceTypeId, 'name' => 'Baixa']);
        $absence = Absence::create([
            'assistant_id' => $a1->id,
            'absence_type_id' => $absenceTypeId,
            'valid_from' => '2026-11-02',
            'valid_until' => '2026-11-02',
            'total_days' => 1,
        ]);
        $absence->status = 'justified';
        $absence->save();

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/schedules/generate', [
                'start_date' => '2026-11-02',
                'end_date' => '2026-11-02',
                'preserve_locked' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['type' => 'locked_absence_conflict'])
            ->assertJsonFragment(['code' => 'post-locked-absence', 'status' => 'warn']);
    }

    public function test_suggests_external_transfer_candidates_with_origin_shortfall_check(): void
    {
        // A1 da escola principal está ausente
        $a1 = $this->createAssistantWithShift(
            name: 'Assistente Ausente',
            internalNumber: 'A001',
            entryTime: '08:00:00',
            exitTime: '12:00:00',
            lunchEnabled: false,
            lunchStart: null,
            lunchEnd: null,
            lunchDuration: null,
            days: [0]
        );

        $absenceTypeId = (string) Str::uuid();
        DB::table('absence_types')->insert(['id' => $absenceTypeId, 'name' => 'Férias']);
        $absence = Absence::create([
            'assistant_id' => $a1->id,
            'absence_type_id' => $absenceTypeId,
            'valid_from' => '2026-11-02',
            'valid_until' => '2026-11-02',
            'total_days' => 1,
        ]);
        $absence->status = 'justified';
        $absence->save();

        // Outra escola do agrupamento com um assistente livre e disponível para mobilidade
        $otherSchool = School::create([
            'name' => 'Escola Básica Secundária',
            'acronym' => 'EBS',
            'active' => true,
        ]);
        $externalAssistant = $this->createAssistantWithShift(
            name: 'Assistente Externo Livre',
            internalNumber: 'E001',
            entryTime: '08:00:00',
            exitTime: '16:00:00',
            lunchEnabled: true,
            lunchStart: '12:00:00',
            lunchEnd: '13:00:00',
            lunchDuration: 60,
            days: [0],
            school: $otherSchool,
            availableForTransfer: true
        );

        // Regra obrigatória na escola principal
        $rule = ScheduleRule::create([
            'school_id' => $this->school->id,
            'activity_type_id' => $this->portaria->id,
            'period_start' => '08:00',
            'period_end' => '10:00',
            'min' => 1,
            'type' => 'mandatory',
            'valid_from' => '2026-01-01',
            'days_of_week' => [0],
        ]);
        $rule->assistants()->attach($a1->id, ['priority' => 1]);

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/schedules/generate', [
                'start_date' => '2026-11-02',
                'end_date' => '2026-11-02',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('resolution_status', 'draft_with_deficit')
            ->assertJsonCount(1, 'conflicts')
            ->assertJsonPath('conflicts.0.transfer_candidates.0.assistant_id', $externalAssistant->id)
            ->assertJsonPath('conflicts.0.transfer_candidates.0.causes_origin_shortfall', false)
            ->assertJsonPath('conflicts.0.transfer_candidates.0.score', 96);
    }

    public function test_idempotency_key_reused_with_other_range_generates_fresh_schedule(): void
    {
        $this->createAssistantWithShift(
            name: 'Assistente Alfa',
            internalNumber: 'A100',
            entryTime: '08:00:00',
            exitTime: '16:00:00'
        );

        $idempotencyKey = 'idemp-test-' . Str::random(10);

        // 1. Pedido para 02 Nov
        $resp1 = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/schedules/generate', [
                'start_date' => '2026-11-02',
                'end_date' => '2026-11-02',
                'idempotency_key' => $idempotencyKey,
            ]);

        $resp1->assertStatus(200)
            ->assertJsonPath('start_date', '2026-11-02')
            ->assertJsonPath('end_date', '2026-11-02');

        // 2. Pedido para 09 a 13 Nov com a MESMA chave de idempotência
        $resp2 = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/schedules/generate', [
                'start_date' => '2026-11-09',
                'end_date' => '2026-11-13',
                'idempotency_key' => $idempotencyKey,
            ]);

        $resp2->assertStatus(200)
            ->assertJsonPath('start_date', '2026-11-09')
            ->assertJsonPath('end_date', '2026-11-13');
    }

    public function test_idempotency_key_does_not_hide_changed_data(): void
    {
        $a1 = $this->createAssistantWithShift(
            name: 'Assistente Falta',
            internalNumber: 'A200',
            entryTime: '08:00:00',
            exitTime: '16:00:00',
            lunchEnabled: false,
            days: [0]
        );

        $rule = ScheduleRule::create([
            'school_id' => $this->school->id,
            'activity_type_id' => $this->portaria->id,
            'period_start' => '08:00',
            'period_end' => '10:00',
            'min' => 1,
            'type' => 'mandatory',
            'valid_from' => '2026-01-01',
            'valid_until' => null,
            'days_of_week' => [0],
        ]);
        $rule->assistants()->attach($a1->id, ['priority' => 1]);

        $idempotencyKey = 'idemp-test-absence-' . Str::random(10);

        // 1. Gerar escala sem faltas
        $resp1 = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/schedules/generate', [
                'start_date' => '2026-11-02',
                'end_date' => '2026-11-02',
                'idempotency_key' => $idempotencyKey,
            ]);

        $resp1->assertStatus(200)
            ->assertJsonPath('entries_count', 2);

        // 2. Registar uma falta para este assistente no mesmo dia
        $absenceType = \App\Models\Absences\AbsenceType::firstOrCreate(
            ['name' => 'Doença'],
            ['code' => 'DOENCA', 'requires_document' => false, 'active' => true]
        );

        Absence::create([
            'assistant_id' => $a1->id,
            'absence_type_id' => $absenceType->id,
            'valid_from' => '2026-11-02',
            'valid_until' => '2026-11-02',
            'status' => 'justified',
            'total_days' => 1,
        ]);

        // 3. Voltar a gerar para o mesmo dia com a MESMA chave de idempotência
        $resp2 = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/schedules/generate', [
                'start_date' => '2026-11-02',
                'end_date' => '2026-11-02',
                'idempotency_key' => $idempotencyKey,
            ]);

        // Sem o nosso fix, a cache devolvia a escala antiga (entries_count = 2).
        // Com o fix, a versão dos dados mudou, logo calcula de fresco e entries_count é 0 (assistente faltou).
        $resp2->assertStatus(200)
            ->assertJsonPath('entries_count', 0);
    }

    public function test_idempotency_returns_cached_response_when_identical_retry(): void
    {
        $assistant = $this->createAssistantWithShift(
            name: 'Assistente Idemp',
            internalNumber: 'I100',
            entryTime: '08:00:00',
            exitTime: '16:00:00',
            days: [0]
        );

        $idempotencyKey = 'identical-retry-' . Str::random(10);

        $resp1 = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/schedules/generate', [
                'start_date' => '2026-11-02',
                'end_date' => '2026-11-02',
                'idempotency_key' => $idempotencyKey,
            ]);

        $resp1->assertStatus(200);

        // Segundo pedido exatamente igual (retry legítimo)
        $resp2 = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/schedules/generate', [
                'start_date' => '2026-11-02',
                'end_date' => '2026-11-02',
                'idempotency_key' => $idempotencyKey,
            ]);

        $resp2->assertStatus(200);
        $this->assertSame($resp1->json('generated_at'), $resp2->json('generated_at'));
        $this->assertSame($resp1->json('entries_count'), $resp2->json('entries_count'));
    }

    public function test_idempotency_key_does_not_hide_changed_assistant_exception(): void
    {
        $assistant = $this->createAssistantWithShift(
            name: 'Assistente Excecao',
            internalNumber: 'E200',
            entryTime: '08:00:00',
            exitTime: '16:00:00',
            days: [0]
        );

        $idempotencyKey = 'idemp-exception-' . Str::random(10);

        $resp1 = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/schedules/generate', [
                'start_date' => '2026-11-02',
                'end_date' => '2026-11-02',
                'idempotency_key' => $idempotencyKey,
            ]);
        $resp1->assertStatus(200);

        // Criar uma exceção de horário que altera a entrada para as 09:00
        \App\Models\Assistants\AssistantException::create([
            'assistant_id' => $assistant->id,
            'type' => 'specific_dates',
            'valid_from' => '2026-11-02',
            'valid_until' => '2026-11-02',
            'entry_time' => '09:00:00',
            'exit_time' => '16:00:00',
        ]);

        // Gerar de novo com a mesma chave: não deve devolver a cache de 08:00!
        $resp2 = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/schedules/generate', [
                'start_date' => '2026-11-02',
                'end_date' => '2026-11-02',
                'idempotency_key' => $idempotencyKey,
            ]);

        $resp2->assertStatus(200);
        // O hash mudou, logo gerou uma nova resposta com timestamp diferente
        $this->assertNotSame($resp1->json('generated_at'), $resp2->json('generated_at'));
    }

    public function test_idempotency_respects_preserve_locked_flag_change(): void
    {
        $assistant = $this->createAssistantWithShift(
            name: 'Assistente Locked',
            internalNumber: 'L300',
            entryTime: '08:00:00',
            exitTime: '16:00:00',
            days: [0]
        );

        $idempotencyKey = 'idemp-locked-' . Str::random(10);

        // 1. Gerar com preserve_locked = true
        $resp1 = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/schedules/generate', [
                'start_date' => '2026-11-02',
                'end_date' => '2026-11-02',
                'preserve_locked' => true,
                'idempotency_key' => $idempotencyKey,
            ]);
        $resp1->assertStatus(200);

        // 2. Gerar com a mesma chave mas preserve_locked = false
        $resp2 = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/schedules/generate', [
                'start_date' => '2026-11-02',
                'end_date' => '2026-11-02',
                'preserve_locked' => false,
                'idempotency_key' => $idempotencyKey,
            ]);
        $resp2->assertStatus(200);

        // Não deve devolver o cache do primeiro porque o preserve_locked é diferente
        $this->assertNotSame($resp1->json('generated_at'), $resp2->json('generated_at'));
    }
}
