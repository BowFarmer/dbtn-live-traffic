(function () {
  "use strict";

  const config = window.DBTNCredentialValidation;

  if (!config) {
    return;
  }

  function setResult(status, message, type) {
    status.textContent = message;
    status.classList.remove("is-success", "is-error");

    if (type) {
      status.classList.add(type);
    }
  }

  function setBusy(button, busy) {
    button.disabled = busy;
    button.setAttribute("aria-busy", busy ? "true" : "false");

    const spinner = button.nextElementSibling;
    if (spinner && spinner.classList.contains("spinner")) {
      spinner.classList.toggle("is-active", busy);
    }
  }

  async function post(url, body) {
    const response = await window.fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        "X-WP-Nonce": config.nonce,
      },
      body: JSON.stringify(body),
    });
    const data = await response.json().catch(function () {
      return {};
    });

    if (!response.ok) {
      throw new Error(data.message || config.strings.requestFailed);
    }

    return data;
  }

  function loadTurnstile() {
    if (window.turnstile) {
      return Promise.resolve(window.turnstile);
    }

    return new Promise(function (resolve, reject) {
      const existing = document.querySelector(
        'script[src*="challenges.cloudflare.com/turnstile/v0/api.js"]'
      );

      if (existing) {
        existing.addEventListener("load", function () {
          window.turnstile ? resolve(window.turnstile) : reject(new Error(config.strings.turnstileLoad));
        }, { once: true });
        existing.addEventListener("error", function () {
          reject(new Error(config.strings.turnstileLoad));
        }, { once: true });
        return;
      }

      const script = document.createElement("script");
      script.src = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
      script.async = true;
      script.defer = true;
      script.onload = function () {
        window.turnstile ? resolve(window.turnstile) : reject(new Error(config.strings.turnstileLoad));
      };
      script.onerror = function () {
        reject(new Error(config.strings.turnstileLoad));
      };
      document.head.appendChild(script);
    });
  }

  function createTurnstileToken(siteKey) {
    return loadTurnstile().then(function (turnstile) {
      return new Promise(function (resolve, reject) {
        const container = document.createElement("div");
        container.className = "dbtn-turnstile-validation-widget";
        document.body.appendChild(container);

        let widgetId = null;
        let settled = false;

        function finish(error, token) {
          if (settled) {
            return;
          }
          settled = true;
          window.clearTimeout(timeout);

          if (widgetId !== null && typeof turnstile.remove === "function") {
            turnstile.remove(widgetId);
          }
          container.remove();

          if (error) {
            reject(error);
          } else {
            resolve(token);
          }
        }

        const timeout = window.setTimeout(function () {
          finish(new Error(config.strings.turnstileToken));
        }, 20000);

        try {
          widgetId = turnstile.render(container, {
            sitekey: siteKey,
            appearance: "interaction-only",
            action: "dbtn_validate_credentials",
            callback: function (token) {
              finish(null, token);
            },
            "error-callback": function () {
              finish(new Error(config.strings.turnstileToken));
            },
            "expired-callback": function () {
              finish(new Error(config.strings.turnstileToken));
            },
          });
        } catch (error) {
          finish(new Error(config.strings.turnstileToken));
        }
      });
    });
  }

  function initializeTurnstileValidation() {
    const button = document.getElementById("dbtn-validate-turnstile");
    const status = document.getElementById("dbtn-turnstile-validation-status");
    const siteKey = document.getElementById("turnstile_site_key");
    const secretKey = document.getElementById("turnstile_secret_key");

    if (!button || !status || !siteKey || !secretKey) {
      return;
    }

    [siteKey, secretKey].forEach(function (field) {
      field.addEventListener("input", function () {
        setResult(status, "", "");
      });
    });

    button.addEventListener("click", async function () {
      const siteValue = siteKey.value.trim();
      const secretValue = secretKey.value.trim();

      if (!siteValue || !secretValue) {
        setResult(status, config.strings.turnstileEmpty, "is-error");
        return;
      }

      setBusy(button, true);
      setResult(status, config.strings.validating, "");

      try {
        const token = await createTurnstileToken(siteValue);
        const result = await post(config.turnstileUrl, {
          token: token,
          secret_key: secretValue,
        });
        setResult(status, result.message, "is-success");
      } catch (error) {
        setResult(status, error.message || config.strings.requestFailed, "is-error");
      } finally {
        setBusy(button, false);
      }
    });
  }

  function initializeMaxmindValidation() {
    const button = document.getElementById("dbtn-validate-maxmind");
    const status = document.getElementById("dbtn-maxmind-validation-status");
    const accountId = document.getElementById("maxmind_account_id");
    const licenseKey = document.getElementById("maxmind_license_key");

    if (!button || !status || !accountId || !licenseKey) {
      return;
    }

    [accountId, licenseKey].forEach(function (field) {
      field.addEventListener("input", function () {
        setResult(status, "", "");
      });
    });

    button.addEventListener("click", async function () {
      const accountValue = accountId.value.trim();
      const licenseValue = licenseKey.value.trim();

      if (!accountValue || !licenseValue) {
        setResult(status, config.strings.maxmindEmpty, "is-error");
        return;
      }

      setBusy(button, true);
      setResult(status, config.strings.validating, "");

      try {
        const result = await post(config.maxmindUrl, {
          account_id: accountValue,
          license_key: licenseValue,
        });
        setResult(status, result.message, "is-success");
      } catch (error) {
        setResult(status, error.message || config.strings.requestFailed, "is-error");
      } finally {
        setBusy(button, false);
      }
    });
  }

  function initializeHighlightRules() {
    const rules = document.getElementById("dbtn-highlight-rules");
    const template = document.getElementById("dbtn-highlight-rule-template");
    const addButton = document.getElementById("dbtn-add-highlight-rule");

    if (!rules || !template || !addButton) {
      return;
    }

    const body = rules.querySelector("tbody");

    function updateBackgroundControl(row) {
      const enabled = row.querySelector('input[name$="[use_background]"]');
      const color = row.querySelector('input[type="color"]');

      if (enabled && color) {
        color.disabled = !enabled.checked;
      }
    }

    function initializeRow(row) {
      updateBackgroundControl(row);
      row.addEventListener("change", function (event) {
        if (event.target.matches('input[name$="[use_background]"]')) {
          updateBackgroundControl(row);
        }
      });
      row.querySelector(".dbtn-remove-highlight-rule").addEventListener("click", function () {
        row.remove();
      });
      row.querySelector(".dbtn-move-highlight-rule-up").addEventListener("click", function () {
        if (row.previousElementSibling) {
          body.insertBefore(row, row.previousElementSibling);
        }
      });
      row.querySelector(".dbtn-move-highlight-rule-down").addEventListener("click", function () {
        if (row.nextElementSibling) {
          body.insertBefore(row.nextElementSibling, row);
        }
      });
    }

    rules.querySelectorAll(".dbtn-highlight-rule-row").forEach(initializeRow);

    addButton.addEventListener("click", function () {
      const index = Number.parseInt(rules.dataset.nextIndex || "0", 10);
      rules.dataset.nextIndex = String(index + 1);

      const wrapper = document.createElement("tbody");
      wrapper.innerHTML = template.innerHTML.replaceAll("__index__", String(index)).trim();
      const row = wrapper.firstElementChild;

      if (row) {
        body.appendChild(row);
        initializeRow(row);
        row.querySelector('input[type="text"]').focus();
      }
    });
  }

  document.addEventListener("DOMContentLoaded", function () {
    initializeTurnstileValidation();
    initializeMaxmindValidation();
    initializeHighlightRules();
  });
})();
