<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Generator;

/**
 * Genera ventas completamente ficticias y reproducibles (misma semilla = mismos datos) para desarrollo y demostraciones.
 * Es una función pura: no toca la base de datos. Ver DemoSalesSeeder para escribirlas.
 *
 * Patrones incluidos, para que los gráficos tengan qué mostrar: tendencia de crecimiento, estacionalidad
 * (picos en noviembre y diciembre, baja en enero y febrero), menos ventas en domingo, vendedores con rendimientos
 * distintos (uno en descenso), clientes concentrados (pocos compran mucho) y algunas líneas con descuento.
 */
final class DemoSalesGenerator
{
    public const SEED = 20261019;

    /** IVA ficticio de las ventas de demostración. */
    public const TAX_RATE = 0.12;

    public const SERIES = 'DEMO';

    /** @var array<string, array{branches: array<string, float>, sellers: array<string, array{branch: string, weight: float}>, operators: list<string>}> */
    public const COMPANIES = [
        'ALFA' => [
            'branches' => ['CEN' => 0.60, 'NOR' => 0.40],
            'sellers' => [
                'Vendedor Uno Demo' => ['branch' => 'CEN', 'weight' => 1.5],
                'Lucía Ramírez' => ['branch' => 'CEN', 'weight' => 1.0],
                'Jorge Paredes' => ['branch' => 'CEN', 'weight' => 0.7],
                'Ana Torres' => ['branch' => 'NOR', 'weight' => 1.2],
                'Marcos Solís' => ['branch' => 'NOR', 'weight' => 0.4], // va en descenso durante el año
            ],
            'operators' => ['Caja 1', 'Caja 2', 'Caja 3'],
        ],
        'BETA' => [
            'branches' => ['CEN' => 1.0],
            'sellers' => [
                'Vendedor Dos Demo' => ['branch' => 'CEN', 'weight' => 1.3],
                'Elena Castro' => ['branch' => 'CEN', 'weight' => 1.0],
                'Pablo Duarte' => ['branch' => 'CEN', 'weight' => 0.6],
            ],
            'operators' => ['Caja 1', 'Caja 2'],
        ],
    ];

    private const COMPANY_WEIGHT = ['ALFA' => 0.68, 'BETA' => 0.32];

    private const CATEGORIES = ['Filtros', 'Lubricantes', 'Frenos', 'Baterías', 'Llantas', 'Iluminación', 'Accesorios', 'Herramientas', 'Limpieza', 'Eléctricos'];

    private const CATEGORY_PRICE = [38, 55, 120, 260, 340, 70, 45, 90, 25, 60];

    /** Estacionalidad por mes (1 = enero). */
    private const SEASON = [1 => 0.85, 2 => 0.88, 3 => 1.0, 4 => 1.0, 5 => 1.02, 6 => 0.98, 7 => 0.97, 8 => 1.0, 9 => 1.03, 10 => 1.08, 11 => 1.25, 12 => 1.35];

    /** @var list<array{code: string, name: string, category: string, price: float, cost: float}> */
    private array $products = [];

    /** @var array<string, list<array{code: string, name: string}>> */
    private array $customers = [];

    /** @param  float  $scale  1.0 ≈ 6 000 documentos y 17 000 líneas en 12 meses */
    public function __construct(private readonly float $scale = 1.0) {}

