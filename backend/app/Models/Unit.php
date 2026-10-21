<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Unidad de medida (catálogo permanente del grupo; se desactiva, no se borra).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property bool $active
 */
#[Fillable(['code', 'name', 'active'])]
class Unit extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
