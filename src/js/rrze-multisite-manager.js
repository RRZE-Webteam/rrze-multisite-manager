'use strict';

var rrzeMsmSiteSearchTimer = 0;
var rrzeMsmPluginSearchTimer = 0;
var rrzeMsmThemeSearchTimer = 0;
var rrzeMsmStorageAnalysisTimer = 0;
var rrzeMsmSearchDelay = 250;

function getAdminConfig() {
    if (typeof window.rrzeMultisiteManagerAdmin === 'undefined') {
        return null;
    }

    return window.rrzeMultisiteManagerAdmin;
}

function submitViewForm() {
    var form = document.querySelector('.rrze-msm-view-form');

    if (form) {
        form.submit();
    }
}

function initViewSelect() {
    var select = document.querySelector('#rrze-msm-view-select');

    if (!select) {
        return;
    }

    select.addEventListener('change', submitViewForm);
}

function closeDeleteCptModal() {
    var modal = document.querySelector('#rrze-msm-delete-cpt-modal');

    if (!modal) {
        return;
    }

    modal.setAttribute('hidden', 'hidden');
}

function updateDeleteCptSubmitState() {
    var checkbox = document.querySelector('#rrze-msm-delete-cpt-confirm');
    var submit = document.querySelector('#rrze-msm-delete-cpt-submit');

    if (!checkbox || !submit) {
        return;
    }

    submit.disabled = !checkbox.checked;
}

function closePluginDeactivateModal() {
    var modal = document.querySelector('#rrze-msm-plugin-deactivate-modal');

    if (!modal) {
        return;
    }

    modal.setAttribute('hidden', 'hidden');
}

function updatePluginDeactivateSubmitState() {
    var checkbox = document.querySelector('#rrze-msm-plugin-deactivate-confirm');
    var submit = document.querySelector('#rrze-msm-plugin-deactivate-submit');

    if (!checkbox || !submit) {
        return;
    }

    if (checkbox.checked) {
        submit.classList.remove('disabled');
        submit.removeAttribute('aria-disabled');
        return;
    }

    submit.classList.add('disabled');
    submit.setAttribute('aria-disabled', 'true');
}

function closeSiteDeleteModal() {
    var modal = document.querySelector('#rrze-msm-site-delete-modal');

    if (!modal) {
        return;
    }

    modal.setAttribute('hidden', 'hidden');
}

function updateSiteDeleteSubmitState() {
    var checkbox = document.querySelector('#rrze-msm-site-delete-confirm');
    var submit = document.querySelector('#rrze-msm-site-delete-submit');

    if (!checkbox || !submit) {
        return;
    }

    if (checkbox.checked) {
        submit.classList.remove('disabled');
        submit.removeAttribute('aria-disabled');
        return;
    }

    submit.classList.add('disabled');
    submit.setAttribute('aria-disabled', 'true');
}

function closeOrphanFileDeleteModal() {
    var modal = document.querySelector('#rrze-msm-orphan-file-delete-modal');

    if (!modal) {
        return;
    }

    modal.setAttribute('hidden', 'hidden');
}

function updateOrphanFileDeleteSubmitState() {
    var checkbox = document.querySelector('#rrze-msm-orphan-file-delete-confirm');
    var submit = document.querySelector('#rrze-msm-orphan-file-delete-submit');

    if (!checkbox || !submit) {
        return;
    }

    submit.disabled = !checkbox.checked;
}

function onDeleteCptButtonClick(event) {
    var button = event.currentTarget;
    var modal = document.querySelector('#rrze-msm-delete-cpt-modal');
    var target = document.querySelector('#rrze-msm-delete-cpt-target');
    var input = document.querySelector('#rrze-msm-delete-cpt-input');
    var checkbox = document.querySelector('#rrze-msm-delete-cpt-confirm');

    if (!button || !modal || !target || !input || !checkbox) {
        return;
    }

    target.textContent = button.getAttribute('data-post-type-label') || button.getAttribute('data-post-type') || '';
    input.value = button.getAttribute('data-post-type') || '';
    checkbox.checked = false;
    updateDeleteCptSubmitState();
    modal.removeAttribute('hidden');
}

function onPluginDeactivateButtonClick(event) {
    var button = event.currentTarget;
    var modal = document.querySelector('#rrze-msm-plugin-deactivate-modal');
    var target = document.querySelector('#rrze-msm-plugin-deactivate-target');
    var submit = document.querySelector('#rrze-msm-plugin-deactivate-submit');
    var checkbox = document.querySelector('#rrze-msm-plugin-deactivate-confirm');

    if (!button || !modal || !target || !submit || !checkbox) {
        return;
    }

    target.textContent = button.getAttribute('data-plugin-name') || '';
    submit.setAttribute('href', button.getAttribute('data-deactivate-url') || '#');
    checkbox.checked = false;
    updatePluginDeactivateSubmitState();
    modal.removeAttribute('hidden');
}

function onSiteDeleteButtonClick(event) {
    var button = event.currentTarget;
    var modal = document.querySelector('#rrze-msm-site-delete-modal');
    var target = document.querySelector('#rrze-msm-site-delete-target');
    var submit = document.querySelector('#rrze-msm-site-delete-submit');
    var checkbox = document.querySelector('#rrze-msm-site-delete-confirm');

    if (!button || !modal || !target || !submit || !checkbox) {
        return;
    }

    target.textContent = button.getAttribute('data-site-name') || '';
    submit.setAttribute('href', button.getAttribute('data-delete-url') || '#');
    checkbox.checked = false;
    updateSiteDeleteSubmitState();
    modal.removeAttribute('hidden');
}

function onOrphanFileDeleteButtonClick(event) {
    var button = event.currentTarget;
    var modal = document.querySelector('#rrze-msm-orphan-file-delete-modal');
    var target = document.querySelector('#rrze-msm-orphan-file-delete-target');
    var pathInput = document.querySelector('#rrze-msm-orphan-file-delete-path');
    var pathsContainer = document.querySelector('#rrze-msm-orphan-file-delete-paths');
    var siteInput = document.querySelector('#rrze-msm-orphan-file-delete-site-id');
    var nonceInput = document.querySelector('#rrze-msm-orphan-file-delete-nonce');
    var checkbox = document.querySelector('#rrze-msm-orphan-file-delete-confirm');

    if (!button || !modal || !target || !pathInput || !pathsContainer || !siteInput || !nonceInput || !checkbox) {
        return;
    }

    target.textContent = button.getAttribute('data-file-path') || '';
    pathInput.value = button.getAttribute('data-file-path') || '';
    pathsContainer.innerHTML = '';
    siteInput.value = button.getAttribute('data-site-id') || '';
    nonceInput.value = button.getAttribute('data-delete-nonce') || '';
    checkbox.checked = false;
    updateOrphanFileDeleteSubmitState();
    modal.removeAttribute('hidden');
}

