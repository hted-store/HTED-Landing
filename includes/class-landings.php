<?php
if (!defined('ABSPATH')) exit;

/**
 * Les landings vivent HORS du plugin, dans wp-content/uploads/hted-landings/<slug>/ :
 * une mise à jour du plugin ne les touche jamais.
 *
 * Format d'un paquet (zip) :
 *   landing.html  — le contenu de la page (HTML simple, sans script ni style inline)
 *   landing.json  — {"slug","title","description","hero","faq":[["question","réponse"],…]}
 *   *.webp|jpg|png — les images, à plat (pas de sous-dossiers)
 *
 * Jetons disponibles dans landing.html :
 *   {{price}}         prix du produit formaté (« 7 300 »)
 *   {{product_name}}  nom du produit WooCommerce
 *   {{img:hero.webp}} URL d'une image du paquet
 */
class HTED_Landing_Landings {

    const IMG_EXT = ['webp', 'jpg', 'jpeg', 'png', 'gif'];
    const THEMES  = ['clair' => 'Clair', 'sombre' => 'Sombre', 'couleur' => 'Couleur', 'elegant' => 'Élégant', 'energie' => 'Énergie'];
    const STYLE_OPT = 'hted_landing_styles';

    /**
     * Style effectif d'une landing : ['theme', 'accent' (#hex ou '')].
     * Priorité : réglage fait dans l'admin > landing.json du paquet.
     */
    public static function style($slug) {
        $meta = self::meta($slug);
        $over = get_option(self::STYLE_OPT, []);
        $over = is_array($over) ? ($over[$slug] ?? []) : [];
        $theme = $over['theme'] ?? ($meta['theme'] ?? 'clair');
        $accent = $over['accent'] ?? ($meta['accent'] ?? '');
        return [
            'theme'  => isset(self::THEMES[$theme]) ? $theme : 'clair',
            'accent' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $accent) ? $accent : '',
            'custom' => !empty($over),
        ];
    }

    public static function set_style($slug, $theme, $accent) {
        $all = get_option(self::STYLE_OPT, []);
        $all = is_array($all) ? $all : [];
        $row = [];
        if (isset(self::THEMES[$theme])) $row['theme'] = $theme;
        if (preg_match('/^#[0-9a-fA-F]{6}$/', (string) $accent)) $row['accent'] = strtolower($accent);
        $all[$slug] = $row;
        update_option(self::STYLE_OPT, $all, false);
    }

    /** Revient au style prévu par le paquet. */
    public static function reset_style($slug) {
        $all = get_option(self::STYLE_OPT, []);
        if (is_array($all) && isset($all[$slug])) {
            unset($all[$slug]);
            update_option(self::STYLE_OPT, $all, false);
        }
    }
    const MAX_ZIP = 15 * 1024 * 1024;

    public static function base_dir() {
        $u = wp_upload_dir(null, false);
        return trailingslashit($u['basedir']) . 'hted-landings/';
    }

    public static function base_url() {
        $u = wp_upload_dir(null, false);
        return trailingslashit(set_url_scheme($u['baseurl'])) . 'hted-landings/';
    }

    /** Mode d'une landing : 'hted' (paquet au format du plugin) ou 'libre' (page HTML complète). */
    public static function mode($slug) {
        return (self::meta($slug)['mode'] ?? '') === 'libre' ? 'libre' : 'hted';
    }

    public static function exists($slug) {
        $slug = sanitize_key($slug);
        if ($slug === '') return false;
        $dir = self::base_dir() . $slug . '/';
        if (self::mode($slug) === 'libre') return file_exists($dir . (self::meta($slug)['entry'] ?? 'index.html'));
        return file_exists($dir . 'landing.html');
    }

    public static function meta($slug) {
        $f = self::base_dir() . sanitize_key($slug) . '/landing.json';
        $m = file_exists($f) ? json_decode((string) file_get_contents($f), true) : [];
        return is_array($m) ? $m : [];
    }

    /** [slug => ['title','updated','images','mode','placement']] */
    public static function all() {
        $out = [];
        foreach (glob(self::base_dir() . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            $slug = basename($dir);
            if (!self::exists($slug)) continue;
            $m = self::meta($slug);
            $libre = self::mode($slug) === 'libre';
            $file = $dir . '/' . ($libre ? ($m['entry'] ?? 'index.html') : 'landing.html');
            $out[$slug] = [
                'title'   => $m['title'] ?? $slug,
                'updated' => filemtime($file),
                'images'  => count(array_filter(self::files($dir), function ($f) { return in_array(strtolower($f->getExtension()), self::IMG_EXT, true); })),
                'mode'    => $libre ? 'libre' : 'hted',
                'placement' => $m['form_placement'] ?? '',
                'missing' => (array) ($m['missing'] ?? []),
            ];
        }
        ksort($out);
        return $out;
    }

    /** Liste récursive des fichiers d'un dossier. */
    private static function files($dir) {
        $out = [];
        if (!is_dir($dir)) return $out;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $out[] = $f;
        return array_filter($out, function ($f) { return $f->isFile(); });
    }

    public static function img_url($slug, $name) {
        $name = basename($name);
        $path = self::base_dir() . $slug . '/' . $name;
        if (!file_exists($path)) return '';
        return esc_url(self::base_url() . $slug . '/' . rawurlencode($name) . '?v=' . filemtime($path));
    }

    /** Balises et attributs autorisés : aucun script, aucun style inline, aucun gestionnaire d'événement. */
    private static function allowed_html() {
        $common = ['class' => true, 'id' => true, 'dir' => true, 'lang' => true, 'aria-label' => true, 'aria-hidden' => true];
        $tags = ['section', 'header', 'div', 'span', 'p', 'h1', 'h2', 'h3', 'h4', 'strong', 'b', 'em', 'i', 'small', 'br', 'ul', 'ol', 'li',
                 'dl', 'dt', 'dd', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'figure', 'figcaption', 'blockquote', 'bdi', 'details', 'summary', 'hr'];
        $a = [];
        foreach ($tags as $t) $a[$t] = $common;
        $a['a']   = $common + ['href' => true, 'target' => true, 'rel' => true];
        $a['img'] = $common + ['src' => true, 'alt' => true, 'width' => true, 'height' => true, 'loading' => true, 'fetchpriority' => true, 'decoding' => true];
        return $a;
    }

    /** HTML final de la landing, jetons remplacés, nettoyé. */
    public static function render($slug, $vars) {
        $slug = sanitize_key($slug);
        $html = (string) file_get_contents(self::base_dir() . $slug . '/landing.html');
        $html = preg_replace_callback('/\{\{\s*img:([A-Za-z0-9._-]+)\s*\}\}/', function ($m) use ($slug) {
            return self::img_url($slug, $m[1]);
        }, $html);
        $html = strtr($html, [
            '{{price}}'        => esc_html($vars['price']),
            '{{product_name}}' => esc_html($vars['product_name']),
        ]);
        return wp_kses($html, self::allowed_html(), ['http', 'https']);
    }

    /** Extensions acceptées dans une page libre (en plus de son fichier HTML). */
    const LIBRE_EXT = ['css', 'js', 'mjs', 'webp', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'avif', 'ico',
                       'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp4', 'webm'];

    /** Vérifie qu'un fichier est bien ce que son extension annonce (images seulement). */
    private static function looks_like($ext, $data) {
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) return (bool) @getimagesizefromstring($data);
        if ($ext === 'svg') return (bool) preg_match('/^\s*(?:<\?xml[^>]*>\s*)?(?:<!--.*?-->\s*)*(?:<!DOCTYPE[^>]*>\s*)?<svg\b/is', substr($data, 0, 2000));
        return true; // css, js, polices, avif, ico, vidéos : pas de contrôle de contenu
    }

    /** Chemin relatif sûr (pas de .., pas de chemin absolu) ou '' si à ignorer. */
    private static function safe_path($name) {
        $name = str_replace('\\', '/', (string) $name);
        if ($name === '' || substr($name, -1) === '/' || strpos($name, '__MACOSX') !== false) return '';
        $parts = [];
        foreach (explode('/', $name) as $seg) {
            if ($seg === '' || $seg === '.') continue;
            if ($seg === '..' || $seg[0] === '.') return ''; // remontée ou fichier caché
            // Noms conservés tels quels (les liens de la page doivent continuer à pointer dessus),
            // sauf caractères de contrôle et séparateurs interdits.
            if (preg_match('/[\x00-\x1F<>:"|?*]/', $seg)) return '';
            $parts[] = $seg;
        }
        return implode('/', $parts);
    }

    /**
     * Lit le zip en mémoire : [chemin relatif => contenu].
     * ZipArchive si présent, sinon PclZip (fourni par WordPress) : marche sur tout hébergement.
     */
    private static function read_zip($tmp) {
        $out = [];
        if (class_exists('ZipArchive') && !defined('HTED_LANDING_FORCE_PCLZIP')) {
            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) return new WP_Error('open', 'Fichier zip illisible.');
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $path = self::safe_path($zip->getNameIndex($i));
                if ($path === '') continue;
                $data = $zip->getFromIndex($i);
                if ($data !== false) $out[$path] = $data;
            }
            $zip->close();
            return $out;
        }
        if (!class_exists('PclZip')) require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        $archive = new PclZip($tmp);
        $list = $archive->extract(PCLZIP_OPT_EXTRACT_AS_STRING);
        if (!is_array($list)) return new WP_Error('open', 'Fichier zip illisible (' . $archive->errorInfo(true) . ').');
        foreach ($list as $f) {
            if (!empty($f['folder'])) continue;
            $path = self::safe_path($f['filename']);
            if ($path !== '' && isset($f['content'])) $out[$path] = $f['content'];
        }
        return $out;
    }

    private static function prepare_dir($slug) {
        $dir = self::base_dir() . $slug . '/';
        if (!wp_mkdir_p($dir)) return new WP_Error('mkdir', 'Impossible de créer le dossier ' . $dir);
        // Bloque toute exécution PHP et l'accès direct aux fichiers HTML (Apache / LiteSpeed).
        if (!file_exists(self::base_dir() . '.htaccess')) {
            file_put_contents(self::base_dir() . '.htaccess', "<FilesMatch \"\\.(php|phtml|phar|html?)$\">\nRequire all denied\n</FilesMatch>\nOptions -Indexes\n");
        }
        // Remplacement complet : on vide l'ancienne version et on oublie le style réglé à la main.
        self::empty_dir($dir);
        self::reset_style($slug);
        return $dir;
    }

    /**
     * Importe un fichier téléversé : paquet .zip (format HTED ou site exporté par un outil/une IA)
     * ou page .html seule. Retourne le slug ou WP_Error.
     * $opts : ['slug' => nom souhaité, 'sku' => UGS/ID, 'allow_html' => bool (capacité unfiltered_html)]
     */
    public static function import_file($tmp, $original_name, $opts = []) {
        if (filesize($tmp) > self::MAX_ZIP) return new WP_Error('size', 'Fichier trop lourd (15 Mo maximum).');
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $stem = sanitize_key(preg_replace('/^landing-/', '', pathinfo($original_name, PATHINFO_FILENAME)));

        if (in_array($ext, ['html', 'htm'], true)) {
            $files = ['index.html' => (string) file_get_contents($tmp)];
        } elseif ($ext === 'zip') {
            $files = self::read_zip($tmp);
            if (is_wp_error($files)) return $files;
        } else {
            return new WP_Error('type', 'Envoyez un fichier .zip ou .html.');
        }

        // Paquet au format HTED : présence de landing.html.
        foreach (array_keys($files) as $path) {
            if (basename($path) === 'landing.html') return self::import_hted($files, $stem, $opts);
        }
        return self::import_libre($files, $stem, $opts);
    }

    /** Compatibilité avec l'ancien appel. */
    public static function import_zip($tmp, $original_name) {
        return self::import_file($tmp, $original_name, ['allow_html' => true]);
    }

    private static function import_hted($files, $stem, $opts) {
        $flat = [];
        foreach ($files as $path => $data) $flat[basename($path)] = $data; // à plat
        $meta = isset($flat['landing.json']) ? json_decode((string) $flat['landing.json'], true) : [];
        $meta = is_array($meta) ? $meta : [];
        $slug = sanitize_key(($opts['slug'] ?? '') ?: ($meta['slug'] ?? $stem));
        if ($slug === '') return new WP_Error('slug', 'Indiquez un nom pour la page.');
        $dir = self::prepare_dir($slug);
        if (is_wp_error($dir)) return $dir;

        foreach ($flat as $base => $data) {
            $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
            $is_img = in_array($ext, self::IMG_EXT, true);
            if (!$is_img && $base !== 'landing.html') continue;
            if ($is_img && !@getimagesizefromstring($data)) continue; // pas une vraie image
            file_put_contents($dir . $base, $data);
        }
        $meta['slug'] = $slug;
        $meta['mode'] = 'hted';
        if (!empty($opts['sku'])) $meta['sku'] = (string) $opts['sku'];
        file_put_contents($dir . 'landing.json', wp_json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $slug;
    }

    private static function import_libre($files, $stem, $opts) {
        if (empty($opts['allow_html'])) {
            return new WP_Error('perm', 'Seul un administrateur peut importer une page HTML libre.');
        }
        // Page d'entrée : index.html le moins profond, sinon le seul fichier HTML.
        $htmls = array_values(array_filter(array_keys($files), function ($p) { return preg_match('/\.html?$/i', $p); }));
        if (!$htmls) return new WP_Error('html', 'Aucune page HTML dans le fichier.');
        usort($htmls, function ($a, $b) { return substr_count($a, '/') - substr_count($b, '/') ?: strcmp($a, $b); });
        $entry = null;
        foreach ($htmls as $h) if (strtolower(basename($h)) === 'index.html') { $entry = $h; break; }
        if (!$entry) {
            if (count($htmls) > 1) return new WP_Error('html', 'Plusieurs pages HTML et aucune nommée index.html : laquelle utiliser ?');
            $entry = $htmls[0];
        }
        $root = dirname($entry) === '.' ? '' : dirname($entry) . '/';

        $zip_meta = [];
        foreach (['landing.json', $root . 'landing.json'] as $j) {
            if (isset($files[$j])) { $zip_meta = json_decode((string) $files[$j], true) ?: []; break; }
        }
        $html = (string) $files[$entry];
        $title = preg_match('/<title[^>]*+>([^<]{0,300})<\/title>/i', substr($html, 0, 50000), $m) ? trim(html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES, 'UTF-8')) : '';
        $slug = sanitize_key(($opts['slug'] ?? '') ?: ($zip_meta['slug'] ?? ''));
        if ($slug === '' && !in_array($stem, ['', 'index', 'page', 'landing'], true)) $slug = $stem;
        if ($slug === '' && $title !== '') {
            // « NovaWatch — Votre quotidien, en mieux » → novawatch
            $short = trim(preg_split('/\s+[—–|:-]\s+/u', $title)[0]);
            $slug = sanitize_key(sanitize_title(implode(' ', array_slice(preg_split('/\s+/u', $short), 0, 5))));
        }
        if ($slug === '' || $slug === 'index') $slug = 'landing-' . gmdate('Ymd-His');

        $dir = self::prepare_dir($slug);
        if (is_wp_error($dir)) return $dir;

        file_put_contents($dir . 'index.html', $html);
        foreach ($files as $path => $data) {
            if ($path === $entry || ($root !== '' && strpos($path, $root) !== 0)) continue;
            $rel = substr($path, strlen($root));
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            if (!in_array($ext, self::LIBRE_EXT, true)) continue;
            if (!self::looks_like($ext, $data)) continue;
            $target = $dir . $rel;
            wp_mkdir_p(dirname($target));
            file_put_contents($target, $data);
        }

        $test = $html;
        $placement = self::place_form($test, '')['placement'];
        $meta = array_merge($zip_meta, [
            'slug'  => $slug,
            'mode'  => 'libre',
            'entry' => 'index.html',
            'title' => ($zip_meta['title'] ?? '') ?: ($title ?: $slug),
            'form_placement' => $placement,
            'missing' => self::missing_files($html, $dir),
        ]);
        if (!empty($opts['sku'])) $meta['sku'] = (string) $opts['sku'];
        file_put_contents($dir . 'landing.json', wp_json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $slug;
    }

    /** Position de l'élément ouvrant <tag ...> à partir de $from (insensible à la casse), ou false. */
    private static function find_tag($html, $tag, $from = 0) {
        $len = strlen($tag) + 1;
        while (($p = stripos($html, '<' . $tag, $from)) !== false) {
            $c = substr($html, $p + $len, 1);
            if ($c === '' || $c === '>' || $c === '/' || ctype_space($c)) return $p;
            $from = $p + $len;
        }
        return false;
    }

    /** Fichiers locaux (images, CSS, JS) appelés par la page mais absents de l'envoi. */
    public static function missing_files($html, $dir) {
        $refs = [];
        $collect = function ($u) use (&$refs) {
            $u = trim(html_entity_decode($u));
            if ($u === '' || preg_match('#^(?:[a-z][a-z0-9+.-]*:|//|/|\#|\{\{)#i', $u)) return;
            $u = preg_replace('#^(?:\./)+#', '', strtok($u, '?#'));
            if ($u !== '' && preg_match('/\.(?:' . implode('|', self::LIBRE_EXT) . ')$/i', $u)) $refs[$u] = true;
        };
        if (preg_match_all('/\s(?:src|href|poster|data-src)\s*+=\s*+(?:"([^"]*+)"|\'([^\']*+)\')/i', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) $collect(isset($x[2]) ? $x[2] : $x[1]);
        }
        if (preg_match_all('/url\(\s*+(?:"([^"]*+)"|\'([^\']*+)\'|([^\s"\')]++))\s*+\)/i', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) $collect($x[3] ?? ($x[2] ?? '') ?: $x[1]);
        }
        $missing = [];
        foreach (array_keys($refs) as $r) {
            if (!file_exists($dir . rawurldecode($r))) $missing[] = $r;
        }
        return array_slice($missing, 0, 20);
    }

    /**
     * Place le formulaire dans la page libre. Ordre de priorité :
     * 1. {{order_form}}  2. élément portant data-hted-form  3. le formulaire unique de la page
     * (souvent un faux formulaire généré par l'outil)  4. en fin de page.
     * Sans expression régulière sur toute la page : fiable même sur une page de plusieurs Mo.
     * Retourne ['html', 'placement' => placeholder|element|replaced|appended].
     */
    public static function place_form($html, $host) {
        if (strpos($html, '{{order_form}}') !== false) {
            return ['html' => str_replace('{{order_form}}', $host, $html), 'placement' => 'placeholder'];
        }
        $p = stripos($html, 'data-hted-form');
        if ($p !== false) {
            $open = strrpos(substr($html, 0, $p), '<');
            $close = strpos($html, '>', $p);
            if ($open !== false && $close !== false) {
                return ['html' => substr_replace($html, $host, $close + 1, 0), 'placement' => 'element'];
            }
        }
        $first = self::find_tag($html, 'form');
        if ($first !== false && self::find_tag($html, 'form', $first + 5) === false) {
            $end = stripos($html, '</form', $first);
            if ($end !== false && ($gt = strpos($html, '>', $end)) !== false) {
                return ['html' => substr_replace($html, $host, $first, $gt + 1 - $first), 'placement' => 'replaced'];
            }
        }
        // Une section de commande identifiée (id="commander", "order"…) : formulaire juste après elle.
        if (preg_match('/<([a-z][a-z0-9-]*+)\b[^>]*?\sid\s*=\s*["\']?(?:commande|commander|order|checkout|acheter|buy|achat|formulaire)["\'\s>]/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $end = self::element_end($html, $m[0][1], strtolower($m[1][0]));
            if ($end !== false) {
                return ['html' => substr_replace($html, $host, $end, 0), 'placement' => 'section'];
            }
        }
        // Sinon juste avant le pied de page, ou en fin de page.
        $foot = self::find_tag($html, 'footer');
        if ($foot !== false) return ['html' => substr_replace($html, $host, $foot, 0), 'placement' => 'appended'];
        $pos = strripos($html, '</body>');
        return ['html' => $pos === false ? $html . $host : substr_replace($html, $host, $pos, 0), 'placement' => 'appended'];
    }

    /** Position juste après la balise fermante de l'élément <$tag> ouvert en $start (gère l'imbrication), ou false. */
    private static function element_end($html, $start, $tag) {
        $void = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];
        if (in_array($tag, $void, true)) return false;
        $depth = 0;
        $pos = $start;
        $len = strlen($tag);
        while (true) {
            $open = self::find_tag($html, $tag, $pos);
            $close = stripos($html, '</' . $tag, $pos);
            if ($close === false) return false;
            if ($open !== false && $open < $close) {
                $depth++;
                $pos = $open + $len + 1;
                continue;
            }
            $depth--;
            $gt = strpos($html, '>', $close);
            if ($gt === false) return false;
            if ($depth === 0) return $gt + 1;
            $pos = $gt + 1;
            if ($depth < 0) return false;
        }
    }

    /**
     * Réécrit les chemins relatifs (src, href, poster, srcset, url()) vers le dossier de la landing.
     * Quantificateurs possessifs (pas de retour arrière) : tient sur des pages de plusieurs Mo,
     * images en base64 comprises. En cas d'échec, la page est rendue telle quelle.
     */
    public static function absolutize($html, $base) {
        $is_rel = function ($u) {
            $u = trim($u);
            return $u !== '' && !preg_match('#^(?:[a-z][a-z0-9+.-]*:|//|/|\#|\{\{)#i', $u);
        };
        $abs = function ($u) use ($base) {
            return $base . preg_replace('#^(?:\./)+#', '', trim($u));
        };
        $steps = [
            // src="…", href='…', poster, data-src
            ['/(\s(?:src|href|poster|data-src)\s*+=\s*+)(?:"([^"]*+)"|\'([^\']*+)\')/i', function ($m) use ($is_rel, $abs) {
                $q = isset($m[3]) ? "'" : '"';
                $v = isset($m[3]) ? $m[3] : $m[2];
                return $is_rel($v) ? $m[1] . $q . $abs($v) . $q : $m[0];
            }],
            // srcset="a.jpg 1x, b.jpg 2x"
            ['/(\ssrcset\s*+=\s*+)(?:"([^"]*+)"|\'([^\']*+)\')/i', function ($m) use ($is_rel, $abs) {
                $q = isset($m[3]) ? "'" : '"';
                $v = isset($m[3]) ? $m[3] : $m[2];
                $parts = array_map(function ($c) use ($is_rel, $abs) {
                    $bits = preg_split('/\s+/', trim($c), 2);
                    if ($is_rel($bits[0])) $bits[0] = $abs($bits[0]);
                    return implode(' ', $bits);
                }, explode(',', $v));
                return $m[1] . $q . implode(', ', $parts) . $q;
            }],
            // url(…) dans les <style> et attributs style
            ['/url\(\s*+(?:"([^"]*+)"|\'([^\']*+)\'|([^\s"\')]++))\s*+\)/i', function ($m) use ($is_rel, $abs) {
                $v = isset($m[3]) ? $m[3] : (isset($m[2]) && $m[2] !== '' ? $m[2] : $m[1]);
                $q = isset($m[3]) ? '' : (isset($m[2]) && $m[2] !== '' ? "'" : '"');
                return $is_rel($v) ? 'url(' . $q . $abs($v) . $q . ')' : $m[0];
            }],
        ];
        foreach ($steps as $st) {
            $out = preg_replace_callback($st[0], $st[1], $html);
            if ($out !== null) $html = $out; // échec PCRE : on garde la version précédente plutôt qu'une page vide
        }
        return $html;
    }

    public static function delete($slug) {
        $slug = sanitize_key($slug);
        if ($slug === '') return;
        self::empty_dir(self::base_dir() . $slug);
        @rmdir(self::base_dir() . $slug);
        self::reset_style($slug);
    }

    private static function empty_dir($dir) {
        if (!is_dir($dir)) return;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    }

    /** Pages qui utilisent une landing. */
    public static function pages_using($slug) {
        global $wpdb;
        $like = '%' . $wpdb->esc_like('landing="' . sanitize_key($slug) . '"') . '%';
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status IN ('publish','draft','private','future') AND post_content LIKE %s LIMIT 20",
            $like
        )));
    }

    /** ID produit depuis un ID ou une UGS. 0 si introuvable. */
    public static function resolve_product($ref) {
        $ref = trim((string) $ref);
        if ($ref === '') return 0;
        if (ctype_digit($ref) && wc_get_product((int) $ref)) return (int) $ref;
        return (int) wc_get_product_id_by_sku($ref);
    }

    /**
     * Crée et publie la page de la landing (adresse = /slug/). Retourne l'ID de page ou WP_Error.
     */
    public static function create_page($slug, $product_id) {
        $slug = sanitize_key($slug);
        $product = wc_get_product($product_id);
        if (!$product) return new WP_Error('product', 'Produit introuvable.');
        if (!$product->is_purchasable()) return new WP_Error('product', 'Ce produit n\'est pas achetable (prix vide ou brouillon ?).');
        $meta = self::meta($slug);
        $id = wp_insert_post([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => $meta['title'] ?? $product->get_name(),
            'post_name'    => $slug,
            'post_content' => '[hted_landing landing="' . $slug . '" product="' . (int) $product_id . '"]',
        ], true);
        if (is_wp_error($id)) return $id;
        do_action('litespeed_purge_all');
        return (int) $id;
    }
}
