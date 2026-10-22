<?php

declare(strict_types=1);

namespace App\Support;

/**
 * ¿Es una dirección a la que el servidor puede llamar sin riesgo de alcanzar la red interna? (SSRF)
 * Exige http(s), y rechaza localhost, direcciones privadas, de enlace local (incluida la de metadatos de la nube 169.254.169.254) y
 * reservadas, tanto si se escriben como número como si el nombre resuelve a una de ellas. En producción además exige HTTPS.
 */
final class PublicUrl
{
    public static function isSafe(string $url, ?bool $production = null): bool
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (($production ?? app()->isProduction()) && $scheme !== 'https') {
            return false;
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : self::resolve($host);
        if ($addresses === []) {
            return false;
        }
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            $ips[] = (string) ($record['ipv6'] ?? '');
        }

        return array_values(array_filter($ips));
    }
}
