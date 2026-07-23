<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        // --- Catálogos globales (estándares, plantillas de documentos) ---
        $this->call(SstStandardsSeeder::class);          // 60 estándares Res. 0312
        $this->call(WorkPlanActivitiesSeeder::class);    // actividades del plan de trabajo
        $this->call(IndicatorsSeeder::class);            // indicadores legales del SG-SST
        $this->call(DocumentTemplatesSeeder::class);     // plantillas de documentos SGI
        $this->call(BaseDocumentsSeeder::class);         // contenido base (POL/MAN/INV)
        $this->call(ProcedimientosBaseSeeder::class);    // procedimientos base adicionales
        $this->call(FormFormatsSeeder::class);           // motor de formatos (inspecciones/actas)

        // --- Personal de CMK GROUP (sin tenant, acceso multi-cliente) ---
        $admin = User::factory()->create([
            'name' => 'Administrador CMK',
            'email' => 'admin@cmkgroup.com',
            'password' => Hash::make('password'),
            'tenant_id' => null,
        ]);
        $admin->assignRole('consultor_admin');

        $consultor = User::factory()->create([
            'name' => 'Consultor CMK',
            'email' => 'consultor@cmkgroup.com',
            'password' => Hash::make('password'),
            'tenant_id' => null,
        ]);
        $consultor->assignRole('consultor_operativo');

        // --- Cliente de ejemplo (tenant) + sus usuarios ---
        $tenant = Tenant::create([
            'name' => 'Empresa Demo S.A.S.',
            'legal_name' => 'Empresa Demostración S.A.S.',
            'nit' => '900.123.456-7',
            'email' => 'contacto@empresademo.com',
            'phone' => '+57 300 000 00 00',
            'city' => 'Barranquilla, Atlántico',
            'is_active' => true,
        ]);

        $clientUsers = [
            ['Cliente Admin Demo', 'admin@empresademo.com', 'cliente_admin'],
            ['Cliente Usuario Demo', 'usuario@empresademo.com', 'cliente_usuario'],
            ['Inspector Demo', 'inspector@empresademo.com', 'inspector'],
            ['Auditor Demo', 'auditor@empresademo.com', 'auditor'],
        ];

        foreach ($clientUsers as [$name, $email, $role]) {
            $user = User::factory()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make('password'),
                'tenant_id' => $tenant->id,
            ]);
            $user->assignRole($role);
        }
    }
}
