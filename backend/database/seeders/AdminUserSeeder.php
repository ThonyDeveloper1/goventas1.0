<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name'     => 'Administrador GO Systems',
                'email'    => 'admin@gosystems.com',
                'password' => 'Admin@2024!',
                'role'     => 'admin',
                'active'   => true,
            ],
            [
                'name'     => 'María Vendedora',
                'email'    => 'vendedora@gosystems.com',
                'password' => 'Vendedora@2024!',
                'role'     => 'vendedora',
                'active'   => true,
            ],
            [
                'name'     => 'Carlos Supervisor',
                'email'    => 'supervisor@gosystems.com',
                'password' => 'Supervisor@2024!',
                'role'     => 'supervisor',
                'active'   => true,
            ],
        ];

        foreach ($users as $data) {
            User::updateOrCreate(
                ['email' => $data['email']],
                $data
            );
        }
    }
}
