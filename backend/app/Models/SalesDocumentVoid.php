<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopedByOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Factura anulada según el detalle de facturación. Una anulación no se deshace: el registro no se reemplaza.
 *
 * @property int $id
 * @property int $company_id
 * @property string $document
 * @property int|null $sales_document_id
 */
#[Fillable(['company_id', 'document', 'sales_document_id', 'import_batch_id'])]
class SalesDocumentVoid extends Model
{
    use ScopedByOrganization;

    public static function organizationBranchColumn(): ?string
    {
        return null;
    }
}
