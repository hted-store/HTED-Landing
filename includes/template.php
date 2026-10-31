<?php
if (!defined('ABSPATH')) exit;

require HTED_LANDING_DIR . 'includes/context.php';
$hted_content = HTED_Landing_Landings::render($hted['slug'], ['price' => $price_fmt, 'product_name' => $product->get_name()]);

$faq_default = [
    ['Est-ce un produit original ?', 'Oui, boîte scellée avec étiquette anti-contrefaçon de la marque.'],
    ['Quel délai de livraison ?', ($settings['delay'] ?: '24 à 72 h') . ' selon la wilaya.'],
];
if ($settings['warranty']) {
    $faq_default[] = ['Y a-t-il une garantie ?', $settings['warranty'] . ' contre les défauts de fabrication, échange via notre service client.'];
}
$faq = array_merge($hted_faq, $faq_default);
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html($hted_title); ?> – <?php echo esc_html($price_fmt); ?> DA</title>
<meta name="description" content="<?php echo esc_attr($hted_description); ?>">
<meta name="robots" content="noindex, follow">
<meta property="og:title" content="<?php echo esc_attr($hted_title); ?>">
<meta property="og:description" content="<?php echo esc_attr($hted_description); ?>">
<?php if ($hero_img) : ?><meta property="og:image" content="<?php echo $hero_img; ?>">
<link rel="preload" as="image" href="<?php echo $hero_img; ?>"><?php endif; ?>
<link rel="stylesheet" href="<?php echo esc_url(HTED_LANDING_URL . 'assets/landing.css?v=' . HTED_LANDING_VERSION); ?>">
<style>:root{--accent:<?php echo esc_html($accent); ?>;--accent-dark:<?php echo esc_html($accent_dark); ?>;--on-accent:<?php echo esc_html($on_accent); ?>}</style>
<?php include HTED_LANDING_DIR . 'includes/pixel.php'; ?>
</head>
<body class="hl-theme-<?php echo esc_attr($theme); ?>">

<?php if ($settings['topbar'] !== '') : ?><div class="hl-topbar"><?php echo esc_html($settings['topbar']); ?></div><?php endif; ?>

<header class="hl-header">
  <div class="hl-logo">
    <?php if ($settings['logo_url']) : ?><img src="<?php echo esc_url($settings['logo_url']); ?>" alt="<?php echo esc_attr($brand); ?>" height="40"><?php else : echo esc_html($brand); endif; ?>
  </div>
  <a href="#commande" class="hl-btn-ghost">Commander</a>
</header>

<?php echo $hted_content; // HTML de la landing, déjà filtré par wp_kses ?>

<?php include HTED_LANDING_DIR . 'includes/order-form.php'; ?>

<section class="hl-faq">
  <h2>Questions fréquentes</h2>
  <?php foreach ($faq as $q) : ?>
    <details><summary><?php echo esc_html($q[0]); ?></summary><p><?php echo esc_html($q[1]); ?></p></details>
  <?php endforeach; ?>
</section>

<footer class="hl-footer">
  <span><?php echo esc_html(get_bloginfo('name')); ?></span>
  <?php if ($settings['phone']) : ?><a href="tel:<?php echo esc_attr(preg_replace('/[^\d+]/', '', $settings['phone'])); ?>"><?php echo esc_html($settings['phone']); ?></a><?php endif; ?>
</footer>

<script>window.HTED_LANDING = <?php echo wp_json_encode($config); ?>;</script>
<script src="<?php echo esc_url(HTED_LANDING_URL . 'assets/landing.js?v=' . HTED_LANDING_VERSION); ?>" defer></script>
</body>
</html>
