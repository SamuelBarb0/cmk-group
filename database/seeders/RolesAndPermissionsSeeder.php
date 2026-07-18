<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // --- Permisos agrupados por área funcional ---
        $permissions = [
            // Gestión de la consultora (solo CMK)
            'dashboard.master',   // dashboard maestro consolidado de todos los clientes
            'clients.view', 'clients.manage',
            'users.view', 'users.manage',
            'settings.manage',

            // Módulos técnicos del sector
            'sst.view', 'sst.manage',
            'hseq.view', 'hseq.manage',
            'pesv.view', 'pesv.manage',

            // Gestión documental y evidencias
            'documents.view', 'documents.manage',

            // App móvil de campo
            'inspections.view', 'inspections.perform',
            'incidents.view', 'incidents.manage',

            // Reportes y auditoría
            'reports.view', 'reports.generate',
            'audit.view',
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // --- Roles y sus permisos ---
        $matrix = [
            // Control total.
            'consultor_admin' => $permissions,

            // Ejecuta la consultoría; sin configuración global ni gestión de clientes/usuarios.
            'consultor_operativo' => [
                'dashboard.master', 'clients.view',
                'sst.view', 'sst.manage',
                'hseq.view', 'hseq.manage',
                'pesv.view', 'pesv.manage',
                'documents.view', 'documents.manage',
                'inspections.view', 'inspections.perform',
                'incidents.view', 'incidents.manage',
                'reports.view', 'reports.generate',
                'audit.view',
            ],

            // Responsable del cliente: gestiona su empresa y sus usuarios.
            'cliente_admin' => [
                'users.view', 'users.manage',
                'sst.view', 'sst.manage',
                'hseq.view', 'hseq.manage',
                'pesv.view', 'pesv.manage',
                'documents.view', 'documents.manage',
                'inspections.view',
                'incidents.view', 'incidents.manage',
                'reports.view', 'reports.generate',
            ],

            // Usuario operativo del cliente: consulta y diligencia con permisos limitados.
            'cliente_usuario' => [
                'sst.view', 'hseq.view', 'pesv.view',
                'documents.view', 'documents.manage',
                'incidents.view',
                'reports.view',
            ],

            // Inspector de campo (PWA).
            'inspector' => [
                'inspections.view', 'inspections.perform',
                'incidents.view', 'incidents.manage',
                'documents.view',
            ],

            // Auditor: solo consulta/evidencia información auditable. Sin edición.
            'auditor' => [
                'audit.view',
                'sst.view', 'hseq.view', 'pesv.view',
                'documents.view',
                'incidents.view',
                'reports.view',
            ],
        ];

        foreach ($matrix as $roleName => $rolePermissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($rolePermissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
