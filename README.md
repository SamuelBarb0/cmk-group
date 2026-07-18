# CMK GROUP · Plataforma SST · HSEQ · PESV con IA

Plataforma SaaS **multi-tenant** para la consultora **CMK GROUP S.A.S.**: gestiona la
información de múltiples empresas cliente (segregada por tenant), con módulos SST / HSEQ /
PESV, app móvil de campo (PWA), IA aplicada (Anthropic Claude) y reportes auditables.

Proveedor de desarrollo: **MY Tech Solutions**. Este repositorio corresponde a la
**Fase 1 · Setup y multi-tenancy**.

## Stack

- **Laravel 12** (PHP 8.2+) · Inertia 2 · **React 19 + TypeScript** · Tailwind CSS 4
- **spatie/laravel-permission** (roles y permisos)
- **MySQL** en local (XAMPP) · **PostgreSQL + Row Level Security** en producción
- IA: **Anthropic Claude API** (configurable a OpenAI)

## Estado — Fase 1 (completada)

- [x] Proyecto base (starter kit React) con identidad de CMK (tema azul marino/gris, logo, favicon, tipografía)
- [x] Arquitectura **multi-tenant** con scoping por `tenant_id` (global scope + contexto de tenant por request)
- [x] Autenticación + **6 roles** con permisos (incluye el rol **Auditor** solicitado por CMK)
- [x] Navegación por rol (el menú se filtra según permisos)
- [x] **Dashboard maestro consolidado** (CMK) y **dashboard individual** por cliente
- [x] Shells navegables de todos los módulos, protegidos por permiso
- [x] Datos de la empresa (NIT, sedes, contacto) centralizados en `config/cmk.php`

Los módulos (SST/HSEQ/PESV, PWA, IA, reportes) se desarrollan en las fases F2–F6.

## Arquitectura multi-tenant

- `Tenant` = empresa **cliente** de CMK. Su información se segrega por `tenant_id`.
- `users.tenant_id`: `null` → personal de CMK (acceso multi-cliente) · valor → usuario de un cliente.
- `App\Support\TenantContext` (singleton) guarda el tenant activo del request.
- `App\Http\Middleware\SetCurrentTenant` lo resuelve:
  - usuario cliente → su `tenant_id` (bloqueado);
  - consultor CMK → cliente seleccionado en sesión (`active_tenant_id`) o `null` = vista consolidada.
- `App\Models\Concerns\BelongsToTenant` (trait) + `App\Models\Scopes\TenantScope` aplican el
  filtro automático a cualquier modelo de negocio y rellenan `tenant_id` al crear.
  > En producción (PostgreSQL) este scoping se refuerza con políticas **RLS nativas**.

## Roles y permisos

Definidos en `database/seeders/RolesAndPermissionsSeeder.php` y descritos en `config/cmk.php`:

| Rol                    | Ámbito | Resumen                                                        |
| ---------------------- | ------ | -------------------------------------------------------------- |
| `consultor_admin`      | CMK    | Control total + dashboard maestro consolidado.                 |
| `consultor_operativo`  | CMK    | Ejecuta consultoría; sin configuración global.                 |
| `cliente_admin`        | Cliente| Gestiona su empresa y sus usuarios.                            |
| `cliente_usuario`      | Cliente| Consulta y diligencia con permisos limitados.                  |
| `inspector`            | Campo  | Inspecciones en campo desde la PWA (offline).                  |
| `auditor`              | Cliente| **Solo consulta/evidencia** información auditable. Sin edición.|

## Puesta en marcha (local)

```bash
# 1. Dependencias
composer install
npm install

# 2. Entorno (ya configurado para MySQL/XAMPP en .env)
#    Base de datos: cmk_group  ·  Falta: ANTHROPIC_API_KEY

# 3. Migrar + datos de demostración
php artisan migrate:fresh --seed

# 4. Desarrollo (server + vite + queue + logs)
composer run dev
#    o por separado:  php artisan serve   +   npm run dev
```

### Usuarios de demostración (contraseña: `password`)

| Email                        | Rol                   |
| ---------------------------- | --------------------- |
| admin@cmkgroup.com           | consultor_admin       |
| consultor@cmkgroup.com       | consultor_operativo   |
| admin@empresademo.com        | cliente_admin         |
| usuario@empresademo.com      | cliente_usuario       |
| inspector@empresademo.com    | inspector             |
| auditor@empresademo.com      | auditor               |

## Pendiente de CMK GROUP (insumos)

- **API Key de IA** (Anthropic recomendado) → variable `ANTHROPIC_API_KEY` en `.env`.
- **Hosting/dominio** definitivo (`cmkgroup.com`) para el despliegue.
- Logo en **vectorial** (.svg/.ai/.pdf) y documentos de referencia para los reportes PDF.
