<?php

declare(strict_types=1);

namespace App\Services\Reports\Output;

use App\Http\Requests\ReportDetailRequest;
use App\Models\User;
use App\Services\Reports\Branches\BranchCategoryMix;
use App\Services\Reports\Branches\BranchReport;
use App\Services\Reports\Conciliation\ConciliationReport;
use App\Services\Reports\FilterDescriber;
use App\Services\Reports\Operators\OperatorReport;
use App\Services\Reports\Output\Documents\BranchDocument;
use App\Services\Reports\Output\Documents\ConciliationDocument;
use App\Services\Reports\Output\Documents\OperatorDocument;
use App\Services\Reports\Output\Documents\SellerDocument;
use App\Services\Reports\ReportScope;
use App\Services\Reports\Sellers\SellerReport;

/** Los cuatro reportes exportables: arma el reporte con los mismos servicios que usa la pantalla y lo convierte en documento. */
class ReportCatalog
{
    public const REPORTS = ['sellers', 'operators', 'branches', 'conciliation'];

    public function __construct(
        private readonly ReportScope $scope,
        private readonly FilterDescriber $describer,
        private readonly SellerReport $sellers,
        private readonly OperatorReport $operators,
        private readonly BranchReport $branches,
        private readonly BranchCategoryMix $mix,
        private readonly ConciliationReport $conciliation,
    ) {}

    public function document(string $report, User $user, ReportDetailRequest $request): ReportDocument
    {
        $companyIds = $this->scope->companyIds($user, $request->companyId());
        $filters = $request->salesFilters();
        $text = $this->describer->describe($filters + ($request->companyId() === null ? [] : ['company_id' => $request->companyId()]));

        return match ($report) {
            'sellers' => (new SellerDocument)->build($this->sellers->build($companyIds, $request->period(), $filters), $text),
            'operators' => (new OperatorDocument)->build($this->operators->build($companyIds, $request->period(), $filters), $text),
            'branches' => (new BranchDocument)->build(
                $this->branches->build($companyIds, $request->period(), $filters),
                $text,
                $this->mix->build($companyIds, $request->from(), $request->to(), ['branch_ids' => $this->branches->visibleBranchIds($companyIds, $filters)]),
            ),
            'conciliation' => (new ConciliationDocument)->build($this->conciliation->build($companyIds, $request->from(), $request->to(), $filters), $text),
            default => throw new \InvalidArgumentException("Reporte desconocido: $report"),
        };
    }
}
