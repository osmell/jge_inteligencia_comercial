<?php

declare(strict_types=1);

namespace App\Automations;

use App\Models\CreditInvoice;
use App\Models\PaymentPromise;
use App\Models\User;
use App\Services\Notifier;
use App\Services\PaymentPromises;
use Carbon\CarbonImmutable;

/**
 * Promesas de pago que vencen hoy (todavía sin cumplirse) o que acaban de quedar incumplidas. Se avisa a quien la registró y a los
 * vendedores de las facturas del cliente; el estado se deriva de los abonos, desde el alcance de cada destinatario.
 */
class PromiseDueHandler extends NoticeHandler
{
    private const LOOKBACK_DAYS = 3;

    public function __construct(Notifier $notifier, private readonly PaymentPromises $promises)
    {
        parent::__construct($notifier);
    }

    protected function notices(array $params): void
    {
        $today = CarbonImmutable::today();
        $candidates = PaymentPromise::withoutGlobalScopes()->whereNull('cancelled_at')->whereBetween('promised_on', [$today->subDays(self::LOOKBACK_DAYS)->toDateString(), $today->toDateString()])->get();

        foreach ($candidates as $promise) {
            foreach ($this->recipients($promise) as $user) {
                $row = $this->notifier->scopedAs($user, fn () => collect($this->promises->list($user, ['company_id' => $promise->company_id, 'code' => $promise->customer_code, 'status' => 'all'], $today)['rows'])->firstWhere('id', $promise->id));
                if ($row === null) {
                    continue;
                }
                $amount = number_format((float) $promise->amount, 2, '.', ',');
                $url = '/clientes/ficha?'.http_build_query(['codigo' => $promise->customer_code, 'compania' => $promise->company_id]);
                if ($row['status'] === PaymentPromises::OPEN && $row['promised_on'] === $today->toDateString()) {
                    $this->notify($user, 'promise_due', "La promesa de pago de {$promise->customer_name} vence hoy", "Prometió {$amount} para hoy.", $url, "promise:{$promise->id}:due", 'warning', $promise->company_id);
                } elseif ($row['status'] === PaymentPromises::BROKEN) {
                    $this->notify($user, 'promise_due', "Promesa de pago incumplida: {$promise->customer_name}", "Prometió {$amount} para el {$row['promised_on']} y no se completó el abono.", $url, "promise:{$promise->id}:broken", 'danger', $promise->company_id);
                }
            }
        }
    }

    /** @return list<User> */
    private function recipients(PaymentPromise $promise): array
    {
        $sellerUsers = CreditInvoice::withoutGlobalScopes()->join('sellers', 'sellers.id', '=', 'credit_invoices.seller_id')->where(['credit_invoices.company_id' => $promise->company_id, 'credit_invoices.customer_code' => $promise->customer_code])
            ->whereNotNull('sellers.user_id')->pluck('sellers.user_id')->all();
        $ids = array_unique(array_filter([$promise->user_id, ...$sellerUsers]));

        return array_values(User::query()->whereIn('id', $ids)->where('is_active', true)->get()->all());
    }
}
