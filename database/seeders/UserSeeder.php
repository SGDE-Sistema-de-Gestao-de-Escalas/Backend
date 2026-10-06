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



        User::firstOrCreate(
            ['email' => 'admin@sgde.pt'],
            [
                'first_name' => 'Miguel',
                'last_name' => 'Silva',
                'password' => Hash::make('admin123'),
                'role_id' => $adminRole->id,
            ]
        );

        User::firstOrCreate(
            ['email' => 'assistente@sgde.pt'],
            [
                'first_name' => 'Ana',
                'last_name' => 'Costa',
                'password' => Hash::make('staff123'),
                'role_id' => $staffRole->id,
            ]
        );
    }
}
