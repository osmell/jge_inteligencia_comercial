<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopedByOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Regla de asociación entre categorías: de los clientes que compran el antecedente, qué porcentaje compra también el consecuente.
 *
 * @property int $id
 * @property int $insight_run_id
 * @property int $company_id
 * @property string $antecedent
 * @property string $consequent
 * @property int $antecedent_customers
 * @property int $consequent_customers
 * @property int $both_customers
 * @property int $total_customers
 * @property string $confidence
 * @property string $lift
 */
class CrossSellRule extends Model
{
    use ScopedByOrganization;

    public $timestamps = false;

    protected $guarded = [];

    public static function organizationBranchColumn(): ?string
    {
        return null;
    }

    protected function casts(): array
    {
        return ['confidence' => 'decimal:4', 'lift' => 'decimal:4'];
    }

    /** Frase que explica la regla con los conteos que la respaldan. */
    public function explain(): string
    {
        $pct = number_format((float) $this->confidence * 100, 1, '.', ',');
        $lift = number_format((float) $this->lift, 2, '.', ',');

        return "{$this->both_customers} de {$this->antecedent_customers} clientes que compran «{$this->antecedent}» ({$pct} %) también compran «{$this->consequent}». "
            ."La elevación es {$lift}: compran «{$this->consequent}» {$lift} veces más que el cliente promedio ({$this->consequent_customers} de {$this->total_customers}).";
    }
}
