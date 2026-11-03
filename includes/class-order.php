<?php
if (!defined('ABSPATH')) exit;

/**
 * POST /wp-json/hted-landing/v1/order
 * Crée une vraie commande WooCommerce (COD), traitée ensuite comme les autres
 * (confirmation, Yalidine, gestion de stock, emails).
 */
class HTED_Landing_Order {

    const MAX_QTY = 5;        // formulaire d'un seul produit
    const MAX_LINE_QTY = 10;  // par ligne de panier
    const MAX_LINES = 20;

    /**
     * Panier envoyé par la vitrine : [{id, qty}, …] ou "12:2,15:1". Renvoie [[WC_Product, qté], …].
     * $lenient = true (calcul des frais) : lignes invalides ignorées ; false (commande) : gardées pour être refusées.
     */
    public static function parse_items($raw, $lenient = true) {
        $pairs = [];
        if (is_string($raw)) {
            foreach (explode(',', $raw) as $chunk) { $x = explode(':', $chunk); $pairs[] = ['id' => $x[0] ?? 0, 'qty' => $x[1] ?? 1]; }
        } elseif (is_array($raw)) {
            $pairs = $raw;
        }
        $merged = [];
        foreach (array_slice($pairs, 0, self::MAX_LINES) as $it) {
            $id = absint(is_array($it) ? ($it['id'] ?? 0) : 0);
            $q = max(1, min(self::MAX_LINE_QTY, absint(is_array($it) ? ($it['qty'] ?? 1) : 1)));
            if ($id) $merged[$id] = min(self::MAX_LINE_QTY, ($merged[$id] ?? 0) + $q);
        }
        $out = [];
        foreach ($merged as $id => $q) {
            $prod = wc_get_product($id);
            if ($lenient && (!$prod || !$prod->is_purchasable())) continue;
            $out[] = [$prod ?: null, $q];
        }
        return $out;
    }

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
        $hlv = HTED_Landing_HLV::available();
        $yld = !$hlv && HTED_Landing_Yalidine::available();
        $yld_data = [];
        $hlv_cid = 0;
        if ($hlv) {
            $hlv_cid = absint($p['commune_id'] ?? 0);
            $city = HTED_Landing_HLV::commune_name($hlv_cid);
            if ($city === '') return self::fail('Choisissez votre commune.');
            if ($type === 'desk' && trim((string) ($p['desk'] ?? '')) === '') return self::fail('Choisissez le bureau où récupérer le colis.');
        } elseif ($yld) {
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

        // Lignes commandées : un panier (items) ou un seul produit (product_id + qty)
        $lines = !empty($p['items']) ? self::parse_items($p['items'], false)
            : [[wc_get_product(absint($p['product_id'] ?? 0)), $qty]];
        if (!$lines) return self::fail('Votre panier est vide.');
        foreach ($lines as $l) {
            list($lp, $lq) = $l;
            if (!$lp || !$lp->is_purchasable() || !$lp->is_in_stock()) {
                return self::fail(($lp ? '« ' . $lp->get_name() . ' » n\'est plus disponible.' : 'Ce produit n\'est plus disponible.') . ' Retirez-le pour continuer.', 409);
            }
            if (!$lp->has_enough_stock($lq)) return self::fail('Stock insuffisant pour « ' . $lp->get_name() . ' ».', 409);
        }
        $product = $lines[0][0];
        $subtotal = HTED_Landing_HLV::subtotal($lines);

        // Frais recalculés côté serveur (jamais ceux envoyés par le navigateur).
        $hlv_sel = null;
        if ($hlv) {
            $hlv_sel = HTED_Landing_HLV::resolve($hlv_cid, $type, sanitize_text_field((string) ($p['desk'] ?? '')), $lines);
            if (is_string($hlv_sel)) return self::fail($hlv_sel);
            if ((int) $hlv_sel['w'] !== HTED_Landing_Yalidine::wilaya_id($state)) return self::fail('La commune ne correspond pas à la wilaya choisie.');
            $fee = HTED_Landing_HLV::client_fee($hlv_sel['fee'], $subtotal);
            $known = true;
        } elseif ($yld) {
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
        $dup_key = 'hl_dup_' . md5($phone . '|' . implode(',', array_map(function ($l) { return $l[0]->get_id() . 'x' . $l[1]; }, $lines)));
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
            // Commande montée en mémoire puis enregistrée d'un coup (comme le checkout WooCommerce) :
            // au premier enregistrement (woocommerce_new_order), produits, adresse et total sont déjà là.
            // Facebook for WooCommerce envoie alors un Purchase serveur complet (valeur, téléphone),
            // au lieu d'un Purchase vide à 0 DA avec wc_create_order().
            $order = new WC_Order();
            $order->set_created_via('hted-landing');
            $order->set_currency(get_woocommerce_currency());
            $order->set_prices_include_tax('yes' === get_option('woocommerce_prices_include_tax'));
            foreach ($lines as $l) $order->add_item(self::line_item($l[0], $l[1]));
            $order->set_status('pending');

            $parts = preg_split('/\s+/', $name, 2);
            $addr = [
                'first_name' => $parts[0],
                'last_name'  => $parts[1] ?? '',
                'phone'      => $phone,
                'country'    => 'DZ',
                'state'      => $state,
                'city'       => $city,
                'address_1'  => $hlv_sel
                    ? ($type === 'desk' ? 'Stop desk : ' . $hlv_sel['desk_name'] : $city)
                    : (($type === 'desk' && !empty($yld_data['center_name'])) ? 'Stop desk : ' . $yld_data['center_name'] : $city),
            ];
            $order->set_address($addr, 'billing');
            unset($addr['phone']);
            $order->set_address($addr, 'shipping');

            $ship = new WC_Order_Item_Shipping();
            if ($hlv_sel) {
                $ship->set_method_title(($type === 'desk' ? 'Retrait : ' . $hlv_sel['desk_name'] : 'Livraison à domicile')
                    . ' — ' . HTED_Landing_HLV::carrier_label($hlv_sel['carrier']));
                $ship->add_meta_data('Transporteur', HTED_Landing_HLV::carrier_label($hlv_sel['carrier']), true);
            } else {
                $ship->set_method_title($type === 'desk' ? 'Stop desk Yalidine' : 'Livraison à domicile');
            }
            $ship->set_method_id('hted_landing_' . $type);
            $ship->set_total($fee);
            $order->add_item($ship);

            $order->set_payment_method('cod');
            $order->set_payment_method_title('Paiement à la livraison');
            $order->set_customer_ip_address($ip);
            $order->set_customer_user_agent(wc_get_user_agent());
            $order->update_meta_data('_hted_landing', $slug);
            self::save_attribution($order, $p['attribution'] ?? null);
            $order->update_meta_data('_hted_delivery_type', $type);
            if (!$known) $order->update_meta_data('_hted_fee_to_confirm', 'yes');
            foreach ($yld_data as $k => $v) $order->update_meta_data('_hted_yld_' . $k, $v);
            if ($hlv_sel) {
                // Mêmes champs que le checkout HTED Livraison : colis, impression et suivi depuis le bloc « Livraison ».
                HTED_Landing_HLV::save($order, $hlv_sel);
            } elseif ($yld) {
                // Champs lus par le plugin yalidine-woocommerce pour créer le bordereau.
                $wname = HTED_Landing_Yalidine::wilaya_name($yld_data['wilaya_id'], preg_replace('/^\d+\s*–\s*/u', '', $wilayas[$state]));
                $order->update_meta_data('_yld_wilaya_id', $yld_data['wilaya_id']);
                $order->update_meta_data('_yld_wilaya_name', $wname);
                $order->update_meta_data('_yld_commune_id', $yld_data['commune_id']);
                $order->update_meta_data('_yld_commune_name', $yld_data['commune_name']);
                $order->update_meta_data('_yld_type', $type);
                $order->update_meta_data('_yld_stopdesk_id', $type === 'desk' ? (int) $yld_data['center_id'] : 0);
            }

            $note = 'Commande via landing « ' . $slug . ' » · ' . ($type === 'desk' ? 'stop desk' : 'domicile');
            if ($hlv_sel) $note .= ' · ' . HTED_Landing_HLV::carrier_label($hlv_sel['carrier'])
                . ($type === 'desk' ? ' · bureau ' . $hlv_sel['desk_name'] : '') . ' · ' . number_format_i18n($fee) . ' DA (HTED Livraison)';
            if (!empty($yld_data['center_name'])) $note .= ' · bureau ' . $yld_data['center_name'] . ' (#' . $yld_data['center_id'] . ')';
            if (!empty($yld_data['commune_id'])) $note .= ' · commune Yalidine #' . $yld_data['commune_id'];
            if (!$known) $note .= ' · frais de livraison à confirmer par téléphone';
            $order->calculate_totals(false); // premier enregistrement, statut « en attente » (comme le checkout)
            // Puis passage au statut choisi : déclenche stock, e-mails et note, comme avant.
            $order->set_status(HTED_Landing_Settings::get('order_status'), $note);
            $order->save();
        } catch (Exception $e) {
            return self::fail('Erreur lors de l\'enregistrement. Réessayez ou appelez-nous.', 500);
        }

        set_transient($dup_key, $order->get_id(), 30 * MINUTE_IN_SECONDS);

        // Identifiant d'événement créé par Facebook for WooCommerce pour son Purchase serveur (CAPI) :
        // le Pixel du navigateur envoie le même, Meta ne compte alors l'achat qu'une fois.
        $event_id = (string) $order->get_meta('_meta_event_id');
        if ($event_id === '' && ($fresh = wc_get_order($order->get_id()))) $event_id = (string) $fresh->get_meta('_meta_event_id');

        return new WP_REST_Response([
            'ok'       => true,
            'number'   => $order->get_order_number(),
            'total'    => (float) $order->get_total(),
            'phone'    => $phone,
            'event_id' => $event_id,
        ]);
    }

