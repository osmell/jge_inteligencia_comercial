<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\RfmSegmentation;
use Illuminate\Console\Command;

class ComputeRfm extends Command
{
    protected $signature = 'insights:rfm {--company= : Id de la compañía}';

    protected $description = 'Recalcula la segmentación RFM de las compañías activas (registra cada cálculo).';

    public function handle(RfmSegmentation $rfm): int
    {
        $companies = Company::withoutGlobalScopes()->where('is_active', true)->when($this->option('company'), fn ($q) => $q->whereKey((int) $this->option('company')))->get();
        foreach ($companies as $company) {
            $run = $rfm->compute($company);
            $this->line("{$company->name}: ".($run->status === 'ok' ? 'calculado ('.($run->summary['customers'] ?? 0).' clientes)' : (string) $run->note));
        }

        return self::SUCCESS;
    }
}
