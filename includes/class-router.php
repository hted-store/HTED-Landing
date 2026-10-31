<?php
if (!defined('ABSPATH')) exit;

/**
 * Une page contenant [hted_landing landing="tws43" product="123"] est rendue
 * par le plugin, sans le thème : pas de menu, pas de CSS/JS du thème, page légère.
 */
class HTED_Landing_Router {

    /** @var array|null */
    private static $current = null;

    public static function init() {
        add_shortcode('hted_landing', '__return_empty_string');
        add_filter('template_include', [__CLASS__, 'template'], 99);
    }

    public static function current() {
        return self::$current;
    }

    public static function template($template) {
        if (!is_page()) return $template;
        $post = get_queried_object();
        if (!$post || !has_shortcode($post->post_content, 'hted_landing')) return $template;
        if (!preg_match('/\[hted_landing([^\]]*)\]/', $post->post_content, $m)) return $template;

        $atts = shortcode_atts(['landing' => '', 'product' => 0], shortcode_parse_atts($m[1]) ?: []);
        $slug = sanitize_key($atts['landing']);
        $product = wc_get_product(absint($atts['product']));

        if (!HTED_Landing_Landings::exists($slug) || !$product || !$product->is_purchasable()) {
            if (current_user_can('manage_woocommerce')) {
                $why = !HTED_Landing_Landings::exists($slug)
                    ? 'la landing « ' . esc_html($slug) . ' » n\'est pas installée (WooCommerce → HTED Landing → Importer).'
                    : 'le produit #' . absint($atts['product']) . ' est introuvable ou non achetable (prix vide, brouillon ?).';
                wp_die('HTED Landing : ' . $why, 'HTED Landing', ['response' => 200]);
            }
            return $template;
        }

        self::$current = [
            'slug'    => $slug,
            'product' => $product,
            'page'    => $post,
        ];
        return HTED_LANDING_DIR . (HTED_Landing_Landings::mode($slug) === 'libre' ? 'includes/template-libre.php' : 'includes/template.php');
    }

    /** Prix formaté « 7 300 ». */
    public static function price($amount) {
        return number_format((float) $amount, 0, ',', ' ');
    }
}
