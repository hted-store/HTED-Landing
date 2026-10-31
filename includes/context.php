<?php
if (!defined('ABSPATH')) exit;
/* Variables communes aux deux gabarits (landing HTED et page libre). */

$hted     = HTED_Landing_Router::current();
$product  = $hted['product'];
$price    = (float) wc_get_price_to_display($product);
$price_fmt = HTED_Landing_Router::price($price);
$settings = HTED_Landing_Settings::get();
$pixel    = $settings['pixel_id'];
$meta     = HTED_Landing_Landings::meta($hted['slug']);
$hero_img = !empty($meta['hero']) ? HTED_Landing_Landings::img_url($hted['slug'], $meta['hero']) : '';
if (!$hero_img && ($img_id = $product->get_image_id())) $hero_img = esc_url(wp_get_attachment_image_url($img_id, 'medium_large'));
$hted_title = $meta['title'] ?? $product->get_name();
$hted_description = $meta['description'] ?? wp_strip_all_tags($product->get_short_description());
$hted_faq = [];
foreach ((array) ($meta['faq'] ?? []) as $q) {
    if (is_array($q) && count($q) >= 2) $hted_faq[] = [(string) $q[0], (string) $q[1]];
}

$brand  = $settings['brand'] !== '' ? $settings['brand'] : get_bloginfo('name');
$accent = preg_match('/^#[0-9a-fA-F]{6}$/', $settings['accent']) ? $settings['accent'] : '#C8141F';
$style = HTED_Landing_Landings::style($hted['slug']);
if ($style['accent'] !== '') $accent = $style['accent'];
$theme = $style['theme'];
// Texte des boutons : blanc ou noir selon la clarté de la couleur.
$rgb = sscanf($accent, '#%02x%02x%02x');
$on_accent = (0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2]) > 165 ? '#111111' : '#ffffff';
$accent_dark = sprintf('#%02x%02x%02x', $rgb[0] * .8, $rgb[1] * .8, $rgb[2] * .8);

$wilayas = HTED_Landing_Settings::wilayas();
$yalidine = HTED_Landing_Yalidine::available();
$config = [
    'endpoint'  => esc_url_raw(rest_url('hted-landing/v1/order')),
    'api'       => esc_url_raw(rest_url('hted-landing/v1/')),
    'yalidine'  => $yalidine,
    'productId' => $product->get_id(),
    'landing'   => $hted['slug'],
    'price'     => $price,
    'fees'      => HTED_Landing_Settings::fees(),
    'pixel'     => $pixel !== '',
];

