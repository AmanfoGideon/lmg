import mysql from "mysql2/promise";
import { createClient } from "@supabase/supabase-js";
import { readFile } from "node:fs/promises";

const APPLY = process.argv.includes("--apply");
const sourceTables = [
  "users",
  "clients",
  "invoices",
  "products",
  "invoice_items",
  "transactions",
  "client_interactions",
  "user_logs",
  "sms_messages",
];
const destinationTables = sourceTables.filter((table) => table !== "users");
const allowedColumns = {
  clients: ["id", "full_name", "phone", "email", "address", "created_at"],
  invoices: ["id", "invoice_number", "client_id", "invoice_date", "due_date", "subtotal", "tax", "total", "notes", "status", "branch", "created_at"],
  products: ["id", "name", "sku", "price", "stock", "min_stock", "created_at"],
  invoice_items: ["id", "invoice_id", "item_name", "quantity", "price", "total"],
  transactions: ["id", "client_id", "amount", "type", "description", "date", "created_at", "payment_method", "invoice_id", "branch"],
  client_interactions: ["id", "client_id", "note", "created_at"],
  user_logs: ["id", "user_id", "action", "details", "created_at"],
  sms_messages: ["id", "client_id", "phone", "message", "status", "provider", "provider_message_id", "error", "created_at", "scheduled_for", "schedule_offset", "retry_count", "next_retry_at", "last_attempt_at"],
};
const timestampFields = new Set(["created_at", "last_login", "scheduled_for", "next_retry_at", "last_attempt_at"]);

function required(name) {
  const value = process.env[name];
  if (!value) throw new Error(`Missing required environment variable: ${name}`);
  return value;
}

function mysqlConfig() {
  return {
    host: required("MYSQL_HOST"),
    port: Number(process.env.MYSQL_PORT || 3306),
    database: required("MYSQL_DATABASE"),
    user: required("MYSQL_USER"),
    password: required("MYSQL_PASSWORD"),
    dateStrings: true,
    multipleStatements: false,
  };
}

function toPostgresRow(table, source, userIdMap) {
  const allowed = allowedColumns[table];
  const output = {};
  for (const column of allowed) {
    if (!(column in source)) continue;
    let value = source[column];
    if (table === "user_logs" && column === "user_id") {
      value = userIdMap.get(Number(value));
      if (!value) throw new Error(`No migrated Auth account is mapped to legacy user ${source.user_id}.`);
    }
    if (timestampFields.has(column) && typeof value === "string" && value !== "") {
      if (table === "sms_messages" && column === "scheduled_for" && source.schedule_offset) {
        if (!/^[+-]\d{2}:\d{2}$/.test(source.schedule_offset)) {
          throw new Error(`SMS row ${source.id} has an invalid schedule offset.`);
        }
        const scheduled = new Date(`${value.replace(" ", "T")}${source.schedule_offset}`);
        if (Number.isNaN(scheduled.getTime())) throw new Error(`SMS row ${source.id} has an invalid scheduled time.`);
        value = scheduled.toISOString();
      } else {
        value = `${value.replace(" ", "T")}Z`;
      }
    }
    if (column === "status" && table === "sms_messages" && value === "sending") value = "queued";
    output[column] = value;
  }

  if (table === "invoices" && !("status" in output)) output.status = "unpaid";
  if (table === "sms_messages") {
    output.provider ??= "smsonline";
    output.status ??= "queued";
    output.retry_count ??= 0;
  }
  return output;
}

async function readTable(connection, table) {
  let cursor = 0;
  const rows = [];
  while (true) {
    const [batch] = await connection.query(`SELECT * FROM \`${table}\` WHERE id > ? ORDER BY id LIMIT 500`, [cursor]);
    rows.push(...batch);
    if (batch.length < 500) break;
    cursor = Number(batch.at(-1).id);
  }
  return rows;
}

async function ensureEmptyTarget(client) {
  const { data: authUsers, error: authError } = await client.auth.admin.listUsers({ page: 1, perPage: 1 });
  if (authError) throw new Error(`Cannot inspect Supabase Auth users: ${authError.message}`);
  if (authUsers.users.length !== 0) throw new Error("The Supabase Auth project is not empty. This importer requires a fresh target.");
  for (const table of ["profiles", ...destinationTables]) {
    const { count, error } = await client.from(table).select("id", { count: "exact", head: true });
    if (error) throw new Error(`Cannot inspect Supabase table ${table}: ${error.message}`);
    if (count !== 0) throw new Error(`Target table ${table} is not empty. This importer refuses to overwrite or merge production data.`);
  }
}

async function batchInsert(client, table, rows) {
  for (let offset = 0; offset < rows.length; offset += 250) {
    const batch = rows.slice(offset, offset + 250);
    const { error } = await client.from(table).insert(batch);
    if (error) throw new Error(`Import stopped while writing ${table} at row ${offset}: ${error.message}`);
  }
}

async function verifyCounts(client, expected) {
  const actual = {};
  for (const [table, count] of Object.entries(expected)) {
    const { count: targetCount, error } = await client.from(table).select("id", { count: "exact", head: true });
    if (error) throw new Error(`Cannot verify target table ${table}: ${error.message}`);
    actual[table] = targetCount;
    if (targetCount !== count) throw new Error(`Row count mismatch for ${table}: source ${count}, target ${targetCount}.`);
  }
  return actual;
}

