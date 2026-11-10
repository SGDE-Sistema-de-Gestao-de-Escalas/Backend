<?php

namespace Tests\Feature\Schedules;

use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schedules\Holiday;
use App\Models\Schedules\Schedule;
use App\Models\Schedules\ScheduleEntry;
use App\Models\Schools\School;
use App\Models\System\ActivityType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HolidayImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrador']);
        $staffRole = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Assistente']);

        $this->admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $this->staff = User::factory()->create([
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);
    }

    public function test_authenticated_user_can_list_municipalities(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/holidays/municipalities');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['slug', 'name', 'district', 'holiday_name'],
                ],
            ]);

        $this->assertCount(308, $response->json('data'));
    }

    public function test_admin_can_preview_holidays_with_municipality(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/holidays/preview?year=2026&municipality=lisboa');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'name',
                        'date',
                        'type',
                        'municipality',
                        'description',
                        'already_exists',
                        'is_past',
                    ],
                ],
            ]);

        $holidays = $response->json('data');
        $this->assertNotEmpty($holidays);

        // Deve conter Santo António (Lisboa) a 13 de junho
        $santoAntonio = collect($holidays)->first(fn ($h) => $h['date'] === '2026-06-13');
        $this->assertNotNull($santoAntonio);
        $this->assertEquals('municipal', $santoAntonio['type']);
        $this->assertEquals('Lisboa', $santoAntonio['municipality']);
    }

    public function test_non_admin_cannot_preview_or_import_holidays(): void
    {
        $previewResponse = $this->actingAs($this->staff)
            ->getJson('/api/holidays/preview?year=2026');

        $previewResponse->assertForbidden();

        $importResponse = $this->actingAs($this->staff)
            ->postJson('/api/holidays/import', [
                'year' => 2026,
            ]);

        $importResponse->assertForbidden();
    }

    public function test_admin_can_import_holidays_and_duplicates_are_skipped(): void
    {
        // Pré-criar o feriado de 1 de janeiro
        Holiday::create([
            'name' => 'Ano Novo Antigo',
            'date' => '2026-01-01',
            'description' => 'Já existia',
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/holidays/import', [
                'year' => 2026,
                'municipality' => 'porto',
            ]);

        $response->assertOk()
            ->assertJsonStructure([
                'message',
                'imported_count',
                'skipped_count',
                'total_processed',
                'requires_recalculation',
            ]);

        // 2026-01-01 deve ter sido ignorado (skipped)
        $this->assertGreaterThan(0, $response->json('skipped_count'));
        $this->assertGreaterThan(0, $response->json('imported_count'));

        // O feriado existente não deve ter sido substituído
        $this->assertEquals('Ano Novo Antigo', Holiday::where('date', '2026-01-01')->first()->name);

        // Deve ter importado São João (Porto) a 24 de junho
        $this->assertDatabaseHas('holidays', [
            'date' => '2026-06-24',
        ]);
    }

    public function test_cannot_update_past_holiday(): void
    {
        $pastDate = CarbonImmutable::yesterday()->toDateString();
        $holiday = Holiday::create([
            'name' => 'Feriado Passado',
            'date' => $pastDate,
        ]);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/holidays/{$holiday->id}", [
                'name' => 'Novo Nome',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Não é possível alterar um feriado que já passou.',
            ]);
    }

    public function test_cannot_delete_past_holiday(): void
    {
        $pastDate = CarbonImmutable::yesterday()->toDateString();
        $holiday = Holiday::create([
            'name' => 'Feriado Passado',
            'date' => $pastDate,
        ]);

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/holidays/{$holiday->id}");

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Não é possível eliminar um feriado que já passou.',
            ]);

        $this->assertDatabaseHas('holidays', [
            'id' => $holiday->id,
        ]);
    }

    public function test_can_update_and_delete_future_holiday(): void
    {
        $futureDate = CarbonImmutable::tomorrow()->toDateString();
        $holiday = Holiday::create([
            'name' => 'Feriado Futuro',
            'date' => $futureDate,
        ]);

        $updateResponse = $this->actingAs($this->admin)
            ->putJson("/api/holidays/{$holiday->id}", [
                'name' => 'Feriado Futuro Alterado',
            ]);

        $updateResponse->assertOk();
        $this->assertDatabaseHas('holidays', [
            'id' => $holiday->id,
            'name' => 'Feriado Futuro Alterado',
        ]);

        $deleteResponse = $this->actingAs($this->admin)
            ->deleteJson("/api/holidays/{$holiday->id}");

        $deleteResponse->assertOk()
            ->assertJson([
                'message' => 'Feriado removido com sucesso.',
            ]);

        $this->assertDatabaseMissing('holidays', [
            'id' => $holiday->id,
        ]);
    }

    public function test_modifying_or_deleting_holiday_on_day_with_generated_schedule_returns_impact_warning(): void
    {
        $school = School::create([
            'name' => 'Escola Teste',
            'acronym' => 'ET',
            'code' => 'ESC-TEST',
            'active' => true,
        ]);
        $targetDate = CarbonImmutable::now()->addDays(5)->toDateString();

        $activityType = ActivityType::create([
            'school_id' => $school->id,
            'name' => 'Apoio Geral',
            'color' => '#3B82F6',
        ]);

        $assistant = Assistant::create([
            'user_id' => $this->staff->id,
            'school_id' => $school->id,
            'internal_number' => 'AST-999',
        ]);

        $schedule = Schedule::create([
            'school_id' => $school->id,
            'assistant_id' => $assistant->id,
            'week_start' => $targetDate,
            'week_end' => $targetDate,
        ]);

        ScheduleEntry::create([
            'schedule_id' => $schedule->id,
            'activity_type_id' => $activityType->id,
            'date' => $targetDate,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'activity_name' => 'Apoio Geral',
        ]);

        // Criar feriado nessa data
        $createResponse = $this->actingAs($this->admin)
            ->postJson('/api/holidays', [
                'name' => 'Feriado de Teste',
                'date' => $targetDate,
            ]);

        $createResponse->assertCreated();
        $this->assertTrue($createResponse->json('impact.requires_recalculation'));
        $this->assertNotEmpty($createResponse->json('impact.impacted_schools'));

        $holidayId = $createResponse->json('data.id');

        // Eliminar feriado também deve retornar impact
        $deleteResponse = $this->actingAs($this->admin)
            ->deleteJson("/api/holidays/{$holidayId}");

        $deleteResponse->assertOk();
        $this->assertArrayHasKey('impact', $deleteResponse->json());
    }
}
