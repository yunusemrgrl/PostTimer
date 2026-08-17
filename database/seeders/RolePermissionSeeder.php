<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Platform seviyesinde (tenant'a bağlı olmayan) izinler.
        // Tenant içi (owner/admin/member) roller burada değil,
        // team_user.role kolonunda tutulur.
        $permissions = [
            'manage teams',
            'manage users',
            'manage roles',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $superAdmin = Role::findOrCreate('super_admin', 'web');
        $superAdmin->syncPermissions($permissions);

        // Örnek: sınırlı yetkili bir platform rolü. super_admin kadar
        // güçlü değildir; sadece hesap ve kullanıcı yönetebilir.
        $support = Role::findOrCreate('support', 'web');
        $support->syncPermissions(['manage teams', 'manage users']);
    }
}
