<?php
if (!defined('ABSPATH')) exit;

class HTED_Landing_Settings {

    const OPT = 'hted_landing_settings';

    public static function defaults() {
        return [
            'pixel_id'      => '',
            'fee_home'      => '',
            'fee_desk'      => '',
            'fee_overrides' => '',
            'order_status'  => 'processing',
            'phone'         => '',
            'delay'         => '24 à 72 h',
            'warranty'      => '',
            'brand'         => '',
            'logo_url'      => '',
            'accent'        => '#C8141F',
            'topbar'        => 'Paiement à la livraison · Livraison 58 wilayas · Produit original',
        ];
    }

    public static function get($key = null) {
        $s = wp_parse_args(get_option(self::OPT, []), self::defaults());
        return $key ? ($s[$key] ?? '') : $s;
    }

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register']);
        add_action('admin_post_hted_landing_import', [__CLASS__, 'handle_import']);
        add_action('admin_post_hted_landing_delete', [__CLASS__, 'handle_delete']);
        add_action('admin_post_hted_landing_page', [__CLASS__, 'handle_page']);
        add_action('admin_post_hted_landing_style', [__CLASS__, 'handle_style']);
    }

    private static function back($msg, $type = 'success') {
        wp_safe_redirect(add_query_arg(['page' => 'hted-landing', 'hl_msg' => rawurlencode($msg), 'hl_type' => $type], admin_url('admin.php')));
        exit;
    }

    public static function handle_import() {
        if (!current_user_can('manage_woocommerce')) wp_die('Accès refusé.');
        // Envoi plus gros que la limite du serveur : PHP vide $_POST, on le dit clairement.
        if (empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) {
            self::back('Fichier trop lourd pour le serveur (limite : ' . ini_get('post_max_size') . ').', 'error');
        }
        check_admin_referer('hted_landing_import');
        @set_time_limit(180);
        $tmp_own = '';
        if (!empty($_POST['hl_b64'])) {
            // Fichier encodé par le navigateur : le pare-feu de l'hébergeur ne voit pas de HTML brut.
            $name = sanitize_file_name(wp_unslash($_POST['hl_b64_name'] ?? 'page.html'));
            $data = base64_decode((string) $_POST['hl_b64'], true);
            if ($data === false || $data === '') self::back('Fichier illisible pendant l\'envoi. Réessayez.', 'error');
            $tmp_own = tempnam(get_temp_dir(), 'hted');
            file_put_contents($tmp_own, $data);
            unset($data);
            $f = ['name' => $name, 'tmp_name' => $tmp_own];
        } else {
            $f = $_FILES['hl_zip'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) self::back('Aucun fichier reçu (ou fichier trop lourd pour le serveur).', 'error');
        }
        if (!in_array(strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)), ['zip', 'html', 'htm'], true)) {
            if ($tmp_own) @unlink($tmp_own);
            self::back('Envoyez un fichier .zip ou .html.', 'error');
        }
        $sku = sanitize_text_field(wp_unslash($_POST['hl_sku'] ?? ''));
        try {
            $slug = HTED_Landing_Landings::import_file($f['tmp_name'], $f['name'], [
                'slug'       => sanitize_title(wp_unslash($_POST['hl_slug'] ?? '')),
                'sku'        => $sku,
                'allow_html' => current_user_can('unfiltered_html'),
            ]);
        } catch (Throwable $e) {
            $slug = new WP_Error('crash', 'Erreur pendant l\'import : ' . $e->getMessage());
        }
        if ($tmp_own) @unlink($tmp_own);
        if (is_wp_error($slug)) self::back($slug->get_error_message(), 'error');
        do_action('litespeed_purge_all');
        $meta = HTED_Landing_Landings::meta($slug);
        $where = [
            'placeholder' => ' Formulaire placé à l\'emplacement {{order_form}}.',
            'element'     => ' Formulaire placé dans l\'élément data-hted-form.',
            'replaced'    => ' Le formulaire de la page a été remplacé par le formulaire de commande.',
            'section'     => ' Formulaire de commande placé après la section « commander » de la page.',
            'appended'    => ' Formulaire ajouté en bas de page, avant le pied de page (écrivez {{order_form}} dans la page pour choisir son emplacement).',
        ][$meta['form_placement'] ?? ''] ?? '';
        $label = ($meta['mode'] ?? '') === 'libre' ? 'Page' : 'Landing';
        if (!empty($meta['missing'])) {
            $where .= ' ATTENTION : ' . count($meta['missing']) . ' fichier(s) appelé(s) par la page sont absents (' . implode(', ', array_slice($meta['missing'], 0, 4)) . (count($meta['missing']) > 4 ? '…' : '') . '). Mettez la page et ses images dans un même .zip et réimportez.';
        }
        // Page créée automatiquement si l'UGS est connue et qu'aucune page n'existe encore.
        if (!HTED_Landing_Landings::pages_using($slug)) {
            $pid = HTED_Landing_Landings::resolve_product($meta['sku'] ?? '');
            if ($pid) {
                $page = HTED_Landing_Landings::create_page($slug, $pid);
                if (!is_wp_error($page)) self::back($label . ' « ' . $slug . ' » installée et publiée : ' . get_permalink($page) . '.' . $where);
            }
            self::back($label . ' « ' . $slug . ' » installée. Choisissez le produit ci-dessous pour publier sa page.' . $where);
        }
        self::back($label . ' « ' . $slug . ' » mise à jour.' . $where);
    }

    public static function handle_style() {
        if (!current_user_can('manage_woocommerce')) wp_die('Accès refusé.');
        $slug = sanitize_key($_POST['slug'] ?? '');
        check_admin_referer('hted_landing_style_' . $slug);
        if (!HTED_Landing_Landings::exists($slug)) self::back('Landing introuvable.', 'error');
        if (!empty($_POST['reset'])) {
            HTED_Landing_Landings::reset_style($slug);
            $msg = 'Style d\'origine rétabli pour « ' . $slug . ' ».';
        } else {
            $accent = !empty($_POST['use_accent']) ? sanitize_hex_color($_POST['accent'] ?? '') : '';
            HTED_Landing_Landings::set_style($slug, sanitize_key($_POST['theme'] ?? ''), $accent);
            $msg = 'Style de « ' . $slug . ' » mis à jour.';
        }
        do_action('litespeed_purge_all');
        $pages = HTED_Landing_Landings::pages_using($slug);
        if ($pages) $msg .= ' Voir : ' . get_permalink($pages[0]);
        self::back($msg);
    }

    public static function handle_page() {
        if (!current_user_can('manage_woocommerce')) wp_die('Accès refusé.');
        $slug = sanitize_key($_POST['slug'] ?? '');
        check_admin_referer('hted_landing_page_' . $slug);
        if (!HTED_Landing_Landings::exists($slug)) self::back('Landing introuvable.', 'error');
        $pid = HTED_Landing_Landings::resolve_product(wp_unslash($_POST['product'] ?? ''));
        if (!$pid) self::back('Produit introuvable : indiquez son ID ou son UGS.', 'error');
        $page = HTED_Landing_Landings::create_page($slug, $pid);
        if (is_wp_error($page)) self::back($page->get_error_message(), 'error');
        self::back('Page publiée : ' . get_permalink($page));
    }

    public static function handle_delete() {
        if (!current_user_can('manage_woocommerce')) wp_die('Accès refusé.');
        $slug = sanitize_key($_GET['slug'] ?? '');
        check_admin_referer('hted_landing_delete_' . $slug);
        HTED_Landing_Landings::delete($slug);
        self::back('Landing « ' . $slug . ' » supprimée.');
    }

    public static function menu() {
        add_submenu_page('woocommerce', 'HTED Landing', 'HTED Landing', 'manage_woocommerce', 'hted-landing', [__CLASS__, 'page']);
    }

    public static function register() {
        register_setting('hted_landing', self::OPT, ['sanitize_callback' => [__CLASS__, 'sanitize']]);
    }

    public static function sanitize($in) {
        $out = self::defaults();
        $out['pixel_id']      = preg_replace('/\D/', '', $in['pixel_id'] ?? '');
        $out['fee_home']      = ($in['fee_home'] ?? '') === '' ? '' : absint($in['fee_home']);
        $out['fee_desk']      = ($in['fee_desk'] ?? '') === '' ? '' : absint($in['fee_desk']);
        $out['fee_overrides'] = sanitize_textarea_field($in['fee_overrides'] ?? '');
        $out['order_status']  = in_array($in['order_status'] ?? '', ['processing', 'on-hold', 'pending'], true) ? $in['order_status'] : 'processing';
        $out['phone']         = sanitize_text_field($in['phone'] ?? '');
        $out['delay']         = sanitize_text_field($in['delay'] ?? '');
        $out['warranty']      = sanitize_text_field($in['warranty'] ?? '');
        $out['brand']         = sanitize_text_field($in['brand'] ?? '');
        $out['logo_url']      = esc_url_raw($in['logo_url'] ?? '');
        $out['accent']        = sanitize_hex_color($in['accent'] ?? '') ?: '#C8141F';
        $out['topbar']        = sanitize_text_field($in['topbar'] ?? '');
        return $out;
    }

    /** Wilayas : codes WooCommerce (DZ-01…DZ-58) => "16 – Alger". */
    public static function wilayas() {
        $states = WC()->countries->get_states('DZ');
        if (empty($states)) {
            $names = ['Adrar','Chlef','Laghouat','Oum El Bouaghi','Batna','Béjaïa','Biskra','Béchar','Blida','Bouira','Tamanrasset','Tébessa','Tlemcen','Tiaret','Tizi Ouzou','Alger','Djelfa','Jijel','Sétif','Saïda','Skikda','Sidi Bel Abbès','Annaba','Guelma','Constantine','Médéa','Mostaganem','M\'Sila','Mascara','Ouargla','Oran','El Bayadh','Illizi','Bordj Bou Arréridj','Boumerdès','El Tarf','Tindouf','Tissemsilt','El Oued','Khenchela','Souk Ahras','Tipaza','Mila','Aïn Defla','Naâma','Aïn Témouchent','Ghardaïa','Relizane','Timimoun','Bordj Badji Mokhtar','Ouled Djellal','Béni Abbès','In Salah','In Guezzam','Touggourt','Djanet','El M\'Ghair','El Meniaa'];
            $states = [];
            foreach ($names as $i => $n) $states[sprintf('DZ-%02d', $i + 1)] = $n;
        }
        $out = [];
        foreach ($states as $code => $name) {
            $num = (int) preg_replace('/\D/', '', $code);
            $label = preg_match('/^\d/', $name) ? $name : sprintf('%02d – %s', $num, $name);
            $out[$code] = $label;
        }
        return $out;
    }

    /** Frais par wilaya : [code => ['home' => int|null, 'desk' => int|null]]. */
    public static function fees() {
        $s = self::get();
        $home = $s['fee_home'] === '' ? null : (int) $s['fee_home'];
        $desk = $s['fee_desk'] === '' ? null : (int) $s['fee_desk'];
        $over = [];
        foreach (preg_split('/\R/', (string) $s['fee_overrides']) as $line) {
            $p = array_map('trim', explode(';', $line));
            if (count($p) < 2 || !ctype_digit($p[0])) continue;
            $over[(int) $p[0]] = [
                'home' => ($p[1] ?? '') === '' ? null : (int) $p[1],
                'desk' => ($p[2] ?? '') === '' || $p[2] === '-' ? null : (int) $p[2],
            ];
        }
        $out = [];
        foreach (array_keys(self::wilayas()) as $code) {
            $num = (int) preg_replace('/\D/', '', $code);
            $out[$code] = $over[$num] ?? ['home' => $home, 'desk' => $desk];
        }
        return $out;
    }

    public static function page() {
        $s = self::get();
        $n = self::OPT;
        ?>
        <div class="wrap">
            <h1>HTED Landing</h1>
            <p>Importez un paquet de landing : sa page est publiée automatiquement à l'adresse <code>/nom-de-la-landing/</code> si le paquet indique l'UGS du produit, sinon choisissez le produit dans la liste. La page s'affiche sans le thème, et chaque commande arrive dans WooCommerce comme une commande normale.</p>
            <?php if (class_exists('HTED_Landing_HLV') && HTED_Landing_HLV::available()) : ?>
                <div class="notice notice-success inline"><p><strong>HTED Livraison détecté.</strong> La landing utilise le même référentiel que le checkout : communes, bureaux, transporteurs (Yalidine, ZR Express…), frais, remise, surpoids et livraison offerte. Les commandes se traitent depuis le bloc « Livraison ». Les frais ci-dessous ne servent qu'en secours.</p></div>
            <?php elseif (class_exists('HTED_Landing_Yalidine') && HTED_Landing_Yalidine::available()) : ?>
                <div class="notice notice-success inline"><p><strong>Plugin Yalidine détecté.</strong> La landing utilise les frais réels Yalidine (même calcul et même remise que le checkout), la liste des communes et des bureaux stop desk. Les frais ci-dessous ne servent qu'en secours.</p></div>
            <?php else : ?>
                <div class="notice notice-warning inline"><p>Plugin Yalidine non détecté ou clés API absentes : la landing utilise les frais saisis ci-dessous.</p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['hl_msg'])) : ?>
                <div class="notice notice-<?php echo ($_GET['hl_type'] ?? '') === 'error' ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html(wp_unslash($_GET['hl_msg'])); ?></p></div>
            <?php endif; ?>

            <h2>Landings installées</h2>
            <?php $landings = HTED_Landing_Landings::all(); ?>
            <?php if (!$landings) : ?>
                <p>Aucune landing installée. Importez un paquet .zip ci-dessous.</p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:900px">
                    <thead><tr><th>Landing</th><th>Style</th><th>Pages</th><th>Mise à jour</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($landings as $slug => $l) : $pages = HTED_Landing_Landings::pages_using($slug); ?>
                        <tr>
                            <td><strong><?php echo esc_html($l['title']); ?></strong><br><code><?php echo esc_html($slug); ?></code> · <?php echo (int) $l['images']; ?> images<?php if ($l['mode'] === 'libre') echo ' · <em>page libre</em>'; ?>
                                <?php if ($l['placement'] === 'appended') echo '<br><span style="color:#996800">Formulaire en bas de page</span>'; ?>
                                <?php if (!empty($l['missing'])) echo '<br><span style="color:#b32d2e">' . count($l['missing']) . ' fichier(s) manquant(s) : réimportez en .zip avec les images</span>'; ?></td>
                            <td><?php $st = HTED_Landing_Landings::style($slug); $acc = $st['accent'] ?: $s['accent']; ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                                    <input type="hidden" name="action" value="hted_landing_style">
                                    <input type="hidden" name="slug" value="<?php echo esc_attr($slug); ?>">
                                    <?php wp_nonce_field('hted_landing_style_' . $slug); ?>
                                    <select name="theme" aria-label="Thème">
                                        <?php foreach (HTED_Landing_Landings::THEMES as $k => $label) : ?>
                                            <option value="<?php echo esc_attr($k); ?>" <?php selected($st['theme'], $k); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label title="Couleur des boutons propre à cette landing"><input type="checkbox" name="use_accent" value="1" <?php checked($st['accent'] !== ''); ?>> <input type="color" name="accent" value="<?php echo esc_attr($acc); ?>" aria-label="Couleur des boutons"></label>
                                    <button class="button">Appliquer</button>
                                    <?php if ($st['custom']) : ?><button class="button-link" name="reset" value="1">Style d'origine</button><?php endif; ?>
                                </form>
                            </td>
                            
                            <td><?php if ($pages) { foreach ($pages as $pid) echo '<a href="' . esc_url(get_permalink($pid)) . '" target="_blank">' . esc_html(get_the_title($pid)) . '</a> · <a href="' . esc_url(get_edit_post_link($pid)) . '">modifier</a><br>'; } else { ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:6px;flex-wrap:wrap">
                                    <input type="hidden" name="action" value="hted_landing_page">
                                    <input type="hidden" name="slug" value="<?php echo esc_attr($slug); ?>">
                                    <?php wp_nonce_field('hted_landing_page_' . $slug); ?>
                                    <input name="product" placeholder="ID ou UGS du produit" required style="width:170px">
                                    <button class="button button-primary">Publier la page</button>
                                </form>
                            <?php } ?></td>
                            <td><?php echo esc_html(date_i18n('d/m/Y H:i', $l['updated'])); ?></td>
                            <td><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=hted_landing_delete&slug=' . $slug), 'hted_landing_delete_' . $slug)); ?>" onclick="return confirm('Supprimer cette landing ? Les pages qui l\'utilisent ne s\'afficheront plus.');" style="color:#b32d2e">Supprimer</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2>Importer une landing</h2>
            <form id="hl-import-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="hted_landing_import">
                <input type="hidden" name="hl_b64" value="">
                <input type="hidden" name="hl_b64_name" value="">
                <?php wp_nonce_field('hted_landing_import'); ?>
                <table class="form-table" role="presentation" style="max-width:900px">
                    <tr><th><label for="hl_zip">Fichier</label></th>
                        <td><input id="hl_zip" type="file" name="hl_zip" accept=".zip,.html,.htm" required>
                        <p class="description">Un paquet HTED (.zip), ou <strong>n'importe quelle page de vente</strong> faite avec un autre outil ou une IA : un fichier .html seul, ou un .zip contenant index.html et ses images/CSS.</p></td></tr>
                    <tr><th><label for="hl_slug">Nom de la page</label></th>
                        <td><input id="hl_slug" name="hl_slug" class="regular-text" placeholder="ex. casque-ep12 (facultatif)">
                        <p class="description">Devient l'adresse : <?php echo esc_html(home_url('/')); ?><em>nom</em>/. Vide = nom du fichier.</p></td></tr>
                    <tr><th><label for="hl_sku">Produit (UGS ou ID)</label></th>
                        <td><input id="hl_sku" name="hl_sku" class="regular-text" placeholder="ex. EP12 ou 9620 (facultatif)">
                        <p class="description">Renseigné : la page est publiée tout de suite.</p></td></tr>
                </table>
                <?php submit_button('Importer', 'primary', 'submit', false); ?>
                <p class="description">Un fichier du même nom remplace l'ancien : c'est ainsi qu'on met à jour une landing.<br>
                Page d'un autre outil : son formulaire est remplacé par le formulaire de commande du plugin. Pour choisir l'emplacement, écrivez <code>{{order_form}}</code> dans la page ; <code>{{price}}</code> affiche le prix WooCommerce.</p>
            </form>
            <script>
            (function () {
                // Le fichier est encodé avant l'envoi : le pare-feu de l'hébergeur ne voit pas de HTML ni de script,
                // donc il ne bloque pas l'import et ne bannit pas l'adresse IP de l'administrateur.
                var form = document.getElementById('hl-import-form');
                if (!form || !window.FileReader) return;
                form.addEventListener('submit', function (e) {
                    if (form.dataset.ready) return;
                    var input = document.getElementById('hl_zip');
                    var file = input.files && input.files[0];
                    if (!file) return;
                    e.preventDefault();
                    var btn = form.querySelector('[type=submit]');
                    if (btn) { btn.disabled = true; btn.value = 'Envoi…'; }
                    var r = new FileReader();
                    r.onload = function () {
                        var s = String(r.result);
                        form.querySelector('[name=hl_b64]').value = s.slice(s.indexOf(',') + 1);
                        form.querySelector('[name=hl_b64_name]').value = file.name;
                        input.disabled = true;
                        form.dataset.ready = '1';
                        // Le bouton s'appelle « submit » : form.submit serait le bouton, pas la fonction.
                        HTMLFormElement.prototype.submit.call(form);
                    };
                    r.onerror = function () { if (btn) { btn.disabled = false; btn.value = 'Importer'; } alert('Lecture du fichier impossible.'); };
                    r.readAsDataURL(file);
                });
            })();
            </script>
            <hr>

            <form method="post" action="options.php">
                <?php settings_fields('hted_landing'); ?>
                <h2>Apparence</h2>
                <table class="form-table" role="presentation">
                    <tr><th><label for="br">Nom affiché</label></th>
                        <td><input id="br" class="regular-text" name="<?php echo $n; ?>[brand]" value="<?php echo esc_attr($s['brand']); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>"></td></tr>
                    <tr><th><label for="lg">Logo (URL de l'image)</label></th>
                        <td><input id="lg" class="large-text" name="<?php echo $n; ?>[logo_url]" value="<?php echo esc_attr($s['logo_url']); ?>" placeholder="Laisser vide pour afficher le nom">
                        <p class="description">Médiathèque → votre logo → « Copier l'URL ». Hauteur affichée : 40 px.</p></td></tr>
                    <tr><th><label for="ac">Couleur des boutons</label></th>
                        <td><input id="ac" type="color" name="<?php echo $n; ?>[accent]" value="<?php echo esc_attr($s['accent']); ?>"></td></tr>
                    <tr><th><label for="tb">Bandeau du haut</label></th>
                        <td><input id="tb" class="large-text" name="<?php echo $n; ?>[topbar]" value="<?php echo esc_attr($s['topbar']); ?>">
                        <p class="description">Vide = pas de bandeau.</p></td></tr>
                </table>
                <h2>Commandes et livraison</h2>
                <table class="form-table" role="presentation">
                    <tr><th><label for="px">ID Pixel Meta</label></th>
                        <td><input id="px" class="regular-text" name="<?php echo $n; ?>[pixel_id]" value="<?php echo esc_attr($s['pixel_id']); ?>">
                        <p class="description">Laisser vide si un autre plugin gère déjà le pixel (sinon les événements seront comptés deux fois). Événements envoyés : PageView, ViewContent, InitiateCheckout, Purchase.</p></td></tr>
                    <tr><th><label for="fh">Frais domicile par défaut (DA)</label></th>
                        <td><input id="fh" type="number" min="0" name="<?php echo $n; ?>[fee_home]" value="<?php echo esc_attr($s['fee_home']); ?>"></td></tr>
                    <tr><th><label for="fd">Frais stop desk par défaut (DA)</label></th>
                        <td><input id="fd" type="number" min="0" name="<?php echo $n; ?>[fee_desk]" value="<?php echo esc_attr($s['fee_desk']); ?>">
                        <p class="description">Vide = option stop desk masquée.</p></td></tr>
                    <tr><th><label for="fo">Frais par wilaya</label></th>
                        <td><textarea id="fo" rows="10" class="large-text code" name="<?php echo $n; ?>[fee_overrides]" placeholder="16;400;250&#10;15;450;300&#10;11;1200;-"><?php echo esc_textarea($s['fee_overrides']); ?></textarea>
                        <p class="description">Une ligne par wilaya : <code>numéro;domicile;stopdesk</code>. Mettre <code>-</code> pour désactiver le stop desk. Les wilayas absentes prennent les frais par défaut. Sans aucun frais connu, la page affiche « frais confirmés par téléphone ».</p></td></tr>
                    <tr><th><label for="os">Statut des nouvelles commandes</label></th>
                        <td><select id="os" name="<?php echo $n; ?>[order_status]">
                            <?php foreach (['processing' => 'En cours (processing)', 'on-hold' => 'En attente (on-hold)', 'pending' => 'En attente de paiement (pending)'] as $k => $l) : ?>
                                <option value="<?php echo $k; ?>" <?php selected($s['order_status'], $k); ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Choisir le même statut que vos commandes COD habituelles, pour que la confirmation et Yalidine les traitent pareil.</p></td></tr>
                    <tr><th><label for="ph">Téléphone service client</label></th>
                        <td><input id="ph" class="regular-text" name="<?php echo $n; ?>[phone]" value="<?php echo esc_attr($s['phone']); ?>"></td></tr>
                    <tr><th><label for="dl">Délai de livraison</label></th>
                        <td><input id="dl" class="regular-text" name="<?php echo $n; ?>[delay]" value="<?php echo esc_attr($s['delay']); ?>"></td></tr>
                    <tr><th><label for="wr">Garantie</label></th>
                        <td><input id="wr" class="regular-text" name="<?php echo $n; ?>[warranty]" value="<?php echo esc_attr($s['warranty']); ?>" placeholder="ex. 6 mois"></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
