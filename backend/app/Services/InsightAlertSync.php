<?php

namespace App\Services;

use App\Models\InsightAlert;
use App\Models\InsightRun;
use Illuminate\Support\Facades\DB;

/**
 * Aplica el resultado de un cálculo a las alertas de una compañía sin duplicarlas: una alerta vigente se actualiza (y se reabre
 * si se había resuelto sola), una nueva se crea, las que dejaron de cumplirse se marcan «resueltas», y una alerta que una persona
 * cerró no se reabre por el mismo hecho.
 */
class InsightAlertSync
{
    /**
     * @param  list<string>  $types  tipos de alerta que este cálculo gobierna
     * @param  list<array{customer_code: string, customer_name: string, type: string, severity: string, dedupe_key: string, facts: array<string, mixed>, explanation: string}>  $candidates
     * @return array{new: int, updated: int, resolved: int}
     */
    public function sync(InsightRun $run, array $types, array $candidates): array
    {
        $counts = ['new' => 0, 'updated' => 0, 'resolved' => 0];
        $now = now();

        DB::transaction(function () use ($run, $types, $candidates, $now, &$counts) {
            $existing = InsightAlert::withoutGlobalScopes()->where('company_id', $run->company_id)->whereIn('type', $types)->get()->keyBy('dedupe_key');
            $seen = [];
            foreach ($candidates as $c) {
                $seen[$c['dedupe_key']] = true;
                $alert = $existing[$c['dedupe_key']] ?? null;
                if ($alert === null) {
                    InsightAlert::withoutGlobalScopes()->create($c + ['company_id' => $run->company_id, 'insight_run_id' => $run->id, 'status' => InsightAlert::OPEN, 'first_detected_at' => $now, 'last_detected_at' => $now]);
                    $counts['new']++;

                    continue;
                }
                if ($alert->status === InsightAlert::CLOSED) {
                    continue;
                }
                $alert->forceFill([
                    'insight_run_id' => $run->id, 'severity' => $c['severity'], 'facts' => $c['facts'], 'explanation' => $c['explanation'], 'customer_name' => $c['customer_name'],
                    'status' => InsightAlert::OPEN, 'resolved_at' => null, 'last_detected_at' => $now,
                ])->save();
                $counts['updated']++;
            }
            foreach ($existing as $key => $alert) {
                if (! isset($seen[$key]) && $alert->status === InsightAlert::OPEN) {
                    $alert->forceFill(['status' => InsightAlert::RESOLVED, 'resolved_at' => $now, 'insight_run_id' => $run->id])->save();
                    $counts['resolved']++;
                }
            }
        });

        return $counts;
    }
}
