import Papa from "papaparse";
import { getFinancialReport, listReportBranches } from "./data.js";
import { setEmptyRow, setFormBusy, showNotice } from "./feedback.js";

const symbol = globalThis.APP_CONFIG?.currencySymbol || "₵";
let currentRows = [];
let totals = { income: 0, expense: 0, profit: 0, count: 0 };
let reportState = "loading";
const money = (amount) => `${symbol}${Number(amount || 0).toFixed(2)}`;

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
  totals = { income: 0, expense: 0, profit: 0, count: 0 };
  document.getElementById("reportIncome").textContent = money(0);
  document.getElementById("reportExpense").textContent = money(0);
  document.getElementById("reportProfit").textContent = money(0);
  document.getElementById("reportCount").textContent = "0";
  setEmptyRow(document.getElementById("reportRows"), 5, "Loading report data...");
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
    setReportState(
      currentRows.length ? "ready" : "empty",
      currentRows.length
        ? `${currentRows.length} report periods loaded. Ready to print.`
        : "No matching transactions. This empty report is ready to print.",
    );
  } catch (error) {
    currentRows = [];
    setEmptyRow(document.getElementById("reportRows"), 5, "Report data could not be loaded.");
    setReportState("error", "Unable to load report data. Check your connection and try again.");
    showNotice(error.message || "Unable to load report.", "danger");
  } finally {
    setFormBusy(form, false);
  }
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
    const csv = Papa.unparse({ fields: ["Period", "Income", "Expense", "Profit", "Transactions"], data: currentRows.map((row) => [row.period, row.income, row.expense, row.profit, row.count]) }, { escapeFormulae: true });
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
