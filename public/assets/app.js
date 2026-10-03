(function () {
  /*
  |--------------------------------------------------------------------------
  | General helpers
  |--------------------------------------------------------------------------
  */

  const uuid = (prefix = 'evt') =>
    `${prefix}_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;

  const money = value =>
    `৳${Number(value || 0).toFixed(2)}`;

  /*
  |--------------------------------------------------------------------------
  | Cookie helpers
  |--------------------------------------------------------------------------
  */

  function getCookie(name) {
    const prefix = `${name}=`;
    const cookies = document.cookie ? document.cookie.split(';') : [];

    for (const item of cookies) {
      const cookie = item.trim();

      if (cookie.indexOf(prefix) === 0) {
        try {
          return decodeURIComponent(
            cookie.substring(prefix.length)
          );
        } catch (e) {
          return cookie.substring(prefix.length);
        }
      }
    }

    return null;
  }

  function setCookie(name, value, maxAgeSeconds) {
    if (!name || !value) {
      return;
    }

    let cookie =
      `${name}=${encodeURIComponent(value)}` +
      `; Max-Age=${maxAgeSeconds}` +
      '; Path=/' +
      '; SameSite=Lax';

    if (window.location.protocol === 'https:') {
      cookie += '; Secure';
    }

    document.cookie = cookie;
  }

  /*
  |--------------------------------------------------------------------------
  | Meta identifiers
  |--------------------------------------------------------------------------
  */

  function currentFbclid() {
    try {
      const params = new URLSearchParams(window.location.search);
      const fbclid = params.get('fbclid');

      if (!fbclid) {
        return null;
      }

      const clean = String(fbclid).trim();

      if (!clean || clean.length > 1000) {
        return null;
      }

      /*
       * Do not lowercase fbclid.
       * Meta Click IDs are case-sensitive.
       */
      return clean;
    } catch (e) {
      return null;
    }
  }

  function ensureFbc() {
    /*
     * Existing _fbc should always be preserved.
     */
    const existing = getCookie('_fbc');

    if (existing) {
      return existing;
    }

    /*
     * If visitor entered through a Facebook/Instagram ad,
     * fbclid should exist in the landing URL.
     */
    const fbclid = currentFbclid();

    if (!fbclid) {
      return null;
    }

    const fbc =
      `fb.1.${Date.now()}.${fbclid}`;

    /*
     * Keep for 90 days.
     */
    setCookie(
      '_fbc',
      fbc,
      90 * 24 * 60 * 60
    );

    return fbc;
  }

  function metaIdentifiers() {
    const fbc = ensureFbc();

    /*
     * Meta Pixel normally creates _fbp itself.
     * We do not replace or rewrite Meta's existing value.
     */
    const fbp = getCookie('_fbp');

    return {
      fbc: fbc || null,
      fbp: fbp || null,
      fbclid: currentFbclid()
    };
  }

  /*
   * Capture fbclid immediately when this JS executes.
   */
  ensureFbc();

  /*
  |--------------------------------------------------------------------------
  | Meta event helpers
  |--------------------------------------------------------------------------
  */

  window.metaEventId = uuid;

  window.trackMeta = function (
    eventName,
    customData = {},
    forcedId = null
  ) {
    const eventId =
      forcedId ||
      uuid(eventName.toLowerCase());

    if (!window.shopMeta?.enabled) {
      return eventId;
    }

    /*
     * Re-check identifiers immediately before every event.
     */
    const identifiers = metaIdentifiers();

    /*
     * Browser Pixel event.
     */
    try {
      if (typeof fbq === 'function') {
        fbq(
          'track',
          eventName,
          customData,
          {
            eventID: eventId
          }
        );
      }
    } catch (e) {
      // Tracking must never break storefront functionality.
    }

    /*
     * Server/CAPI copy.
     */
    try {
      fetch(
        window.shopMeta.endpoint,
        {
          method: 'POST',

          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': window.shopMeta.csrf,
            'Accept': 'application/json'
          },

          credentials: 'same-origin',

          body: JSON.stringify({
            event_name: eventName,
            event_id: eventId,
            source_url: location.href,
            custom_data: customData,

            /*
             * Meta identifiers are also included explicitly.
             *
             * The server can still read them directly from
             * first-party cookies.
             */
            fbc: identifiers.fbc,
            fbp: identifiers.fbp,
            fbclid: identifiers.fbclid
          }),

          keepalive: true
        }
      );
    } catch (e) {
      // Ignore tracking network errors.
    }

    return eventId;
  };

  /*
  |--------------------------------------------------------------------------
  | Cart badges
  |--------------------------------------------------------------------------
  */

  function updateCartBadges(count) {
    document
      .querySelectorAll('a[href*="/cart"] .badge')
      .forEach(badge => {
        badge.textContent = String(count);
      });
  }

  /*
  |--------------------------------------------------------------------------
  | Quick checkout modal
  |--------------------------------------------------------------------------
  */

  function modalElements() {
    const modal =
      document.getElementById('quickCheckoutModal');

    if (!modal) {
      return {};
    }

    return {
      modal,

      content:
        modal.querySelector(
          '[data-quick-checkout-content]'
        ),

      loading:
        modal.querySelector(
          '[data-quick-checkout-loading]'
        )
    };
  }

  function closeQuickCheckout() {
    const { modal } = modalElements();

    if (!modal) {
      return;
    }

    modal.classList.remove('open');

    modal.setAttribute(
      'aria-hidden',
      'true'
    );

    document.body.classList.remove(
      'quick-checkout-open'
    );
  }

  function showCheckoutErrors(
    form,
    errors
  ) {
    const box =
      form
        .closest('.quick-checkout-sheet-inner')
        ?.querySelector('[data-checkout-errors]');

    if (!box) {
      return;
    }

    const messages = [];

    Object
      .values(errors || {})
      .forEach(value => {
        if (Array.isArray(value)) {
          messages.push(...value);
        } else if (value) {
          messages.push(value);
        }
      });

    if (!messages.length) {
      box.hidden = true;
      box.innerHTML = '';
      return;
    }

    box.hidden = false;

    box.innerHTML =
      `<b>অনুগ্রহ করে ঠিক করুন:</b>` +
      `<ul>` +
      messages
        .map(
          message =>
            `<li>${String(message)}</li>`
        )
        .join('') +
      `</ul>`;

    box.scrollIntoView({
      behavior: 'smooth',
      block: 'nearest'
    });
  }

  /*
  |--------------------------------------------------------------------------
  | Add Meta identifiers to checkout form
  |--------------------------------------------------------------------------
  */

  function applyMetaIdentifiersToForm(form) {
    if (!form) {
      return;
    }

    const ids = metaIdentifiers();

    const values = {
      fbc: ids.fbc,
      fbp: ids.fbp,
      fbclid: ids.fbclid
    };

    Object.entries(values).forEach(
      ([name, value]) => {
        if (!value) {
          return;
        }

        let input =
          form.querySelector(
            `input[name="${name}"]`
          );

        if (!input) {
          input =
            document.createElement('input');

          input.type = 'hidden';
          input.name = name;

          form.appendChild(input);
        }

        input.value = value;
      }
    );
  }

  /*
  |--------------------------------------------------------------------------
  | Quick checkout form
  |--------------------------------------------------------------------------
  */

  function initQuickCheckoutForm() {
    const { modal } = modalElements();

    if (!modal) {
      return;
    }

    const form =
      modal.querySelector(
        '[data-quick-checkout-form]'
      );

    if (!form) {
      return;
    }

    applyMetaIdentifiersToForm(form);

    const subtotal =
      Number(form.dataset.subtotal || 0);

    const refreshTotals = () => {
      const selected =
        form.querySelector(
          '[name="shipping_area"]:checked'
        );

      const charge =
        Number(
          selected?.dataset.charge || 0
        );

      const shipping =
        form.querySelector(
          '[data-shipping-amount]'
        );

      const grand =
        form.querySelector(
          '[data-grand-total]'
        );

      const button =
        form.querySelector(
          '[data-button-total]'
        );

      if (shipping) {
        shipping.textContent =
          money(charge);
      }

      if (grand) {
        grand.textContent =
          money(subtotal + charge);
      }

      if (button) {
        button.textContent =
          String(
            Math.round(
              subtotal + charge
            )
          );
      }
    };

    form
      .querySelectorAll(
        '[name="shipping_area"]'
      )
      .forEach(radio =>
        radio.addEventListener(
          'change',
          refreshTotals
        )
      );

    refreshTotals();

    form.addEventListener(
      'submit',
      async event => {
        event.preventDefault();

        if (!form.reportValidity()) {
          return;
        }

        /*
         * Refresh identifiers immediately before Purchase.
         */
        applyMetaIdentifiersToForm(form);

        const submit =
          form.querySelector(
            'button[type="submit"]'
          );

        const original =
          submit?.innerHTML;

        if (submit) {
          submit.disabled = true;

          submit.textContent =
            'অর্ডার পাঠানো হচ্ছে...';
        }

        showCheckoutErrors(
          form,
          {}
        );

        /*
         * This same ID is later used by:
         *
         * Browser Purchase
         * +
         * Server Purchase
         *
         * for Meta deduplication.
         */
        const eventId =
          window.metaEventId?.(
            'purchase'
          ) ||
          `purchase_${Date.now()}`;

        const eventInput =
          form.querySelector(
            '[name="event_id"]'
          );

        if (eventInput) {
          eventInput.value =
            eventId;
        }

        try {
          const response =
            await fetch(
              form.action,
              {
                method: 'POST',

                headers: {
                  'Accept':
                    'application/json',

                  'X-Requested-With':
                    'XMLHttpRequest'
                },

                credentials:
                  'same-origin',

                body:
                  new FormData(form)
              }
            );

          const data =
            await response
              .json()
              .catch(
                () => ({})
              );

          if (!response.ok) {
            showCheckoutErrors(
              form,
              data.errors || {
                checkout:
                  data.message ||
                  'অর্ডার সম্পন্ন করা যায়নি।'
              }
            );

            return;
          }

          updateCartBadges(0);

          window.location.href =
            data.redirect || '/';

        } catch (error) {

          showCheckoutErrors(
            form,
            {
              checkout:
                'নেটওয়ার্ক সমস্যার কারণে অর্ডার সম্পন্ন করা যায়নি। আবার চেষ্টা করুন।'
            }
          );

        } finally {

          if (submit) {
            submit.disabled = false;
            submit.innerHTML = original;
          }
        }
      }
    );
  }

  /*
  |--------------------------------------------------------------------------
  | Open quick checkout
  |--------------------------------------------------------------------------
  */

  async function openQuickCheckout() {
    const {
      modal,
      content,
      loading
    } = modalElements();

    if (
      !modal ||
      !content ||
      !window.storeRoutes?.quickCheckout
    ) {
      return;
    }

    modal.classList.add('open');

    modal.setAttribute(
      'aria-hidden',
      'false'
    );

    document.body.classList.add(
      'quick-checkout-open'
    );

    content.innerHTML = '';

    if (loading) {
      loading.hidden = false;
    }

    try {
      const response =
        await fetch(
          window.storeRoutes.quickCheckout,
          {
            headers: {
              'Accept': 'text/html',
              'X-Requested-With':
                'XMLHttpRequest'
            },

            credentials:
              'same-origin'
          }
        );

      if (!response.ok) {
        throw new Error(
          'checkout load failed'
        );
      }

      content.innerHTML =
        await response.text();

      if (loading) {
        loading.hidden = true;
      }

      initQuickCheckoutForm();

      const form =
        content.querySelector(
          '[data-quick-checkout-form]'
        );

      const subtotal =
        Number(
          form?.dataset.subtotal || 0
        );

      window.trackMeta?.(
        'InitiateCheckout',
        {
          currency: 'BDT',
          value: subtotal,
          content_type: 'product'
        }
      );

      setTimeout(
        () =>
          content
            .querySelector(
              'input[name="customer_name"]'
            )
            ?.focus(),
        100
      );

    } catch (error) {

      if (loading) {
        loading.hidden = false;

        loading.innerHTML =
          'চেকআউট লোড করা যায়নি। ' +
          '<button type="button" ' +
          'class="btn small" ' +
          'data-retry-checkout>' +
          'আবার চেষ্টা করুন' +
          '</button>';

        loading
          .querySelector(
            '[data-retry-checkout]'
          )
          ?.addEventListener(
            'click',
            openQuickCheckout,
            {
              once: true
            }
          );
      }
    }
  }

  /*
  |--------------------------------------------------------------------------
  | Quick order
  |--------------------------------------------------------------------------
  */

  async function submitQuickOrder(
    form
  ) {
    if (
      form.dataset.quickSubmitting ===
      '1'
    ) {
      return;
    }

    form.dataset.quickSubmitting =
      '1';

    const button =
      form.querySelector(
        'button[type="submit"],button:not([type])'
      );

    const original =
      button?.innerHTML;

    if (button) {
      button.disabled = true;
      button.textContent =
        'যোগ হচ্ছে...';
    }

    try {
      const response =
        await fetch(
          form.action,
          {
            method:
              (
                form.method ||
                'POST'
              ).toUpperCase(),

            headers: {
              'Accept':
                'application/json',

              'X-Requested-With':
                'XMLHttpRequest'
            },

            credentials:
              'same-origin',

            body:
              new FormData(form)
          }
        );

      const data =
        await response
          .json()
          .catch(
            () => ({})
          );

      if (!response.ok) {
        throw new Error(
          data.message ||
          'cart add failed'
        );
      }

      updateCartBadges(
        data.cart_count || 0
      );

      const value =
        Number(
          form.dataset.value || 0
        );

      const id =
        form.dataset.contentId;

      const qty =
        Number(
          form.querySelector(
            '[name="quantity"]'
          )?.value || 1
        );

      window.trackMeta?.(
        'AddToCart',
        {
          content_ids:
            id ? [id] : [],

          content_type:
            'product',

          currency:
            'BDT',

          value:
            value * qty,

          contents:
            id
              ? [
                  {
                    id,
                    quantity: qty,
                    item_price: value
                  }
                ]
              : []
        }
      );

      await openQuickCheckout();

    } catch (error) {

      const existing =
        document.querySelector(
          '.quick-order-error'
        );

      existing?.remove();

      const notice =
        document.createElement(
          'div'
        );

      notice.className =
        'quick-order-error';

      notice.textContent =
        'পণ্যটি কার্টে যোগ করা বা চেকআউট খোলা যায়নি। পেজ রিফ্রেশ করে আবার চেষ্টা করুন।';

      document.body.appendChild(
        notice
      );

      setTimeout(
        () => notice.remove(),
        4500
      );

    } finally {

      form.dataset.quickSubmitting =
        '0';

      if (button) {
        button.disabled = false;
        button.innerHTML = original;
      }
    }
  }

  function initQuickOrderForms() {
    document
      .querySelectorAll(
        '.quick-order-form'
      )
      .forEach(form => {
        form.addEventListener(
          'submit',
          event => {
            event.preventDefault();

            submitQuickOrder(
              form
            );
          }
        );
      });
  }

  /*
  |--------------------------------------------------------------------------
  | Modal close
  |--------------------------------------------------------------------------
  */

  function initQuickCheckoutClose() {
    document.addEventListener(
      'click',
      event => {
        if (
          event.target.closest(
            '[data-quick-checkout-close]'
          )
        ) {
          closeQuickCheckout();
        }

        const sticky =
          event.target.closest(
            '[data-submit-order-form]'
          );

        if (sticky) {
          const form =
            document.getElementById(
              sticky.dataset
                .submitOrderForm
            );

          form?.requestSubmit();
        }
      }
    );

    document.addEventListener(
      'keydown',
      event => {
        if (
          event.key ===
          'Escape'
        ) {
          closeQuickCheckout();
        }
      }
    );
  }

  /*
  |--------------------------------------------------------------------------
  | Mobile sticky order
  |--------------------------------------------------------------------------
  */

  function initMobileStickyOrder() {
    const sticky =
      document.getElementById(
        'mobileStickyOrder'
      );

    const original =
      document.querySelector(
        '.product-order-bar'
      );

    if (
      !sticky ||
      !original
    ) {
      return;
    }

    let ticking = false;

    const update = () => {
      ticking = false;

      if (
        window.innerWidth >
        720
      ) {
        sticky.classList.remove(
          'visible'
        );

        sticky.setAttribute(
          'aria-hidden',
          'true'
        );

        return;
      }

      const rect =
        original.getBoundingClientRect();

      const show =
        rect.bottom < 0;

      sticky.classList.toggle(
        'visible',
        show
      );

      sticky.setAttribute(
        'aria-hidden',
        show
          ? 'false'
          : 'true'
      );
    };

    const requestUpdate = () => {
      if (ticking) {
        return;
      }

      ticking = true;

      requestAnimationFrame(
        update
      );
    };

    window.addEventListener(
      'scroll',
      requestUpdate,
      {
        passive: true
      }
    );

    window.addEventListener(
      'resize',
      requestUpdate
    );

    update();
  }

  /*
  |--------------------------------------------------------------------------
  | Initial page load
  |--------------------------------------------------------------------------
  */

  document.addEventListener(
    'DOMContentLoaded',
    () => {
      /*
       * Ensure fbc is captured before first CAPI event.
       */
      ensureFbc();

      /*
       * Meta Pixel may create _fbp asynchronously.
       *
       * We do not create our own conflicting value.
       */
      window.trackMeta(
        'PageView',
        {}
      );

      initQuickOrderForms();

      initQuickCheckoutClose();

      initMobileStickyOrder();

      /*
       * If this is the normal full checkout page,
       * add Meta identifiers to that form as well.
       */
      const checkoutForm =
        document.getElementById(
          'checkoutForm'
        );

      if (checkoutForm) {
        applyMetaIdentifiersToForm(
          checkoutForm
        );

        checkoutForm.addEventListener(
          'submit',
          () => {
            applyMetaIdentifiersToForm(
              checkoutForm
            );
          }
        );
      }
    }
  );

  /*
  |--------------------------------------------------------------------------
  | Quantity control
  |--------------------------------------------------------------------------
  */

  window.stepQty = function (
    btn,
    delta
  ) {
    const input =
      btn.parentElement
        .querySelector(
          'input'
        );

    let value =
      parseInt(
        input.value || '1',
        10
      ) + delta;

    input.value =
      Math.max(
        1,
        Math.min(
          99,
          value
        )
      );
  };
})();