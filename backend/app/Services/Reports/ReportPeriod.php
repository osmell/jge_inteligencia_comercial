<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Periodo de un reporte y el periodo con el que se compara. */
final readonly class ReportPeriod
{
    public const PREVIOUS = 'previous';

    public const LAST_YEAR = 'last_year';

    public const CUSTOM = 'custom';

    public const MODES = [self::PREVIOUS, self::LAST_YEAR, self::CUSTOM];

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public CarbonImmutable $compareFrom,
        public CarbonImmutable $compareTo,
        public string $mode,
    ) {}

    /**
     * @param  string  $mode  previous: los días inmediatamente anteriores, de igual duración · last_year: las mismas fechas un año antes · custom: el rango dado
     */
    public static function resolve(CarbonImmutable $from, CarbonImmutable $to, string $mode = self::PREVIOUS, ?CarbonImmutable $customFrom = null, ?CarbonImmutable $customTo = null): self
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();
        if ($to < $from) {
            throw new InvalidArgumentException('El fin del periodo no puede ser anterior a su inicio.');
        }

        [$compareFrom, $compareTo] = match ($mode) {
            self::PREVIOUS => [$from->subDays($from->diffInDays($to) + 1), $from->subDay()],
            self::LAST_YEAR => [$from->subYearsNoOverflow(1), $to->subYearsNoOverflow(1)],
            self::CUSTOM => self::custom($customFrom, $customTo),
            default => throw new InvalidArgumentException("Modo de comparación no soportado: $mode"),
        };

        return new self($from, $to, $compareFrom, $compareTo, $mode);
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    public function compareDays(): int
    {
        return (int) $this->compareFrom->diffInDays($this->compareTo) + 1;
    }

    /** El periodo de comparación dura distinto (p. ej. un mes de 31 días contra uno de 28): las cifras no son directamente comparables. */
    public function isUnevenComparison(): bool
    {
        return $this->days() !== $this->compareDays();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private static function custom(?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        if ($from === null || $to === null || $to->startOfDay() < $from->startOfDay()) {
            throw new InvalidArgumentException('El periodo de comparación personalizado necesita un inicio y un fin válidos.');
        }

        return [$from->startOfDay(), $to->startOfDay()];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'period' => ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString(), 'days' => $this->days()],
            'comparison' => ['mode' => $this->mode, 'from' => $this->compareFrom->toDateString(), 'to' => $this->compareTo->toDateString(), 'days' => $this->compareDays(), 'uneven' => $this->isUnevenComparison()],
        ];
    }
}
