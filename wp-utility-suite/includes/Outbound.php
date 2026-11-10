<?php
namespace Wpus;

defined('ABSPATH') || exit;

final class Outbound
{
    public static function available(): bool
    {
        return function_exists('curl_init') && function_exists('dns_get_record') && !defined('WP_PROXY_HOST');
    }

    public static function origin(string $url): ?string
    {
        if (strlen($url) > 2048 || !preg_match(
            "#\Ahttps://([a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)(/[-A-Za-z0-9._~!$&'()*+,;=:@/]*)?\z#iD",
            $url,
            $match
        )) {
            return null;
        }
        $host = strtolower($match[1]);
        if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || !preg_match('/\.[a-z][a-z0-9-]+\z/D', $host)
            || filter_var($host, FILTER_VALIDATE_IP)
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~D', $match[2] ?? '')) {
            return null;
        }
        return 'https://' . $host;
    }

    public static function approved(string $origin): bool
    {
        return defined('WPUS_WEBHOOK_ORIGINS') && is_array(WPUS_WEBHOOK_ORIGINS)
            && in_array($origin, WPUS_WEBHOOK_ORIGINS, true);
    }

    public static function publicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
            return false;
        }
        if (str_contains($ip, ':')) {
            $bytes = inet_pton($ip);
            $first = unpack('n2', $bytes);
            // Use only ordinary globally routed unicast and exclude transition and documentation blocks.
            return ($first[1] & 0xe000) === 0x2000
                && $first[1] !== 0x2002
                && $first[1] !== 0x3fff
                && !($first[1] === 0x2001 && ($first[2] < 0x0200 || $first[2] === 0x0db8));
        }
        $first = (int) strstr($ip, '.', true);
        return $first > 0 && $first < 224;
    }

    private static function address(string $host): ?string
    {
        $records = @dns_get_record($host . '.', DNS_A | DNS_AAAA);
        $ips = [];
        foreach ($records ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip !== null) {
                if (!self::publicIp($ip)) {
                    return null;
                }
                $ips[] = $ip;
            }
        }
        return $ips ? $ips[array_rand($ips)] : null;
    }

    public static function request(
        string $method,
        string $url,
        string $expectedOrigin,
        array $headers = [],
        string $body = '',
        int $timeout = 5,
        ?callable $beforeConnect = null
    ): array
    {
        $failed = ['code' => 'blocked', 'status' => 0, 'latency' => 0, 'retry_after' => null];
        if (!self::available() || !Policy::outbound(true) || self::origin($url) !== $expectedOrigin
            || !in_array($method, ['HEAD', 'GET', 'POST'], true) || (new \WP_Http())->block_request($url)) {
            return $failed;
        }
        $host = substr($expectedOrigin, 8);
        $ip = self::address($host);
        if ($ip === null) {
            $failed['code'] = 'dns';
            return $failed;
        }
        $ip = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $curl = curl_init($url);
        if (!$curl) {
            return $failed;
        }
        $bytes = 0;
        $headerBytes = 0;
        $limited = false;
        $retry = null;
        $options = [
            CURLOPT_RESOLVE => ["{$host}:443:{$ip}"],
            CURLOPT_PROXY => '',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => min(2, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERAGENT => 'WP-Utility-Suite/' . WPUS_VERSION,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$bytes, &$limited): int {
                $bytes += strlen($chunk);
                if ($bytes > 16384) {
                    $limited = true;
                    return 0;
                }
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headerBytes, &$retry): int {
                $headerBytes += strlen($line);
                if ($headerBytes > 16384) {
                    return 0;
                }
                if (str_starts_with($line, 'HTTP/')) {
                    $retry = null;
                }
                if (stripos($line, 'Retry-After:') === 0) {
                    $value = trim(substr($line, 12));
                    if (ctype_digit($value) && strlen($value) <= 7) {
                        $retry = (int) $value;
                    } elseif (preg_match('/\A[A-Za-z]{3}, \d{2} [A-Za-z]{3} \d{4} \d{2}:\d{2}:\d{2} GMT\z/D', $value) === 1) {
                        $when = strtotime($value);
                        $retry = $when ? max(0, $when - time()) : null;
                    }
                }
                return strlen($line);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'https';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        if ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        } elseif ($method === 'POST') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if (!curl_setopt_array($curl, $options)) {
            return $failed;
        }
        if (!Policy::outbound(true) || ($beforeConnect !== null && !$beforeConnect())) {
            $failed['code'] = 'policy_paused';
            return $failed;
        }
        $started = hrtime(true);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        return [
            'code' => $ok !== false || ($limited && $status >= 200) ? 'http' : 'transport',
            'status' => $status,
            'latency' => (int) ((hrtime(true) - $started) / 1_000_000),
            'retry_after' => $retry,
        ];
    }
}
