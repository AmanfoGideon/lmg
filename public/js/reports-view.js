import Papa from "papaparse";
import { getFinancialReport, listReportBranches } from "./data.js";
import { setEmptyRow, setFormBusy, showNotice } from "./feedback.js";

const symbol = globalThis.APP_CONFIG?.currencySymbol || "₵";
let currentRows = [];
let currentMethods = [];
let currentFilters = null;
let reportLoadRequest = 0;
let totals = { income: 0, expense: 0, profit: 0, count: 0 };
let reportState = "loading";
const money = (amount) => `${symbol}${Number(amount || 0).toFixed(2)}`;
const percent = (amount) => `${Number(amount || 0).toFixed(2)}%`;
const methodLabel = (method) => {
  const value = String(method || "").trim();
  if (!value) return "Not specified";
  const labels = { cash: "Cash", mobile: "MoMo", card: "Card", bank_transfer: "Bank Transfer", cheque: "Cheque" };
  return labels[value.toLowerCase()] || value.replace(/[_-]+/g, " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
};

function setReportState(state, message) {
  reportState = state;
  const printButton = document.getElementById("reportPrint");
  printButton.disabled = state !== "ready" && state !== "empty";
  document.getElementById("reportExport").disabled = state !== "ready" && state !== "empty";
  document.getElementById("reportPrintStatus").textContent = message;
}

function updatePrintPeriod() {
  const filters = currentFilters;
  const group = { daily: "Daily", weekly: "Weekly", monthly: "Monthly" }[filters.group];
  const { start, end, branch } = filters;
  document.getElementById("reportPrintPeriod").textContent =
    `${group} · ${start} to ${end}${branch ? ` · ${branch}` : " · All branches"}`;
  document.getElementById("reportPrintGenerated").textContent = new Date().toLocaleString();
}

async function loadReport() {
  const requestId = ++reportLoadRequest;
  const form = document.getElementById("reportFilter");
  currentFilters = {
    group: document.getElementById("reportGroup").value,
    start: document.getElementById("reportStart").value,
    end: document.getElementById("reportEnd").value,
    branch: document.getElementById("reportBranch").value,
  };
  setFormBusy(form, true, "Loading...");
  currentRows = [];
  currentMethods = [];
  totals = { income: 0, expense: 0, profit: 0, count: 0 };
  document.getElementById("reportIncome").textContent = money(0);
  document.getElementById("reportExpense").textContent = money(0);
  document.getElementById("reportProfit").textContent = money(0);
  document.getElementById("reportCount").textContent = "0";
  setEmptyRow(document.getElementById("reportRows"), 5, "Loading report data...");
  setEmptyRow(document.getElementById("paymentMethodRows"), 6, "Loading payment method summary...");
  document.getElementById("paymentMethodCards").replaceChildren();
  document.getElementById("paymentMethodHighlights").replaceChildren();
  setReportState("loading", "Loading report data. Printing is unavailable until the report is ready.");
  try {
    updatePrintPeriod();
    const result = await getFinancialReport(
      currentFilters.group,
      currentFilters.start,
      currentFilters.end,
      currentFilters.branch,
    );
    if (requestId !== reportLoadRequest) return;
    currentRows = result.rows;
    currentMethods = result.payment_methods || [];
    totals = result.totals;
    document.getElementById("reportIncome").textContent = money(totals.income);
    document.getElementById("reportExpense").textContent = money(totals.expense);
    document.getElementById("reportProfit").textContent = money(totals.profit);
    document.getElementById("reportCount").textContent = String(totals.count);
    const body = document.getElementById("reportRows");
    if (!currentRows.length) setEmptyRow(body, 5, "No transactions in the selected period.");
    else {
      body.replaceChildren(...currentRows.map((report) => {
        const row = document.createElement("tr");
        [report.period, money(report.income), money(report.expense), money(report.profit), report.count].forEach((value, index) => {
          const cell = document.createElement("td");
          cell.textContent = value;
          if (index > 0) cell.classList.add("text-end");
          row.append(cell);
        });
        return row;
      }));
    }
    renderPaymentMethods();
    setReportState(
      currentRows.length ? "ready" : "empty",
      currentRows.length
        ? `${currentRows.length} report periods loaded. Ready to print.`
        : "No matching transactions. This empty report is ready to print.",
    );
  } catch (error) {
    if (requestId !== reportLoadRequest) return;
    currentRows = [];
    currentMethods = [];
    setEmptyRow(document.getElementById("reportRows"), 5, "Report data could not be loaded.");
    setEmptyRow(document.getElementById("paymentMethodRows"), 6, "Payment method data could not be loaded.");
    document.getElementById("paymentMethodCards").replaceChildren();
    document.getElementById("paymentMethodHighlights").replaceChildren();
    setReportState("error", "Unable to load report data. Check your connection and try again.");
    showNotice(error.message || "Unable to load report.", "danger");
  } finally {
    if (requestId === reportLoadRequest) setFormBusy(form, false);
  }
}

function renderPaymentMethods() {
  const methodsByKey = new Map(currentMethods.map((item) => [item.payment_method || "unspecified", item]));
  for (const method of ["cash", "mobile", "card", "unspecified"]) {
    if (!methodsByKey.has(method)) methodsByKey.set(method, {
      payment_method: method, income_count: 0, income_total: 0, income_percent: 0,
      expense_count: 0, expense_total: 0, expense_percent: 0,
    });
  }
  currentMethods = [...methodsByKey.values()].sort((a, b) => a.payment_method.localeCompare(b.payment_method));
  const rows = currentMethods;
  const incomeTotal = rows.reduce((sum, row) => sum + Number(row.income_total || 0), 0);
  const expenseTotal = rows.reduce((sum, row) => sum + Number(row.expense_total || 0), 0);
  const incomeCount = rows.reduce((sum, row) => sum + Number(row.income_count || 0), 0);
  const expenseCount = rows.reduce((sum, row) => sum + Number(row.expense_count || 0), 0);
  const cards = document.getElementById("paymentMethodCards");
  const body = document.getElementById("paymentMethodRows");
  const highlights = document.getElementById("paymentMethodHighlights");
  if (!rows.length) {
    cards.replaceChildren();
    highlights.replaceChildren();
    setEmptyRow(body, 6, "No matching transactions.");
    return;
  }
  const methodContribution = (item) => Number(item.income_total || 0) - Number(item.expense_total || 0);
  const highest = rows.reduce((current, item) => methodContribution(item) > methodContribution(current) ? item : current);
  const highestLabel = incomeTotal || expenseTotal
    ? `${methodLabel(highest.payment_method)} · ${money(methodContribution(highest))}`
    : "No activity";
  const highlightItems = [
    ["Total Income", money(incomeTotal)],
    ["Total Expenses", money(expenseTotal)],
    ["Overall Net Contribution", money(incomeTotal - expenseTotal)],
    ["Highest Net Contribution", highestLabel],
  ];
  highlights.replaceChildren(...highlightItems.map(([label, value]) => {
    const column = document.createElement("div");
    column.className = "col-lg-3 col-sm-6";
    const card = document.createElement("div");
    card.className = "stat-card p-3 h-100";
    const title = document.createElement("div");
    title.className = "stat-title";
    title.textContent = label;
    const amount = document.createElement("div");
    amount.className = "stat-value fs-6 mt-2";
    amount.textContent = value;
    card.append(title, amount);
    column.append(card);
    return column;
  }));
  cards.replaceChildren(...rows.map((item) => {
    const column = document.createElement("div");
    column.className = "col-xl-3 col-md-4 col-sm-6";
    const card = document.createElement("div");
    card.className = "stat-card p-3 h-100";
    const title = document.createElement("div");
    title.className = "stat-title";
    title.textContent = methodLabel(item.payment_method);
    const income = document.createElement("div");
    income.className = "stat-value fs-6 mt-2";
    income.textContent = `Income · ${money(item.income_total)} (${Number(item.income_count || 0)})`;
    const expense = document.createElement("div");
    expense.className = "small text-muted mt-1";
    expense.textContent = `Expenses · ${money(item.expense_total)} (${Number(item.expense_count || 0)})`;
    const net = document.createElement("div");
    net.className = "small mt-1";
    net.textContent = `Net contribution · ${money(methodContribution(item))}`;
    card.append(title, income, expense, net);
    column.append(card);
    return column;
  }));
  const tableRows = rows.map((item) => ({
    label: methodLabel(item.payment_method),
    count: Number(item.income_count || 0) + Number(item.expense_count || 0),
    income: Number(item.income_total || 0),
    expense: Number(item.expense_total || 0),
    contribution: methodContribution(item),
    incomePercent: Number(item.income_percent || 0),
  }));
  tableRows.push({ label: "Total", count: incomeCount + expenseCount, income: incomeTotal,
    expense: expenseTotal, contribution: incomeTotal - expenseTotal, incomePercent: incomeTotal ? 100 : 0 });
  body.replaceChildren(...tableRows.map((item) => {
    const row = document.createElement("tr");
    if (item.label === "Total") row.className = "fw-bold";
    [item.label, item.count, money(item.income), money(item.expense), money(item.contribution),
      percent(item.incomePercent)].forEach((value, index) => {
      const cell = document.createElement("td");
      cell.textContent = String(value);
      if (index >= 2 && index <= 4) cell.classList.add("text-end");
      row.append(cell);
    });
    return row;
  }));
}

export async function initReports() {
  const now = new Date();
  const start = new Date(now.getFullYear(), now.getMonth(), 1);
  const localDate = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
  document.getElementById("reportStart").value = localDate(start);
  document.getElementById("reportEnd").value = localDate(now);
  let branches;
  try {
    branches = await listReportBranches();
  } catch (error) {
    setReportState("error", "Unable to load report filters. Check your connection and try again.");
    throw error;
  }
  const branchSelect = document.getElementById("reportBranch");
  branches.forEach((branch) => {
    const option = document.createElement("option");
    option.value = branch;
    option.textContent = branch;
    branchSelect.append(option);
  });
  document.getElementById("reportFilter").addEventListener("submit", (event) => {
    event.preventDefault();
    loadReport();
  });
  const markReportStale = () => {
    if (reportState === "loading" || reportState === "ready" || reportState === "empty") {
      reportLoadRequest++;
      setFormBusy(document.getElementById("reportFilter"), false);
      setReportState("stale", "Filters changed. Apply them to refresh the report before printing or exporting.");
    }
  };
  document.getElementById("reportFilter").addEventListener("input", markReportStale);
  document.getElementById("reportFilter").addEventListener("change", markReportStale);
  document.getElementById("reportExport").addEventListener("click", () => {
    if (reportState !== "ready" && reportState !== "empty") return;
    const reportCsv = Papa.unparse({ fields: ["Period", "Income", "Expense", "Net Contribution", "Transactions"], data: currentRows.map((row) => [row.period, row.income, row.expense, row.profit, row.count]) }, { escapeFormulae: true });
    const methodData = currentMethods.map((row) => [
      methodLabel(row.payment_method),
      Number(row.income_count || 0) + Number(row.expense_count || 0),
      row.income_total,
      row.expense_total,
      Number(row.income_total || 0) - Number(row.expense_total || 0),
      row.income_percent,
    ]);
    methodData.push([
      "Total",
      currentMethods.reduce((sum, row) => sum + Number(row.income_count || 0) + Number(row.expense_count || 0), 0),
      currentMethods.reduce((sum, row) => sum + Number(row.income_total || 0), 0),
      currentMethods.reduce((sum, row) => sum + Number(row.expense_total || 0), 0),
      currentMethods.reduce((sum, row) => sum + Number(row.income_total || 0) - Number(row.expense_total || 0), 0),
      currentMethods.some((row) => Number(row.income_total) > 0) ? 100 : 0,
    ]);
    const methodsCsv = Papa.unparse({ fields: ["Payment Method", "Transactions", "Income", "Expenses", "Net Contribution", "Income %"], data: methodData }, { escapeFormulae: true });
    const csv = `${reportCsv}\n\nPayment Method Summary\n${methodsCsv}`;
    const url = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8" }));
    const link = document.createElement("a");
    link.href = url;
    link.download = "financial-report.csv";
    link.click();
    URL.revokeObjectURL(url);
  });
  const printButton = document.getElementById("reportPrint");
  const finishPrint = () => {
    document.body.classList.remove("report-printing");
    printButton.disabled = reportState !== "ready" && reportState !== "empty";
    printButton.innerHTML = '<i class="bi bi-printer"></i> Print';
  };
  window.addEventListener("afterprint", finishPrint);
  printButton.addEventListener("click", async () => {
    if (reportState !== "ready" && reportState !== "empty") return;
    printButton.disabled = true;
    printButton.textContent = "Preparing...";
    document.body.classList.add("report-printing");
    try {
      updatePrintPeriod();
      await Promise.race([
        document.fonts?.ready || Promise.resolve(),
        new Promise((resolve) => setTimeout(resolve, 1500)),
      ]);
      await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
      window.print();
    } catch (error) {
      showNotice(error.message || "Unable to prepare the report for printing.", "danger");
    } finally {
      finishPrint();
    }
  });
  await loadReport();
}
