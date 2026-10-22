<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ImportStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Operator;
use App\Models\Seller;
use App\Models\User;
use Database\Seeders\Demo\DemoSalesGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ventas ficticias de 12 meses para desarrollo y demostraciones: `php artisan db:seed --class=DemoSalesSeeder`.
 * Nunca en producción. Es repetible: borra la carga de demostración anterior (la serie DEMO) y la regenera.
 * Escribe con el constructor de consultas porque las ventas son inmutables desde los modelos.
 */
class DemoSalesSeeder extends Seeder
{
    public const FILE_HASH_SEED = 'demo-sales';

    private float $scale = 1.0;

    public function scale(float $scale): static
    {
        $this->scale = $scale;

        return $this;
    }

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSalesSeeder no puede ejecutarse en producción.');
        }

        // Compañías, sucursales y usuarios de demostración.
        if (! Company::withoutGlobalScopes()->where('code', 'ALFA')->exists()) {
            $this->call(RolePermissionSeeder::class);
            $this->call(DevDataSeeder::class);
        }

        DB::transaction(function () {
            $this->purge();

            $companies = Company::withoutGlobalScopes()->whereIn('code', array_keys(DemoSalesGenerator::COMPANIES))->get()->keyBy('code');
            $branches = Branch::withoutGlobalScopes()->get()->groupBy('company_id')->map(fn ($b) => $b->keyBy('code'));
            $people = $this->people($companies);
            $batchId = $this->batch();

            $now = now();
            $lineBuffer = [];

            foreach ((new DemoSalesGenerator($this->scale))->documents() as $doc) {
                $company = $companies[$doc['company']];
                $branch = $branches[$company->id][$doc['branch']];

                $documentId = DB::table('sales_documents')->insertGetId([
                    'company_id' => $company->id,
                    'branch_id' => $branch->id,
                    'series' => $doc['series'],
                    'number' => $doc['number'],
                    'issued_on' => $doc['issued_on'],
                    'issued_at' => null,
                    'customer_code' => $doc['customer_code'],
                    'customer_name' => $doc['customer_name'],
                    'seller_id' => $people['sellers'][$doc['company'].'|'.$doc['seller']],
                    'operator_id' => $people['operators'][$doc['company'].'|'.$doc['operator']],
                    'import_batch_id' => $batchId,
                    'lines_count' => count($doc['lines']),
                    'content_hash' => hash('sha256', "demo|{$company->id}|{$doc['series']}|{$doc['number']}"),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach ($doc['lines'] as $i => $line) {
                    $lineBuffer[] = [
                        'sales_document_id' => $documentId,
                        'company_id' => $company->id,
                        'branch_id' => $branch->id,
                        'issued_on' => $doc['issued_on'],
                        'line_number' => $i + 1,
                        ...$line,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if (count($lineBuffer) >= 1000) {
                    DB::table('sales_lines')->insert($lineBuffer);
                    $lineBuffer = [];
                }
            }

            if ($lineBuffer) {
                DB::table('sales_lines')->insert($lineBuffer);
            }

            $this->summarize($batchId);
            $this->linkDemoUsers($people['sellers']);
        });
    }

    /** Borra solo la carga de demostración anterior (identificada por su lote). */
    private function purge(): void
    {
        $batch = DB::table('import_batches')->where('type', 'sales')->where('file_hash', hash('sha256', self::FILE_HASH_SEED))->value('id');
        if (! $batch) {
            return;
        }

        DB::table('sales_lines')->whereIn('sales_document_id', DB::table('sales_documents')->where('import_batch_id', $batch)->select('id'))->delete();
        DB::table('sales_documents')->where('import_batch_id', $batch)->delete();
        DB::table('import_batches')->where('id', $batch)->delete();
    }

    private function batch(): int
    {
        return DB::table('import_batches')->insertGetId([
            'type' => 'sales',
            'status' => ImportStatus::Imported->value,
            'original_name' => 'DATOS DE DEMOSTRACIÓN (ficticios)',
            'stored_path' => 'demo/ninguno.xlsx',
            'file_hash' => hash('sha256', self::FILE_HASH_SEED),
            'file_size' => 0,
            'user_id' => User::where('username', 'admin')->value('id') ?? User::query()->value('id'),
            'validated_at' => now(),
            'finished_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{sellers: array<string, int>, operators: array<string, int>} clave «COMPAÑÍA|nombre» => id */
    private function people(Collection $companies): array
    {
        $out = ['sellers' => [], 'operators' => []];

        foreach (DemoSalesGenerator::COMPANIES as $code => $spec) {
            $companyId = $companies[$code]->id;
            foreach (array_keys($spec['sellers']) as $name) {
                $out['sellers']["{$code}|{$name}"] = Seller::firstOrCreate(['company_id' => $companyId, 'normalized_name' => Seller::normalize($name)], ['name' => $name])->id;
            }
            foreach ($spec['operators'] as $name) {
                $out['operators']["{$code}|{$name}"] = Operator::firstOrCreate(['company_id' => $companyId, 'normalized_name' => Operator::normalize($name)], ['name' => $name])->id;
            }
        }

        return $out;
    }

    private function summarize(int $batchId): void
    {
        $totals = DB::table('sales_lines as l')->join('sales_documents as d', 'd.id', '=', 'l.sales_document_id')->where('d.import_batch_id', $batchId)
            ->selectRaw('count(distinct d.id) as docs, count(*) as lines, min(d.issued_on) as first, max(d.issued_on) as last, sum(l.net_total) as net')->first();

        DB::table('import_batches')->where('id', $batchId)->update([
            'rows_total' => $totals->lines,
            'documents_total' => $totals->docs,
            'documents_new' => $totals->docs,
            'summary' => json_encode(['demo' => true, 'date_from' => $totals->first, 'date_to' => $totals->last, 'net_total' => (string) $totals->net]),
        ]);
    }

    /** Vincula a los vendedores de demostración con sus usuarios de acceso (para probar «cada vendedor ve lo suyo»). */
    private function linkDemoUsers(array $sellers): void
    {
        foreach (['ALFA|Vendedor Uno Demo' => 'vendedor1', 'BETA|Vendedor Dos Demo' => 'vendedor2'] as $key => $username) {
            if (isset($sellers[$key]) && ($userId = User::where('username', $username)->value('id'))) {
                Seller::whereKey($sellers[$key])->update(['user_id' => $userId]);
            }
        }
    }
}
