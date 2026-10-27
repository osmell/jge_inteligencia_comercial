<?php

declare(strict_types=1);

namespace App\Automations;

use App\Models\Company;
use App\Models\Digest;
use App\Models\User;
use App\Services\DataScope;
use App\Services\DigestBuilder;
use App\Services\Notifier;

/** Genera el resumen diario de cada compañía activa y avisa a quien ve toda la compañía y tiene acceso a los resúmenes. */
class DigestDailyHandler extends NoticeHandler
{
    public function __construct(Notifier $notifier, protected readonly DigestBuilder $builder, protected readonly DataScope $scope)
    {
        parent::__construct($notifier);
    }

    protected function digest(Company $company): Digest
    {
        return $this->builder->daily($company);
    }

    protected function notices(array $params): void
    {
        foreach (Company::withoutGlobalScopes()->where('is_active', true)->get() as $company) {
            $digest = $this->digest($company);
            $summary = $this->summary($digest);
            foreach ($this->notifier->usersWith('digests.view') as $user) {
                if ($this->fullAccess($user, $company->id)) {
                    $this->notify($user, 'digest', $digest->title, $summary, "/resumenes/{$digest->id}", "digest:{$digest->id}", 'info', $company->id);
                }
            }
        }
    }

    /** Solo ve un resumen quien tiene acceso completo a la compañía (no restringido a sucursales). */
    protected function fullAccess(User $user, int $companyId): bool
    {
        $ids = $this->scope->fullCompanyIds($user);

        return $ids === null || in_array($companyId, $ids, true);
    }

    private function summary(Digest $digest): string
    {
        $metric = $digest->data['sections'][0]['metrics'][0] ?? null;
        if ($metric === null) {
            return 'Abre el resumen para ver las cifras.';
        }
        $value = $metric['format'] === 'money' ? number_format((float) $metric['value'], 2, '.', ',') : (string) $metric['value'];

        return "{$metric['label']}: {$value}".(isset($metric['hint']) ? " ({$metric['hint']})" : '');
    }
}
