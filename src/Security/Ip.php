<?php

namespace Snapshot\Security;

use Symfony\Component\HttpFoundation\IpUtils;

class Ip
{
    public const MIN_V4_PREFIX = 8;

    public const MIN_V6_PREFIX = 19;

    private ?string $client = null;

    public function __construct(private Settings $settings) {}

    public static function valid(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    public static function public(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    public static function hex(string $ip): ?string
    {
        $binary = self::valid($ip) ? inet_pton($ip) : false;

        if ($binary === false) {
            return null;
        }

        if (strlen($binary) === 4) {
            $binary = str_repeat("\0", 10)."\xff\xff".$binary;
        }

        return bin2hex($binary);
    }

    public static function range(string $cidr): ?array
    {
        $cidr = trim($cidr);
        [$address, $bits] = array_pad(explode('/', $cidr, 2), 2, null);

        if (! self::valid((string) $address)) {
            return null;
        }

        $isV4 = str_contains($address, '.') && ! str_contains($address, ':');
        $max = $isV4 ? 32 : 128;

        if ($bits === null || $bits === '') {
            $bits = $max;
        } elseif (! ctype_digit($bits) || (int) $bits > $max) {
            return null;
        }

        $bits = (int) $bits + ($isV4 ? 96 : 0);
        $bytes = hex2bin(self::hex($address));
        $start = '';
        $end = '';

        for ($i = 0; $i < 16; $i++) {
            $keep = max(0, min(8, $bits - ($i * 8)));
            $mask = (0xFF << (8 - $keep)) & 0xFF;
            $byte = ord($bytes[$i]);
            $start .= chr($byte & $mask);
            $end .= chr($byte | (~$mask & 0xFF));
        }

        return [
            'start' => bin2hex($start),
            'end' => bin2hex($end),
            'label' => $bits === 128 ? $address : $address.'/'.((int) $bits - ($isV4 ? 96 : 0)),
            'prefix' => (int) $bits - ($isV4 ? 96 : 0),
            'v4' => $isV4,
        ];
    }

    public static function tooWide(string $cidr): bool
    {
        $range = self::range($cidr);

        return $range !== null && $range['prefix'] < ($range['v4'] ? self::MIN_V4_PREFIX : self::MIN_V6_PREFIX);
    }

    public static function matches(string $ip, array $ranges): bool
    {
        $ip = self::normalize($ip);
        $ranges = array_values(array_filter($ranges, fn ($range) => self::range((string) $range) !== null));

        return $ranges !== [] && self::valid($ip) && IpUtils::checkIp($ip, $ranges);
    }

    public static function normalize(string $ip): string
    {
        $ip = trim($ip);

        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $match) && self::valid($match[1])) {
            return $match[1];
        }

        return $ip;
    }

    public static function bucket(string $ip): string
    {
        if (str_contains($ip, ':')) {
            $range = self::range($ip.'/64');

            return $range ? inet_ntop(hex2bin($range['start'])).'/64' : $ip;
        }

        return $ip;
    }

    public function client(): string
    {
        return $this->client ??= self::normalize($this->resolve());
    }

    public function blockable(string $ip): bool
    {
        $ip = self::normalize($ip);

        return self::public($ip)
            && ! self::matches($ip, $this->cloudflareRanges())
            && ! self::matches($ip, $this->settings->lines('trusted_proxies'))
            && ! self::matches($ip, $this->settings->lines('allowlist'));
    }

    public function cloudflareRanges(): array
    {
        $stored = get_option('snapshot_security_cloudflare_ranges', []);

        return is_array($stored) && $stored !== [] ? $stored : snap_security_cloudflare_ranges();
    }

    private function resolve(): string
    {
        $remote = self::normalize((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

        if (! self::valid($remote)) {
            return '0.0.0.0';
        }

        if ($this->settings->bool('cloudflare_enabled') && self::matches($remote, $this->cloudflareRanges())) {
            $connecting = self::normalize((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));

            if (self::valid($connecting)) {
                return $connecting;
            }
        }

        $trusted = $this->settings->lines('trusted_proxies');

        if ($trusted !== [] && self::matches($remote, $trusted) && ! empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $hops = array_reverse(array_map([self::class, 'normalize'], explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])));

            foreach ($hops as $hop) {
                if (! self::valid($hop)) {
                    break;
                }

                if (! self::matches($hop, $trusted)) {
                    return $hop;
                }
            }
        }

        return $remote;
    }
}
