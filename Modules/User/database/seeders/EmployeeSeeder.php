<?php

namespace Modules\User\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\User\Models\Employee;

class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        Employee::updateOrCreate(
            ['email' => 'superadmin@jeevan.com'],
            [
                'name'     => 'Super Admin',
                'password' => bcrypt('password'),
                'role'     => 'super_admin',
                'is_active'   => true,
            ]
        );

        Employee::updateOrCreate(
            ['email' => 'admin@jeevan.com'],
            [
                'name'     => 'Admin',
                'password' => bcrypt('password'),
                'role'     => 'admin',
                'is_active'   => true,
            ]
        );

        Employee::updateOrCreate(
            ['email' => 'dev@jeevan.com'],
            [
                'name'     => 'Developer',
                'password' => bcrypt('password'),
                'role'     => 'developer',
                'is_active'   => true,
            ]
        );

        Employee::updateOrCreate(
            ['email' => 'support@jeevan.com'],
            [
                'name'     => 'Support',
                'password' => bcrypt('password'),
                'role'     => 'support',
                'is_active'   => true,
            ]
        );
    }
}