function onOrphanFileBulkDeleteButtonClick(event) {
    var button = event.currentTarget;
    var modal = document.querySelector('#rrze-msm-orphan-file-delete-modal');
    var target = document.querySelector('#rrze-msm-orphan-file-delete-target');
    var pathInput = document.querySelector('#rrze-msm-orphan-file-delete-path');
    var pathsContainer = document.querySelector('#rrze-msm-orphan-file-delete-paths');
    var siteInput = document.querySelector('#rrze-msm-orphan-file-delete-site-id');
    var nonceInput = document.querySelector('#rrze-msm-orphan-file-delete-nonce');
    var checkbox = document.querySelector('#rrze-msm-orphan-file-delete-confirm');
    var form = null;
    var checkboxes = [];
    var i = 0;
    var hiddenInput = null;

    if (!button || !modal || !target || !pathInput || !pathsContainer || !siteInput || !nonceInput || !checkbox) {
        return;
    }

    form = button.closest('form');

    if (!form) {
        return;
    }

    checkboxes = form.querySelectorAll('input[name="relative_paths[]"]:checked');

    if (!checkboxes.length) {
        window.alert('Bitte zuerst mindestens eine Datei auswählen.');
        return;
    }

    target.textContent = checkboxes.length === 1
        ? (checkboxes[0].value || '')
        : (checkboxes.length + ' Dateien');
    pathInput.value = '';
    pathsContainer.innerHTML = '';

    for (i = 0; i < checkboxes.length; i++) {
        hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'relative_paths[]';
        hiddenInput.value = checkboxes[i].value || '';
        pathsContainer.appendChild(hiddenInput);
    }

    siteInput.value = button.getAttribute('data-site-id') || '';
    nonceInput.value = button.getAttribute('data-delete-nonce') || '';
    checkbox.checked = false;
    updateOrphanFileDeleteSubmitState();
    modal.removeAttribute('hidden');
}

function onCloseDeleteCptModalClick(event) {
    event.preventDefault();
    closeDeleteCptModal();
}

function onClosePluginDeactivateModalClick(event) {
    event.preventDefault();
    closePluginDeactivateModal();
}

function onPluginDeactivateSubmitClick(event) {
    var submit = event.currentTarget;

    if (!submit || submit.getAttribute('aria-disabled') === 'true') {
        event.preventDefault();
    }
}

function onCloseSiteDeleteModalClick(event) {
    event.preventDefault();
    closeSiteDeleteModal();
}

function onCloseOrphanFileDeleteModalClick(event) {
    event.preventDefault();
    closeOrphanFileDeleteModal();
}

function onSiteDeleteSubmitClick(event) {
    var submit = event.currentTarget;

    if (!submit || submit.getAttribute('aria-disabled') === 'true') {
        event.preventDefault();
    }
}

function onOrphanFileDeleteSubmitClick(event) {
    var submit = event.currentTarget;

    if (!submit || submit.disabled) {
        event.preventDefault();
    }
}

function initDeleteCptModal() {
    var openButtons = document.querySelectorAll('.rrze-msm-open-delete-cpt-modal');
    var closeButtons = document.querySelectorAll('.rrze-msm-close-modal');
    var checkbox = document.querySelector('#rrze-msm-delete-cpt-confirm');
    var i = 0;

    for (i = 0; i < openButtons.length; i++) {
        openButtons[i].addEventListener('click', onDeleteCptButtonClick);
    }

    for (i = 0; i < closeButtons.length; i++) {
        closeButtons[i].addEventListener('click', onCloseDeleteCptModalClick);
    }

    if (checkbox) {
        checkbox.addEventListener('change', updateDeleteCptSubmitState);
    }
}

function initPluginDeactivateModal() {
    var openButtons = document.querySelectorAll('.rrze-msm-open-plugin-deactivate-modal');
    var closeButtons = document.querySelectorAll('.rrze-msm-close-plugin-modal');
    var checkbox = document.querySelector('#rrze-msm-plugin-deactivate-confirm');
    var submit = document.querySelector('#rrze-msm-plugin-deactivate-submit');
    var i = 0;

    for (i = 0; i < openButtons.length; i++) {
        openButtons[i].addEventListener('click', onPluginDeactivateButtonClick);
    }

    for (i = 0; i < closeButtons.length; i++) {
        closeButtons[i].addEventListener('click', onClosePluginDeactivateModalClick);
    }

    if (checkbox) {
        checkbox.addEventListener('change', updatePluginDeactivateSubmitState);
    }

    if (submit) {
        submit.addEventListener('click', onPluginDeactivateSubmitClick);
        updatePluginDeactivateSubmitState();
    }
}

function initSiteDeleteModal() {
    var openButtons = document.querySelectorAll('.rrze-msm-open-site-delete-modal');
    var closeButtons = document.querySelectorAll('.rrze-msm-close-site-delete-modal');
    var checkbox = document.querySelector('#rrze-msm-site-delete-confirm');
    var submit = document.querySelector('#rrze-msm-site-delete-submit');
    var i = 0;

    for (i = 0; i < openButtons.length; i++) {
        openButtons[i].addEventListener('click', onSiteDeleteButtonClick);
    }

    for (i = 0; i < closeButtons.length; i++) {
        closeButtons[i].addEventListener('click', onCloseSiteDeleteModalClick);
    }

    if (checkbox) {
        checkbox.addEventListener('change', updateSiteDeleteSubmitState);
    }

    if (submit) {
        submit.addEventListener('click', onSiteDeleteSubmitClick);
        updateSiteDeleteSubmitState();
    }
}

function initOrphanFileDeleteModal() {
    var openButtons = document.querySelectorAll('.rrze-msm-open-orphan-file-delete-modal');
    var openBulkButtons = document.querySelectorAll('.rrze-msm-open-orphan-file-bulk-delete-modal');
    var closeButtons = document.querySelectorAll('.rrze-msm-close-orphan-file-delete-modal');
    var checkbox = document.querySelector('#rrze-msm-orphan-file-delete-confirm');
    var submit = document.querySelector('#rrze-msm-orphan-file-delete-submit');
    var i = 0;

    for (i = 0; i < openButtons.length; i++) {
        openButtons[i].addEventListener('click', onOrphanFileDeleteButtonClick);
    }

    for (i = 0; i < openBulkButtons.length; i++) {
        openBulkButtons[i].addEventListener('click', onOrphanFileBulkDeleteButtonClick);
    }

    for (i = 0; i < closeButtons.length; i++) {
        closeButtons[i].addEventListener('click', onCloseOrphanFileDeleteModalClick);
    }

    if (checkbox) {
        checkbox.addEventListener('change', updateOrphanFileDeleteSubmitState);
    }

    if (submit) {
        submit.addEventListener('click', onOrphanFileDeleteSubmitClick);
        updateOrphanFileDeleteSubmitState();
    }
}

