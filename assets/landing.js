(function () {
  var C = window.HTED_LANDING;
  if (!C) return;
  var start = Date.now();
  // Page libre : le formulaire vit dans le Shadow DOM de <hted-order>.
  var host = document.querySelector('hted-order');
  var R = (host && host.shadowRoot) || document;
  var $ = function (id) { return R.getElementById(id); };
  var form = $('hl-form');
  var qty = 1;
  // Mode panier (vitrine) : C.items = [{id, qty, price}] — plusieurs produits, un seul formulaire
  var cart = function () { return Array.isArray(C.items) && C.items.length ? C.items : null; };
  var subtotal = function () {
    var it = cart();
    return it ? it.reduce(function (t, i) { return t + i.price * i.qty; }, 0) : C.price * qty;
  };
  var itemsParam = function () { return cart().map(function (i) { return i.id + ':' + i.qty; }).join(','); };
  var ids = function () { return cart() ? cart().map(function (i) { return String(i.id); }) : [String(C.productId)]; };
  var count = function () { return cart() ? cart().reduce(function (t, i) { return t + i.qty; }, 0) : qty; };
  var initiated = false;
  // Mode Yalidine : frais réels de la commune choisie (chargés depuis le serveur).
  var yfees = { home: null, desk: null, loaded: false };
  var centers = [];
  var reqW = 0, reqC = 0;

  var fmt = function (n) { return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' '); };
  // eid : même identifiant que le Purchase serveur de Facebook for WooCommerce → dédoublonné par Meta
  var track = function (ev, data, eid) { if (C.pixel && window.fbq) { if (eid) window.fbq('track', ev, data || {}, { eventID: eid }); else window.fbq('track', ev, data || {}); } };
  var getJSON = function (path) {
    return fetch(C.api + path, { headers: { Accept: 'application/json' } }).then(function (r) {
      if (!r.ok) throw new Error('http ' + r.status);
      return r.json();
    });
  };
  var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
  // Origine de la commande (colonne « Origine » de WooCommerce) : lue dans les cookies sbjs_* posés par WooCommerce
  // sur les pages WordPress, ou par HTED Vitrine (même format) sur les pages de la vitrine.
  var attribution = function () {
    var ck = function (n) { var m = document.cookie.match('(?:^|; )' + n + '=([^;]*)'); if (!m) return {}; var o = {}, v = m[1];
      try { v = decodeURIComponent(v); } catch (e) {}
      v.split('|||').forEach(function (kv) { var i = kv.indexOf('='); if (i > 0) o[kv.slice(0, i)] = kv.slice(i + 1); }); return o; };
    var cur = ck('sbjs_current'), add = ck('sbjs_current_add'), ses = ck('sbjs_session'), ud = ck('sbjs_udata');
    if (!cur.typ) return undefined;
    var ua = navigator.userAgent || '';
    return { source_type: cur.typ, utm_source: cur.src, utm_medium: cur.mdm, utm_campaign: cur.cmp, utm_content: cur.cnt,
      utm_term: cur.trm, utm_id: cur.id, referrer: add.rf, session_entry: add.ep, session_start_time: add.fd,
      session_pages: ses.pgs, session_count: ud.vst, user_agent: ua,
      device_type: /tablet|ipad/i.test(ua) ? 'Tablet' : (/mobi|android/i.test(ua) ? 'Mobile' : 'Desktop') };
  };

  function delivery() {
    var r = form.querySelector('input[name=delivery]:checked');
    return r ? r.value : 'home';
  }

  function currentFees() {
    if (C.yalidine) return yfees;
    var state = $('hl-wilaya').value;
    var f = (state && C.fees[state]) || { home: null, desk: null };
    var known = f.home !== null || f.desk !== null;
    return { home: f.home, desk: f.desk, loaded: !!state, unknown: !known };
  }

  function refresh() {
    var state = $('hl-wilaya').value;
    var f = currentFees();
    // HTED Livraison : le prix du stop desk est celui du bureau choisi (Yalidine et ZR n'ont pas le même tarif).
    if (C.hlv && delivery() === 'desk') {
      var cs = $('hl-center'), pick = cs && cs.value ? centers.filter(function (c) { return c.id === cs.value; })[0] : null;
      if (pick) f = { home: f.home, desk: pick.price, loaded: f.loaded, unknown: f.unknown };
    }
    var deskOpt = $('hl-desk-opt');
    var deskAvailable = !f.loaded || f.unknown || f.desk !== null;
    deskOpt.hidden = !deskAvailable;
    if (!deskAvailable && delivery() === 'desk') form.querySelector('input[value=home]').checked = true;
    var homeOpt = form.querySelector('input[value=home]').closest('label');
    homeOpt.hidden = f.loaded && !f.unknown && f.home === null && f.desk !== null;
    if (homeOpt.hidden && delivery() === 'home') form.querySelector('input[value=desk]').checked = true;

    form.querySelectorAll('[data-fee]').forEach(function (el) {
      var v = f[el.getAttribute('data-fee')];
      if (!f.loaded) el.textContent = '';
      else if (f.unknown) el.textContent = 'à confirmer';
      else el.textContent = v === null || v === undefined ? '' : (v === 0 ? 'offerte' : fmt(v) + ' DA');
    });

    var cf = $('hl-center-field');
    if (cf) cf.hidden = !(C.yalidine && delivery() === 'desk' && f.loaded);

    var fee = f[delivery()];
    var sub = subtotal();
    var total = $('hl-total');
    if (!state || !f.loaded) total.textContent = fmt(sub) + ' DA + livraison';
    else if (f.unknown || fee === null || fee === undefined) total.textContent = fmt(sub) + ' DA + livraison à confirmer';
    else total.textContent = fmt(sub + fee) + ' DA';
    if ($('hl-qty')) $('hl-qty').textContent = qty;
  }

  function fillCenters() {
    var sel = $('hl-center');
    if (!sel) return;
    var cid = parseInt($('hl-commune').value, 10) || 0;
    var prev = sel.value;
    // HTED Livraison : liste déjà triée par le serveur (bureaux de la commune d'abord).
    var list = C.hlv ? centers.slice() : centers.slice().sort(function (a, b) { return (b.commune_id === cid) - (a.commune_id === cid); });
    sel.innerHTML = '<option value="">Choisir un bureau</option>' + list.map(function (c) {
      var label = C.hlv
        ? (c.near ? '📍 ' : '') + esc(c.name) + ' (' + esc(c.carrier) + ') – ' + (c.price === 0 ? 'offerte' : fmt(c.price) + ' DA')
        : esc(c.name) + (c.address ? ' – ' + esc(c.address) : '');
      return '<option value="' + esc(String(c.id)) + '">' + label + '</option>';
    }).join('');
    if (prev && list.some(function (c) { return String(c.id) === prev; })) sel.value = prev;
    else if (list.length === 1) sel.value = String(list[0].id);
  }

  function onWilaya() {
    if (!C.yalidine) return refresh();
    var wid = parseInt($('hl-wilaya').value.replace(/\D/g, ''), 10) || 0;
    var sel = $('hl-commune');
    yfees = { home: null, desk: null, loaded: false };
    centers = [];
    var my = ++reqW;
    reqC++;
    sel.disabled = true;
    sel.innerHTML = '<option value="">' + (wid ? 'Chargement…' : "Choisir d'abord la wilaya") + '</option>';
    refresh();
    if (!wid) return;
    getJSON((C.hlv ? 'hlv-communes' : 'communes') + '?wilaya=' + wid).then(function (list) {
      if (my !== reqW) return;
      sel.innerHTML = '<option value="">Choisir la commune</option>' + list.map(function (c) {
        return '<option value="' + c.id + '">' + esc(c.name) + '</option>';
      }).join('');
      sel.disabled = false;
    }).catch(function () {
      if (my !== reqW) return;
      sel.innerHTML = '<option value="">Erreur, réessayez</option>';
    });
    if (C.hlv) return; // HTED Livraison : bureaux chargés avec les frais de la commune
    getJSON('centers?wilaya=' + wid).then(function (list) {
      if (my !== reqW) return;
      centers = list;
      fillCenters();
    }).catch(function () {});
  }

  // HTED Livraison : frais et bureaux de la commune pour ce produit et cette quantité (poids, livraison offerte).
  function loadOptions(keep) {
    var cid = parseInt($('hl-commune').value, 10) || 0;
    if (!keep) { yfees = { home: null, desk: null, loaded: false }; centers = []; fillCenters(); }
    refresh();
    if (!cid) return;
    var my = ++reqC;
    if (!keep) $('hl-total').textContent = 'Calcul des frais…';
    getJSON('hlv-options?commune=' + cid + (cart() ? '&items=' + encodeURIComponent(itemsParam()) : '&product=' + C.productId + '&qty=' + qty)).then(function (f) {
      if (my !== reqC) return;
      yfees = { home: f.home, desk: f.desk, loaded: true };
      centers = f.centers || [];
      fillCenters();
      if (f.home === null && f.desk === null) showError('Livraison indisponible pour cette commune. Appelez-nous.');
      refresh();
    }).catch(function () {
      if (my !== reqC) return;
      yfees = { home: null, desk: null, loaded: true, unknown: true };
      refresh();
    });
  }

  function onCommune() {
    if (!C.yalidine) return refresh();
    if (C.hlv) return loadOptions(false);
    var wid = parseInt($('hl-wilaya').value.replace(/\D/g, ''), 10) || 0;
    var cid = parseInt($('hl-commune').value, 10) || 0;
    yfees = { home: null, desk: null, loaded: false };
    fillCenters();
    refresh();
    if (!wid || !cid) return;
    var my = ++reqC;
    $('hl-total').textContent = 'Calcul des frais…';
    getJSON('fee?wilaya=' + wid + '&commune=' + cid).then(function (f) {
      if (my !== reqC) return;
      yfees = { home: f.home, desk: f.desk, loaded: true };
      if (f.home === null && f.desk === null) showError('Livraison indisponible pour cette commune. Appelez-nous.');
      refresh();
    }).catch(function () {
      if (my !== reqC) return;
      yfees = { home: null, desk: null, loaded: true, unknown: true };
      refresh();
    });
  }

  $('hl-wilaya').addEventListener('change', onWilaya);
  if (C.yalidine) $('hl-commune').addEventListener('change', onCommune);
  form.addEventListener('change', function (e) {
    if (e.target.id !== 'hl-wilaya' && e.target.id !== 'hl-commune') refresh();
  });
  form.addEventListener('focusin', function () {
    if (initiated) return;
    initiated = true;
    track('InitiateCheckout', { content_ids: ids(), content_type: 'product', value: subtotal(), currency: 'DZD', num_items: count() });
  });
  form.querySelectorAll('[data-qty]').forEach(function (b) {
    b.addEventListener('click', function () {
      var before = qty;
      qty = Math.max(1, Math.min(5, qty + parseInt(b.getAttribute('data-qty'), 10)));
      refresh();
      if (C.hlv && qty !== before && $('hl-commune').value) loadOptions(true); // poids et seuil de gratuité
    });
  });

  function phoneOk(v) {
    var d = v.replace(/\D/g, '');
    if (d.indexOf('00213') === 0) d = d.slice(5);
    else if (d.indexOf('213') === 0 && d.length === 12) d = d.slice(3);
    if (d.length === 9) d = '0' + d;
    return /^0[567]\d{8}$/.test(d);
  }

  function showError(msg, field) {
    var e = $('hl-error');
    // Erreur technique du serveur (HTML, trace…) : message clair pour le client.
    if (!msg || /[<>{}]|critical|fatal|error/i.test(msg)) msg = 'Un problème technique empêche l\'envoi. Réessayez dans un instant ou appelez-nous.';
    e.textContent = msg;
    e.hidden = false;
    form.querySelectorAll('[aria-invalid]').forEach(function (x) { x.removeAttribute('aria-invalid'); });
    if (field) { field.setAttribute('aria-invalid', 'true'); field.focus(); }
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var name = $('hl-name'), phone = $('hl-phone'), wil = $('hl-wilaya'), city = $('hl-commune'), center = $('hl-center');
    if (name.value.trim().length < 3) return showError('Indiquez votre nom et prénom.', name);
    if (!phoneOk(phone.value)) return showError('Numéro invalide : 10 chiffres commençant par 05, 06 ou 07.', phone);
    if (!wil.value) return showError('Choisissez votre wilaya.', wil);
    if (C.yalidine ? !city.value : city.value.trim().length < 2) return showError('Indiquez votre commune.', city);
    if (C.yalidine && delivery() === 'desk' && center && !center.value) return showError(C.hlv ? 'Choisissez le bureau de retrait.' : 'Choisissez le bureau Yalidine.', center);
    $('hl-error').hidden = true;

    var btn = $('hl-submit');
    btn.disabled = true;
    btn.textContent = 'Envoi en cours…';

    fetch(C.endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name: name.value.trim(),
        phone: phone.value,
        state: wil.value,
        city: C.yalidine ? '' : city.value.trim(),
        commune_id: C.yalidine ? parseInt(city.value, 10) : 0,
        center_id: center && !C.hlv ? parseInt(center.value, 10) || 0 : 0,
        desk: center && C.hlv && delivery() === 'desk' ? center.value : '',
        delivery: delivery(),
        qty: qty,
        product_id: C.productId,
        items: cart() ? cart().map(function (i) { return { id: i.id, qty: i.qty }; }) : undefined,
        landing: C.landing,
        attribution: attribution(),
        website: $('hl-website').value,
        elapsed: Date.now() - start
      })
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.ok) throw new Error((res && res.message) || 'Erreur. Réessayez.');
        if (!res.duplicate) track('Purchase', { content_ids: ids(), content_type: 'product', value: res.total, currency: 'DZD', num_items: count() }, res.event_id);
        try { window.dispatchEvent(new CustomEvent('hted:ordered', { detail: res })); } catch (e) {}
        form.hidden = true;
        $('hl-success-name').textContent = name.value.trim().split(/\s+/)[0];
        $('hl-success-id').textContent = res.number;
        $('hl-success-phone').textContent = res.phone;
        var s = $('hl-success');
        s.hidden = false;
        s.focus();
        if ($('hl-sticky')) $('hl-sticky').classList.add('is-hidden');
      })
      .catch(function (err) {
        btn.disabled = false;
        btn.textContent = 'Confirmer ma commande';
        showError(err.message || 'Connexion impossible. Vérifiez votre réseau et réessayez.');
      });
  });

  // Barre « Commander » fixe sur mobile, masquée quand le formulaire est visible.
  var sticky = $('hl-sticky');
  if (sticky && $('commande') && 'IntersectionObserver' in window) {
    new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { sticky.classList.toggle('is-hidden', e.isIntersecting); });
    }, { threshold: 0.15 }).observe($('commande'));
  }

  // Page libre : tout ce qui ressemble à un achat (Commander, Ajouter au panier, liens panier,
  // formulaires de la page) mène au formulaire de commande. Une landing n'a pas de panier.
  if (host) {
    var BUY = /(commander|commandez|acheter|achetez|je commande|je veux|je suis intéress|j'en profite|profitez|order now|buy now|اطلب|اشتري|شراء)/i;
    var CART = /(ajouter au panier|ajout au panier|mettre au panier|ajouter à mon panier|add to cart|add to basket|أضف إلى السلة|اضف الى السلة|أضف للسلة)/i;
    var HASH = /^#(commande|commander|order|acheter|buy|checkout|form|formulaire|panier|cart)$/i;
    var CART_HREF = /(add-to-cart=|[\/?&](cart|panier|checkout|basket)\b)/i;
    var goToForm = function () {
      host.scrollIntoView({ behavior: 'smooth', block: 'start' });
      setTimeout(function () { var n = $('hl-name'); if (n) n.focus({ preventScroll: true }); }, 600);
    };
    // « Ajouter au panier » devient « Commander » dans les boutons et liens de la page.
    var relabel = function (el) {
      if (el.tagName === 'INPUT') { if (CART.test(el.value)) el.value = el.value.replace(CART, 'Commander'); return; }
      var w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null), n;
      while ((n = w.nextNode())) if (CART.test(n.nodeValue)) n.nodeValue = n.nodeValue.replace(CART, 'Commander');
    };
    Array.prototype.forEach.call(document.querySelectorAll('a,button,input[type=submit],input[type=button],[role=button]'), function (el) {
      if (!host.contains(el)) relabel(el);
    });
    document.addEventListener('click', function (e) {
      var el = e.target.closest ? e.target.closest('a,button,input[type=submit],input[type=button],[role=button],[data-hted-buy]') : null;
      if (!el || host.contains(el)) return;
      var href = el.tagName === 'A' ? (el.getAttribute('href') || '') : null;
      var label = el.tagName === 'INPUT' ? el.value : (el.textContent || '');
      var go = el.hasAttribute('data-hted-buy')
        || (href !== null && (HASH.test(href) || CART_HREF.test(href)))
        || CART.test(label)
        || ((el.tagName !== 'A' || href === '' || href === '#') && BUY.test(label))
        || (el.tagName !== 'A' && el.closest('form'));
      if (!go) return;
      e.preventDefault();
      e.stopImmediatePropagation();
      goToForm();
    }, true);
    // Tout formulaire de la page (hors le nôtre) : pas d'envoi, on va au formulaire de commande.
    document.addEventListener('submit', function (e) {
      if (host.contains(e.target)) return;
      e.preventDefault();
      e.stopImmediatePropagation();
      goToForm();
    }, true);
  }

  // La vitrine prévient quand le panier change : frais recalculés (poids, livraison offerte)
  window.HTED_LANDING_UPDATE = function () { if (C.hlv && $('hl-commune').value) loadOptions(true); else refresh(); };

  refresh();
})();
