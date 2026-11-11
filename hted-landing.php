<?php
/**
 * Plugin Name: HTED Landing COD
 * Description: Landing pages produit avec formulaire COD qui crée une vraie commande WooCommerce. Importe des paquets HTED ou n'importe quelle page HTML faite avec un autre outil ou une IA.
 * Version: 3.5.0
 * Author: HTED Store
 * Requires Plugins: woocommerce
 * Text Domain: hted-landing
 */

if (!defined('ABSPATH')) exit;

define('HTED_LANDING_VERSION', '3.5.0');
define('HTED_LANDING_DIR', plugin_dir_path(__FILE__));
define('HTED_LANDING_URL', plugin_dir_url(__FILE__));

require_once HTED_LANDING_DIR . 'includes/class-landings.php';
require_once HTED_LANDING_DIR . 'includes/class-settings.php';
require_once HTED_LANDING_DIR . 'includes/class-router.php';
require_once HTED_LANDING_DIR . 'includes/class-order.php';
require_once HTED_LANDING_DIR . 'includes/class-bundle.php';
require_once HTED_LANDING_DIR . 'includes/class-yalidine.php';
require_once HTED_LANDING_DIR . 'includes/class-hlv.php';

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>HTED Landing nécessite WooCommerce.</p></div>';
        });
        return;
    }
    HTED_Landing_Settings::init();
    HTED_Landing_Router::init();
    HTED_Landing_Order::init();
    HTED_Landing_Yalidine::init();
    HTED_Landing_HLV::init();
});
