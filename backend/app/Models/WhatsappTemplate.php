<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plantilla de mensaje. Solo las aprobadas (por Meta) se pueden enviar.
 *
 * @property int $id
 * @property string $name
 * @property string $language
 * @property string $category
 * @property string $body
 * @property string $status
 */
class WhatsappTemplate extends Model
{
    public const CATEGORIES = ['utility', 'marketing', 'authentication'];

    public const STATUSES = ['draft', 'pending', 'approved', 'rejected'];

    protected $guarded = [];

    /** Cantidad de variables ({{1}}, {{2}}…) que pide el cuerpo. */
    public function variableCount(): int
    {
        preg_match_all('/\{\{(\d+)\}\}/', $this->body, $m);

        return $m[1] === [] ? 0 : (int) max($m[1]);
    }

    /** @param list<string> $params */
    public function render(array $params): string
    {
        return (string) preg_replace_callback('/\{\{(\d+)\}\}/', fn (array $m) => $params[(int) $m[1] - 1] ?? '', $this->body);
    }
}
