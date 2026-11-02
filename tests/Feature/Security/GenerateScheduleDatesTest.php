<?php

namespace Tests\Feature\Security;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schools\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F-26: datas inválidas ao gerar escalas davam erro 500 em vez de 422.
 */
class GenerateScheduleDatesTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrador']);
        $this->school = School::create(['name' => 'Escola A', 'acronym' => 'EA', 'active' => true]);
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);
    }

    private function generate(array $payload)
    {
        Sanctum::actingAs($this->admin);

        return $this->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/schedules/generate', $payload);
    }

    #[DataProvider('badDates')]
    public function test_invalid_dates_return_422_never_500(array $payload): void
    {
        $this->generate($payload)->assertStatus(422);
    }

    public static function badDates(): array
    {
        return [
            'texto' => [['start_date' => 'abc', 'end_date' => '2027-03-02']],
            'mes 13' => [['start_date' => '2027-03-01', 'end_date' => '2026-13-01']],
            'lista no inicio' => [['start_date' => [1], 'end_date' => '2027-03-02']],
            'objeto no fim' => [['start_date' => '2027-03-01', 'end_date' => ['a' => 1]]],
            'numero' => [['start_date' => 20270301, 'end_date' => '2027-03-02']],
        ];
    }

    public function test_the_31_day_limit_still_applies(): void
    {
        $this->generate(['start_date' => '2027-03-01', 'end_date' => '2027-04-10'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_date');
    }

    public function test_an_end_before_the_start_is_still_rejected(): void
    {
        $this->generate(['start_date' => '2027-03-10', 'end_date' => '2027-03-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_date');
    }
}
