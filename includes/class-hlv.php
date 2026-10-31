<?php
if (!defined('ABSPATH')) exit;

/**
 * Pont vers HTED Livraison (Yalidine + ZR Express, référentiel neutre).
 * Utilisé en priorité dès que le checkout du site passe par HTED Livraison :
 * la landing affiche alors exactement les mêmes communes, bureaux, transporteurs
 * et frais que le checkout, et ses commandes portent les mêmes champs (_hlv_*),
 * donc les colis se créent depuis le bloc « Livraison » comme pour le checkout.
 */
class HTED_Landing_HLV {

    /** HTED Livraison installé, référentiel synchronisé, au moins un transporteur actif. */
    public static function ready() {
        return class_exists('HLV_Carriers') && class_exists('HLV_Geo') && class_exists('HLV_Settings')
            && HLV_Geo::ready() && HLV_Carriers::enabled();
    }

    /**
     * Source des communes et des frais de la landing :
     * - HTED Livraison si le checkout du site l'utilise (ou si l'ancien plugin Yalidine est absent) ;
     * - sinon l'ancien plugin Yalidine (période de transition) ;
     * - sinon les frais saisis à la main.
     */
    public static function available() {
        if (!self::ready()) return false;
        if (method_exists('HLV_Settings', 'checkout_on') && HLV_Settings::checkout_on()) return true;
        return !(class_exists('HTED_Landing_Yalidine') && HTED_Landing_Yalidine::available());
    }

    /** Poids facturable du produit commandé (le calcul HTED Livraison lit sinon le panier, vide ici). */
    private static function with_weight($product, $qty, callable $fn) {
        // Surpoids : seulement avec HTED Livraison ≥ 0.6 (avant, pas de calcul de poids : tarif de base)
        if (!method_exists('HLV_Carriers', 'set_kg') || !method_exists('HLV_Carriers', 'lines_kg')) return $fn();
        HLV_Carriers::set_kg($product ? HLV_Carriers::lines_kg([[$product, max(1, (int) $qty)]]) : 0.0);
        try {
            return $fn();
        } finally {
            HLV_Carriers::set_kg(null);
        }
    }

    /** Livraison offerte au-delà du seuil réglé dans HTED Livraison. */
    public static function is_free($subtotal) {
        $t = (int) HLV_Settings::get('free_threshold');
        return $t > 0 && (float) $subtotal >= $t;
    }

    /** Frais facturés au client : 0 si offerte, sinon tarif transporteur − remise HTED Livraison. */
    public static function client_fee($raw, $subtotal) {
        return self::is_free($subtotal) ? 0 : (int) round(HLV_Settings::client_fee($raw));
    }

    /** [{id, name}] — communes desservies par au moins un transporteur actif. */
    public static function communes($w) {
        $out = [];
        foreach (HLV_Geo::communes_of((int) $w) as $cid => $c) {
            $o = HLV_Carriers::options((int) $cid);
            if (!$o['home'] && !$o['desks']) continue;
            $out[] = ['id' => (int) $cid, 'name' => (string) $c['name']];
        }
        return $out;
    }

    /**
     * Frais et bureaux d'une commune pour ce produit et cette quantité.
     * home/desk = frais client (desk = le moins cher des bureaux), centers = bureaux triés (proches d'abord).
     */
    public static function options($cid, $product, $qty) {
        $subtotal = $product ? (float) wc_get_price_to_display($product) * max(1, (int) $qty) : 0;
        $o = self::with_weight($product, $qty, function () use ($cid) { return HLV_Carriers::options((int) $cid); });
        $centers = [];
        foreach ($o['desks'] as $d) {
            $centers[] = [
                'id'      => (string) $d['key'],
                'name'    => (string) $d['name'],
                'carrier' => (string) $d['label'],
                'address' => (string) $d['addr'],
                'near'    => !empty($d['near']),
                'price'   => self::client_fee($d['price'], $subtotal),
            ];
        }
        $dmin = $centers ? min(array_column($centers, 'price')) : null;
        $home = $o['home'] ? self::client_fee($o['home']['price'], $subtotal) : null;
        $hcar = $o['home'] ? HLV_Carriers::get((string) $o['home']['carrier']) : null;
        return [
            'home'         => $home,
            'home_carrier' => $hcar ? $hcar->label() : '',
            'desk'         => $dmin,
            'centers'      => $centers,
        ];
    }

    /** Sélection validée côté serveur, comme au checkout. @return array|string */
    public static function resolve($cid, $type, $desk_key, $product, $qty) {
        return self::with_weight($product, $qty, function () use ($cid, $type, $desk_key) {
            return HLV_Carriers::resolve((int) $cid, $type === 'desk' ? 'desk' : 'home', (string) $desk_key);
        });
    }

    /** Champs de commande identiques à ceux du checkout HTED Livraison. */
    public static function save(WC_Order $order, array $sel) {
        $car = HLV_Carriers::get($sel['carrier']);
        if (!$car) return;
        $order->update_meta_data('_hlv_carrier', $sel['carrier']);
        $order->update_meta_data('_hlv_type', $sel['type']);
        $order->update_meta_data('_hlv_wilaya', $sel['w']);
        $order->update_meta_data('_hlv_commune', $sel['cid']);
        $order->update_meta_data('_hlv_desk', $sel['desk']);
        $order->update_meta_data('_hlv_desk_name', $sel['desk_name']);
        $order->update_meta_data('_hlv_fee', (string) $sel['fee']);
        $order->update_meta_data('_hlv_kg', (string) ($sel['kg'] ?? ''));
        $order->update_meta_data('_hlv_weight_extra', (string) ($sel['extra'] ?? 0));
        $car->compat_meta($order, $sel);
    }

    public static function carrier_label($id) {
        $c = HLV_Carriers::get((string) $id);
        return $c ? $c->label() : (string) $id;
    }

    public static function commune_name($cid) {
        return (string) (HLV_Geo::commune((int) $cid)['name'] ?? '');
    }

    public static function init() {
        add_action('rest_api_init', function () {
            $ns = 'hted-landing/v1';
            register_rest_route($ns, '/hlv-communes', [
                'methods' => 'GET', 'permission_callback' => '__return_true',
                'callback' => function (WP_REST_Request $r) {
                    $w = absint($r['wilaya']);
                    if (!$w || $w > 58 || !self::available()) return new WP_REST_Response([], 400);
                    return new WP_REST_Response(self::communes($w));
                },
            ]);
            register_rest_route($ns, '/hlv-options', [
                'methods' => 'GET', 'permission_callback' => '__return_true',
                'callback' => function (WP_REST_Request $r) {
                    $cid = absint($r['commune']);
                    if (!$cid || !self::available()) return new WP_REST_Response(['home' => null, 'desk' => null, 'centers' => []], 400);
                    $product = wc_get_product(absint($r['product']));
                    $qty = max(1, min(HTED_Landing_Order::MAX_QTY, absint($r['qty'])));
                    nocache_headers();
                    try {
                        return new WP_REST_Response(self::options($cid, $product ?: null, $qty));
                    } catch (Throwable $e) {
                        if (function_exists('wc_get_logger')) wc_get_logger()->error('HTED Landing (frais) : ' . $e->getMessage(), ['source' => 'hted-landing']);
                        return new WP_REST_Response(['home' => null, 'desk' => null, 'centers' => []], 500);
                    }
                },
            ]);
        });
    }
}
