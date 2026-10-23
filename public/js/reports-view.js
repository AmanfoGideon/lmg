import Papa from "papaparse";
import { getFinancialReport, listReportBranches } from "./data.js";
import { setEmptyRow, setFormBusy, showNotice } from "./feedback.js";

const symbol = globalThis.APP_CONFIG?.currencySymbol || "₵";
let currentRows = [];
let currentMethods = [];
let totals = { income: 0, expense: 0, profit: 0, count: 0 };
let reportState = "loading";
const money = (amount) => `${symbol}${Number(amount || 0).toFixed(2)}`;
const percent = (amount) => `${Number(amount || 0).toFixed(2)}%`;
const methodLabel = (method) => ({ cash: "Cash", mobile: "MoMo", card: "Card", unspecified: "Not specified" })[method] || `Other (${method})`;

function setReportState(state, message) {
  reportState = state;
  const printButton = document.getElementById("reportPrint");
  printButton.disabled = state !== "ready" && state !== "empty";
  document.getElementById("reportPrintStatus").textContent = message;
}

function updatePrintPeriod() {
  const group = document.getElementById("reportGroup").selectedOptions[0].textContent;
  const start = document.getElementById("reportStart").value;
  const end = document.getElementById("reportEnd").value;
  const branch = document.getElementById("reportBranch").value;
  document.getElementById("reportPrintPeriod").textContent =
    `${group} · ${start} to ${end}${branch ? ` · ${branch}` : " · All branches"}`;
}

async function loadReport() {
  const form = document.getElementById("reportFilter");
  setFormBusy(form, true, "Loading...");
  currentRows = [];
  currentMethods = [];
  totals = { income: 0, expense: 0, profit: 0, count: 0 };
  document.getElementById("reportIncome").textContent = money(0);
  document.getElementById("reportExpense").textContent = money(0);
  document.getElementById("reportProfit").textContent = money(0);
  document.getElementById("reportCount").textContent = "0";
  setEmptyRow(document.getElementById("reportRows"), 5, "Loading report data...");
  setEmptyRow(document.getElementById("paymentMethodRows"), 7, "Loading payment method summary...");
  document.getElementById("paymentMethodCards").replaceChildren();
  setReportState("loading", "Loading report data. Printing is unavailable until the report is ready.");
  try {
    updatePrintPeriod();
    const result = await getFinancialReport(
      document.getElementById("reportGroup").value,
      document.getElementById("reportStart").value,
      document.getElementById("reportEnd").value,
      document.getElementById("reportBranch").value,
    );
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
        [report.period, money(report.income), money(report.expense), money(report.profit), report.count].forEach((value) => {
          const cell = document.createElement("td");
          cell.textContent = value;
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
    currentRows = [];
    currentMethods = [];
    setEmptyRow(document.getElementById("reportRows"), 5, "Report data could not be loaded.");
    setEmptyRow(document.getElementById("paymentMethodRows"), 7, "Payment method data could not be loaded.");
    document.getElementById("paymentMethodCards").replaceChildren();
    setReportState("error", "Unable to load report data. Check your connection and try again.");
    showNotice(error.message || "Unable to load report.", "danger");
  } finally {
    setFormBusy(form, false);
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
  if (!rows.length) {
    cards.replaceChildren();
    setEmptyRow(body, 7, "No matching transactions.");
    return;
  }
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
    card.append(title, income, expense);
    column.append(card);
    return column;
  }));
  const tableRows = rows.map((item) => ({
    label: methodLabel(item.payment_method), incomeCount: Number(item.income_count || 0),
    income: Number(item.income_total || 0), incomePercent: Number(item.income_percent || 0),
    expenseCount: Number(item.expense_count || 0), expense: Number(item.expense_total || 0),
    expensePercent: Number(item.expense_percent || 0),
  }));
  tableRows.push({ label: "Total", incomeCount, income: incomeTotal, incomePercent: incomeTotal ? 100 : 0,
    expenseCount, expense: expenseTotal, expensePercent: expenseTotal ? 100 : 0 });
  body.replaceChildren(...tableRows.map((item) => {
    const row = document.createElement("tr");
    if (item.label === "Total") row.className = "fw-bold";
    [item.label, item.incomeCount, money(item.income), percent(item.incomePercent), item.expenseCount,
      money(item.expense), percent(item.expensePercent)].forEach((value) => {
      const cell = document.createElement("td");
      cell.textContent = String(value);
      row.append(cell);
    });
    return row;
  }));
}

export async function initReports() {
  const now = new Date();
  const start = new Date(now.getFullYear(), now.getMonth(), 1);
  document.getElementById("reportStart").value = start.toISOString().slice(0, 10);
  document.getElementById("reportEnd").value = now.toISOString().slice(0, 10);
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
  document.getElementById("reportExport").addEventListener("click", () => {
    const reportCsv = Papa.unparse({ fields: ["Period", "Income", "Expense", "Profit", "Transactions"], data: currentRows.map((row) => [row.period, row.income, row.expense, row.profit, row.count]) }, { escapeFormulae: true });
    const methodData = currentMethods.map((row) => [methodLabel(row.payment_method), row.income_count, row.income_total, row.income_percent, row.expense_count, row.expense_total, row.expense_percent]);
    methodData.push(["Total", currentMethods.reduce((sum, row) => sum + Number(row.income_count || 0), 0), currentMethods.reduce((sum, row) => sum + Number(row.income_total || 0), 0), currentMethods.some((row) => Number(row.income_total) > 0) ? 100 : 0, currentMethods.reduce((sum, row) => sum + Number(row.expense_count || 0), 0), currentMethods.reduce((sum, row) => sum + Number(row.expense_total || 0), 0), currentMethods.some((row) => Number(row.expense_total) > 0) ? 100 : 0]);
    const methodsCsv = Papa.unparse({ fields: ["Payment Method", "Income Count", "Income Total", "Income %", "Expense Count", "Expense Total", "Expense %"], data: methodData }, { escapeFormulae: true });
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
