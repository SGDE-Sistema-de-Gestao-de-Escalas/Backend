<?php

namespace Tests\Feature;

use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schedules\AssistantScheduleProfile;
use App\Models\Schools\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AssistantScheduleProfileClosingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private School $school;
    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $staffRole = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Staff']);

        $this->school = School::create([
            'name' => 'EB1 Central',
            'acronym' => 'EB1C',
            'active' => true,
        ]);

        $this->admin = User::create([
            'first_name' => 'Admin',
            'last_name' => 'Sistema',
            'email' => 'admin.profile@escola.pt',
            'password' => Hash::make('password123'),
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $assistantUser = User::create([
            'first_name' => 'Carla',
            'last_name' => 'Sousa',
            'email' => 'carla.sousa@escola.pt',
            'password' => Hash::make('password123'),
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        $this->assistant = Assistant::create([
            'user_id' => $assistantUser->id,
            'school_id' => $this->school->id,
            'internal_number' => 'AO-701',
            'weekly_hours' => 35,
            'daily_hours' => 7,
        ]);
    }

    public function test_creating_new_profile_starting_today_or_future_closes_previous_open_profile(): void
    {
        // 1. Criar perfil inicial no passado (vigência aberta)
        $initialProfile = AssistantScheduleProfile::create([
            'assistant_id' => $this->assistant->id,
            'type' => 'fixo',
            'valid_from' => '2026-01-01',
            'valid_until' => null,
        ]);

        // 2. Criar novo perfil a começar HOJE -> deve fechar o anterior em ontem
        $todayStr = now()->format('Y-m-d');
        $yesterdayStr = now()->subDay()->format('Y-m-d');

        $responseToday = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/assistant-schedule-profiles', [
                'assistant_id' => $this->assistant->id,
                'type' => 'fixo',
                'valid_from' => $todayStr,
                'shifts' => [
                    [
                        'shift_label' => 'A',
                        'entry_time' => '08:00',
                        'exit_time' => '16:00',
                        'lunch_enabled' => true,
                        'lunch_start' => '12:00',
                        'lunch_end' => '14:00',
                        'lunch_duration_minutes' => 60,
                        'days' => [0, 1, 2, 3, 4],
                    ],
                ],
            ]);

        $responseToday->assertStatus(201);

        $this->assertEquals(
            $yesterdayStr,
            $initialProfile->fresh()->valid_until?->format('Y-m-d'),
            'O perfil anterior deve ser fechado com valid_until = ontem quando o novo começa hoje.'
        );

        $todayProfileId = $responseToday->json('data.id');
        $todayProfile = AssistantScheduleProfile::findOrFail($todayProfileId);

        // 3. Criar mais um perfil a começar daqui a 10 dias -> deve fechar o perfil de hoje em D - 1
        $futureDateStr = now()->addDays(10)->format('Y-m-d');
        $expectedUntilStr = now()->addDays(9)->format('Y-m-d');

        $responseFuture = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/assistant-schedule-profiles', [
                'assistant_id' => $this->assistant->id,
                'type' => 'fixo',
                'valid_from' => $futureDateStr,
                'shifts' => [
                    [
                        'shift_label' => 'A',
                        'entry_time' => '09:00',
                        'exit_time' => '17:00',
                        'lunch_enabled' => true,
                        'lunch_start' => '12:30',
                        'lunch_end' => '14:30',
                        'lunch_duration_minutes' => 60,
                        'days' => [0, 1, 2, 3, 4],
                    ],
                ],
            ]);

        $responseFuture->assertStatus(201);

        $this->assertEquals(
            $expectedUntilStr,
            $todayProfile->fresh()->valid_until?->format('Y-m-d'),
            'O perfil em vigor deve ser fechado com valid_until = D - 1 dia quando um perfil futuro é criado.'
        );

        // 4. Eliminar o perfil futuro e depois eliminar o perfil de hoje -> o perfil inicial deve reabrir (valid_until = null)
        $futureProfileId = $responseFuture->json('data.id');

        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->deleteJson("/api/assistant-schedule-profiles/{$futureProfileId}")
            ->assertStatus(200);

        $this->assertNull($todayProfile->fresh()->valid_until);

        // Eliminar o perfil que começou hoje (29/10/2026) -> deve permitir apagar e reabrir o perfil anterior (initialProfile)
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->deleteJson("/api/assistant-schedule-profiles/{$todayProfileId}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('assistant_schedule_profiles', ['id' => $todayProfileId]);
        $this->assertNull(
            $initialProfile->fresh()->valid_until,
            'Ao eliminar o perfil criado hoje, o perfil anterior tem de voltar a ficar em aberto (valid_until = null).'
        );
    }
}

