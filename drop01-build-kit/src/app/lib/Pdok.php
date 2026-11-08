<?php
// Address lookup with PDOK Locatieserver (free Dutch government geocoder), exact postcode + house number, cached for 30 days.
// The free-text form ("9742BD 22") can return a different house, so the query filters on postcode and huisnummer.
defined('DROP01') || exit;

final class Pdok {
    public const URL = 'https://api.pdok.nl/bzk/locatieserver/search/v3_1/free';
    /** @var callable|null fn(string $url): ?array — swapped in tests */
    public static $http = null;

    /** Returns ['street','city','postcode','house_no','addition','lat','lng'] or null when the address does not exist. */
    public static function lookup(string $postcode, string $houseNo, string $addition = ''): ?array {
        $pc = Delivery::normPostcode($postcode);
        $no = preg_replace('/\D/', '', $houseNo);
        if (!$pc || strlen($pc) !== 6 || $no === '') return null;
        $add = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $addition));
        $key = sha1("$pc|$no|$add");
        $hit = DB::one('SELECT data, created_at FROM address_cache WHERE k = ?', [$key]);
        if ($hit && strtotime($hit['created_at']) > time() - 30 * 86400) return json_decode($hit['data'], true) ?: null;

        $url = self::URL . '?q=*:*&fq=type:adres&fq=postcode:' . $pc . '&fq=huisnummer:' . $no . '&rows=20'
            . '&fl=straatnaam,woonplaatsnaam,centroide_ll,huisnummer,huisletter,huisnummertoevoeging,postcode';
        $docs = self::fetch($url);
        if ($docs === null) return null;    // PDOK unreachable: do not cache, the checkout falls back to manual entry
        $best = null;
        foreach ($docs as $d) {
            $da = strtoupper(($d['huisletter'] ?? '') . ($d['huisnummertoevoeging'] ?? ''));
            if ($best === null || $da === $add) $best = $d;
            if ($da === $add) break;
        }
        $out = null;
        if ($best && preg_match('/POINT\(([\d.]+) ([\d.]+)\)/', $best['centroide_ll'] ?? '', $m)) {
            $out = ['street' => $best['straatnaam'], 'city' => $best['woonplaatsnaam'], 'postcode' => $pc, 'house_no' => $no, 'addition' => $add, 'lng' => (float) $m[1], 'lat' => (float) $m[2]];
        }
        DB::upsert('address_cache', ['k' => $key, 'data' => json_encode($out), 'created_at' => DB::now()], ['k']);
        return $out;
    }

    /** Free-text address ("Grote Markt 1, 9711 LV Groningen") → ['lat','lng','label'] or null. Used for the depot. */
    public static function geocodeText(string $text): ?array {
        $text = trim($text);
        if ($text === '') return null;
        $docs = self::fetch(self::URL . '?q=' . rawurlencode($text) . '&fq=type:adres&rows=1&fl=weergavenaam,centroide_ll');
        if (!$docs || !preg_match('/POINT\(([\d.]+) ([\d.]+)\)/', $docs[0]['centroide_ll'] ?? '', $m)) return null;
        return ['lat' => (float) $m[2], 'lng' => (float) $m[1], 'label' => (string) ($docs[0]['weergavenaam'] ?? $text)];
    }

    private static function fetch(string $url): ?array {
        if (self::$http) return (self::$http)($url);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($raw === false || $code !== 200) return null;
        $j = json_decode((string) $raw, true);
        return $j['response']['docs'] ?? null;
    }
}
