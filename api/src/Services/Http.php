<?php
declare(strict_types=1);

namespace App\Services;

/** Outbound HTTP via cURL. Tests replace $mock to avoid real network calls. */
final class Http
{
    /** @var null|callable(string $method, string $url, array $headers, ?string $body): array{0:int,1:string} */
    public static $mock = null;
    public static array $log = [];

    /** @return array{0:int,1:string} status, body */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): array
    {
        if (self::$mock) {
            self::$log[] = [$method, $url, $headers, $body];
            return (self::$mock)($method, $url, $headers, $body);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) return [0, $err];
        return [$code, (string)$res];
    }

    public static function json(string $method, string $url, ?array $data = null, array $headers = []): array
    {
        [$code, $body] = self::request($method, $url, array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers), $data === null ? null : json_encode($data, JSON_UNESCAPED_UNICODE));
        return [$code, json_decode($body, true) ?? ['raw' => $body]];
    }
}
