<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
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



        User::firstOrCreate([
            'name' => 'Miguel Silva',
            'email' => 'admin@sgde.pt',
            'password' => Hash::make('admin123'),
            'role_id' => $adminRole->id,
        ]);

        User::firstOrCreate([
            'name' => 'Ana Costa',
            'email' => 'assistente@sgde.pt',
            'password' => Hash::make('staff123'),
            'role_id' => $staffRole->id,
        ]);
    }
}
