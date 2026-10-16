import { getDashboard } from "./data.js";
import { setEmptyRow, showNotice } from "./feedback.js";
import {
  Chart,
  BarController,
  BarElement,
  LineController,
  LineElement,
  PointElement,
  CategoryScale,
  LinearScale,
  Tooltip,
  Legend,
  Filler,
} from "chart.js";

// Register only the controllers/elements this dashboard uses to keep the bundle small.
Chart.register(
  BarController,
  BarElement,
  LineController,
  LineElement,
  PointElement,
  CategoryScale,
  LinearScale,
  Tooltip,
  Legend,
  Filler,
);

let revenueChart;
let weeklyChart;
const symbol = globalThis.APP_CONFIG?.currencySymbol || "₵";
const currency = (amount) => `${symbol}${Number(amount || 0).toFixed(2)}`;

function appendActivity(target, label, detail, time) {
  const item = document.createElement("li");
  item.className = "py-2 border-bottom";
  const title = document.createElement("div");
  title.className = "fw-semibold";
  title.textContent = label;
  const metadata = document.createElement("small");
  metadata.className = "text-muted d-block";
  metadata.textContent = [detail, time].filter(Boolean).join(" · ");
  item.append(title, metadata);
  target.append(item);
}

function renderChart(id, instance, config) {
  const canvas = document.getElementById(id);
  if (!canvas) return instance;
  instance?.destroy();
  return new Chart(canvas, config);
}

function setStat(id, text) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.remove("is-loading");
  el.textContent = text;
}

function setNote(id, text) {
  const el = document.getElementById(id);
  if (!el) return;
  el.textContent = text || "";
  el.classList.toggle("d-none", !text);
}

// Percentage change between the last two points of a real, RPC-derived series.
// Renders nothing when there is no usable baseline so a comparison is never invented.
function renderTrend(id, series, { invert = false, baseline = "the previous period" } = {}) {
  const el = document.getElementById(id);
  if (!el) return;
  el.replaceChildren();
  el.className = "stat-trend d-none";
  if (!Array.isArray(series) || series.length < 2) return;
  const current = Number(series[series.length - 1] || 0);
  const previous = Number(series[series.length - 2] || 0);
  if (!Number.isFinite(previous) || previous <= 0) return;
  const delta = ((current - previous) / previous) * 100;
  const direction = delta > 0.05 ? "up" : delta < -0.05 ? "down" : "flat";
  // "up" is favourable for income/profit, but unfavourable for expenses (invert).
  const tone = direction === "flat" ? "flat" : (direction === "up") !== invert ? "up" : "down";
  el.className = `stat-trend stat-trend--${tone}`;
  el.title = `Compared with ${baseline}`;
  el.setAttribute("aria-label", `${delta >= 0 ? "plus" : "minus"}${Math.abs(delta).toFixed(1)} percent compared with ${baseline}`);
  const icon = document.createElement("i");
  icon.className = `bi ${direction === "up" ? "bi-arrow-up-short" : direction === "down" ? "bi-arrow-down-short" : "bi-dash"}`;
  icon.setAttribute("aria-hidden", "true");
  const text = document.createElement("span");
  text.textContent = `${delta >= 0 ? "+" : ""}${delta.toFixed(1)}%`;
  el.append(icon, text);
}

