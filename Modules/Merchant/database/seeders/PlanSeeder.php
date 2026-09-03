<?php
// database/seeders/PlanSeeder.php

namespace Modules\Merchant\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['code' => 'trial', 'name' => 'Trial', 'is_public' => false, 'description' => 'Untuk mencoba fitur dasar Jeevan'],
            ['code' => 'free', 'name' => 'Free', 'description' => 'Untuk mencoba fitur dasar Jeevan'],
            ['code' => 'basic', 'name' => 'Basic', 'description' => 'Untuk usaha kecil dengan kebutuhan dasar'],
            ['code' => 'pro', 'name' => 'Pro', 'description' => 'Untuk usaha berkembang dengan kebutuhan lebih lengkap'],
            ['code' => 'enterprise', 'name' => 'Enterprise', 'description' => 'Untuk usaha besar dengan kebutuhan custom & module tambahan gratis'],
        ];

        foreach ($plans as $plan) {
            DB::table('plans')->insert([
                ...$plan,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}