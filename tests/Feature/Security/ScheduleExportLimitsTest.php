<?php

namespace Tests\Feature\Security;

use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schools\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * F-40: a exportação em PDF é muito pesada e só tinha o limite geral da API.
 */
class ScheduleExportLimitsTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private Role $staffRole;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrador']);
        $this->staffRole = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Assistente Operacional']);

        $this->school = School::create(['name' => 'Escola A', 'acronym' => 'EA', 'active' => true]);
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);
    }

    private function registerAssistants(int $howMany): array
    {
        $ids = [];

        foreach (range(1, $howMany) as $number) {
            $user = User::factory()->create(['role_id' => $this->staffRole->id, 'is_active' => true]);
            $ids[] = Assistant::create([
                'school_id' => $this->school->id,
                'user_id' => $user->id,
                'internal_number' => 'N'.$number,
                'available_for_transfer' => false,
            ])->id;
        }

        return $ids;
    }

    private function export(array $query)
    {
        Sanctum::actingAs($this->admin);

        return $this->withHeader('X-School-ID', $this->school->id)
            ->getJson('/api/schedules/export?'.http_build_query(array_merge([
                'start_date' => '2027-03-01',
                'end_date' => '2027-03-03',
            ], $query)));
    }

    public function test_the_export_has_the_tighter_limit(): void
    {
        config(['rate_limits.heavy' => 2]);

        $this->export([])->assertStatus(422);
        $this->export([])->assertStatus(422);
        $this->export([])->assertStatus(429);
    }

    public static function pdfModelsForEveryone(): array
    {
        return [
            'horário individual' => ['individual'],
            'mapa mensal' => ['monthly'],
        ];
    }

    #[DataProvider('pdfModelsForEveryone')]
    public function test_a_pdf_for_too_many_assistants_is_refused(string $model): void
    {
        config(['rate_limits.export_pdf_max_assistants' => 2]);
        $this->registerAssistants(3);

        $this->export(['model' => $model, 'format' => 'pdf'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['assistant_id']);
    }

    #[DataProvider('pdfModelsForEveryone')]
    public function test_a_pdf_for_one_chosen_assistant_is_not_refused_for_size(string $model): void
    {
        config(['rate_limits.export_pdf_max_assistants' => 2]);
        $ids = $this->registerAssistants(3);

        $this->export(['model' => $model, 'format' => 'pdf', 'assistant_id' => $ids[0]])
            ->assertJsonMissingValidationErrors(['assistant_id']);
    }

    #[DataProvider('pdfModelsForEveryone')]
    public function test_a_pdf_for_exactly_the_limit_is_not_refused_for_size(string $model): void
    {
        config(['rate_limits.export_pdf_max_assistants' => 3]);
        $this->registerAssistants(3);

        $this->export(['model' => $model, 'format' => 'pdf'])
            ->assertJsonMissingValidationErrors(['assistant_id']);
    }

    public function test_the_csv_and_the_daily_pdf_are_not_refused_for_size(): void
    {
        config(['rate_limits.export_pdf_max_assistants' => 2]);
        $this->registerAssistants(3);

        $this->export(['model' => 'individual', 'format' => 'csv'])
            ->assertJsonMissingValidationErrors(['assistant_id']);
        $this->export(['model' => 'wall', 'format' => 'pdf'])
            ->assertJsonMissingValidationErrors(['assistant_id']);
    }

    public function test_an_absurdly_long_assistant_id_is_a_422(): void
    {
        $this->export(['assistant_id' => str_repeat('a', 500)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['assistant_id']);
    }

    public function test_the_default_limit_is_a_sensible_number(): void
    {
        $this->assertGreaterThanOrEqual(10, config('rate_limits.export_pdf_max_assistants'));
        $this->assertLessThanOrEqual(100, config('rate_limits.export_pdf_max_assistants'));
    }
}