async function refreshDashboard(period) {
  try {
    const result = await getDashboard(period);
    const stats = result.month;
    setStat("statIncome", currency(stats.income));
    setStat("statExpenses", currency(stats.expense));
    setStat("statProfit", currency(stats.profit));
    setStat("statInvoices", String(stats.pending_invoices));
    setStat("weekIncome", currency(result.weekly.income));
    setStat("weekExpense", currency(result.weekly.expense));
    setStat("weekProfit", currency(result.weekly.profit));
    setStat("lowStockSummary", String(result.inventory.low_stock));

    // Supporting context derived strictly from the figures the RPC returned.
    const periodIncome = Number(stats.income || 0);
    const periodProfit = Number(stats.profit || 0);
    setNote("statProfitNote", periodIncome > 0 ? `Margin ${((periodProfit / periodIncome) * 100).toFixed(1)}%` : "");
    setNote("weekIncomeNote", `Avg ${currency(Number(result.weekly.income || 0) / 7)}/day`);
    setNote("weekExpenseNote", `Avg ${currency(Number(result.weekly.expense || 0) / 7)}/day`);
    setNote("weekProfitNote", `Avg ${currency(Number(result.weekly.profit || 0) / 7)}/day`);

    // Only the monthly series carries a comparable prior period (the RPC returns
    // the previous 11 months). Other periods have no baseline, so no trend is shown.
    if (result.period === "month" && result.monthlyChart) {
      const incomeSeries = result.monthlyChart.income || [];
      const expenseSeries = result.monthlyChart.expense || [];
      renderTrend("statIncomeTrend", incomeSeries, { baseline: "last month" });
      renderTrend("statExpensesTrend", expenseSeries, { invert: true, baseline: "last month" });
      const profitSeries = incomeSeries.map((value, index) => Number(value || 0) - Number(expenseSeries[index] || 0));
      renderTrend("statProfitTrend", profitSeries, { baseline: "last month" });
    } else {
      renderTrend("statIncomeTrend", null);
      renderTrend("statExpensesTrend", null);
      renderTrend("statProfitTrend", null);
    }

    revenueChart = renderChart("dashboardChart", revenueChart, {
      type: "bar",
      data: { labels: result.monthlyChart.labels, datasets: [
        { 
          label: "Income", 
          data: result.monthlyChart.income, 
          backgroundColor: "#FFD700",
          borderRadius: 6,
        },
        { 
          label: "Expense", 
          data: result.monthlyChart.expense, 
          backgroundColor: "rgba(230, 238, 245, 0.2)",
          borderRadius: 6,
        },
      ] },
      options: { 
        responsive: true, 
        maintainAspectRatio: false, 
        plugins: { 
          legend: { labels: { color: "#e6eef5", font: { family: "'Inter', sans-serif", weight: '600' } } } 
        }, 
        scales: { 
          x: { grid: { display: false }, ticks: { color: "rgba(230, 238, 245, 0.6)" } }, 
          y: { grid: { color: "rgba(255, 255, 255, 0.05)" }, ticks: { color: "rgba(230, 238, 245, 0.6)" } } 
        } 
      },
    });
    weeklyChart = renderChart("weeklyChart", weeklyChart, {
      type: "line",
      data: { labels: result.weeklyLabels, datasets: [{ 
        label: "Profit", 
        data: result.weeklyTrend, 
        fill: true, 
        backgroundColor: "rgba(255, 215, 0, 0.1)", 
        borderColor: "#FFD700", 
        tension: 0.4,
        pointBackgroundColor: "#FFD700",
        pointRadius: 4
      }] },
      options: { 
        responsive: true, 
        maintainAspectRatio: false, 
        plugins: { legend: { display: false } }, 
        scales: { 
          x: { grid: { display: false }, ticks: { color: "rgba(230, 238, 245, 0.6)" } }, 
          y: { grid: { color: "rgba(255, 255, 255, 0.05)" }, ticks: { color: "rgba(230, 238, 245, 0.6)" } } 
        } 
      },
    });

    const txBody = document.getElementById("dashboardTransactions");
    if (!result.recent.length) setEmptyRow(txBody, 4, "No transactions in this period.");
    else {
      const rows = result.recent.map((tx) => {
        const row = document.createElement("tr");
        const typeBadge = tx.type === 'income' ? 'bg-success' : tx.type === 'expense' ? 'bg-danger' : tx.type === 'payment' ? 'bg-info' : 'bg-secondary';
        for (const value of [tx.client, `<span class="badge ${typeBadge}">${tx.type}</span>`, currency(tx.amount), tx.date]) {
          const cell = document.createElement("td");
          cell.innerHTML = value || "";
          row.append(cell);
        }
        return row;
      });
      txBody.replaceChildren(...rows);
    }

    const topClients = document.getElementById("dashboardTopClients");
    topClients.replaceChildren();
    if (!result.topClients.length) appendActivity(topClients, "No client activity yet", "", "");
    result.topClients.forEach((client) => appendActivity(topClients, client.name, currency(client.total), ""));

    const activity = document.getElementById("dashboardActivity");
    activity.replaceChildren();
    if (!result.recentActivities.length) appendActivity(activity, "No recent activity", "", "");
    result.recentActivities.forEach((entry) => appendActivity(activity, entry.label, entry.details, entry.time ? new Date(entry.time).toLocaleString() : ""));

    const lowStock = document.getElementById("dashboardLowStock");
    lowStock.replaceChildren();
    if (!result.inventory.items.length) appendActivity(lowStock, "No low-stock products", "", "");
    result.inventory.items.forEach((product) => appendActivity(lowStock, product.name, `${product.stock} remaining · minimum ${product.min_stock}`, ""));
  } catch (error) {
    // Clear any shimmering placeholders so the cards do not stay in a loading state.
    for (const id of ["statIncome", "statExpenses", "statProfit", "statInvoices", "weekIncome", "weekExpense", "weekProfit", "lowStockSummary"]) {
      const el = document.getElementById(id);
      if (el?.classList.contains("is-loading")) {
        el.classList.remove("is-loading");
        el.textContent = "--";
      }
    }
    showNotice(error.message || "Unable to load dashboard data.", "danger");
  }
}

export async function initDashboard() {
  const select = document.getElementById("dashboardPeriod");
  select.addEventListener("change", () => refreshDashboard(select.value));
  await refreshDashboard(select.value);
}