function moveWidget(widget, direction) {
    var sibling = null;
    var parent = null;

    if (!widget || !widget.parentNode) {
        return;
    }

    parent = widget.parentNode;

    if (direction === 'up') {
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

    widgets = grid.querySelectorAll('.rrze-msm-widget[data-widget-id]');

    for (i = 0; i < widgets.length; i++) {
        widget = widgets[i];
        moveUp = widget.querySelector('.rrze-msm-widget-move-up');
        moveDown = widget.querySelector('.rrze-msm-widget-move-down');

        if (moveUp) {
            moveUp.disabled = (i === 0);
            moveUp.setAttribute('aria-disabled', i === 0 ? 'true' : 'false');
        }

        if (moveDown) {
            moveDown.disabled = (i === widgets.length - 1);
            moveDown.setAttribute('aria-disabled', i === widgets.length - 1 ? 'true' : 'false');
        }
    }
}

function onWidgetMoveClick(event) {
    var button = event.currentTarget;
    var widget = button.closest('.rrze-msm-widget[data-widget-id]');
    var grid = document.querySelector('.rrze-msm-grid-primary');
    var direction = button.getAttribute('data-direction') || '';

    if (!widget || !grid) {
        return;
    }

    moveWidget(widget, direction);
    updateWidgetMoveButtons(grid);
    saveWidgetOrder(grid);
}

function initWidgetControls() {
    var buttons = document.querySelectorAll('.rrze-msm-widget-move');
    var i = 0;

    for (i = 0; i < buttons.length; i++) {
        buttons[i].addEventListener('click', onWidgetMoveClick);
    }
}

function getWidgetOrder(grid) {
    var widgets = grid.querySelectorAll('.rrze-msm-widget[data-widget-id]');
    var order = [];
    var i = 0;
    var widgetId = '';

    for (i = 0; i < widgets.length; i++) {
        widgetId = widgets[i].getAttribute('data-widget-id') || '';

        if (widgetId !== '') {
            order.push(widgetId);
        }
    }

    return order;
}

function getCookieName(view) {
    return 'rrze_msm_widget_order_' + view;
}

function getColorModeCookieName() {
    return 'rrze_msm_color_mode';
}

function setCookie(name, value, days) {
    var expires = '';
    var date = new Date();

    date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
    expires = '; expires=' + date.toUTCString();
    document.cookie = name + '=' + encodeURIComponent(value) + expires + '; path=/; SameSite=Lax';
}

function getCookie(name) {
    var cookies = document.cookie ? document.cookie.split(';') : [];
    var i = 0;
    var cookie = '';
    var parts = [];
    var cookieName = '';

    for (i = 0; i < cookies.length; i++) {
        cookie = cookies[i].trim();
        parts = cookie.split('=');
        cookieName = parts.shift();

        if (cookieName === name) {
            return decodeURIComponent(parts.join('='));
        }
    }

    return '';
}

function saveWidgetOrder(grid) {
    var config = getAdminConfig();
    var view = '';
    var order = [];

    if (!config || !grid) {
        return;
    }

    view = grid.getAttribute('data-current-view') || config.currentView || 'default';
    order = getWidgetOrder(grid);
    setCookie(getCookieName(view), JSON.stringify(order), 365);
}

function applySavedWidgetOrder(grid) {
    var config = getAdminConfig();
    var view = '';
    var cookieValue = '';
    var order = [];
    var i = 0;
    var widgetId = '';
    var widget = null;

    if (!config || !grid) {
        return;
    }

    view = grid.getAttribute('data-current-view') || config.currentView || 'default';
    cookieValue = getCookie(getCookieName(view));

    if (cookieValue === '') {
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
        widgetId = String(order[i] || '');
        widget = grid.querySelector('.rrze-msm-widget[data-widget-id="' + widgetId + '"]');

        if (widget) {
            grid.appendChild(widget);
        }
    }
}

function initSortableWidgets() {
    var grid = document.querySelector('.rrze-msm-grid-primary');

    if (!grid) {
        return;
    }

    applySavedWidgetOrder(grid);
    updateWidgetMoveButtons(grid);
}

function getSiteTableRows(wrapper) {
    return Array.prototype.slice.call(wrapper.querySelectorAll('tbody tr'));
}

function getSiteTableSearchQuery(wrapper) {
    var input = wrapper.querySelector('.rrze-msm-site-table-search');

    if (!input) {
        return '';
    }

    return String(input.value || '').trim().toLowerCase();
}

function filterSiteTableRows(wrapper, rows) {
    var query = getSiteTableSearchQuery(wrapper);
    var filteredRows = [];
    var i = 0;
    var sortName = '';

    if (query === '') {
        return rows;
    }

    for (i = 0; i < rows.length; i++) {
        sortName = String(getSiteTableSortValue(rows[i], 'name') || '').toLowerCase();

        if (sortName.indexOf(query) !== -1) {
            filteredRows.push(rows[i]);
        }
    }

    return filteredRows;
}

function getSiteTablePerPage(wrapper) {
    var select = wrapper.querySelector('.rrze-msm-site-table-per-page');
    var perPage = 0;

    if (!select) {
        return 10;
    }

    perPage = parseInt(select.value, 10);

    if (isNaN(perPage) || perPage < 1) {
        perPage = parseInt(wrapper.getAttribute('data-default-per-page') || '10', 10);
    }

    if (isNaN(perPage) || perPage < 1) {
        return 10;
    }

    return perPage;
}

function getSiteTableCurrentPage(wrapper) {
    var currentPage = parseInt(wrapper.getAttribute('data-current-page') || '1', 10);

    if (isNaN(currentPage) || currentPage < 1) {
        return 1;
    }

    return currentPage;
}

function getSiteTableSortKey(wrapper) {
    return wrapper.getAttribute('data-sort-key') || 'name';
}

function getSiteTableSortDirection(wrapper) {
    var direction = wrapper.getAttribute('data-sort-direction') || 'asc';

    if (direction !== 'desc') {
        return 'asc';
    }

    return 'desc';
}

function getSiteTableSortType(key) {
    if (key === 'registered' || key === 'last-updated' || key === 'storage' || key === 'active-sites') {
        return 'number';
    }

    return 'string';
}

function getSiteTableSortValue(row, key) {
    return row.getAttribute('data-sort-' + key) || '';
}

function sortSiteTableRows(wrapper, rows) {
    var sortKey = getSiteTableSortKey(wrapper);
    var sortDirection = getSiteTableSortDirection(wrapper);
    var sortType = getSiteTableSortType(sortKey);

    function compareRows(left, right) {
        var leftValue = getSiteTableSortValue(left, sortKey);
        var rightValue = getSiteTableSortValue(right, sortKey);
        var comparison = 0;

        if (sortType === 'number') {
            comparison = parseInt(leftValue || '0', 10) - parseInt(rightValue || '0', 10);
        } else {
            comparison = String(leftValue).localeCompare(String(rightValue), 'de', { sensitivity: 'base' });
        }

        if (comparison === 0) {
            comparison = String(getSiteTableSortValue(left, 'name')).localeCompare(String(getSiteTableSortValue(right, 'name')), 'de', { sensitivity: 'base' });
        }

        return sortDirection === 'desc' ? (comparison * -1) : comparison;
    }

    rows.sort(compareRows);
}

function updateSiteTableSortButtons(wrapper) {
    var buttons = wrapper.querySelectorAll('.rrze-msm-site-table-sort');
    var sortKey = getSiteTableSortKey(wrapper);
    var sortDirection = getSiteTableSortDirection(wrapper);
    var i = 0;
    var button = null;
    var buttonKey = '';
    var indicator = null;

    for (i = 0; i < buttons.length; i++) {
        button = buttons[i];
        buttonKey = button.getAttribute('data-sort-key') || '';
        indicator = button.querySelector('.rrze-msm-site-table-sort-indicator');
        button.classList.remove('is-active', 'is-asc', 'is-desc');

        if (indicator) {
            indicator.textContent = '';
        }

        if (buttonKey === sortKey) {
            button.classList.add('is-active');
            button.classList.add(sortDirection === 'desc' ? 'is-desc' : 'is-asc');

            if (indicator) {
                indicator.textContent = sortDirection === 'desc' ? '▼' : '▲';
            }
        }
    }
}

function renderSiteTablePagination(wrapper, totalRows, perPage, currentPage) {
    var pagination = wrapper.querySelector('.rrze-msm-site-table-pagination');
    var totalPages = Math.max(1, Math.ceil(totalRows / perPage));
    var startItem = 0;
    var endItem = 0;
    var html = '';

    if (!pagination) {
        return;
    }

    if (totalRows === 0) {
        pagination.innerHTML = '';
        return;
    }

    startItem = ((currentPage - 1) * perPage) + 1;
    endItem = Math.min(totalRows, currentPage * perPage);
    html += '<span class="displaying-num">' + String(startItem) + '–' + String(endItem) + ' / ' + String(totalRows) + '</span>';
    html += '<span class="pagination-links">';
    html += '<button type="button" class="first-page button rrze-msm-site-table-page" data-page="1"' + (currentPage === 1 ? ' disabled aria-disabled="true"' : '') + '><span aria-hidden="true">«</span></button>';
    html += '<button type="button" class="prev-page button rrze-msm-site-table-page" data-page="' + String(Math.max(1, currentPage - 1)) + '"' + (currentPage === 1 ? ' disabled aria-disabled="true"' : '') + '><span aria-hidden="true">‹</span></button>';
    html += '<span class="paging-input"><span class="tablenav-paging-text">' + String(currentPage) + ' / <span class="total-pages">' + String(totalPages) + '</span></span></span>';
    html += '<button type="button" class="next-page button rrze-msm-site-table-page" data-page="' + String(Math.min(totalPages, currentPage + 1)) + '"' + (currentPage === totalPages ? ' disabled aria-disabled="true"' : '') + '><span aria-hidden="true">›</span></button>';
    html += '<button type="button" class="last-page button rrze-msm-site-table-page" data-page="' + String(totalPages) + '"' + (currentPage === totalPages ? ' disabled aria-disabled="true"' : '') + '><span aria-hidden="true">»</span></button>';
    html += '</span>';
    pagination.innerHTML = html;
}

function renderSiteTable(wrapper) {
    var rows = [];
    var filteredRows = [];
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

    sortSiteTableRows(wrapper, rows);
    filteredRows = filterSiteTableRows(wrapper, rows);
    totalPages = Math.max(1, Math.ceil(filteredRows.length / perPage));

    if (currentPage > totalPages) {
        currentPage = totalPages;
        wrapper.setAttribute('data-current-page', String(currentPage));
    }

    for (i = 0; i < rows.length; i++) {
        rows[i].parentNode.appendChild(rows[i]);
    }

    startIndex = (currentPage - 1) * perPage;
    endIndex = startIndex + perPage;

    for (i = 0; i < rows.length; i++) {
        rows[i].style.display = 'none';
    }

    for (i = 0; i < filteredRows.length; i++) {
        filteredRows[i].style.display = (i >= startIndex && i < endIndex) ? '' : 'none';
    }

    updateSiteTableSortButtons(wrapper);
    renderSiteTablePagination(wrapper, filteredRows.length, perPage, currentPage);
}

function onSiteTableClick(event) {
    var sortButton = event.target.closest('.rrze-msm-site-table-sort');
    var pageButton = event.target.closest('.rrze-msm-site-table-page');
    var wrapper = event.currentTarget;
    var currentSortKey = '';
    var nextDirection = 'asc';

    if (sortButton) {
        currentSortKey = getSiteTableSortKey(wrapper);

        if ((sortButton.getAttribute('data-sort-key') || '') === currentSortKey) {
            nextDirection = getSiteTableSortDirection(wrapper) === 'asc' ? 'desc' : 'asc';
        }

        wrapper.setAttribute('data-sort-key', sortButton.getAttribute('data-sort-key') || 'name');
        wrapper.setAttribute('data-sort-direction', nextDirection);
        wrapper.setAttribute('data-current-page', '1');
        renderSiteTable(wrapper);
        return;
    }

    if (pageButton && !pageButton.disabled) {
        wrapper.setAttribute('data-current-page', pageButton.getAttribute('data-page') || '1');
        renderSiteTable(wrapper);
    }
}

function onSiteTablePerPageChange(event) {
    var wrapper = event.currentTarget.closest('.rrze-msm-site-table-wrap');

    if (!wrapper) {
        return;
    }

    wrapper.setAttribute('data-current-page', '1');
    renderSiteTable(wrapper);
}

function onSiteTableSearchInput(event) {
    var wrapper = event.currentTarget.closest('.rrze-msm-site-table-wrap');

    if (!wrapper) {
        return;
    }

    wrapper.setAttribute('data-current-page', '1');
    renderSiteTable(wrapper);
}

function initSiteTables() {
    var wrappers = document.querySelectorAll('.rrze-msm-site-table-wrap');
    var i = 0;
    var searchInput = null;

    for (i = 0; i < wrappers.length; i++) {
        wrappers[i].addEventListener('click', onSiteTableClick);

        if (wrappers[i].querySelector('.rrze-msm-site-table-per-page')) {
            wrappers[i].querySelector('.rrze-msm-site-table-per-page').addEventListener('change', onSiteTablePerPageChange);
        }

        searchInput = wrappers[i].querySelector('.rrze-msm-site-table-search');

        if (searchInput) {
            searchInput.addEventListener('input', onSiteTableSearchInput);
        }

        renderSiteTable(wrappers[i]);
    }
}

function setModeButtonState(button, mode, label) {
    var nextMode = mode === 'dark' ? 'light' : 'dark';

    button.setAttribute('data-next-mode', nextMode);
    button.textContent = label;
}

function applyColorMode(mode) {
    var root = document.querySelector('.rrze-multisite-manager-admin');
    var body = document.body;

    if (!root) {
        return;
    }

    root.classList.remove('rrze-msm-mode-light', 'rrze-msm-mode-dark');
    root.classList.add('rrze-msm-mode-' + mode);

    if (body) {
        body.classList.add('rrze-msm-admin');
        body.classList.remove('rrze-msm-mode-light', 'rrze-msm-mode-dark');
        body.classList.add('rrze-msm-mode-' + mode);
    }
}

function onModeToggleClick(event) {
    var config = getAdminConfig();
    var button = event.currentTarget;
    var nextMode = button.getAttribute('data-next-mode') || 'dark';
    var nextLabel = '';

    if (!config) {
        return;
    }

    nextLabel = nextMode === 'dark' ? config.lightModeLabel : config.darkModeLabel;
    setCookie(getColorModeCookieName(), nextMode, 365);
    applyColorMode(nextMode);
    setModeButtonState(button, nextMode, nextLabel);
}

function initModeToggle() {
    var button = document.querySelector('.rrze-msm-mode-toggle');

    if (!button) {
        return;
    }

    button.addEventListener('click', onModeToggleClick);
}

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function getSiteSearchInput() {
    return document.querySelector('#rrze-msm-site-search');
}

function getSiteSearchResults() {
    return document.querySelector('#rrze-msm-site-search-results');
}

function clearSiteSearchResults() {
    var results = getSiteSearchResults();

    if (!results) {
        return;
    }

    results.innerHTML = '';
}

function renderSiteSearchResults(items) {
    var config = getAdminConfig();
    var results = getSiteSearchResults();
    var html = '';
    var i = 0;
    var item = null;
    var targetUrl = '';

    if (!results || !config) {
        return;
    }

    if (!Array.isArray(items) || items.length === 0) {
        results.innerHTML = '<p class="rrze-msm-site-search-empty">' + String(config.siteSearchNoResults || '') + '</p>';
        return;
    }

    html += '<ul class="rrze-msm-site-search-list">';

    for (i = 0; i < items.length; i++) {
        item = items[i];
        targetUrl = String(config.siteSearchBaseUrl || config.siteDetailsBaseUrl || '') + '&site_id=' + String(item.id || '');
        html += '<li class="rrze-msm-site-search-item">';
        html += '<a href="' + escapeHtml(targetUrl) + '">';
        html += '<strong>' + escapeHtml(item.name || '') + '</strong>';
        html += '<span>' + escapeHtml(item.url || '') + '</span>';
        html += '</a>';
        html += '</li>';
    }

    html += '</ul>';
    results.innerHTML = html;
}

function fetchSiteSearchResults(query) {
    var config = getAdminConfig();
    var url = '';

    if (!config) {
        return;
    }

    url = String(config.ajaxUrl || '') + '?action=rrze_msm_search_sites&nonce=' + encodeURIComponent(String(config.siteSearchNonce || '')) + '&q=' + encodeURIComponent(query);

    fetch(url, {
        credentials: 'same-origin'
    }).then(function (response) {
        return response.json();
    }).then(function (payload) {
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
    var query = '';
    var minLength = 3;

    if (!config || !input) {
        return;
    }

    query = String(input.value || '').trim();
    minLength = parseInt(String(config.siteSearchMinLength || '3'), 10);

    if (isNaN(minLength) || minLength < 1) {
        minLength = 3;
    }

    if (query.length < minLength) {
        clearSiteSearchResults();
        if (rrzeMsmSiteSearchTimer) {
            window.clearTimeout(rrzeMsmSiteSearchTimer);
            rrzeMsmSiteSearchTimer = 0;
        }
        return;
    }

    if (rrzeMsmSiteSearchTimer) {
        window.clearTimeout(rrzeMsmSiteSearchTimer);
    }

    rrzeMsmSiteSearchTimer = window.setTimeout(function () {
        fetchSiteSearchResults(query);
    }, rrzeMsmSearchDelay);
}

function initSiteSearch() {
    var input = getSiteSearchInput();

    if (!input) {
        return;
    }

    input.addEventListener('input', onSiteSearchInput);
}

function getPluginSearchInput() {
    return document.querySelector('#rrze-msm-plugin-search');
}

function getPluginSearchResults() {
    return document.querySelector('#rrze-msm-plugin-search-results');
}

function clearPluginSearchResults() {
    var results = getPluginSearchResults();

    if (!results) {
        return;
    }

    results.innerHTML = '';
}

function renderPluginSearchResults(items) {
    var config = getAdminConfig();
    var results = getPluginSearchResults();
    var html = '';
    var i = 0;
    var item = null;
    var targetUrl = '';

    if (!results || !config) {
        return;
    }

    if (!Array.isArray(items) || items.length === 0) {
        results.innerHTML = '<p class="rrze-msm-site-search-empty">' + String(config.pluginSearchNoResults || '') + '</p>';
        return;
    }

    html += '<ul class="rrze-msm-site-search-list">';

    for (i = 0; i < items.length; i++) {
        item = items[i];
        targetUrl = String(config.pluginDetailsBaseUrl || '') + '&plugin=' + encodeURIComponent(String(item.id || ''));
        html += '<li class="rrze-msm-site-search-item">';
        html += '<a href="' + escapeHtml(targetUrl) + '">';
        html += '<strong>' + escapeHtml(item.name || '') + '</strong>';
        html += '<span>' + escapeHtml(item.file || '') + '</span>';
        html += '</a>';
        html += '</li>';
    }

    html += '</ul>';
    results.innerHTML = html;
}

function fetchPluginSearchResults(query) {
    var config = getAdminConfig();
    var url = '';

    if (!config) {
        return;
    }

    url = String(config.ajaxUrl || '') + '?action=rrze_msm_search_plugins&nonce=' + encodeURIComponent(String(config.pluginSearchNonce || '')) + '&q=' + encodeURIComponent(query);

    fetch(url, {
        credentials: 'same-origin'
    }).then(function (response) {
        return response.json();
    }).then(function (payload) {
        if (!payload || payload.success !== true || !payload.data) {
            clearPluginSearchResults();
            return;
        }

        renderPluginSearchResults(payload.data.results || []);
    }).catch(clearPluginSearchResults);
}

function onPluginSearchInput() {
    var config = getAdminConfig();
    var input = getPluginSearchInput();
    var query = '';
    var minLength = 3;

    if (!config || !input) {
        return;
    }

    query = String(input.value || '').trim();
    minLength = parseInt(String(config.pluginSearchMinLength || '3'), 10);

    if (isNaN(minLength) || minLength < 1) {
        minLength = 3;
    }

    if (query.length < minLength) {
        clearPluginSearchResults();
        if (rrzeMsmPluginSearchTimer) {
            window.clearTimeout(rrzeMsmPluginSearchTimer);
            rrzeMsmPluginSearchTimer = 0;
        }
        return;
    }

    if (rrzeMsmPluginSearchTimer) {
        window.clearTimeout(rrzeMsmPluginSearchTimer);
    }

    rrzeMsmPluginSearchTimer = window.setTimeout(function () {
        fetchPluginSearchResults(query);
    }, rrzeMsmSearchDelay);
}

function initPluginSearch() {
    var input = getPluginSearchInput();

    if (!input) {
        return;
    }

    input.addEventListener('input', onPluginSearchInput);
}

function getThemeSearchInput() {
    return document.querySelector('#rrze-msm-theme-search');
}

function getThemeSearchResults() {
    return document.querySelector('#rrze-msm-theme-search-results');
}

function clearThemeSearchResults() {
    var results = getThemeSearchResults();

    if (!results) {
        return;
    }

    results.innerHTML = '';
}

function renderThemeSearchResults(items) {
    var config = getAdminConfig();
    var results = getThemeSearchResults();
    var html = '';
    var i = 0;
    var item = null;
    var targetUrl = '';

    if (!results || !config) {
        return;
    }

    if (!Array.isArray(items) || items.length === 0) {
        results.innerHTML = '<p class="rrze-msm-site-search-empty">' + String(config.themeSearchNoResults || '') + '</p>';
        return;
    }

    html += '<ul class="rrze-msm-site-search-list">';

    for (i = 0; i < items.length; i++) {
        item = items[i];
        targetUrl = String(config.themeDetailsBaseUrl || '') + '&theme=' + encodeURIComponent(String(item.id || ''));
        html += '<li class="rrze-msm-site-search-item">';
        html += '<a href="' + escapeHtml(targetUrl) + '">';
        html += '<strong>' + escapeHtml(item.name || '') + '</strong>';
        html += '<span>' + escapeHtml(item.stylesheet || '') + '</span>';
        html += '</a>';
        html += '</li>';
    }

    html += '</ul>';
    results.innerHTML = html;
}

function fetchThemeSearchResults(query) {
    var config = getAdminConfig();
    var url = '';

    if (!config) {
        return;
    }

    url = String(config.ajaxUrl || '') + '?action=rrze_msm_search_themes&nonce=' + encodeURIComponent(String(config.themeSearchNonce || '')) + '&q=' + encodeURIComponent(query);

    fetch(url, {
        credentials: 'same-origin'
    }).then(function (response) {
        return response.json();
    }).then(function (payload) {
        if (!payload || payload.success !== true || !payload.data) {
            clearThemeSearchResults();
            return;
        }

        renderThemeSearchResults(payload.data.results || []);
    }).catch(clearThemeSearchResults);
}

function onThemeSearchInput() {
    var config = getAdminConfig();
    var input = getThemeSearchInput();
    var query = '';
    var minLength = 3;

    if (!config || !input) {
        return;
    }

    query = String(input.value || '').trim();
    minLength = parseInt(String(config.themeSearchMinLength || '3'), 10);

    if (isNaN(minLength) || minLength < 1) {
        minLength = 3;
    }

    if (query.length < minLength) {
        clearThemeSearchResults();
        if (rrzeMsmThemeSearchTimer) {
            window.clearTimeout(rrzeMsmThemeSearchTimer);
            rrzeMsmThemeSearchTimer = 0;
        }
        return;
    }

    if (rrzeMsmThemeSearchTimer) {
        window.clearTimeout(rrzeMsmThemeSearchTimer);
    }

    rrzeMsmThemeSearchTimer = window.setTimeout(function () {
        fetchThemeSearchResults(query);
    }, rrzeMsmSearchDelay);
}

function initThemeSearch() {
    var input = getThemeSearchInput();

    if (!input) {
        return;
    }

    input.addEventListener('input', onThemeSearchInput);
}

function updateReadmeToggle(container, expanded) {
    var collapsed = container.querySelector('.rrze-msm-readme-toggle-collapsed');
    var content = container.querySelector('.rrze-msm-readme-toggle-content');
    var buttons = container.querySelectorAll('.rrze-msm-readme-toggle-button');
    var i = 0;

    if (collapsed) {
        collapsed.hidden = expanded;
    }

    if (content) {
        content.hidden = !expanded;
    }

    for (i = 0; i < buttons.length; i++) {
        buttons[i].setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }
}

function onReadmeToggleClick(event) {
    var button = event.currentTarget;
    var readmeId = button.getAttribute('data-readme-id') || '';
    var container = null;
    var expanded = false;

    if (readmeId === '') {
        return;
    }

    container = document.querySelector('.rrze-msm-readme-toggle[data-readme-id="' + readmeId + '"]');

    if (!container) {
        return;
    }

    expanded = button.getAttribute('aria-expanded') !== 'true';
    updateReadmeToggle(container, expanded);
}

function initReadmeToggles() {
    var buttons = document.querySelectorAll('.rrze-msm-readme-toggle-button');
    var i = 0;

    for (i = 0; i < buttons.length; i++) {
        buttons[i].addEventListener('click', onReadmeToggleClick);
    }
}

function updatePluginSitesTextToggle(container, expanded) {
    var buttons = [];
    var i = 0;
    var details = null;
    var collapsed = null;

    if (!container) {
        return;
    }

    buttons = container.querySelectorAll('.rrze-msm-plugin-sites-toggle-text');
    details = container.querySelector('.rrze-msm-plugin-sites-details');
    collapsed = container.querySelector('.rrze-msm-plugin-sites-collapsed');

    for (i = 0; i < buttons.length; i++) {
        buttons[i].setAttribute('aria-expanded', expanded ? 'true' : 'false');
        buttons[i].textContent = i === 0 && expanded ? '▼ Websites anzeigen' : (i === 0 ? '▼ Websites anzeigen' : '▲ Websites verbergen');
    }

    if (collapsed) {
        collapsed.hidden = expanded;
    }

    if (details) {
        details.hidden = !expanded;
    }
}

function onPluginSitesTextToggleClick(event) {
    var button = event.currentTarget;
    var toggleId = button.getAttribute('data-plugin-sites-id') || '';
    var container = null;
    var expanded = false;

    if (toggleId === '') {
        return;
    }

    container = document.querySelector('.rrze-msm-plugin-sites-inline[data-plugin-sites-id="' + toggleId + '"]');

    if (!container) {
        return;
    }

    expanded = button.getAttribute('aria-expanded') !== 'true';
    updatePluginSitesTextToggle(container, expanded);
}

function initPluginSitesTextToggles() {
    var buttons = document.querySelectorAll('.rrze-msm-plugin-sites-toggle-text');
    var i = 0;

    for (i = 0; i < buttons.length; i++) {
        buttons[i].addEventListener('click', onPluginSitesTextToggleClick);
    }
}

function updatePluginSitesPager(container) {
    var currentPage = parseInt(container.getAttribute('data-current-page') || '1', 10);
    var totalPages = parseInt(container.getAttribute('data-total-pages') || '1', 10);
    var prevButton = container.querySelector('.rrze-msm-plugin-sites-page[data-direction="prev"]');
    var nextButton = container.querySelector('.rrze-msm-plugin-sites-page[data-direction="next"]');
    var label = container.querySelector('.rrze-msm-plugin-sites-page-label');
    var items = [];
    var i = 0;

    if (isNaN(currentPage) || currentPage < 1) {
        currentPage = 1;
    }

    if (isNaN(totalPages) || totalPages < 1) {
        totalPages = 1;
    }

    items = container.parentNode.querySelectorAll('.rrze-msm-plugin-sites-list li');

    for (i = 0; i < items.length; i++) {
        items[i].hidden = String(items[i].getAttribute('data-page') || '1') !== String(currentPage);
    }

    if (prevButton) {
        prevButton.disabled = currentPage <= 1;
        prevButton.setAttribute('aria-disabled', currentPage <= 1 ? 'true' : 'false');
    }

    if (nextButton) {
        nextButton.disabled = currentPage >= totalPages;
        nextButton.setAttribute('aria-disabled', currentPage >= totalPages ? 'true' : 'false');
    }

    if (label) {
        label.textContent = 'Seite ' + String(currentPage) + ' von ' + String(totalPages);
    }
}

function onPluginSitesPageClick(event) {
    var button = event.currentTarget;
    var container = button.closest('.rrze-msm-plugin-sites-pagination');
    var currentPage = 1;
    var totalPages = 1;
    var direction = '';

    if (!container || button.disabled) {
        return;
    }

    currentPage = parseInt(container.getAttribute('data-current-page') || '1', 10);
    totalPages = parseInt(container.getAttribute('data-total-pages') || '1', 10);
    direction = button.getAttribute('data-direction') || '';

    if (isNaN(currentPage) || currentPage < 1) {
        currentPage = 1;
    }

    if (isNaN(totalPages) || totalPages < 1) {
        totalPages = 1;
    }

    if (direction === 'prev' && currentPage > 1) {
        currentPage--;
    }

    if (direction === 'next' && currentPage < totalPages) {
        currentPage++;
    }

    container.setAttribute('data-current-page', String(currentPage));
    updatePluginSitesPager(container);
}

function initPluginSitesPagers() {
    var containers = document.querySelectorAll('.rrze-msm-plugin-sites-pagination');
    var buttons = document.querySelectorAll('.rrze-msm-plugin-sites-page');
    var i = 0;

    for (i = 0; i < containers.length; i++) {
        updatePluginSitesPager(containers[i]);
    }

    for (i = 0; i < buttons.length; i++) {
        buttons[i].addEventListener('click', onPluginSitesPageClick);
    }
}

function updateOptionEditFormState(form) {
    var field = null;
    var submit = null;
    var initialValue = '';
    var currentValue = '';

    if (!form) {
        return;
    }

    field = form.querySelector('input[name="option_raw_value"], textarea[name="option_raw_value"]');
    submit = form.querySelector('.rrze-msm-option-save-button');

    if (!field || !submit) {
        return;
    }

    initialValue = form.getAttribute('data-initial-value') || '';
    currentValue = field.value || '';

    if (currentValue !== initialValue) {
        submit.hidden = false;
        return;
    }

    submit.hidden = true;
}

function onOptionEditFieldChange(event) {
    var field = event.currentTarget;
    var form = null;

    if (!field) {
        return;
    }

    form = field.closest('.rrze-msm-option-edit-form');
    updateOptionEditFormState(form);
}

function initOptionEditForms() {
    var forms = document.querySelectorAll('.rrze-msm-option-edit-form');
    var form = null;
    var field = null;
    var i = 0;

    for (i = 0; i < forms.length; i++) {
        form = forms[i];
        field = form.querySelector('input[name="option_raw_value"], textarea[name="option_raw_value"]');

        if (!field) {
            continue;
        }

        field.addEventListener('input', onOptionEditFieldChange);
        field.addEventListener('change', onOptionEditFieldChange);
        updateOptionEditFormState(form);
    }
}

function getStorageAnalysisRunner() {
    return document.querySelector('#rrze-msm-storage-analysis-runner');
}

function getStorageAnalysisFeedback() {
    return document.querySelector('#rrze-msm-storage-analysis-feedback');
}

function setStorageAnalysisFeedback(message, type) {
    var feedback = getStorageAnalysisFeedback();
    var className = 'notice notice-info inline';

    if (!feedback) {
        return;
    }

    if (type === 'error') {
        className = 'notice notice-error inline';
    } else if (type === 'success') {
        className = 'notice notice-success inline';
    }

    if (!message) {
        feedback.innerHTML = '';
        feedback.setAttribute('hidden', 'hidden');
        return;
    }

    feedback.innerHTML = '<div class="' + className + '"><p>' + escapeHtml(message) + '</p></div>';
    feedback.removeAttribute('hidden');
}

function updateStorageAnalysisButtons(runner, baseStatus, orphanStatus, hasCachedAnalysis) {
    var baseButton = null;
    var orphanButton = null;
    var baseRunning = false;
    var orphanRunning = false;

    if (!runner) {
        return;
    }

    baseButton = runner.querySelector('.rrze-msm-start-storage-analysis');
    orphanButton = runner.querySelector('.rrze-msm-start-storage-orphan-analysis');
    baseRunning = baseStatus === 'running';
    orphanRunning = orphanStatus === 'running';

    if (baseButton) {
        baseButton.disabled = baseRunning || orphanRunning;
        baseButton.textContent = baseRunning ? 'Analyse läuft ...' : (hasCachedAnalysis ? 'Analyse aktualisieren' : 'Analyse starten');
    }

    if (orphanButton) {
        orphanButton.disabled = baseRunning || orphanRunning || !hasCachedAnalysis;
        orphanButton.textContent = orphanRunning ? 'Verwaist-Prüfung läuft ...' : 'Verwaist-Prüfung starten';
    }
}

function updateStorageAnalysisRunnerStatus(status) {
    var runner = getStorageAnalysisRunner();
    var baseMessage = null;
    var orphanMessage = null;
    var baseStatus = {};
    var orphanStatus = {};
    var hasCachedAnalysis = false;

    if (!runner || !status) {
        return;
    }

    baseMessage = document.querySelector('#rrze-msm-storage-analysis-base-message');
    orphanMessage = document.querySelector('#rrze-msm-storage-analysis-orphan-message');
    baseStatus = status.base || {};
    orphanStatus = status.orphan || {};
    hasCachedAnalysis = Boolean(status.has_cached_analysis);

    runner.setAttribute('data-base-status', String(baseStatus.status || 'idle'));
    runner.setAttribute('data-orphan-status', String(orphanStatus.status || 'idle'));

    if (baseMessage && baseStatus.message) {
        baseMessage.textContent = String(baseStatus.message || '');
    }

    if (orphanMessage && orphanStatus.message) {
        orphanMessage.textContent = String(orphanStatus.message || '');
    }

    updateStorageAnalysisButtons(
        runner,
        String(baseStatus.status || 'idle'),
        String(orphanStatus.status || 'idle'),
        hasCachedAnalysis
    );
}

function scheduleStorageAnalysisRun(operation) {
    if (rrzeMsmStorageAnalysisTimer) {
        window.clearTimeout(rrzeMsmStorageAnalysisTimer);
        rrzeMsmStorageAnalysisTimer = 0;
    }

    rrzeMsmStorageAnalysisTimer = window.setTimeout(function () {
        runStorageAnalysisOperation(operation, false);
    }, 350);
}

function getStorageAnalysisRequestData(operation, siteId, restart) {
    var config = getAdminConfig();
    var action = '';
    var nonce = '';

    if (!config) {
        return null;
    }

    if (operation === 'orphan') {
        action = 'rrze_msm_run_site_storage_orphan_analysis';
        nonce = String(config.siteStorageOrphanAnalysisNonce || '');
    } else {
        action = 'rrze_msm_run_site_storage_analysis';
        nonce = String(config.siteStorageAnalysisNonce || '');
    }

    return {
        url: String(config.ajaxUrl || '') +
            '?action=' + encodeURIComponent(action) +
            '&nonce=' + encodeURIComponent(nonce) +
            '&site_id=' + encodeURIComponent(String(siteId || '0')) +
            '&restart=' + (restart ? '1' : '0')
    };
}

function reloadCurrentPage() {
    window.location.reload();
}

function handleStorageAnalysisResponse(operation, payload) {
    var config = getAdminConfig();
    var status = payload && payload.status ? payload.status : null;
    var phaseStatus = '';

    if (status) {
        updateStorageAnalysisRunnerStatus(status);
    }

    if (operation === 'orphan') {
        phaseStatus = String(((status && status.orphan) ? status.orphan.status : '') || '');
    } else {
        phaseStatus = String(((status && status.base) ? status.base.status : '') || '');
    }

    if (phaseStatus === 'running') {
        scheduleStorageAnalysisRun(operation);
        return;
    }

    if (phaseStatus === 'complete') {
        setStorageAnalysisFeedback(String((config && config.siteStorageAnalysisCompleted) || ''), 'success');
        window.setTimeout(reloadCurrentPage, 500);
        return;
    }

    if (payload && payload.message) {
        setStorageAnalysisFeedback(String(payload.message || ''), payload.success === false ? 'error' : 'info');
    }
}

function runStorageAnalysisOperation(operation, restart) {
    var runner = getStorageAnalysisRunner();
    var requestData = null;
    var siteId = 0;
    var config = getAdminConfig();
    var hasCachedAnalysis = false;

    if (!runner || !config) {
        return;
    }

    siteId = parseInt(String(runner.getAttribute('data-site-id') || '0'), 10);

    if (isNaN(siteId) || siteId < 1) {
        return;
    }

    requestData = getStorageAnalysisRequestData(operation, siteId, restart);
    hasCachedAnalysis = String(runner.getAttribute('data-base-status') || 'idle') === 'complete'
        || String(runner.getAttribute('data-orphan-status') || 'idle') === 'complete';

    if (!requestData) {
        return;
    }

    updateStorageAnalysisButtons(
        runner,
        operation === 'base' ? 'running' : String(runner.getAttribute('data-base-status') || 'idle'),
        operation === 'orphan' ? 'running' : String(runner.getAttribute('data-orphan-status') || 'idle'),
        hasCachedAnalysis
    );
    setStorageAnalysisFeedback('', 'info');

    fetch(requestData.url, { credentials: 'same-origin' })
        .then(function (response) {
            return response.json();
        })
        .then(function (response) {
            if (!response || response.success !== true || !response.data) {
                setStorageAnalysisFeedback(String((config && config.siteStorageAnalysisFailed) || ''), 'error');
                return;
            }

            handleStorageAnalysisResponse(operation, response.data || {});
        })
        .catch(function () {
            setStorageAnalysisFeedback(String((config && config.siteStorageAnalysisFailed) || ''), 'error');
        });
}

function onStartStorageAnalysisClick(event) {
    event.preventDefault();
    runStorageAnalysisOperation('base', true);
}

function onStartStorageOrphanAnalysisClick(event) {
    event.preventDefault();
    runStorageAnalysisOperation('orphan', true);
}

function initStorageAnalysisRunner() {
    var runner = getStorageAnalysisRunner();
    var baseButton = null;
    var orphanButton = null;
    var baseStatus = '';
    var orphanStatus = '';
    var hasCachedAnalysis = false;
    var shouldAutoStart = false;

    if (!runner) {
        return;
    }

    baseButton = runner.querySelector('.rrze-msm-start-storage-analysis');
    orphanButton = runner.querySelector('.rrze-msm-start-storage-orphan-analysis');
    baseStatus = String(runner.getAttribute('data-base-status') || 'idle');
    orphanStatus = String(runner.getAttribute('data-orphan-status') || 'idle');
    shouldAutoStart = runner.getAttribute('data-auto-start') === '1';
    hasCachedAnalysis = baseStatus === 'complete' || orphanStatus === 'complete';

    if (baseButton) {
        baseButton.addEventListener('click', onStartStorageAnalysisClick);
    }

    if (orphanButton) {
        orphanButton.addEventListener('click', onStartStorageOrphanAnalysisClick);
    }

    updateStorageAnalysisButtons(runner, baseStatus, orphanStatus, hasCachedAnalysis);

    if (shouldAutoStart && baseStatus === 'idle' && !hasCachedAnalysis) {
        runStorageAnalysisOperation('base', true);
        return;
    }

    if (baseStatus === 'running') {
        scheduleStorageAnalysisRun('base');
        return;
    }

    if (orphanStatus === 'running') {
        scheduleStorageAnalysisRun('orphan');
    }
}

function initRrzeMultisiteManager() {
    var config = getAdminConfig();
    var savedMode = '';

    if (config) {
        savedMode = getCookie(getColorModeCookieName()) || config.currentMode;

        if (savedMode !== '') {
            applyColorMode(savedMode);
        }
    }

    initWidgetControls();
    initViewSelect();
    initSortableWidgets();
    initSiteTables();
    initModeToggle();
    initSiteSearch();
    initPluginSearch();
    initThemeSearch();
    initDeleteCptModal();
    initPluginDeactivateModal();
    initSiteDeleteModal();
    initOrphanFileDeleteModal();
    initPluginSitesTextToggles();
    initPluginSitesPagers();
    initReadmeToggles();
    initOptionEditForms();
    initStorageAnalysisRunner();
}

document.addEventListener('DOMContentLoaded', initRrzeMultisiteManager);
