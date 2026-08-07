/**
 * dbtn-visitor-validate.js
 *
 * Runs on every page and owns human-validation end to end. Nothing else on the
 * site needs to know it exists.
 *
 *  - First-time visitors: mints an invisible Turnstile token on first
 *    interaction and POSTs it to /validation/ip. The server marks the IP and
 *    sets the portable, HttpOnly "human grant" cookie.
 *
 *  - Already-validated visitors: re-marks whatever egress IP the request now
 *    arrives from by pinging /validation/assert (cookie-authenticated, and
 *    uncached, so it always reaches origin even when the page content is served
 *    from edge cache). The ping is driven by ordinary page activity — scroll,
 *    click, key, tab-return, network recovery — so a single human stays "green"
 *    as Private Relay or a moving network (WiFi <-> cellular) rotates their IP,
 *    including on long-lived REST-driven pages (store, archives, infinite-scroll
 *    home) that never reload. The store/archive scripts stay completely unaware.
 *
 * Waiting for interaction (not the load event) keeps Lighthouse from tripping
 * the Turnstile mint, so synthetic runs stay clean.
 *
 * Depends on: dbtn-passport.js  (window.dbtnEnsureTurnstilePassport)
 * Localised:  dbtnValidate.restUrl, dbtnValidate.assertUrl, dbtnValidate.nonce
 */
(function () {
  "use strict";

  const STORAGE_KEY = "dbtn_ip_validated_until";
  const ASSERT_KEY = "dbtn_last_assert";

  const TTL_MS = 7 * 24 * 60 * 60 * 1000;

  // Don't re-assert more than once per this window. A rotated IP shows
  // non-green in Live Traffic for at most this long — cosmetic — so we keep
  // request volume low. Drop to 60–90s for tighter tracking on mobile.
  const ASSERT_THROTTLE_MS = 3 * 60 * 1000;

  // Everything the hot path needs is held in memory, so the activity listeners
  // below can fire on every scroll tick without touching storage each time.
  let validated = Number(localStorage.getItem(STORAGE_KEY) || 0) > Date.now();
  let lastAssertAt = Number(sessionStorage.getItem(ASSERT_KEY) || 0);

  function headers() {
    const h = { "Content-Type": "application/json" };
    if (dbtnValidate.nonce) {
      h["X-WP-Nonce"] = dbtnValidate.nonce;
    }
    return h;
  }

  // Cookie-authenticated re-mark of the current egress IP. Self-gating and
  // cheap: an unvalidated visitor or a within-throttle call returns after two
  // in-memory comparisons — no storage, no network.
  function assertCurrentIp() {
    if (!dbtnValidate.assertUrl || !validated) return;

    const now = Date.now();
    if (now - lastAssertAt < ASSERT_THROTTLE_MS) return;
    lastAssertAt = now;
    sessionStorage.setItem(ASSERT_KEY, String(now));

    fetch(dbtnValidate.assertUrl, {
      method: "POST",
      credentials: "same-origin", // sends the HttpOnly dbtn_human cookie
      keepalive: true,
      headers: headers(),
      body: "{}",
    }).catch(function () {
      // Non-critical — never surface errors.
    });
  }

  // Re-mark off ordinary activity and off transitions that tend to accompany a
  // network change. These stay attached for the life of the page; this is what
  // covers long-lived REST-driven pages with zero help from other scripts.
  ["scroll", "click", "touchstart", "keydown", "online", "pageshow"].forEach(
    function (e) {
      window.addEventListener(e, assertCurrentIp, { passive: true });
    },
  );
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "visible") assertCurrentIp();
  });

  // Catch any IP drift since the last page load (throttle dedupes if we just
  // asserted on the previous page in this session).
  assertCurrentIp();

  // Already validated this window → no need to mint a fresh Turnstile token.
  if (validated) {
    return;
  }

  // ---- First-time validation: mint a Turnstile token on first interaction ----

  const EVENTS = ["scroll", "click", "touchstart", "keydown"];

  function onFirstInteraction(event) {
    // Don't respond if the first event is someone clicking the buy button —
    // that has its own Turnstile flow in the cart code.
    if (
      event?.type === "click" &&
      event.target?.closest?.(".dbtn-buy-button-wrap")
    ) {
      return;
    }
    EVENTS.forEach(function (e) {
      window.removeEventListener(e, onFirstInteraction);
    });

    (async function () {
      try {
        const passport = await window.dbtnEnsureTurnstilePassport?.();
        const token = passport?.token;
        if (!token) return; // Turnstile unavailable or challenged.

        const response = await fetch(dbtnValidate.restUrl, {
          method: "POST",
          credentials: "same-origin",
          headers: headers(),
          body: JSON.stringify({ token }),
        });
        if (response.ok) {
          const data = await response.json();
          if (data?.success) {
            // Server has set the grant cookie. Flip the in-memory flag so the
            // activity listeners start re-marking, and record the local window.
            const now = Date.now();
            validated = true;
            lastAssertAt = now;
            localStorage.setItem(STORAGE_KEY, String(now + TTL_MS));
            sessionStorage.setItem(ASSERT_KEY, String(now));
          }
        }
      } catch (_e) {
        // Validation is non-critical — never surface errors.
      }
    })();
  }

  EVENTS.forEach(function (e) {
    window.addEventListener(e, onFirstInteraction, { passive: true });
  });
})();
