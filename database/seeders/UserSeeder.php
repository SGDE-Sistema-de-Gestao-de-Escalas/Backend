<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Auth\User;
use App\Models\Auth\Role;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        $adminRole = Role::where('slug', 'admin')->first();
        $staffRole = Role::where('slug', 'staff')->first();



        $adminPassword = (string) (config('app.seed_admin_password') ?: 'Password123!');
        $staffPassword = (string) (config('app.seed_staff_password') ?: 'Password123!');

        User::firstOrCreate(
            ['email' => 'sgde@admin.com'],
            [
                'first_name' => 'Miguel',
                'last_name' => 'Silva',
                'password' => Hash::make($adminPassword),
                'role_id' => $adminRole->id,
            ]
        );

        User::firstOrCreate(
            ['email' => 'assistente@sgde.pt'],
            [
                'first_name' => 'Ana',
                'last_name' => 'Costa',
                'password' => Hash::make($staffPassword),
                'role_id' => $staffRole->id,
            ]
        );
    }
}
