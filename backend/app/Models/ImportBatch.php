<?php

namespace App\Models;

use App\Enums\ImportStatus;
use Database\Factories\ImportBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $type
 * @property ImportStatus $status
 * @property string $original_name
 * @property string $stored_path
 * @property string $file_hash
 * @property int $user_id
 * @property int $rows_total
 * @property int $documents_total
 * @property int $documents_new
 * @property int $documents_duplicate
 * @property int $errors_total
 * @property int $warnings_total
 * @property array<string, mixed>|null $summary
 */
#[Fillable([
    'type', 'status', 'original_name', 'stored_path', 'file_hash', 'file_size', 'user_id', 'rows_total', 'documents_total',
    'documents_new', 'documents_duplicate', 'errors_total', 'warnings_total', 'summary', 'failure_message', 'validated_at', 'finished_at',
])]
class ImportBatch extends Model
{
    /** @use HasFactory<ImportBatchFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'summary' => 'array',
            'validated_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ImportError, $this> */
    public function errors(): HasMany
    {
        return $this->hasMany(ImportError::class);
    }

    /** @return HasMany<SalesDocument, $this> */
    public function salesDocuments(): HasMany
    {
        return $this->hasMany(SalesDocument::class);
    }
}
