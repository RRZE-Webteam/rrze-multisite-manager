"use strict";
(() => {
  // src/js/rrze-multisite-manager.js
  function getAdminConfig() {
    if (typeof window.rrzeMultisiteManagerAdmin === "undefined") {
      return null;
    }
    return window.rrzeMultisiteManagerAdmin;
  }
  function submitViewForm() {
    var form = document.querySelector(".rrze-msm-view-form");
    if (form) {
      form.submit();
    }
  }
  function initViewSelect() {
    var select = document.querySelector("#rrze-msm-view-select");
    if (!select) {
      return;
    }
    select.addEventListener("change", submitViewForm);
  }
  function moveWidget(widget, direction) {
    var sibling = null;
    var parent = null;
    if (!widget || !widget.parentNode) {
      return;
    }
    parent = widget.parentNode;
    if (direction === "up") {
      sibling = widget.previousElementSibling;
      if (sibling) {
        parent.insertBefore(widget, sibling);
      }
      return;
    }
    sibling = widget.nextElementSibling;
    if (sibling) {
      parent.insertBefore(sibling, widget);
    }
  }
  function updateWidgetMoveButtons(grid) {
    var widgets = [];
    var i = 0;
    var widget = null;
    var moveUp = null;
    var moveDown = null;
    if (!grid) {
      return;
    }
    widgets = grid.querySelectorAll(".rrze-msm-widget[data-widget-id]");
    for (i = 0; i < widgets.length; i++) {
      widget = widgets[i];
      moveUp = widget.querySelector(".rrze-msm-widget-move-up");
      moveDown = widget.querySelector(".rrze-msm-widget-move-down");
      if (moveUp) {
        moveUp.disabled = i === 0;
        moveUp.setAttribute("aria-disabled", i === 0 ? "true" : "false");
      }
      if (moveDown) {
        moveDown.disabled = i === widgets.length - 1;
        moveDown.setAttribute("aria-disabled", i === widgets.length - 1 ? "true" : "false");
      }
    }
  }
  function onWidgetMoveClick(event) {
    var button = event.currentTarget;
    var widget = button.closest(".rrze-msm-widget[data-widget-id]");
    var grid = document.querySelector(".rrze-msm-grid-primary");
    var direction = button.getAttribute("data-direction") || "";
    if (!widget || !grid) {
      return;
    }
    moveWidget(widget, direction);
    updateWidgetMoveButtons(grid);
    saveWidgetOrder(grid);
  }
  function initWidgetControls() {
    var buttons = document.querySelectorAll(".rrze-msm-widget-move");
    var i = 0;
    for (i = 0; i < buttons.length; i++) {
      buttons[i].addEventListener("click", onWidgetMoveClick);
    }
  }
  function getWidgetOrder(grid) {
    var widgets = grid.querySelectorAll(".rrze-msm-widget[data-widget-id]");
    var order = [];
    var i = 0;
    var widgetId = "";
    for (i = 0; i < widgets.length; i++) {
      widgetId = widgets[i].getAttribute("data-widget-id") || "";
      if (widgetId !== "") {
        order.push(widgetId);
      }
    }
    return order;
  }
  function getCookieName(view) {
    return "rrze_msm_widget_order_" + view;
  }
  function getColorModeCookieName() {
    return "rrze_msm_color_mode";
  }
  function setCookie(name, value, days) {
    var expires = "";
    var date = /* @__PURE__ */ new Date();
    date.setTime(date.getTime() + days * 24 * 60 * 60 * 1e3);
    expires = "; expires=" + date.toUTCString();
    document.cookie = name + "=" + encodeURIComponent(value) + expires + "; path=/; SameSite=Lax";
  }
  function getCookie(name) {
    var cookies = document.cookie ? document.cookie.split(";") : [];
    var i = 0;
    var cookie = "";
    var parts = [];
    var cookieName = "";
    for (i = 0; i < cookies.length; i++) {
      cookie = cookies[i].trim();
      parts = cookie.split("=");
      cookieName = parts.shift();
      if (cookieName === name) {
        return decodeURIComponent(parts.join("="));
      }
    }
    return "";
  }
  function saveWidgetOrder(grid) {
    var config = getAdminConfig();
    var view = "";
    var order = [];
    if (!config || !grid) {
      return;
    }
    view = grid.getAttribute("data-current-view") || config.currentView || "default";
    order = getWidgetOrder(grid);
    setCookie(getCookieName(view), JSON.stringify(order), 365);
  }
  function applySavedWidgetOrder(grid) {
    var config = getAdminConfig();
    var view = "";
    var cookieValue = "";
    var order = [];
    var i = 0;
    var widgetId = "";
    var widget = null;
    if (!config || !grid) {
      return;
    }
    view = grid.getAttribute("data-current-view") || config.currentView || "default";
    cookieValue = getCookie(getCookieName(view));
    if (cookieValue === "") {
      return;
    }
    try {
      order = JSON.parse(cookieValue);
    } catch (error) {
      return;
    }
    if (!Array.isArray(order)) {
      return;
    }
    for (i = 0; i < order.length; i++) {
      widgetId = String(order[i] || "");
      widget = grid.querySelector('.rrze-msm-widget[data-widget-id="' + widgetId + '"]');
      if (widget) {
        grid.appendChild(widget);
      }
    }
  }
  function initSortableWidgets() {
    var grid = document.querySelector(".rrze-msm-grid-primary");
    if (!grid) {
      return;
    }
    applySavedWidgetOrder(grid);
    updateWidgetMoveButtons(grid);
  }
  function getSiteTableRows(wrapper) {
    return Array.prototype.slice.call(wrapper.querySelectorAll("tbody tr"));
  }
  function getSiteTablePerPage(wrapper) {
    var select = wrapper.querySelector(".rrze-msm-site-table-per-page");
    var perPage = 0;
    if (!select) {
      return 10;
    }
    perPage = parseInt(select.value, 10);
    if (isNaN(perPage) || perPage < 1) {
      perPage = parseInt(wrapper.getAttribute("data-default-per-page") || "10", 10);
    }
    if (isNaN(perPage) || perPage < 1) {
      return 10;
    }
    return perPage;
  }
  function getSiteTableCurrentPage(wrapper) {
    var currentPage = parseInt(wrapper.getAttribute("data-current-page") || "1", 10);
    if (isNaN(currentPage) || currentPage < 1) {
      return 1;
    }
    return currentPage;
  }
  function getSiteTableSortKey(wrapper) {
    return wrapper.getAttribute("data-sort-key") || "name";
  }
  function getSiteTableSortDirection(wrapper) {
    var direction = wrapper.getAttribute("data-sort-direction") || "asc";
    if (direction !== "desc") {
      return "asc";
    }
    return "desc";
  }
  function getSiteTableSortType(key) {
    if (key === "registered" || key === "last-updated" || key === "storage") {
      return "number";
    }
    return "string";
  }
  function getSiteTableSortValue(row, key) {
    return row.getAttribute("data-sort-" + key) || "";
  }
  function sortSiteTableRows(wrapper, rows) {
    var sortKey = getSiteTableSortKey(wrapper);
    var sortDirection = getSiteTableSortDirection(wrapper);
    var sortType = getSiteTableSortType(sortKey);
    function compareRows(left, right) {
      var leftValue = getSiteTableSortValue(left, sortKey);
      var rightValue = getSiteTableSortValue(right, sortKey);
      var comparison = 0;
      if (sortType === "number") {
        comparison = parseInt(leftValue || "0", 10) - parseInt(rightValue || "0", 10);
      } else {
        comparison = String(leftValue).localeCompare(String(rightValue), "de", { sensitivity: "base" });
      }
      if (comparison === 0) {
        comparison = String(getSiteTableSortValue(left, "name")).localeCompare(String(getSiteTableSortValue(right, "name")), "de", { sensitivity: "base" });
      }
      return sortDirection === "desc" ? comparison * -1 : comparison;
    }
    rows.sort(compareRows);
  }
  function updateSiteTableSortButtons(wrapper) {
    var buttons = wrapper.querySelectorAll(".rrze-msm-site-table-sort");
    var sortKey = getSiteTableSortKey(wrapper);
    var sortDirection = getSiteTableSortDirection(wrapper);
    var i = 0;
    var button = null;
    var buttonKey = "";
    var indicator = null;
    for (i = 0; i < buttons.length; i++) {
      button = buttons[i];
      buttonKey = button.getAttribute("data-sort-key") || "";
      indicator = button.querySelector(".rrze-msm-site-table-sort-indicator");
      button.classList.remove("is-active", "is-asc", "is-desc");
      if (indicator) {
        indicator.textContent = "";
      }
      if (buttonKey === sortKey) {
        button.classList.add("is-active");
        button.classList.add(sortDirection === "desc" ? "is-desc" : "is-asc");
        if (indicator) {
          indicator.textContent = sortDirection === "desc" ? "\u25BC" : "\u25B2";
        }
      }
    }
  }
  function renderSiteTablePagination(wrapper, totalRows, perPage, currentPage) {
    var pagination = wrapper.querySelector(".rrze-msm-site-table-pagination");
    var totalPages = Math.max(1, Math.ceil(totalRows / perPage));
    var startItem = 0;
    var endItem = 0;
    var html = "";
    if (!pagination) {
      return;
    }
    if (totalRows === 0) {
      pagination.innerHTML = "";
      return;
    }
    startItem = (currentPage - 1) * perPage + 1;
    endItem = Math.min(totalRows, currentPage * perPage);
    html += '<span class="displaying-num">' + String(startItem) + "\u2013" + String(endItem) + " / " + String(totalRows) + "</span>";
    html += '<span class="pagination-links">';
    html += '<button type="button" class="first-page button rrze-msm-site-table-page" data-page="1"' + (currentPage === 1 ? ' disabled aria-disabled="true"' : "") + '><span aria-hidden="true">\xAB</span></button>';
    html += '<button type="button" class="prev-page button rrze-msm-site-table-page" data-page="' + String(Math.max(1, currentPage - 1)) + '"' + (currentPage === 1 ? ' disabled aria-disabled="true"' : "") + '><span aria-hidden="true">\u2039</span></button>';
    html += '<span class="paging-input"><span class="tablenav-paging-text">' + String(currentPage) + ' / <span class="total-pages">' + String(totalPages) + "</span></span></span>";
    html += '<button type="button" class="next-page button rrze-msm-site-table-page" data-page="' + String(Math.min(totalPages, currentPage + 1)) + '"' + (currentPage === totalPages ? ' disabled aria-disabled="true"' : "") + '><span aria-hidden="true">\u203A</span></button>';
    html += '<button type="button" class="last-page button rrze-msm-site-table-page" data-page="' + String(totalPages) + '"' + (currentPage === totalPages ? ' disabled aria-disabled="true"' : "") + '><span aria-hidden="true">\xBB</span></button>';
    html += "</span>";
    pagination.innerHTML = html;
  }
  function renderSiteTable(wrapper) {
    var rows = [];
    var perPage = 0;
    var currentPage = 0;
    var totalPages = 0;
    var startIndex = 0;
    var endIndex = 0;
    var i = 0;
    if (!wrapper) {
      return;
    }
    rows = getSiteTableRows(wrapper);
    perPage = getSiteTablePerPage(wrapper);
    currentPage = getSiteTableCurrentPage(wrapper);
    totalPages = Math.max(1, Math.ceil(rows.length / perPage));
    if (currentPage > totalPages) {
      currentPage = totalPages;
      wrapper.setAttribute("data-current-page", String(currentPage));
    }
    sortSiteTableRows(wrapper, rows);
    for (i = 0; i < rows.length; i++) {
      rows[i].parentNode.appendChild(rows[i]);
    }
    startIndex = (currentPage - 1) * perPage;
    endIndex = startIndex + perPage;
    for (i = 0; i < rows.length; i++) {
      rows[i].style.display = i >= startIndex && i < endIndex ? "" : "none";
    }
    updateSiteTableSortButtons(wrapper);
    renderSiteTablePagination(wrapper, rows.length, perPage, currentPage);
  }
  function onSiteTableClick(event) {
    var sortButton = event.target.closest(".rrze-msm-site-table-sort");
    var pageButton = event.target.closest(".rrze-msm-site-table-page");
    var wrapper = event.currentTarget;
    var currentSortKey = "";
    var nextDirection = "asc";
    if (sortButton) {
      currentSortKey = getSiteTableSortKey(wrapper);
      if ((sortButton.getAttribute("data-sort-key") || "") === currentSortKey) {
        nextDirection = getSiteTableSortDirection(wrapper) === "asc" ? "desc" : "asc";
      }
      wrapper.setAttribute("data-sort-key", sortButton.getAttribute("data-sort-key") || "name");
      wrapper.setAttribute("data-sort-direction", nextDirection);
      wrapper.setAttribute("data-current-page", "1");
      renderSiteTable(wrapper);
      return;
    }
    if (pageButton && !pageButton.disabled) {
      wrapper.setAttribute("data-current-page", pageButton.getAttribute("data-page") || "1");
      renderSiteTable(wrapper);
    }
  }
  function onSiteTablePerPageChange(event) {
    var wrapper = event.currentTarget.closest(".rrze-msm-site-table-wrap");
    if (!wrapper) {
      return;
    }
    wrapper.setAttribute("data-current-page", "1");
    renderSiteTable(wrapper);
  }
  function initSiteTables() {
    var wrappers = document.querySelectorAll(".rrze-msm-site-table-wrap");
    var i = 0;
    for (i = 0; i < wrappers.length; i++) {
      wrappers[i].addEventListener("click", onSiteTableClick);
      if (wrappers[i].querySelector(".rrze-msm-site-table-per-page")) {
        wrappers[i].querySelector(".rrze-msm-site-table-per-page").addEventListener("change", onSiteTablePerPageChange);
      }
      renderSiteTable(wrappers[i]);
    }
  }
  function setModeButtonState(button, mode, label) {
    var nextMode = mode === "dark" ? "light" : "dark";
    button.setAttribute("data-next-mode", nextMode);
    button.textContent = label;
  }
  function applyColorMode(mode) {
    var root = document.querySelector(".rrze-multisite-manager-admin");
    var body = document.body;
    if (!root) {
      return;
    }
    root.classList.remove("rrze-msm-mode-light", "rrze-msm-mode-dark");
    root.classList.add("rrze-msm-mode-" + mode);
    if (body) {
      body.classList.add("rrze-msm-admin");
      body.classList.remove("rrze-msm-mode-light", "rrze-msm-mode-dark");
      body.classList.add("rrze-msm-mode-" + mode);
    }
  }
  function onModeToggleClick(event) {
    var config = getAdminConfig();
    var button = event.currentTarget;
    var nextMode = button.getAttribute("data-next-mode") || "dark";
    var nextLabel = "";
    if (!config) {
      return;
    }
    nextLabel = nextMode === "dark" ? config.lightModeLabel : config.darkModeLabel;
    setCookie(getColorModeCookieName(), nextMode, 365);
    applyColorMode(nextMode);
    setModeButtonState(button, nextMode, nextLabel);
  }
  function initModeToggle() {
    var button = document.querySelector(".rrze-msm-mode-toggle");
    if (!button) {
      return;
    }
    button.addEventListener("click", onModeToggleClick);
  }
  function escapeHtml(value) {
    return String(value || "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
  }
  function getSiteSearchInput() {
    return document.querySelector("#rrze-msm-site-search");
  }
  function getSiteSearchResults() {
    return document.querySelector("#rrze-msm-site-search-results");
  }
  function clearSiteSearchResults() {
    var results = getSiteSearchResults();
    if (!results) {
      return;
    }
    results.innerHTML = "";
  }
  function renderSiteSearchResults(items) {
    var config = getAdminConfig();
    var results = getSiteSearchResults();
    var html = "";
    var i = 0;
    var item = null;
    var targetUrl = "";
    if (!results || !config) {
      return;
    }
    if (!Array.isArray(items) || items.length === 0) {
      results.innerHTML = '<p class="rrze-msm-site-search-empty">' + String(config.siteSearchNoResults || "") + "</p>";
      return;
    }
    html += '<ul class="rrze-msm-site-search-list">';
    for (i = 0; i < items.length; i++) {
      item = items[i];
      targetUrl = String(config.siteDetailsBaseUrl || "") + "&site_id=" + String(item.id || "");
      html += '<li class="rrze-msm-site-search-item">';
      html += '<a href="' + escapeHtml(targetUrl) + '">';
      html += "<strong>" + escapeHtml(item.name || "") + "</strong>";
      html += "<span>" + escapeHtml(item.url || "") + "</span>";
      html += "</a>";
      html += "</li>";
    }
    html += "</ul>";
    results.innerHTML = html;
  }
  function fetchSiteSearchResults(query) {
    var config = getAdminConfig();
    var url = "";
    if (!config) {
      return;
    }
    url = String(config.ajaxUrl || "") + "?action=rrze_msm_search_sites&nonce=" + encodeURIComponent(String(config.siteSearchNonce || "")) + "&q=" + encodeURIComponent(query);
    fetch(url, {
      credentials: "same-origin"
    }).then(function(response) {
      return response.json();
    }).then(function(payload) {
      if (!payload || payload.success !== true || !payload.data) {
        clearSiteSearchResults();
        return;
      }
      renderSiteSearchResults(payload.data.results || []);
    }).catch(clearSiteSearchResults);
  }
  function onSiteSearchInput() {
    var config = getAdminConfig();
    var input = getSiteSearchInput();
    var query = "";
    var minLength = 2;
    if (!config || !input) {
      return;
    }
    query = String(input.value || "").trim();
    minLength = parseInt(String(config.siteSearchMinLength || "2"), 10);
    if (isNaN(minLength) || minLength < 1) {
      minLength = 2;
    }
    if (query.length < minLength) {
      clearSiteSearchResults();
      return;
    }
    fetchSiteSearchResults(query);
  }
  function initSiteSearch() {
    var input = getSiteSearchInput();
    if (!input) {
      return;
    }
    input.addEventListener("input", onSiteSearchInput);
  }
  function initRrzeMultisiteManager() {
    var config = getAdminConfig();
    var savedMode = "";
    if (config) {
      savedMode = getCookie(getColorModeCookieName()) || config.currentMode;
      if (savedMode !== "") {
        applyColorMode(savedMode);
      }
    }
    initWidgetControls();
    initViewSelect();
    initSortableWidgets();
    initSiteTables();
    initModeToggle();
    initSiteSearch();
  }
  document.addEventListener("DOMContentLoaded", initRrzeMultisiteManager);
})();
//# sourceMappingURL=rrze-multisite-manager.js.map
