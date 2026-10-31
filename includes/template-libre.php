<?php
if (!defined('ABSPATH')) exit;

/**
 * Page libre : le HTML complet fourni par le client (fait avec n'importe quel outil ou IA)
 * est servi tel quel ; le plugin y greffe son formulaire de commande, isolé dans un
 * Shadow DOM pour que le CSS de la page et celui du formulaire ne se mélangent pas.
 */

require HTED_LANDING_DIR . 'includes/context.php';

$slug  = $hted['slug'];
$base  = HTED_Landing_Landings::base_url() . $slug . '/';
$entry = $meta['entry'] ?? 'index.html';
$html  = (string) file_get_contents(HTED_Landing_Landings::base_dir() . $slug . '/' . $entry);

// 1. Jetons.
$html = strtr($html, [
    '{{price}}'        => esc_html($price_fmt),
    '{{product_name}}' => esc_html($product->get_name()),
]);

// 2. Chemins relatifs (images, CSS, JS du paquet) → URL du dossier de la landing.
$html = HTED_Landing_Landings::absolutize($html, $base);

// 3. Formulaire de commande isolé.
ob_start();
include HTED_LANDING_DIR . 'includes/order-form.php';
$form = ob_get_clean();
$host = '<hted-order id="commande"><template shadowrootmode="open">'
    . '<link rel="stylesheet" href="' . esc_url(HTED_LANDING_URL . 'assets/landing.css?v=' . HTED_LANDING_VERSION) . '">'
    . '<style>:host{all:initial;display:block;--accent:' . esc_html($accent) . ';--accent-dark:' . esc_html($accent_dark) . ';--on-accent:' . esc_html($on_accent) . '}</style>'
    . '<div class="hl-root hl-theme-' . esc_attr($theme) . '">' . $form . '</div>'
    . '</template></hted-order>'
    // Navigateurs sans Shadow DOM déclaratif : on l'attache à la main.
    . '<script>(function(){var h=document.querySelector("hted-order");if(h&&!h.shadowRoot){var t=h.querySelector("template");if(t){h.attachShadow({mode:"open"}).appendChild(t.content);t.remove();}}})();</script>';

$placed = HTED_Landing_Landings::place_form($html, $host);
$html = $placed['html'];

// 4. Tête : pas d'indexation, viewport, pixel.
ob_start();
include HTED_LANDING_DIR . 'includes/pixel.php';
$pixel_html = ob_get_clean();
$head = "\n";
if (stripos($html, 'name="viewport"') === false && stripos($html, "name='viewport'") === false) {
    $head .= '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
}
$head .= '<meta name="robots" content="noindex, follow">' . "\n";
$head .= '<style>@media (max-width:899px){body{padding-bottom:84px}}</style>' . "\n";
$head .= $pixel_html;
if (preg_match('/<head\b[^>]*>/i', $html)) {
    $html = preg_replace('/<head\b[^>]*>/i', '$0' . str_replace(['\\', '$'], ['\\\\', '\$'], $head), $html, 1);
} elseif (preg_match('/<html\b[^>]*>/i', $html)) {
    $html = preg_replace('/<html\b[^>]*>/i', '$0<head><meta charset="utf-8">' . str_replace(['\\', '$'], ['\\\\', '\$'], $head) . '</head>', $html, 1);
} else {
    $html = '<!doctype html><html lang="fr"><head><meta charset="utf-8">' . $head . '</head><body>' . $html . '</body></html>';
}

// 5. Scripts du formulaire, juste avant </body>.
$scripts = '<script>window.HTED_LANDING = ' . wp_json_encode($config) . ';</script>'
    . '<script src="' . esc_url(HTED_LANDING_URL . 'assets/landing.js?v=' . HTED_LANDING_VERSION) . '" defer></script>';
$pos = strripos($html, '</body>');
$html = $pos === false ? $html . $scripts : substr_replace($html, $scripts, $pos, 0);

echo $html; // HTML de la page fournie par un administrateur (capacité unfiltered_html requise à l'import).
