<?php
if (!defined('ABSPATH')) exit;

/**
 * Pont vers le plugin « yalidine-woocommerce » déjà installé :
 * réutilise ses clés, son cache et sa logique de frais (wilaya de départ + remise),
 * pour que la landing affiche exactement les mêmes frais que le checkout.
 */
class HTED_Landing_Yalidine {

    public static function available() {
        return class_exists('Yalidine_API')
            && Yalidine_API::get_option('api_id') !== ''
            && Yalidine_API::get_option('api_token') !== '';
    }

    /** "DZ-16" ou "16" => 16 */
    public static function wilaya_id($state) {
        return (int) preg_replace('/\D/', '', (string) $state);
    }

    /** Liste paginée complète d'un endpoint Yalidine (data + has_more). */
    private static function all($endpoint, $cache_key) {
        $items = [];
        $page = 1;
        do {
            $sep = strpos($endpoint, '?') === false ? '?' : '&';
            $resp = Yalidine_API::request($endpoint . $sep . 'page_size=1000&page=' . $page, $cache_key . '_p' . $page, DAY_IN_SECONDS);
            if (!$resp || empty($resp['data'])) break;
            $items = array_merge($items, $resp['data']);
            $page++;
        } while (!empty($resp['has_more']) && $page <= 5);
        return $items;
    }

    /** [{id, name, desk}] — communes livrables de la wilaya. */
    public static function communes($wid) {
        $out = [];
        foreach (self::all("communes/?wilaya_id={$wid}", "communes_{$wid}") as $c) {
            if (isset($c['is_deliverable']) && !$c['is_deliverable']) continue;
            $out[] = [
                'id'   => (int) $c['id'],
                'name' => (string) $c['name'],
                'desk' => !empty($c['has_stop_desk']),
                'wilaya_name' => (string) ($c['wilaya_name'] ?? ''),
            ];
        }
        usort($out, function ($a, $b) { return strcmp($a['name'], $b['name']); });
        return $out;
    }

    /** [{id, name, commune_id, address}] — bureaux stop desk de la wilaya. */
    public static function centers($wid) {
        $out = [];
        foreach (self::all("centers/?wilaya_id={$wid}", "centers_{$wid}") as $c) {
            $out[] = [
                'id'         => (int) $c['center_id'],
                'name'       => (string) $c['name'],
                'commune_id' => (int) ($c['commune_id'] ?? 0),
                'address'    => (string) ($c['address'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Frais facturés au client, calculés comme le plugin Yalidine (yld_fees) :
     * tarif express de la commune, moins la remise configurée. null = non disponible.
     */
    public static function fee($to_wid, $commune_id, $type) {
        $from = (int) Yalidine_API::get_option('from_wilaya_id', 19);
        $resp = Yalidine_API::request("fees/?from_wilaya_id={$from}&to_wilaya_id={$to_wid}", "fees_{$from}_{$to_wid}", HOUR_IN_SECONDS);
        if (!$resp || empty($resp['per_commune'][$commune_id])) return null;
        $c = $resp['per_commune'][$commune_id];
        $fee = $c[$type === 'desk' ? 'express_desk' : 'express_home'] ?? null;
        if ($fee === null) return null;
        $fee = (int) $fee;
        $discount = (int) Yalidine_API::get_option('shipping_discount', 0);
        if ($discount > 0) $fee = max(0, $fee - $discount);
        return $fee;
    }

    /** Nom de wilaya tel que Yalidine l'écrit (requis par le bordereau). */
    public static function wilaya_name($wid, $fallback = '') {
        foreach (self::communes($wid) as $c) if ($c['wilaya_name'] !== '') return $c['wilaya_name'];
        $resp = Yalidine_API::request("wilayas/?id={$wid}", "wilaya_{$wid}", WEEK_IN_SECONDS);
        if (!empty($resp['data'][0]['name'])) return (string) $resp['data'][0]['name'];
        return $fallback;
    }

    public static function init() {
        add_action('rest_api_init', function () {
            $ns = 'hted-landing/v1';
            register_rest_route($ns, '/communes', [
                'methods' => 'GET', 'permission_callback' => '__return_true',
                'callback' => function (WP_REST_Request $r) {
                    $wid = absint($r['wilaya']);
                    if (!$wid || $wid > 58 || !self::available()) return new WP_REST_Response([], 400);
                    return new WP_REST_Response(self::communes($wid));
                },
            ]);
            register_rest_route($ns, '/centers', [
                'methods' => 'GET', 'permission_callback' => '__return_true',
                'callback' => function (WP_REST_Request $r) {
                    $wid = absint($r['wilaya']);
                    if (!$wid || $wid > 58 || !self::available()) return new WP_REST_Response([], 400);
                    return new WP_REST_Response(self::centers($wid));
                },
            ]);
            register_rest_route($ns, '/fee', [
                'methods' => 'GET', 'permission_callback' => '__return_true',
                'callback' => function (WP_REST_Request $r) {
                    $wid = absint($r['wilaya']);
                    $cid = absint($r['commune']);
                    if (!$wid || !$cid || !self::available()) return new WP_REST_Response(['home' => null, 'desk' => null], 400);
                    nocache_headers();
                    return new WP_REST_Response([
                        'home' => self::fee($wid, $cid, 'home'),
                        'desk' => self::fee($wid, $cid, 'desk'),
                    ]);
                },
            ]);
        });
    }
}
