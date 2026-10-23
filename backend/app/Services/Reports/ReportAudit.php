<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\User;
use App\Services\AuditLogger;

/**
 * Deja constancia de quién consultó, exportó o envió un reporte, con qué filtros y —en los correos— a qué direcciones.
 * Los reportes muestran el desempeño de personas: saber quién los ve es parte de la evaluación.
 */
class ReportAudit
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $filters */
    public function viewed(User $user, string $report, array $filters): void
    {
        $this->record('report.viewed', $user, ['report' => $report, 'filters' => $filters]);
    }

    /** @param  array<string, mixed>  $filters */
    public function exported(User $user, string $report, string $format, array $filters): void
    {
        $this->record('report.exported', $user, ['report' => $report, 'format' => $format, 'filters' => $filters]);
    }

    /**
     * @param  list<string>  $recipients
     * @param  array<string, mixed>  $filters
     */
    public function emailed(User $user, string $report, string $format, array $recipients, array $filters): void
    {
        $this->record('report.emailed', $user, ['report' => $report, 'format' => $format, 'recipients' => $recipients, 'filters' => $filters]);
    }

    /** @param  array<string, mixed>  $values */
    private function record(string $event, User $user, array $values): void
    {
        $company = $values['filters']['company_id'] ?? null;

        $this->audit->record($event, $user, new: $values, companyId: is_numeric($company) ? (int) $company : null);
    }
}
