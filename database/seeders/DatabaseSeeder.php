<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        // --- Süper admin (platformun tamamını yönetir) ---
        $superAdmin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Platform Yöneticisi',
                'password' => 'password', // 'hashed' cast otomatik hash'ler
            ],
        );
        $superAdmin->assignRole('super_admin');

        // --- Demo hesap (tenant) ---
        $team = Team::firstOrCreate(
            ['slug' => 'demo-hesap'],
            ['name' => 'Demo Hesap', 'owner_id' => null],
        );

        // --- Demo takım sahibi ---
        $owner = User::firstOrCreate(
            ['email' => 'owner@example.com'],
            ['name' => 'Demo Sahip', 'password' => 'password'],
        );

        $team->update(['owner_id' => $owner->id]);

        TeamMember::firstOrCreate(
            ['team_id' => $team->id, 'user_id' => $owner->id],
            ['role' => TeamMember::ROLE_OWNER],
        );

        // --- Demo üye ---
        $member = User::firstOrCreate(
            ['email' => 'member@example.com'],
            ['name' => 'Demo Üye', 'password' => 'password'],
        );

        TeamMember::firstOrCreate(
            ['team_id' => $team->id, 'user_id' => $member->id],
            ['role' => TeamMember::ROLE_MEMBER],
        );
    }
}
