/**
 * Auto-label table cells from header text so stacked mobile cards stay readable.
 * Works with .table-wrap /.table-head /.table-row and native <table> elements.
 */
(function () {
    function cleanLabel(text) {
        return String(text || '')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function headCellLabel(cell) {
        if (!cell) return '';
        var stacked = cell.querySelector('.table-head--stacked > span:not(.table-head-sub)');
        if (stacked) return cleanLabel(stacked.textContent);
        var firstSpan = cell.querySelector('span');
        if (firstSpan && !firstSpan.classList.contains('table-head-sub')) {
            return cleanLabel(firstSpan.textContent);
        }
        return cleanLabel(cell.textContent);
    }

    function labelDivTable(wrap) {
        if (!wrap || wrap.getAttribute('data-rt-skip') === '1') return;
        var head = wrap.querySelector(':scope > .table-head');
        if (!head) return;
        var labels = Array.prototype.map.call(head.children, headCellLabel).filter(Boolean);
        if (!labels.length) return;

        wrap.querySelectorAll(':scope > .table-row').forEach(function (row) {
            if (
                row.classList.contains('table-row--category-label') ||
                row.classList.contains('table-row--category-empty')
            ) {
                return;
            }
            var cells = Array.prototype.filter.call(row.children, function (el) {
                return el && el.nodeType === 1;
            });
            // Skip full-width empty/message rows
            if (cells.length === 1 && (cells[0].style.gridColumn || '').indexOf('1') !== -1) {
                return;
            }
            cells.forEach(function (cell, i) {
                if (!labels[i]) return;
                if (cell.getAttribute('data-label') !== labels[i]) {
                    cell.setAttribute('data-label', labels[i]);
                }
            });
        });
    }

    function labelNativeTable(table) {
        if (!table || table.getAttribute('data-rt-skip') === '1') return;
        var headCells = table.querySelectorAll('thead th');
        if (!headCells.length) return;
        var labels = Array.prototype.map.call(headCells, function (th) {
            return cleanLabel(th.textContent);
        });
        table.querySelectorAll('tbody tr').forEach(function (row) {
            var cells = row.children;
            Array.prototype.forEach.call(cells, function (cell, i) {
                if (cell.tagName !== 'TD' && cell.tagName !== 'TH') return;
                if (!labels[i]) return;
                if (cell.getAttribute('data-label') !== labels[i]) {
                    cell.setAttribute('data-label', labels[i]);
                }
            });
        });
    }

    function refresh(root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('.table-wrap').forEach(labelDivTable);
        scope.querySelectorAll(
            'table.sales-report-table, table.inv-edit-history__table, .sales-report-table-wrap table, .inv-edit-history__table-wrap table'
        ).forEach(labelNativeTable);
    }

    function boot() {
        refresh(document);
        if (typeof MutationObserver === 'undefined') return;
        var timer = null;
        var obs = new MutationObserver(function () {
            if (timer) clearTimeout(timer);
            timer = setTimeout(function () {
                refresh(document);
            }, 80);
        });
        obs.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    window.ortusRefreshResponsiveTables = refresh;
})();
