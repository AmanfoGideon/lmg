import Papa from "papaparse";
import { getFinancialReport, listReportBranches } from "./data.js";
import { setEmptyRow, setFormBusy, showNotice } from "./feedback.js";

const symbol = globalThis.APP_CONFIG?.currencySymbol || "₵";
let currentRows = [];
let totals = { income: 0, expense: 0, profit: 0, count: 0 };
const money = (amount) => `${symbol}${Number(amount || 0).toFixed(2)}`;

async function loadReport() {
  const form = document.getElementById("reportFilter");
  setFormBusy(form, true, "Loading...");
  try {
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
  } catch (error) {
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
  const branches = await listReportBranches();
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
  document.getElementById("reportPrint").addEventListener("click", () => window.print());
  await loadReport();
}