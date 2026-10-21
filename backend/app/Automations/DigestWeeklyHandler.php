<?php

namespace App\Automations;

use App\Models\Company;
use App\Models\Digest;

/** Igual que el diario, con el resumen de la semana anterior. */
class DigestWeeklyHandler extends DigestDailyHandler
{
    protected function digest(Company $company): Digest
    {
        return $this->builder->weekly($company);
    }
}
