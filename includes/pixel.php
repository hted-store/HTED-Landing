<?php if (!defined('ABSPATH')) exit; ?>
<?php if ($pixel) : ?>
<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init','<?php echo esc_js($pixel); ?>');fbq('track','PageView');
fbq('track','ViewContent',{content_ids:['<?php echo (int) $product->get_id(); ?>'],content_type:'product',value:<?php echo (float) $price; ?>,currency:'DZD'});
</script>
<?php endif; ?>
