<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopedByOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Neto corregido de una línea vendida por equivalencia. Es un dato derivado: la línea original no cambia.
 *
 * @property int $id
 * @property int $sales_line_id
 * @property string $cause
 * @property string $corrected_net
 * @property string $corrected_tax
 */
#[Fillable(['sales_line_id', 'company_id', 'branch_id', 'issued_on', 'cause', 'corrected_net', 'corrected_tax', 'rule_version'])]
class SalesLineCorrection extends Model
{
    use ScopedByOrganization;

    public const PORTION = 'portion';

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'corrected_net' => 'decimal:4', 'corrected_tax' => 'decimal:4'];
    }

    /** @return BelongsTo<SalesLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(SalesLine::class, 'sales_line_id');
    }
}
