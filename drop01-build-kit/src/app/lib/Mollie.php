<?php
// Mollie REST client (no SDK): create and fetch payments. The HTTP function can be swapped out for tests.
defined('DROP01') || exit;

final class Mollie {
    /** @var callable|null fn(string $method, string $url, array $headers, ?string $body): array [status, decoded json] */
    public static $http = null;

    public static function base(): string { return rtrim((string) config('mollie_api_base', 'https://api.mollie.com/v2'), '/'); }

    public static function key(): string {
        $test = (bool) (Settings::get('mollie')['test_mode'] ?? true);
        return Settings::secret($test ? 'mollie_test' : 'mollie_live');
    }

    /** Mollie is switched off for now: payments go by Tikkie, cash or bank transfer. Set 'mollie_enabled' => true in data/config.php to turn it on. */
    public static function enabled(): bool { return (bool) config('mollie_enabled', false); }

    public static function configured(): bool { return self::enabled() && self::key() !== ''; }

    private static function call(string $method, string $path, ?array $body = null): array {
        if (!self::enabled()) throw new RuntimeException('Online payment is switched off.');
        $key = self::key();
        if ($key === '') throw new RuntimeException('Online payment is not set up yet.');
        $headers = ['Authorization: Bearer ' . $key, 'Content-Type: application/json', 'Accept: application/json'];
        $url = self::base() . $path;
        $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES);
        if (self::$http) return (self::$http)($method, $url, $headers, $payload);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6]);
        if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new RuntimeException('Could not reach Mollie: ' . $err);
        return [$code, json_decode((string) $raw, true) ?: []];
    }

    /** Creates a payment. $method: null (customer picks on Mollie), 'ideal', 'applepay', 'creditcard' or a list. */
    public static function createPayment(int $cents, string $description, string $redirectUrl, string $webhookUrl, array $metadata, $method = null): array {
        $body = ['amount' => ['currency' => 'EUR', 'value' => number_format($cents / 100, 2, '.', '')], 'description' => mb_substr($description, 0, 255),
            'redirectUrl' => $redirectUrl, 'webhookUrl' => $webhookUrl, 'metadata' => $metadata, 'locale' => 'en_US'];
        if ($method) $body['method'] = $method;
        [$code, $j] = self::call('POST', '/payments', $body);
        if ($code >= 300 || empty($j['id'])) throw new RuntimeException('Mollie refused the payment: ' . ($j['detail'] ?? ('HTTP ' . $code)));
        return $j;
    }

    public static function getPayment(string $id): array {
        if (!preg_match('/^tr_[A-Za-z0-9]+$/', $id)) throw new InvalidArgumentException('Not a Mollie payment id');
        [$code, $j] = self::call('GET', '/payments/' . $id);
        if ($code >= 300 || empty($j['id'])) throw new RuntimeException('Mollie payment not found (' . $code . ')');
        return $j;
    }

    public static function checkoutUrl(array $p): ?string { return $p['_links']['checkout']['href'] ?? null; }
}
