<?php
if (!defined('ABSPATH')) exit;

/**
 * POST /wp-json/hted-landing/v1/order
 * Crée une vraie commande WooCommerce (COD), traitée ensuite comme les autres
 * (confirmation, Yalidine, gestion de stock, emails).
 */
class HTED_Landing_Order {

    const MAX_QTY = 5;

    public static function init() {
        add_action('rest_api_init', function () {
            register_rest_route('hted-landing/v1', '/order', [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'create'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    private static function fail($msg, $code = 400) {
        return new WP_REST_Response(['ok' => false, 'message' => $msg], $code);
    }

    /** 0550123456, +213550123456, 213 550 12 34 56, 550123456 => 0550123456 */
    public static function normalize_phone($raw) {
        $d = preg_replace('/\D/', '', (string) $raw);
        if (strpos($d, '00213') === 0) $d = substr($d, 5);
        elseif (strpos($d, '213') === 0 && strlen($d) === 12) $d = substr($d, 3);
        if (strlen($d) === 9) $d = '0' . $d;
        return preg_match('/^0[567]\d{8}$/', $d) ? $d : '';
    }

    public static function create(WP_REST_Request $req) {
        try {
            return self::create_order($req);
        } catch (Throwable $e) {
            if (function_exists('wc_get_logger')) wc_get_logger()->error('HTED Landing : ' . $e->getMessage(), ['source' => 'hted-landing']);
            return self::fail('Un problème technique empêche l\'envoi. Réessayez dans un instant ou appelez-nous.', 500);
        }
    }

    private static function create_order(WP_REST_Request $req) {
        $p = $req->get_json_params() ?: $req->get_body_params();

        // Anti-spam : champ piège rempli ou formulaire envoyé trop vite = robot.
        if (!empty($p['website']) || (int) ($p['elapsed'] ?? 0) < 3000) {
            return self::fail('Envoi refusé. Réessayez dans quelques secondes.');
        }

        $name  = sanitize_text_field($p['name'] ?? '');
        $phone = self::normalize_phone($p['phone'] ?? '');
        $state = sanitize_text_field($p['state'] ?? '');
        $city  = sanitize_text_field($p['city'] ?? '');
        $type  = ($p['delivery'] ?? '') === 'desk' ? 'desk' : 'home';
        $qty   = max(1, min(self::MAX_QTY, (int) ($p['qty'] ?? 1)));
        $slug  = sanitize_key($p['landing'] ?? '');

        if (mb_strlen($name) < 3)  return self::fail('Indiquez votre nom et prénom.');
        if (!$phone)               return self::fail('Numéro invalide : 10 chiffres commençant par 05, 06 ou 07.');
        $wilayas = HTED_Landing_Settings::wilayas();
        if (!isset($wilayas[$state])) return self::fail('Choisissez votre wilaya.');
        $yld = HTED_Landing_Yalidine::available();
        $yld_data = [];
        if ($yld) {
            $wid = HTED_Landing_Yalidine::wilaya_id($state);
            $cid = absint($p['commune_id'] ?? 0);
            $commune = null;
            foreach (HTED_Landing_Yalidine::communes($wid) as $c) if ($c['id'] === $cid) { $commune = $c; break; }
            if (!$commune) return self::fail('Choisissez votre commune.');
            $city = $commune['name'];
            $yld_data = ['wilaya_id' => $wid, 'commune_id' => $cid, 'commune_name' => $commune['name']];
            if ($type === 'desk') {
                $center_id = absint($p['center_id'] ?? 0);
                $center = null;
                foreach (HTED_Landing_Yalidine::centers($wid) as $c) if ($c['id'] === $center_id) { $center = $c; break; }
                if (!$center) return self::fail('Choisissez le bureau Yalidine où récupérer le colis.');
                $yld_data['center_id'] = $center_id;
                $yld_data['center_name'] = $center['name'];
                $yld_data['center_address'] = $center['address'];
            }
        } elseif (mb_strlen($city) < 2) {
            return self::fail('Indiquez votre commune.');
        }

        $product = wc_get_product(absint($p['product_id'] ?? 0));
        if (!$product || !$product->is_purchasable() || !$product->is_in_stock()) {
            return self::fail('Ce produit n\'est plus disponible.', 409);
        }
        if (!$product->has_enough_stock($qty)) {
            return self::fail('Stock insuffisant pour cette quantité.', 409);
        }

        // Frais recalculés côté serveur (jamais ceux envoyés par le navigateur).
        if ($yld) {
            $yfee = HTED_Landing_Yalidine::fee($yld_data['wilaya_id'], $yld_data['commune_id'], $type);
            if ($yfee === null) return self::fail($type === 'desk' ? 'Le stop desk n\'est pas disponible pour cette commune.' : 'Livraison à domicile non disponible pour cette commune.');
            $fee = $yfee;
            $known = true;
        } else {
            $fees = HTED_Landing_Settings::fees()[$state];
            $known = $fees['home'] !== null || $fees['desk'] !== null;
            if ($type === 'desk' && $known && $fees['desk'] === null) {
                return self::fail('Le stop desk n\'est pas disponible dans cette wilaya.');
            }
            $fee = (int) ($fees[$type] ?? 0);
        }

        // Double clic / renvoi : même téléphone dans les 30 min = même commande.
        $dup_key = 'hl_dup_' . md5($phone . '|' . $product->get_id());
        $existing = get_transient($dup_key);
        if ($existing && ($o = wc_get_order($existing))) {
            return new WP_REST_Response(['ok' => true, 'number' => $o->get_order_number(), 'total' => (float) $o->get_total(), 'phone' => $phone, 'duplicate' => true]);
        }

        // Limite : 4 commandes / 15 min par IP.
        $ip = class_exists('WC_Geolocation') ? WC_Geolocation::get_ip_address() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $rl_key = 'hl_rl_' . md5($ip);
        $count = (int) get_transient($rl_key);
        if ($count >= 4) return self::fail('Trop de commandes depuis cette connexion. Appelez-nous.', 429);
        set_transient($rl_key, $count + 1, 15 * MINUTE_IN_SECONDS);

        try {
            $order = wc_create_order(['created_via' => 'hted-landing']);
            $order->add_product($product, $qty);

            $parts = preg_split('/\s+/', $name, 2);
            $addr = [
                'first_name' => $parts[0],
                'last_name'  => $parts[1] ?? '',
                'phone'      => $phone,
                'country'    => 'DZ',
                'state'      => $state,
                'city'       => $city,
                'address_1'  => ($type === 'desk' && !empty($yld_data['center_name'])) ? 'Stop desk : ' . $yld_data['center_name'] : $city,
            ];
            $order->set_address($addr, 'billing');
            unset($addr['phone']);
            $order->set_address($addr, 'shipping');

            $ship = new WC_Order_Item_Shipping();
            $ship->set_method_title($type === 'desk' ? 'Stop desk Yalidine' : 'Livraison à domicile');
            $ship->set_method_id('hted_landing_' . $type);
            $ship->set_total($fee);
            $order->add_item($ship);

            $order->set_payment_method('cod');
            $order->set_payment_method_title('Paiement à la livraison');
            $order->set_customer_ip_address($ip);
            $order->set_customer_user_agent(wc_get_user_agent());
            $order->update_meta_data('_hted_landing', $slug);
            $order->update_meta_data('_hted_delivery_type', $type);
            if (!$known) $order->update_meta_data('_hted_fee_to_confirm', 'yes');
            foreach ($yld_data as $k => $v) $order->update_meta_data('_hted_yld_' . $k, $v);
            if ($yld) {
                // Champs lus par le plugin yalidine-woocommerce pour créer le bordereau.
                $wname = HTED_Landing_Yalidine::wilaya_name($yld_data['wilaya_id'], preg_replace('/^\d+\s*–\s*/u', '', $wilayas[$state]));
                $order->update_meta_data('_yld_wilaya_id', $yld_data['wilaya_id']);
                $order->update_meta_data('_yld_wilaya_name', $wname);
                $order->update_meta_data('_yld_commune_id', $yld_data['commune_id']);
                $order->update_meta_data('_yld_commune_name', $yld_data['commune_name']);
                $order->update_meta_data('_yld_type', $type);
                $order->update_meta_data('_yld_stopdesk_id', $type === 'desk' ? (int) $yld_data['center_id'] : 0);
            }

            $order->calculate_totals(false);
            $note = 'Commande via landing « ' . $slug . ' » · ' . ($type === 'desk' ? 'stop desk' : 'domicile');
            if (!empty($yld_data['center_name'])) $note .= ' · bureau ' . $yld_data['center_name'] . ' (#' . $yld_data['center_id'] . ')';
            if (!empty($yld_data['commune_id'])) $note .= ' · commune Yalidine #' . $yld_data['commune_id'];
            if (!$known) $note .= ' · frais de livraison à confirmer par téléphone';
            $order->set_status(HTED_Landing_Settings::get('order_status'), $note);
            $order->save();
        } catch (Exception $e) {
            return self::fail('Erreur lors de l\'enregistrement. Réessayez ou appelez-nous.', 500);
        }

        set_transient($dup_key, $order->get_id(), 30 * MINUTE_IN_SECONDS);

        return new WP_REST_Response([
            'ok'     => true,
            'number' => $order->get_order_number(),
            'total'  => (float) $order->get_total(),
            'phone'  => $phone,
        ]);
    }
}
