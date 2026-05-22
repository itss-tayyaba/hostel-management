/**
 * @file assets/js/ui.js
 * @brief Dashboard UI utilities loaded on every authenticated page (footer.php).
 * - HostelUI.toast(type, message) — non-blocking notifications
 * - HostelUI.showLoader / hideLoader — full-page spinner on form submit
 * - Reads #hostel-flash-data from PHP session flash (flash_alerts.php)
 */
(function (global) {
  "use strict";

  var toastRoot = null;

  function ensureToastRoot() {
    if (toastRoot) return toastRoot;
    toastRoot = document.getElementById("hostel-toast-root");
    if (!toastRoot) {
      toastRoot = document.createElement("div");
      toastRoot.id = "hostel-toast-root";
      toastRoot.setAttribute("aria-live", "polite");
      document.body.appendChild(toastRoot);
    }
    return toastRoot;
  }

  var icons = {
    success: "bi-check-circle-fill",
    danger: "bi-x-circle-fill",
    warning: "bi-exclamation-triangle-fill",
    info: "bi-info-circle-fill",
  };

  function showToast(message, type, durationMs) {
    type = type || "info";
    durationMs = durationMs == null ? 4500 : durationMs;
    message = message == null ? "" : String(message);
    if (!message) return;

    var root = ensureToastRoot();
    var el = document.createElement("div");
    el.className = "hostel-toast hostel-toast-" + type;
    el.setAttribute("role", "alert");

    var iconClass = icons[type] || icons.info;
    el.innerHTML =
      '<span class="hostel-toast-icon"><i class="bi ' + iconClass + '"></i></span>' +
      '<span class="hostel-toast-msg"></span>' +
      '<button type="button" class="hostel-toast-close" aria-label="Dismiss">&times;</button>';
    el.querySelector(".hostel-toast-msg").textContent = message;

    function dismiss() {
      el.classList.add("is-out");
      setTimeout(function () {
        if (el.parentNode) el.parentNode.removeChild(el);
      }, 260);
    }

    el.querySelector(".hostel-toast-close").addEventListener("click", dismiss);
    root.appendChild(el);

    if (durationMs > 0) {
      setTimeout(dismiss, durationMs);
    }
  }

  var loaderEl = null;
  function showPageLoader() {
    if (!loaderEl) {
      loaderEl = document.createElement("div");
      loaderEl.className = "hostel-page-loader";
      loaderEl.innerHTML = '<div class="hostel-spinner" role="status" aria-label="Loading"></div>';
      document.body.appendChild(loaderEl);
    }
    loaderEl.classList.add("is-active");
  }

  function hidePageLoader() {
    if (loaderEl) loaderEl.classList.remove("is-active");
  }

  function initFlashFromDom() {
    var data = document.getElementById("hostel-flash-data");
    if (!data) return;
    var type = data.getAttribute("data-type") || "info";
    var msg = data.getAttribute("data-message") || "";
    if (msg) showToast(msg, type);
    data.parentNode && data.parentNode.removeChild(data);
  }

  function initFormLoaders() {
    document.querySelectorAll("form[data-hostel-loader]").forEach(function (form) {
      form.addEventListener("submit", function () {
        showPageLoader();
      });
    });
  }

  document.addEventListener("DOMContentLoaded", function () {
    initFlashFromDom();
    initFormLoaders();
  });

  global.HostelUI = {
    toast: showToast,
    showLoader: showPageLoader,
    hideLoader: hidePageLoader,
  };
})(typeof window !== "undefined" ? window : this);
