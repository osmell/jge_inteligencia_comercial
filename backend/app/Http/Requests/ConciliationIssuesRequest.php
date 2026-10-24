<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Reports\Conciliation\CreditConciliation;
use App\Services\Reports\Conciliation\SalesIntegrityChecks;
use Illuminate\Validation\Rule;

/** Documentos que tienen una diferencia de conciliación concreta. */
class ConciliationIssuesRequest extends ReportDetailRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + ['code' => ['required', Rule::in([...SalesIntegrityChecks::CODES, CreditConciliation::CODE])]];
    }

    public function code(): string
    {
        return (string) $this->validated('code');
    }
}
