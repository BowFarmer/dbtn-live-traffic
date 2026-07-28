/**
 * dbtn-visitor-validate.js
 */
(function () {
  "use strict";

  const STORAGE_KEY = "dbtn_ip_validated_until";
  const ASSERT_KEY  = "dbtn_last_assert";

  const TTL_MS = 7 * 24 * 60 * 60 * 1000;

  // Minimum gap between IP assertion calls: one minute.
  const ASSERT_THROTTLE_MS = 1 * 60 * 1000;

  // Delay before attempting first-time validation without an interaction.
  const VALIDATION_DELAY_MS = 3 * 1000;

  // ---------------------------------------------------------------------------
  // Auth-change reset — runs synchronously before anything else.
  // ---------------------------------------------------------------------------
  (function clearIfAuthChanged() {
    function getCookieValue(name) {
      const m = document.cookie.match(
        new RegExp(
          "(?:^|; )" +
          name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&") +
          "=([^;]*)",
        ),
      );

      return m ? decodeURIComponent(m[1]) : null;
    }

    const cookieMarker   = getCookieValue("dbtn_auth") || "0";
    const loggedInMarker = document.body.classList.contains("logged-in")
      ? "in"
      : "out";
    const currentMarker  = loggedInMarker + ":" + cookieMarker;
    const lastMarker     = sessionStorage.getItem("dbtn_auth_last");

    if (lastMarker !== null && lastMarker !== currentMarker) {
      localStorage.removeItem(STORAGE_KEY);
      sessionStorage.removeItem(ASSERT_KEY);
    }
  }());

  let validated =
    Number(localStorage.getItem(STORAGE_KEY) || 0) > Date.now();

  let lastAssertAt =
    Number(sessionStorage.getItem(ASSERT_KEY) || 0);

  function headers() {
    const h = {
      "Content-Type": "application/json",
    };

    if (dbtnValidate.nonce) {
      h["X-WP-Nonce"] = dbtnValidate.nonce;
    }

    return h;
  }

  function assertCurrentIp() {
    if (!dbtnValidate.assertUrl || !validated) return;

    const now = Date.now();

    // Prevent multiple calls when an event and the timer fire close together.
    if (now - lastAssertAt < ASSERT_THROTTLE_MS) return;

    lastAssertAt = now;
    sessionStorage.setItem(ASSERT_KEY, String(now));

    fetch(dbtnValidate.assertUrl, {
      method: "POST",
      credentials: "same-origin",
      keepalive: true,
      headers: headers(),
      body: "{}",
    }).catch(function () {});
  }

  // ---------------------------------------------------------------------------
  // Previously validated visitors
  // ---------------------------------------------------------------------------

  // Assert the current IP following relevant visitor or browser activity.
  ["scroll", "click", "touchstart", "keydown", "online", "pageshow"].forEach(
    function (eventName) {
      window.addEventListener(eventName, assertCurrentIp, {
        passive: true,
      });
    },
  );

  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "visible") {
      assertCurrentIp();
    }
  });

  // Assert the current IP every minute while the page remains open.
  setInterval(assertCurrentIp, ASSERT_THROTTLE_MS);

  // Initial assertion on page load.
  assertCurrentIp();

  // No Turnstile validation is needed if this visitor is already validated.
  if (validated) {
    return;
  }

  // ---------------------------------------------------------------------------
  // First-time validation
  //
  // Mint a Turnstile token following the first meaningful interaction or,
  // if there is no interaction, after the page has been open for five seconds.
  // ---------------------------------------------------------------------------

  const EVENTS = ["scroll", "click", "touchstart", "keydown"];

  let validationInFlight = false;
  let validationTimer;

  async function attemptValidation(event) {
    // Don't start validation from a purchase-button click.
    if (
      event?.type === "click" &&
      event.target?.closest?.(".dbtn-buy-button-wrap")
    ) {
      return;
    }

    // Prevent the timer and an interaction from validating simultaneously.
    if (validated || validationInFlight) return;

    validationInFlight = true;

    try {
      const passport =
        await window.dbtnEnsureTurnstilePassport?.();

      const token = passport?.token;

      if (!token) return;

      const response = await fetch(dbtnValidate.restUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: headers(),
        body: JSON.stringify({ token }),
      });

      if (!response.ok) return;

      const data = await response.json();

      if (data?.success) {
        const now = Date.now();

        validated = true;
        lastAssertAt = now;

        localStorage.setItem(
          STORAGE_KEY,
          String(now + TTL_MS),
        );

        sessionStorage.setItem(
          ASSERT_KEY,
          String(now),
        );

        // Validation succeeded, so these triggers are no longer needed.
        EVENTS.forEach(function (eventName) {
          window.removeEventListener(
            eventName,
            attemptValidation,
          );
        });

        clearTimeout(validationTimer);
      }
    } catch (_e) {
      // Keep the listeners active so a later interaction can retry.
    } finally {
      validationInFlight = false;
    }
  }

  // Attempt validation following the first meaningful interaction.
  EVENTS.forEach(function (eventName) {
    window.addEventListener(eventName, attemptValidation, {
      passive: true,
    });
  });

  // Also attempt validation if the page remains open without interaction.
  validationTimer = setTimeout(
    attemptValidation,
    VALIDATION_DELAY_MS,
  );
}());