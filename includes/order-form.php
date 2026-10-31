<?php if (!defined('ABSPATH')) exit; /* Formulaire de commande + barre fixe. Variables : voir context.php */ ?>
<section id="commande" class="hl-order">
  <div class="hl-card">
    <div class="hl-order-head">
      <?php if ($hero_img) : ?><img src="<?php echo $hero_img; ?>" alt="" width="72" height="72"><?php endif; ?>
      <div>
        <h2>Commander</h2>
        <p>On vous appelle pour confirmer. Paiement à la livraison.</p>
      </div>
    </div>

    <form id="hl-form" novalidate>
      <div class="hl-field">
        <label for="hl-name">Nom et prénom</label>
        <input id="hl-name" name="name" type="text" autocomplete="name" required minlength="3" maxlength="80">
      </div>
      <div class="hl-field">
        <label for="hl-phone">Téléphone</label>
        <input id="hl-phone" name="phone" type="tel" inputmode="numeric" autocomplete="tel" placeholder="05 / 06 / 07 …" required maxlength="14">
      </div>
      <div class="hl-row">
        <div class="hl-field">
          <label for="hl-wilaya">Wilaya</label>
          <select id="hl-wilaya" name="state" required>
            <option value="">Choisir…</option>
            <?php foreach ($wilayas as $code => $label) : ?>
              <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="hl-field">
          <label for="hl-commune">Commune</label>
          <?php if ($yalidine) : ?>
            <select id="hl-commune" name="commune_id" required disabled><option value="">Choisir d'abord la wilaya</option></select>
          <?php else : ?>
            <input id="hl-commune" name="city" type="text" required maxlength="80">
          <?php endif; ?>
        </div>
      </div>

      <fieldset class="hl-field" id="hl-delivery">
        <legend>Livraison</legend>
        <label class="hl-radio"><input type="radio" name="delivery" value="home" checked> <span>À domicile</span> <b data-fee="home"></b></label>
        <label class="hl-radio" id="hl-desk-opt"><input type="radio" name="delivery" value="desk"> <span>Bureau Yalidine (stop desk)</span> <b data-fee="desk"></b></label>
      </fieldset>

      <?php if ($yalidine) : ?>
      <div class="hl-field" id="hl-center-field" hidden>
        <label for="hl-center">Bureau Yalidine</label>
        <select id="hl-center" name="center_id"><option value="">Choisir un bureau</option></select>
      </div>
      <?php endif; ?>

      <div class="hl-field">
        <span class="hl-label" id="hl-qty-label">Quantité</span>
        <div class="hl-qty" role="group" aria-labelledby="hl-qty-label">
          <button type="button" data-qty="-1" aria-label="Retirer un">−</button>
          <output id="hl-qty" aria-live="polite">1</output>
          <button type="button" data-qty="1" aria-label="Ajouter un">+</button>
        </div>
      </div>

      <div class="hl-hp" aria-hidden="true">
        <label for="hl-website">Ne pas remplir</label>
        <input id="hl-website" name="website" type="text" tabindex="-1" autocomplete="off">
      </div>

      <div class="hl-total">
        <span>Total à payer à la réception</span>
        <strong id="hl-total"><?php echo esc_html($price_fmt); ?> DA</strong>
      </div>
      <p class="hl-error" id="hl-error" role="alert" hidden></p>
      <button type="submit" class="hl-btn hl-btn-block" id="hl-submit">Confirmer ma commande</button>
      <p class="hl-note">Vérifiez le colis devant le livreur avant de payer.</p>
    </form>

    <div class="hl-success" id="hl-success" hidden tabindex="-1">
      <h2>Commande reçue ✓</h2>
      <p>Merci <span id="hl-success-name"></span>. Votre commande n° <strong id="hl-success-id"></strong> est enregistrée. Nous vous appelons au <strong id="hl-success-phone"></strong> pour la confirmer.</p>
      <p>Gardez votre téléphone à portée : sans confirmation, la commande n'est pas expédiée.</p>
    </div>
  </div>
</section>

<a href="#commande" class="hl-sticky" id="hl-sticky"><span><?php echo esc_html($price_fmt); ?> DA</span> Commander</a>

