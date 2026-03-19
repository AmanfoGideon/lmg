const userNameEl = document.getElementById('userName');
if (userNameEl) userNameEl.textContent = (window.serverData?.user?.full_name) || 'User';

const monthlyData = window.serverData?.monthlyChart || { labels: [], income: [], expense: [] };
const recentTx = window.serverData?.recent || [];
const topClients = window.serverData?.topClients || [];
const weeklyTrend = window.serverData?.weeklyTrend || [];
const weeklyLabels = window.serverData?.weeklyLabels || ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

// Fill stats (monthly totals)
function updateStats() {
  const m = window.serverData?.month || { income:0, expense:0, profit:0, pending_invoices:0 };
  document.getElementById('statIncome').textContent = `${window.currencySymbol}${Number(m.income).toFixed(2)}`;
  document.getElementById('statExpenses').textContent = `${window.currencySymbol}${Number(m.expense).toFixed(2)}`;
  document.getElementById('statProfit').textContent = `${window.currencySymbol}${Number(m.profit).toFixed(2)}`;
  document.getElementById('statInvoices').textContent = `${m.pending_invoices}`;

  const w = window.serverData?.weekly || { income:0, expense:0, profit:0 };
  const weekIncome = document.getElementById('weekIncome');
  const weekExpense = document.getElementById('weekExpense');
  const weekProfit = document.getElementById('weekProfit');
  if (weekIncome) weekIncome.textContent = `${window.currencySymbol}${Number(w.income).toFixed(2)}`;
  if (weekExpense) weekExpense.textContent = `${window.currencySymbol}${Number(w.expense).toFixed(2)}`;
  if (weekProfit) weekProfit.textContent = `${window.currencySymbol}${Number(w.profit).toFixed(2)}`;
}

// Fill recent transactions table
function populateTransactions() {
  const tableEl = document.getElementById('transactionsTable');
  const tbody = tableEl ? tableEl.querySelector('tbody') : null;
  function getTransactionsDT() {
    if (typeof window.jQuery === 'undefined' || !jQuery.fn || typeof jQuery.fn.DataTable !== 'function') return null;
    const $t = jQuery('#transactionsTable');
    if (!$t.length) return null;
    const el = $t.get(0);
    const opts = { destroy: true, pageLength: 10, lengthMenu: [10,25,50,100], order: [], stateSave: true, language: { searchPlaceholder: 'Search...' } };
    return jQuery.fn.DataTable.isDataTable(el) ? $t.DataTable() : $t.DataTable(opts);
  }
  const dt = getTransactionsDT();
  if (dt) {
    dt.clear();
    const rows = recentTx.map(tx => [
      tx.client || '',
      (tx.type || '').toLowerCase(),
      `${window.currencySymbol}${Number(tx.amount||0).toFixed(2)}`,
      tx.date || ''
    ]);
    dt.rows.add(rows).draw();
    return;
  }
  if (!tbody) return;
  tbody.innerHTML = '';
  recentTx.forEach(tx => {
    const tr = document.createElement('tr');
    const tdClient = document.createElement('td');
    tdClient.textContent = tx.client || '';
    const tdType = document.createElement('td');
    tdType.className = 'text-capitalize';
    tdType.textContent = tx.type || '';
    const tdAmount = document.createElement('td');
    tdAmount.textContent = `${window.currencySymbol}${Number(tx.amount||0).toFixed(2)}`;
    const tdDate = document.createElement('td');
    tdDate.className = 'text-muted small';
    tdDate.textContent = tx.date || '';
    tr.appendChild(tdClient);
    tr.appendChild(tdType);
    tr.appendChild(tdAmount);
    tr.appendChild(tdDate);
    tbody.appendChild(tr);
  });
}

// Fill clients list
function populateClients() {
  const ul = document.getElementById('clientsList');
  ul.innerHTML = '';
  topClients.forEach(c => {
    const li = document.createElement('li');
    const row = document.createElement('div');
    row.className = 'd-flex justify-content-between align-items-center';
    const left = document.createElement('div');
    const strong = document.createElement('strong');
    strong.textContent = c.name || '';
    const sub = document.createElement('div');
    sub.className = 'text-muted small';
    sub.textContent = `Total: ${window.currencySymbol}${Number(c.total||0).toFixed(2)}`;
    left.appendChild(strong);
    left.appendChild(sub);
    const right = document.createElement('div');
    const btn = document.createElement('button');
    btn.className = 'btn btn-sm btn-outline-light';
    btn.setAttribute('aria-label', 'View client details');
    btn.textContent = 'View';
    right.appendChild(btn);
    row.appendChild(left);
    row.appendChild(right);
    li.appendChild(row);
    ul.appendChild(li);
  });
}

// Charts
function renderCharts() {
  // Monthly revenue chart (combined income-expense)
  const ctx = document.getElementById('monthlyChart');
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: monthlyData.labels,
      datasets: [
        { label: 'Income', data: monthlyData.income, backgroundColor: 'rgba(255,215,0,0.9)' },
        { label: 'Expense', data: monthlyData.expense, backgroundColor: 'rgba(255,255,255,0.12)' }
      ]
    },
    options: {
      plugins: { legend: { labels: { color: '#fff' }}},
      scales: {
        x: { ticks: { color:'#cbd5e1' } },
        y: { ticks: { color:'#cbd5e1' } }
      }
    }
  });

  // Sparkline weekly
  const sctx = document.getElementById('sparkline');
  new Chart(sctx, {
    type: 'line',
    data: {
      labels: weeklyLabels,
      datasets: [{
        data: weeklyTrend,
        fill: true,
        backgroundColor: 'rgba(255,215,0,0.15)',
        borderColor: 'rgba(255,215,0,1)',
        tension: 0.35,
        pointRadius: 0
      }]
    },
    options: {
      plugins: { legend: { display: false }},
      scales: {
        x: { display: false },
        y: { display: false }
      }
    }
  });
}
function populateActivities() {
  const items = window.serverData?.recentActivities || [];
  const ul = document.getElementById('activitiesList');
  if (!ul) return;
  ul.innerHTML = '';
  items.forEach(a => {
    const li = document.createElement('li');
    const row = document.createElement('div');
    row.className = 'd-flex justify-content-between align-items-start';
    const info = document.createElement('div');
    const labelEl = document.createElement('div');
    const badge = document.createElement('span');
    badge.className = 'badge ' + (a.badgeClass || 'bg-light text-dark');
    badge.textContent = (a.type || '').toUpperCase();
    labelEl.className = 'fw-bold d-flex align-items-center gap-2';
    const text = document.createElement('span');
    text.textContent = a.label || a.action || '';
    labelEl.appendChild(badge);
    labelEl.appendChild(text);
    const detailsEl = document.createElement('div');
    detailsEl.className = 'text-muted small';
    detailsEl.textContent = a.details || '';
    info.appendChild(labelEl);
    info.appendChild(detailsEl);
    const timeEl = document.createElement('div');
    timeEl.className = 'text-muted small';
    timeEl.textContent = a.time || '';
    row.appendChild(info);
    row.appendChild(timeEl);
    li.appendChild(row);
    ul.appendChild(li);
  });
}

// Logout
document.getElementById('btnLogout').addEventListener('click', (e) => {
  e.preventDefault();
  window.location.href = 'logout.php';
});

// Init
updateStats();
populateTransactions();
populateClients();
renderCharts();
populateActivities();

// --- Replace above demo data + functions with API calls to:
// GET /api/summary-monthly.php
// GET /api/transactions.php?limit=10
// GET /api/clients/top
// then use responses to update UI
