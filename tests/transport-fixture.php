<?php
namespace Wpus;

// Namespace-local stand-ins used only by the WP-CLI integration test.
final class TestTransport
{
    public static array $records = [['ip' => '8.8.8.8']];
    public static array $options = [];
    public static int $status = 204;
    public static int $connections = 0;
    public static ?string $retryAfter = null;
    public static ?\Closure $onLookup = null;
}

function dns_get_record(string $host, int $type): array
{
    if (TestTransport::$onLookup !== null) {
        (TestTransport::$onLookup)();
    }
    return TestTransport::$records;
}

function curl_setopt_array($handle, array $options): bool
{
    TestTransport::$options = $options;
    return true;
}

function curl_exec($handle): bool
{
    TestTransport::$connections++;
    $header = TestTransport::$options[CURLOPT_HEADERFUNCTION];
    $header($handle, "HTTP/1.1 " . TestTransport::$status . "\r\n");
    if (TestTransport::$retryAfter !== null) {
        $header($handle, 'Retry-After: ' . TestTransport::$retryAfter . "\r\n");
    }
    return true;
}

function curl_getinfo($handle, int $option): int
{
    return TestTransport::$status;
}