function cents(value) {
  return Math.round(Number(value ?? 0) * 100);
}

function transactionTotals(rows) {
  const totals = { income: 0, expense: 0, invoice: 0, payment: 0 };
  for (const row of rows) totals[row.type] += cents(row.amount);
  return totals;
}

async function fetchTargetRows(client, table, columns) {
  const rows = [];
  for (let offset = 0; ; offset += 1000) {
    const { data, error } = await client.from(table).select(columns).order("id").range(offset, offset + 999);
    if (error) throw new Error(`Cannot verify ${table} totals: ${error.message}`);
    rows.push(...data);
    if (data.length < 1000) return rows;
  }
}

async function verifyFinancialTotals(client, source) {
  const targetTransactions = await fetchTargetRows(client, "transactions", "type, amount");
  const targetInvoices = await fetchTargetRows(client, "invoices", "total");
  const targetItems = await fetchTargetRows(client, "invoice_items", "total");
  const sourceTotals = {
    transactions: transactionTotals(source.transactions),
    invoiceTotal: source.invoices.reduce((sum, row) => sum + cents(row.total), 0),
    invoiceItemTotal: source.invoice_items.reduce((sum, row) => sum + cents(row.total), 0),
  };
  const targetTotals = {
    transactions: transactionTotals(targetTransactions),
    invoiceTotal: targetInvoices.reduce((sum, row) => sum + cents(row.total), 0),
    invoiceItemTotal: targetItems.reduce((sum, row) => sum + cents(row.total), 0),
  };
  if (JSON.stringify(sourceTotals) !== JSON.stringify(targetTotals)) {
    throw new Error(`Financial total mismatch. Source ${JSON.stringify(sourceTotals)}; target ${JSON.stringify(targetTotals)}.`);
  }
  return sourceTotals;
}

const connection = await mysql.createConnection(mysqlConfig());
let completed = false;
try {
  await connection.query("SET time_zone = '+00:00'");
  const [tableRows] = await connection.query("SHOW TABLES");
  const presentTables = new Set(Object.values(tableRows[0] ?? {}));
  for (const table of sourceTables) {
    if (!presentTables.has(table)) throw new Error(`Source MySQL table is missing: ${table}`);
  }

  const source = {};
  for (const table of sourceTables) source[table] = await readTable(connection, table);
  const counts = Object.fromEntries(sourceTables.map((table) => [table, source[table].length]));
  console.log(JSON.stringify({ mode: APPLY ? "apply" : "dry-run", source_counts: counts }, null, 2));

  const client = createClient(required("SUPABASE_URL"), required("SUPABASE_SERVICE_ROLE_KEY"), {
    auth: { persistSession: false, autoRefreshToken: false },
  });
  await ensureEmptyTarget(client);

  if (!APPLY) {
    console.log("Dry run complete. No Supabase data was changed. Review the source dump and user map, then repeat with --apply.");
    completed = true;
  } else {
    const mappingPath = required("LEGACY_USER_MAP_FILE");
    const userMappings = JSON.parse(await readFile(mappingPath, "utf8"));
    const mappingByLegacyId = new Map(userMappings.map((entry) => [Number(entry.legacy_user_id), entry]));
    for (const user of source.users) {
      const mapping = mappingByLegacyId.get(Number(user.id));
      if (mapping?.verified !== true || !mapping.email) {
        throw new Error(`Legacy user ${user.id} needs a verified email mapping before Auth provisioning.`);
      }
    }

    const userIdMap = new Map();
    for (const legacyUser of source.users) {
      const mapping = mappingByLegacyId.get(Number(legacyUser.id));
      const { data, error } = await client.auth.admin.inviteUserByEmail(mapping.email, {
        data: { full_name: legacyUser.full_name, username: legacyUser.username },
        redirectTo: required("APP_REDIRECT_URL"),
      });
      if (error || !data.user) throw new Error(`Could not invite legacy account ${legacyUser.id}: ${error?.message ?? "no user returned"}`);
      userIdMap.set(Number(legacyUser.id), data.user.id);
      const { error: profileError } = await client.from("profiles").update({
        legacy_user_id: legacyUser.id,
        username: legacyUser.username,
        full_name: legacyUser.full_name,
        email: mapping.email,
        phone: legacyUser.phone || null,
        role: legacyUser.role === "admin" ? "admin" : "staff",
        status: legacyUser.status === "inactive" ? "inactive" : "active",
        branch: legacyUser.branch || null,
      }).eq("id", data.user.id);
      if (profileError) throw new Error(`Could not provision legacy profile ${legacyUser.id}: ${profileError.message}`);
    }

    const expected = { profiles: source.users.length };
    for (const table of destinationTables) {
      const records = source[table].map((row) => toPostgresRow(table, row, userIdMap));
      await batchInsert(client, table, records);
      expected[table] = records.length;
    }
    const { error: sequenceError } = await client.rpc("reset_business_identity_sequences");
    if (sequenceError) throw new Error(`Could not reset target identity sequences: ${sequenceError.message}`);
    const actual = await verifyCounts(client, expected);
    const totals = await verifyFinancialTotals(client, source);
    console.log(JSON.stringify({ verified_target_counts: actual, verified_financial_totals_in_cents: totals }, null, 2));
    completed = true;
  }
} finally {
  await connection.end();
  if (!completed) console.error("Migration did not complete. Do not rerun against this target; restore the empty Supabase project or manually reconcile partial invitations/data first.");
}