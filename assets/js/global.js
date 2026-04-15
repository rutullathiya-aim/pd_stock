let currentLogLines = [];
let currentPage = 1;
const pageSize = 50;

function showAll(btn, className) {
    document.querySelectorAll('.' + className).forEach(el => el.classList.remove('hidden-log'));
    btn.style.display = 'none';
}

async function loadLog(filename, type) {
    document.querySelectorAll('.log-item').forEach(el => el.classList.remove('active'));
    event.currentTarget.classList.add('active');
    document.getElementById('current-file').innerText = 'Viewing: ' + filename;
    currentPage = 1;

    const contentDiv = document.getElementById('main-content');
    contentDiv.innerHTML = '<p style="text-align:center; opacity:0.5;">Loading log data...</p>';

    try {
        const response = await fetch(`index.php?ajax=1&file=${filename}&type=${type}`);
        const data = await response.json();

        if (data.error) {
            contentDiv.innerHTML = `<p style="color:var(--danger)">Error: ${data.error}</p>`;
            return;
        }

        const filterControls = document.getElementById('filter-controls');

        if (type === 'vendor') {
            if (filterControls) filterControls.style.display = 'none';
            contentDiv.innerHTML = `<div class="raw-viewer">${JSON.stringify(data.raw, null, 4)}</div>`;
        } else {
            if (filterControls) {
                document.getElementById('search-sku').value = '';
                document.getElementById('filter-status').value = '';

                // Reset custom dropdown UI
                const customTrigger = document.querySelector('.custom-select-trigger');
                if (customTrigger) {
                    customTrigger.textContent = 'All';
                    document.querySelectorAll('.custom-option').forEach(opt => opt.classList.remove('selected'));
                    const defaultOpt = document.querySelector('.custom-option[data-value=""]');
                    if (defaultOpt) defaultOpt.classList.add('selected');
                }

                filterControls.style.display = 'flex';
            }
            currentLogLines = data.lines || [];
            renderTable(currentLogLines);
        }
    } catch (err) {
        contentDiv.innerHTML = `<p style="color:var(--danger)">Failed to load data.</p>`;
    }
}

function renderTable(lines, isFilterCall = false) {
    const contentDiv = document.getElementById('main-content');

    // Reset page if it's a filter call (we already did it in applyFilters but safe to ensure)
    if (isFilterCall) currentPage = 1;

    let summaryHtml = '';

    // Find summary from the root data so it doesn't get lost during filtering
    const summaryItem = currentLogLines.find(l =>
        (l.raw && l.raw.includes('Inventory: ')) ||
        (l.details && typeof l.details === 'string' && l.details.includes('Inventory: '))
    );

    if (summaryItem) {
        let summaryLine = '';
        if (summaryItem.raw) {
            summaryLine = summaryItem.raw.replace(/\[.*?\] \[SYNC\] /, '');
        } else {
            summaryLine = summaryItem.details;
        }
        summaryHtml = `<div class="summary-banner">${summaryLine}</div>`;
    }

    // Filter out the summary item from the list to be paginated/displayed in table
    const displayLines = lines.filter(l => l !== summaryItem);

    // Pagination slicing
    const totalLines = displayLines.length;
    const totalPages = Math.ceil(totalLines / pageSize) || 1;

    if (currentPage > totalPages) currentPage = totalPages;
    if (currentPage < 1) currentPage = 1;

    const startIdx = (currentPage - 1) * pageSize;
    const endIdx = startIdx + pageSize;
    const pagedLines = displayLines.slice(startIdx, endIdx);

    let html = summaryHtml + `<table class="log-table">
                    <thead>
                        <tr><th>#</th><th>SKU</th><th>Status</th><th>Details</th><th>Action</th></tr>
                    </thead>
                    <tbody>`;

    pagedLines.forEach((line, i) => {
        const rowNumber = startIdx + i + 1;

        // Use raw display for systemic lines (where SKU is empty or '-')
        // or for lines that failed parsing completely.
        const isSystemic = line.raw && (!line.sku || line.sku === '-');

        if (isSystemic) {
            html += `<tr><td colspan="5" style="opacity: 0.5; font-style: italic; font-size: 0.8rem;">${line.raw}</td></tr>`;
        } else {
            let statusClass = 'changed';
            if (line.status === 'NOT FOUND IN SHOPIFY') statusClass = 'notfound';
            if (line.status === 'UNCHANGED') statusClass = 'unchanged';

            const detailsStr = (line.status === 'NOT FOUND IN SHOPIFY' || !line.details) ? '-' : line.details;

            let statusLabel = 'Changed';
            if (line.status === 'NOT FOUND IN SHOPIFY') statusLabel = 'Not found';
            if (line.status === 'UNCHANGED') statusLabel = 'Unchanged';

            const productLink = line.pid ? `${SHOP_URL}/${line.pid}` : `${SHOP_URL}?query=${line.sku}`;

            html += `<tr>
                        <td style="opacity:0.5; font-size: 0.8rem;">${rowNumber}</td>
                        <td><span class="sku-code">${line.sku}</span></td>
                        <td><span class="badge badge-${statusClass}">${statusLabel}</span></td>
                        <td class="details-cell">${detailsStr}</td>
                        <td><a href="${productLink}" target="_blank" class="view-btn">View</a></td>
                    </tr>`;
        }
    });

    html += `</tbody></table>`;

    if (totalLines === 0) {
        html += `<div style="text-align: center; padding: 30px; color: var(--muted);">No records match the current filters.</div>`;
    } else {
        // Add Pagination Controls
        html += `
            <div class="pagination-container">
                <button class="pagination-btn" onclick="changePage(-1)" ${currentPage === 1 ? 'disabled' : ''}>&larr; Previous</button>
                <div class="pagination-info">
                    Page <input type="number" class="page-input" value="${currentPage}" min="1" max="${totalPages}" onchange="jumpToPage(this, ${totalPages})"> of <span>${totalPages}</span>
                    <small style="margin-left:10px; opacity:0.6;">(Total: ${totalLines} records)</small>
                </div>
                <button class="pagination-btn" onclick="changePage(1)" ${currentPage === totalPages ? 'disabled' : ''}>Next &rarr;</button>
            </div>
        `;
    }

    contentDiv.innerHTML = html;
}

