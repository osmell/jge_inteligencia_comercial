<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $import_batch_id
 * @property int|null $row_number
 * @property string|null $column
 * @property string $code
 * @property string $severity
 * @property string $message
 * @property string|null $value
 */
#[Fillable(['import_batch_id', 'row_number', 'column', 'code', 'severity', 'message', 'value'])]
class ImportError extends Model
{
    public const UPDATED_AT = null;

    public const ERROR = 'error';

    public const WARNING = 'warning';

    /** @return BelongsTo<ImportBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
