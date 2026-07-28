/**
 * dbtn-passport.js
 *
 * Loads the Cloudflare Turnstile widget in invisible mode and exposes
 * window.dbtnEnsureTurnstilePassport(), which returns a Promise that
 * resolves with { token } once a valid challenge token is available.
 *
 * Localised: dbtnPassport.siteKey
 */
(function () {
  "use strict";

  if (!window.dbtnPassport || !window.dbtnPassport.siteKey) {
    // Site key not configured — expose a no-op so dependent scripts don't crash.
    window.dbtnEnsureTurnstilePassport = function () {
      return Promise.resolve(null);
    };
    return;
  }

  const SITE_KEY = window.dbtnPassport.siteKey;

  let widgetId = null;
  let tokenResolvers = [];
  let currentToken = null;
  let widgetReady = false;

  function onToken(token) {
    currentToken = token;
    const resolvers = tokenResolvers.splice(0);
    resolvers.forEach(function (resolve) {
      resolve({ token: token });
    });
  }

  function onExpire() {
    currentToken = null;
    if (widgetId !== null && window.turnstile) {
      window.turnstile.reset(widgetId);
    }
  }

  function mountWidget() {
    if (widgetReady) return;
    widgetReady = true;

    const container = document.createElement("div");
    container.style.cssText = "display:none!important;position:absolute;left:-9999px;";
    document.body.appendChild(container);

    widgetId = window.turnstile.render(container, {
      sitekey: SITE_KEY,
      appearance: "interaction-only",
      callback: onToken,
      "expired-callback": onExpire,
      "error-callback": onExpire,
    });
  }

  function loadTurnstile() {
    return new Promise(function (resolve) {
      if (window.turnstile) {
        resolve();
        return;
      }

      const script = document.createElement("script");
      script.src =
        "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
      script.async = true;
      script.defer = true;
      script.onload = resolve;
      script.onerror = resolve; // Degrade gracefully.
      document.head.appendChild(script);
    });
  }

  /**
   * Public API: returns a Promise<{ token }> or Promise<null> on failure.
   */
  window.dbtnEnsureTurnstilePassport = function () {
    return loadTurnstile().then(function () {
      if (!window.turnstile) {
        return null;
      }

      mountWidget();

      if (currentToken) {
        return { token: currentToken };
      }

      return new Promise(function (resolve) {
        tokenResolvers.push(resolve);
        // Time out after 15 s to avoid hanging callers.
        setTimeout(function () {
          const idx = tokenResolvers.indexOf(resolve);
          if (idx !== -1) {
            tokenResolvers.splice(idx, 1);
            resolve(null);
          }
        }, 15000);
      });
    });
  };
})();
