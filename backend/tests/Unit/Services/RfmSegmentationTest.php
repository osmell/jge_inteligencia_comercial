<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\RfmSegmentation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class RfmSegmentationTest extends TestCase
{
    /** Las reglas de puntuación son privadas: se prueban sin construir el servicio (no usan sus dependencias). */
    private function call(string $method, mixed ...$args): mixed
    {
        $service = (new \ReflectionClass(RfmSegmentation::class))->newInstanceWithoutConstructor();

        return (new ReflectionMethod($service, $method))->invoke($service, ...$args);
    }

    /** @return array<string, array{int, float, bool, string}> */
    public static function segments(): array
    {
        return [
            'nuevo gana a todo' => [5, 5.0, true, 'new'],
            'campeón' => [4, 4.0, false, 'champion'],
            'leal por frecuencia/valor 3,5' => [4, 3.5, false, 'loyal'],
            'leal en el borde' => [3, 3.0, false, 'loyal'],
            'prometedor' => [3, 2.5, false, 'promising'],
            'prometedor con recencia alta' => [5, 2.0, false, 'promising'],
            'en riesgo' => [2, 3.0, false, 'at_risk'],
            'en riesgo con recencia mínima' => [1, 4.0, false, 'at_risk'],
            'dormido' => [2, 2.0, false, 'dormant'],
            'perdido' => [1, 2.0, false, 'lost'],
        ];
    }

    #[DataProvider('segments')]
    public function test_segment_rules(int $r, float $fm, bool $isNew, string $expected): void
    {
        $this->assertSame($expected, $this->call('segment', $r, $fm, $isNew));
    }

    public function test_every_segment_has_label_and_rule(): void
    {
        foreach (RfmSegmentation::SEGMENTS as $key => $segment) {
            $this->assertNotSame('', $segment['label'], $key);
            $this->assertNotSame('', $segment['rule'], $key);
        }
    }

    public function test_quintile_scores_from_one_to_five(): void
    {
        $all = [1, 2, 3, 4, 5];

        $this->assertSame(5, $this->call('quintile', 5, $all, true));
        $this->assertSame(3, $this->call('quintile', 3, $all, true));
        $this->assertSame(1, $this->call('quintile', 1, $all, true));
        // menor es mejor (recencia en días): el más reciente obtiene 5
        $this->assertSame(5, $this->call('quintile', 1, $all, false));
        $this->assertSame(1, $this->call('quintile', 5, $all, false));
    }

    public function test_quintile_gives_ties_the_same_score_and_caps_at_five(): void
    {
        $this->assertSame(1, $this->call('quintile', 7, [7, 7, 7, 7], true));
        $this->assertSame(5, $this->call('quintile', 9, [1], true));
    }

    public function test_explanation_sentence(): void
    {
        $text = RfmSegmentation::explain([
            'recency_days' => 12, 'frequency' => 3, 'monetary' => '1234.5', 'r' => 4, 'f' => 3, 'm' => 5, 'segment' => 'champion',
        ]);

        $this->assertSame('Compró por última vez hace 12 días (recencia 4/5), tuvo 3 días con compra (frecuencia 3/5) y una venta neta de 1,234.50 (valor 5/5): Campeones.', $text);
    }
}
