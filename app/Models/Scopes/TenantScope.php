<?php

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope que segrega automáticamente la información por tenant.
 *
 * Si hay un tenant activo (usuario cliente, o consultor que seleccionó un
 * cliente) filtra por tenant_id. Si no lo hay (consultor en vista
 * consolidada) no agrega restricción y devuelve todos los clientes.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->has()) {
            $builder->where($model->getTable().'.tenant_id', $context->id());
        }
    }
}
