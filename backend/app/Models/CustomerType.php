<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Tipo de cliente (catálogo permanente del grupo).
 *
 * @property int $id
 * @property string $name
 * @property bool $active
 */
#[Fillable(['name', 'active'])]
class CustomerType extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
