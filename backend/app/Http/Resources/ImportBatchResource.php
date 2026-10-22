<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ImportStatus;
use App\Models\ImportBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ImportBatch */
class ImportBatchResource extends JsonResource
{
    public function __construct($resource, private readonly bool $detail = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => config('imports.labels.'.$this->type, $this->type),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'original_name' => $this->original_name,
            'file_size' => $this->file_size,
            'rows_total' => $this->rows_total,
            'documents_total' => $this->documents_total,
            'documents_new' => $this->documents_new,
            'documents_duplicate' => $this->documents_duplicate,
            'errors_total' => $this->errors_total,
            'warnings_total' => $this->warnings_total,
            'failure_message' => $this->failure_message,
            'user' => $this->whenLoaded('user', fn () => ['id' => $this->user->id, 'username' => $this->user->username, 'name' => $this->user->name]),
            'created_at' => $this->created_at,
            'validated_at' => $this->validated_at,
            'finished_at' => $this->finished_at,
            'can_confirm' => $this->status === ImportStatus::Validated,
            'can_discard' => $this->status->isDiscardable(),
            'summary' => $this->when($this->detail, $this->summary),
        ];
    }
}