    /**
     * @return Generator<int, array{company: string, branch: string, series: string, number: string, issued_on: string, customer_code: string, customer_name: string, seller: string, operator: string, lines: list<array<string, mixed>>}>
     */
    public function documents(?CarbonImmutable $today = null): Generator
    {
        mt_srand(self::SEED);
        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $start = $today->subMonthsNoOverflow(11)->startOfMonth();
        $this->products = $this->buildProducts();
        $this->customers = $this->buildCustomers();

        $totalDays = max(1, (int) $start->diffInDays($today) + 1); // diffInDays: positivo si el argumento es posterior
        $counters = array_fill_keys(array_keys(self::COMPANIES), 0);

        for ($day = $start; $day <= $today; $day = $day->addDay()) {
            $progress = $start->diffInDays($day) / $totalDays;                    // 0 → 1 a lo largo del año
            $trend = 0.80 + 0.40 * $progress;                                      // crece ~50 % de principio a fin
            $weekday = $day->dayOfWeekIso === 7 ? 0.25 : ($day->dayOfWeekIso === 6 ? 0.8 : 1.0);
            $expected = 19.0 * $this->scale * $trend * self::SEASON[$day->month] * $weekday;
            $count = (int) floor($expected) + (mt_rand() / mt_getrandmax() < $expected - floor($expected) ? 1 : 0);

            for ($i = 0; $i < $count; $i++) {
                $company = (string) $this->pick(self::COMPANY_WEIGHT);
                $spec = self::COMPANIES[$company];
                $seller = $this->pickSeller($company, $progress);
                $customer = $this->pickCustomer($company);

                yield [
                    'company' => $company,
                    'branch' => $spec['sellers'][$seller]['branch'],
                    'series' => self::SERIES,
                    'number' => str_pad((string) ++$counters[$company], 7, '0', STR_PAD_LEFT),
                    'issued_on' => $day->format('Y-m-d'),
                    'customer_code' => $customer['code'],
                    'customer_name' => $customer['name'],
                    'seller' => $seller,
                    'operator' => $spec['operators'][mt_rand(0, count($spec['operators']) - 1)],
                    'lines' => $this->lines(),
                ];
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function lines(): array
    {
        $n = $this->pick([1 => 0.22, 2 => 0.26, 3 => 0.22, 4 => 0.14, 5 => 0.1, 6 => 0.06]);
        $used = [];
        $lines = [];

        while (count($lines) < $n) {
            $i = $this->skewedIndex(count($this->products));
            if (isset($used[$i])) {
                continue;
            }
            $used[$i] = true;
            $p = $this->products[$i];

            $units = mt_rand(1, 100) <= 70 ? mt_rand(1, 3) : mt_rand(4, 12);
            $discount = mt_rand(1, 100) <= 30 ? mt_rand(1, 10) / 100 : 0.0;       // 30 % de las líneas con descuento
            $unitPrice = round($p['price'] * (1 - $discount), 4);
            $net = round($units * $unitPrice, 4);
            $tax = round($net * self::TAX_RATE, 4);

            $lines[] = [
                'product_code' => $p['code'],
                'product_name' => $p['name'],
                'category_name' => $p['category'],
                'units' => number_format($units, 4, '.', ''),
                'unit_cost' => number_format($p['cost'], 4, '.', ''),
                'line_cost_total' => number_format(round($units * $p['cost'], 4), 4, '.', ''),
                'unit_price' => number_format($unitPrice, 4, '.', ''),
                'normal_price' => number_format($p['price'], 4, '.', ''),
                'net_total' => number_format($net, 4, '.', ''),
                'tax_total' => number_format($tax, 4, '.', ''),
                'sale_plus_tax' => number_format($net + $tax, 4, '.', ''),
                'grand_total' => number_format($net + $tax, 4, '.', ''),
            ];
        }

        return $lines;
    }

    /** @return list<array{code: string, name: string, category: string, price: float, cost: float}> */
    private function buildProducts(): array
    {
        $products = [];
        foreach (self::CATEGORIES as $c => $category) {
            for ($k = 1; $k <= 8; $k++) {
                $price = round(self::CATEGORY_PRICE[$c] * (0.6 + 0.8 * (($k * 37 + $c * 11) % 10) / 10), 2);
                $products[] = [
                    'code' => str_pad((string) (100 + $c * 10 + $k), 6, '0', STR_PAD_LEFT),   // con ceros iniciales
                    'name' => "{$category} modelo {$k}",
                    'category' => $category,
                    'price' => $price,
                    'cost' => round($price * (0.55 + (($k * 13 + $c * 7) % 30) / 100), 4),      // costo actual: 55 % a 84 % del precio
                ];
            }
        }

        return $products;
    }

    /** @return array<string, list<array{code: string, name: string}>> */
    private function buildCustomers(): array
    {
        $first = ['Taller', 'Auto', 'Servicio', 'Repuestos', 'Garage', 'Flota', 'Transportes', 'Comercial', 'Distribuidora', 'Mecánica'];
        $last = ['Central', 'del Norte', 'Rápido', 'Andes', 'Pacífico', 'Moderno', 'Sur', 'Express', 'Unidos', 'Premium', 'Delta', 'Horizonte'];
        $customers = ['ALFA' => [], 'BETA' => []];

        for ($i = 1; $i <= 150; $i++) {
            $company = $i <= 90 ? 'ALFA' : 'BETA';
            $customers[$company][] = [
                'code' => 'C'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'name' => $first[$i % count($first)].' '.$last[intdiv($i, 3) % count($last)].' '.$i,
            ];
        }

        return $customers;
    }

    /** @param  array<string|int, float>  $weights */
    private function pick(array $weights): string|int
    {
        $r = mt_rand() / mt_getrandmax() * array_sum($weights);
        foreach ($weights as $key => $w) {
            if (($r -= $w) <= 0) {
                return $key;
            }
        }

        return array_key_last($weights) ?? throw new \LogicException('Sin pesos para elegir.');
    }

    private function pickSeller(string $company, float $progress): string
    {
        $weights = [];
        foreach (self::COMPANIES[$company]['sellers'] as $name => $s) {
            // Marcos Solís pierde peso durante el año; los demás se mantienen.
            $weights[$name] = $name === 'Marcos Solís' ? $s['weight'] * (1.6 - 1.4 * $progress) : $s['weight'];
        }

        return (string) $this->pick($weights);
    }

    /** @return array{code: string, name: string} clientes concentrados: los primeros compran mucho más */
    private function pickCustomer(string $company): array
    {
        $list = $this->customers[$company];

        return $list[$this->skewedIndex(count($list), 2.2)];
    }

    /** Índice en [0, n) con preferencia por los primeros (Pareto aproximado). */
    private function skewedIndex(int $n, float $power = 1.6): int
    {
        return min($n - 1, (int) floor($n * (mt_rand() / mt_getrandmax()) ** $power));
    }
}
