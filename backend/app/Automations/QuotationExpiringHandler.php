<?php

declare(strict_types=1);

namespace App\Automations;

use App\Models\Customer;
use App\Models\Quotation;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Cotizaciones vigentes que vencen pronto (con la anticipación configurada y el mismo día) o que vencieron en los últimos días. Se avisa
 * a quien la registró y al usuario del vendedor, solo si ven al cliente; un aviso por cotización y estado.
 */
class QuotationExpiringHandler extends NoticeHandler
{
    private const LOOKBACK_DAYS = 3;

    protected function notices(array $params): void
    {
        $today = CarbonImmutable::today();
        $quotations = Quotation::withoutGlobalScopes()->with(['customer:id,name', 'seller:id,user_id', 'user:id,is_active'])->whereIn('status', Quotation::EXPIRABLE)->whereNotNull('valid_until')
            ->whereBetween('valid_until', [$today->subDays(self::LOOKBACK_DAYS)->toDateString(), $today->addDays($params['days_before'])->toDateString()])->get();

        foreach ($quotations as $q) {
            $validUntil = CarbonImmutable::parse($q->valid_until);
            $expired = $validUntil->lt($today);
            $days = (int) $today->diffInDays($validUntil);
            $when = $expired ? 'venció' : ($days === 0 ? 'vence hoy' : 'vence en '.$this->plural($days, 'día', 'días'));
            $amount = number_format((float) $q->amount, 2, ',', '.');
            foreach ($this->recipients($q) as $user) {
                if (! $this->notifier->scopedAs($user, fn () => Customer::query()->whereKey($q->customer_id)->exists())) {
                    continue;
                }
                $this->notify($user, 'quotation_expiring', "La cotización {$q->number} {$when}", "{$q->customer?->name} · {$amount}", "/cotizaciones/{$q->id}", "quotation:{$q->id}:".($expired ? 'expired' : 'expiring'), $expired ? 'danger' : 'warning', $q->company_id);
            }
        }
    }

    /** @return list<User> */
    private function recipients(Quotation $q): array
    {
        $ids = array_unique(array_filter([$q->user_id, $q->seller?->user_id]));

        return array_values(User::query()->whereIn('id', $ids)->where('is_active', true)->get()->all());
    }
}