    /** Ligne produit non enregistrée (comme WC_Abstract_Order::add_product, sans le save() prématuré). */
    /**
     * Origine de la commande, enregistrée comme le fait le checkout WooCommerce (colonne « Origine », rapports).
     * Valeurs envoyées par landing.js depuis les cookies sbjs_* (WooCommerce ou HTED Vitrine).
     */
    private static function save_attribution(WC_Order $order, $a): void {
        if (!is_array($a) || empty($a['source_type'])) return;
        $types = ['utm', 'organic', 'referral', 'typein', 'mobile_app', 'admin'];
        if (!in_array($a['source_type'], $types, true)) return;
        foreach (['source_type', 'referrer', 'utm_campaign', 'utm_source', 'utm_medium', 'utm_content', 'utm_id', 'utm_term',
                  'session_entry', 'session_start_time', 'session_pages', 'session_count', 'user_agent', 'device_type'] as $k) {
            if (!isset($a[$k]) || $a[$k] === '' || !is_scalar($a[$k])) continue;
            $v = in_array($k, ['referrer', 'session_entry'], true) ? esc_url_raw((string) $a[$k]) : sanitize_text_field((string) $a[$k]);
            if ($v !== '') $order->update_meta_data('_wc_order_attribution_' . $k, mb_substr($v, 0, 300));
        }
    }

    private static function line_item(WC_Product $p, int $qty): WC_Order_Item_Product {
        $total = wc_get_price_excluding_tax($p, ['qty' => $qty]);
        $item = new WC_Order_Item_Product();
        $item->set_props([
            'name'         => $p->get_name(),
            'tax_class'    => $p->get_tax_class(),
            'product_id'   => $p->is_type('variation') ? $p->get_parent_id() : $p->get_id(),
            'variation_id' => $p->is_type('variation') ? $p->get_id() : 0,
            'variation'    => $p->is_type('variation') ? $p->get_variation_attributes() : [],
            'subtotal'     => $total,
            'total'        => $total,
            'quantity'     => $qty,
        ]);
        $item->set_backorder_meta();
        return $item;
    }
}