function applyFilters(isPaginationCall = false) {
    if (!isPaginationCall) currentPage = 1;

    const searchVal = document.getElementById('search-sku').value.toLowerCase().trim();
    const statusVal = document.getElementById('filter-status').value;

    const filtered = currentLogLines.filter(line => {
        // Identify summary item
        const isSummary = (line.raw && line.raw.includes('Inventory: ')) ||
            (line.details && typeof line.details === 'string' && line.details.includes('Inventory: '));

        if (isSummary) return true; // Keep summary item (managed by renderTable)

        // Only return true for dedicated systemic/raw display items (no SKU or SKUs marked as '-')
        if (line.raw && (!line.sku || line.sku === '-')) return true;

        let matchSku = true;
        let matchStatus = true;

        if (searchVal) {
            matchSku = (line.sku && line.sku.toLowerCase().includes(searchVal));
        }
        if (statusVal) {
            matchStatus = (line.status === statusVal);
        }

        return matchSku && matchStatus;
    });

    renderTable(filtered, !isPaginationCall);
}

function changePage(delta) {
    currentPage += delta;
    applyFilters(true);
    // Scroll to top of content area
    document.querySelector('main').scrollTo({ top: 0, behavior: 'smooth' });
}

function jumpToPage(input, totalPages) {
    let page = parseInt(input.value);
    if (isNaN(page) || page < 1) page = 1;
    if (page > totalPages) page = totalPages;

    currentPage = page;
    applyFilters(true);
    document.querySelector('main').scrollTo({ top: 0, behavior: 'smooth' });
}

// --- Sync Process Functions ---

async function startSync() {
    try {
        const response = await fetch('sync.php?run=1&t=' + Date.now());
        const data = await response.json();

        if (data.success) {
            showFinished(data.results);
        } else {
            showError(data.error);
        }
    } catch (err) {
        showError('A network error occurred during the sync process.');
    }
}

function showFinished(results) {
    document.getElementById('main-loader').style.display = 'none';
    document.getElementById('main-title').innerText = 'Sync Completed';
    document.getElementById('main-text').innerText = 'All product inventory and pricing have been synchronized.';

    document.getElementById('inv-count').innerText = results.inventory.successCount;
    document.getElementById('price-count').innerText = results.price.successCount;
    document.getElementById('not-found-count').innerText = results.totalNotFound;

    document.getElementById('status-title').innerText = 'Sync Completed';
    document.getElementById('status-title').style.color = 'var(--success)';
    document.getElementById('finished-card').style.display = 'block';
}

function showError(msg) {
    document.getElementById('main-loader').style.display = 'none';
    document.getElementById('main-title').innerText = 'Sync Error';
    document.getElementById('main-text').innerText = msg;
    document.getElementById('main-text').style.color = 'var(--error)';
    document.getElementById('status-title').innerText = 'Sync Failed';
    document.getElementById('status-title').style.color = 'var(--error)';
    document.getElementById('finished-card').style.display = 'block';
}

/* --- Event Listeners --- */
document.addEventListener('click', function (e) {
    // 1. Handle Trigger Click
    const trigger = e.target.closest('.custom-select-trigger');
    if (trigger) {
        const select = trigger.closest('.custom-select');
        select.classList.toggle('open');
        return;
    }

    // 2. Handle Option Click
    const option = e.target.closest('.custom-option');
    if (option) {
        const wrapper = option.closest('.custom-select');
        wrapper.querySelector('.custom-select-trigger').textContent = option.textContent;
        wrapper.classList.remove('open');

        wrapper.querySelectorAll('.custom-option').forEach(opt => opt.classList.remove('selected'));
        option.classList.add('selected');

        document.getElementById('filter-status').value = option.dataset.value;
        applyFilters();
        return;
    }

    // 3. Close on Outside Click
    document.querySelectorAll('.custom-select.open').forEach(select => {
        if (!select.contains(e.target)) {
            select.classList.remove('open');
        }
    });
});

document.addEventListener('input', function (e) {
    if (e.target && e.target.id === 'search-sku') {
        e.target.value = e.target.value.replace(/\s+/g, '');
        toggleClearBtn();
    }
});

function toggleClearBtn() {
    const input = document.getElementById('search-sku');
    const btn = document.getElementById('clear-search');
    if (input && btn) {
        btn.style.display = input.value.length > 0 ? 'inline-block' : 'none';
    }
}

function clearSearch() {
    const input = document.getElementById('search-sku');
    if (input) {
        input.value = '';
        toggleClearBtn();
        applyFilters();
    }
}
