<?php

declare(strict_types=1);

namespace App\Automations;

use App\Models\CreditInvoice;
use App\Services\CreditAging;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Facturas con saldo que pasaron a otro tramo de cartera en los últimos dos días. A cada persona con acceso a crédito, un resumen de
 * las que ve (un vendedor, las suyas); la clave del resumen depende de las facturas y tramos que contiene, así que un resumen
 * idéntico no se repite.
 */
class CreditBucketHandler extends NoticeHandler
{
    private const LOOKBACK_DAYS = 2;

    protected function notices(array $params): void
    {
        $today = CarbonImmutable::today();
        $balances = DB::table('credit_movements')->selectRaw('credit_invoice_id, sum(amount) as balance')->groupBy('credit_invoice_id');
        $open = DB::table('credit_invoices as i')->joinSub($balances, 'm', 'm.credit_invoice_id', '=', 'i.id')->where('m.balance', '>', 0)->where('m.balance', '>=', $params['min_balance'])
            ->get(['i.id', 'i.series', 'i.number', 'i.issued_on', 'i.company_id', 'm.balance']);

        /** @var array<int, array{label: string, bucket: string, balance: float}> $crossed */
        $crossed = [];
        foreach ($open as $i) {
            $age = max(0, (int) CarbonImmutable::parse($i->issued_on)->startOfDay()->diffInDays($today));
            $bucket = CreditAging::bucket($age);
            if ($bucket !== CreditAging::bucket(max(0, $age - self::LOOKBACK_DAYS))) {
                $crossed[(int) $i->id] = ['label' => "{$i->series}-{$i->number}", 'bucket' => $bucket, 'balance' => (float) $i->balance];
            }
        }
        if ($crossed === []) {
            return;
        }

        foreach ($this->notifier->usersWith('credit.view') as $user) {
            $visible = $this->notifier->scopedAs($user, fn () => CreditInvoice::query()->whereIn('id', array_keys($crossed))->pluck('id')->all());
            if ($visible === []) {
                continue;
            }
            sort($visible);
            $rows = array_map(fn (int $id) => $crossed[$id], $visible);
            $total = number_format(array_sum(array_column($rows, 'balance')), 2, '.', ',');
            $tramo = fn (string $bucket) => $bucket === CreditAging::CURRENT ? 'al día' : (str_ends_with($bucket, '+') ? 'más de '.((int) $bucket - 1).' días' : str_replace('-', ' a ', $bucket).' días');
            $list = implode(', ', array_map(fn (array $r) => "{$r['label']} (".$tramo($r['bucket']).')', array_slice($rows, 0, 5)));
            $signature = md5(implode('|', array_map(fn (int $id) => $id.':'.$crossed[$id]['bucket'], $visible)));
            $this->notify($user, 'credit_bucket', $this->plural(count($rows), 'factura pasó', 'facturas pasaron').' a otro tramo de cartera', "Saldo {$total}. ".$list.(count($rows) > 5 ? ' y más.' : ''), '/credito', "credit_bucket:{$signature}:{$user->id}", 'warning');
        }
    }
}
