<?php
if (!defined('ABSPATH')) exit;

/**
 * Minimum d'articles par commande et « 1 offert tous les N » (option hted_landing_bundle).
 *   wp option update hted_landing_bundle '{"min":3,"lot":7,"unit":"sachet","units":"sachets"}' --format=json
 * lot = 7 : 6 achetés + le 7e offert (le moins cher). Option absente = règle éteinte.
 * HTED Vitrine lit la même option pour afficher la barre et la remise : une seule règle pour les deux.
 */
class HTED_Landing_Bundle {

    const OPT = 'hted_landing_bundle';

    public static function get(): array {
        $o = get_option(self::OPT, []);
        if (!is_array($o)) $o = [];
        $unit = sanitize_text_field((string) ($o['unit'] ?? 'article'));
        $lot = (int) ($o['lot'] ?? 0);
        return [
            'min'   => max(0, (int) ($o['min'] ?? 0)),
            'lot'   => $lot >= 2 ? $lot : 0,
            'unit'  => $unit,
            'units' => sanitize_text_field((string) ($o['units'] ?? $unit . 's')),
        ];
    }

    public static function count(array $lines): int {
        $n = 0;
        foreach ($lines as $l) $n += (int) $l[1];
        return $n;
    }

    /** Message d'erreur si la commande n'atteint pas le minimum, sinon ''. */
    public static function min_error(array $lines): string {
        $b = self::get();
        $n = self::count($lines);
        if ($b['min'] < 2 || $n >= $b['min']) return '';
        $left = $b['min'] - $n;
        return sprintf('Commande à partir de %d %s : ajoutez-en encore %d.', $b['min'], $b['units'], $left);
    }

    /** [nombre offert, montant de la remise] : les moins chers sont offerts. */
    public static function free(array $lines): array {
        $b = self::get();
        if (!$b['lot']) return [0, 0.0];
        $prices = [];
        foreach ($lines as $l) {
            $p = (float) wc_get_price_to_display($l[0]);
            for ($i = 0; $i < (int) $l[1]; $i++) $prices[] = $p;
        }
        $n = intdiv(count($prices), $b['lot']);
        if ($n < 1) return [0, 0.0];
        sort($prices);
        return [$n, (float) array_sum(array_slice($prices, 0, $n))];
    }

    public static function label(int $n): string {
        $b = self::get();
        return sprintf('🎁 %d %s offert%s', $n, $n > 1 ? $b['units'] : $b['unit'], $n > 1 ? 's' : '');
    }
}
