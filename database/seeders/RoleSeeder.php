<?php

namespace Database\Seeders;

use App\Models\Auth\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [ 
                'slug' => 'admin',
                'name' => 'Administrator',
                'description' => 'Manages system configuration and staff'
            ],
            [ 
                'slug' => 'staff',
                'name' => 'Staff',
                'description' => 'Views personal schedule'
            ]
        ];

        foreach ($roles as $data) {
            $role = Role::firstOrNew([
                'slug' => $data['slug'],
            ]);

            if($role->exists){
                continue;
            }

            $role->name = $data['name'];
            $role->description = $data['description'];
            $role->save();
        }
    }
}
