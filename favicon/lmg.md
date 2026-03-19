📘 BUSINESS PWA SYSTEM DOCUMENTATION (Developer Friendly)

Using: PHP REST API + MySQL + Bootstrap + JavaScript + PWA Support

1. SYSTEM OVERVIEW

A Progressive Web App (PWA) that stores client records, tracks all business transactions, generates invoices, and provides weekly and monthly summaries.

The system runs with:

Frontend: HTML + Bootstrap + JavaScript

Backend: Pure PHP REST API

Database: MySQL

Hosting: WAMP (local) → later to cPanel/VPS

PWA Layer: manifest.json + service-worker.js

Offline Support: frontend caching + local storage (optional advanced: IndexedDB)

2. MAIN FEATURES
   ✔ Client Management

Add/edit/delete clients

Search clients

View client transaction history

✔ Transaction Recording

Add transactions (income, expense, payment, invoice)

Filter by date, type, client

Attach files (optional future feature)

✔ Weekly & Monthly Summaries

Total income

Total expenses

Profit summary

Number of transactions

Best-performing clients

Export summary to PDF

✔ Invoice Generation

Create invoice from client + items

Auto calculate totals

Save invoice to database

Generate printable PDF invoice

Invoice number format: INV-YYYYMMDD-XXXX

✔ Dashboard

Today totals

Weekly totals

Monthly totals

Graphs (JS charts)

✔ PWA Features

Add to Home Screen

Offline caching

Works without internet

3. TECHNICAL ARCHITECTURE
   Frontend (Bootstrap + JS)
   ↓ AJAX/Fetch
   PHP REST API (business-pwa/api/)
   ↓
   MySQL Database (WAMP)

4. DATABASE STRUCTURE
   Table: clients
   CREATE TABLE clients (
   id INT AUTO_INCREMENT PRIMARY KEY,
   full_name VARCHAR(255) NOT NULL,
   phone VARCHAR(50),
   email VARCHAR(255),
   address TEXT,
   created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
   );

Table: transactions
CREATE TABLE transactions (
id INT AUTO_INCREMENT PRIMARY KEY,
client_id INT NOT NULL,
amount DECIMAL(10,2),
type ENUM('income', 'expense', 'invoice', 'payment'),
description TEXT,
date DATE NOT NULL,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
FOREIGN KEY (client_id) REFERENCES clients(id)
);

Table: invoices
CREATE TABLE invoices (
id INT AUTO_INCREMENT PRIMARY KEY,
invoice_number VARCHAR(50),
client_id INT,
invoice_date DATE,
due_date DATE,
subtotal DECIMAL(10,2),
tax DECIMAL(10,2),
total DECIMAL(10,2),
notes TEXT,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
FOREIGN KEY (client_id) REFERENCES clients(id)
);

Table: invoice_items
CREATE TABLE invoice_items (
id INT AUTO_INCREMENT PRIMARY KEY,
invoice_id INT,
item_name VARCHAR(255),
quantity INT,
price DECIMAL(10,2),
total DECIMAL(10,2),
FOREIGN KEY (invoice_id) REFERENCES invoices(id)
);

5. API ENDPOINTS

All endpoints will be under:

/api/

CLIENTS API
✔ GET all clients
GET /api/clients

✔ GET single client
GET /api/clients?id=1

✔ POST create client
POST /api/clients/create

Body:

full_name
phone
email
address

✔ PUT update client
POST /api/clients/update

✔ DELETE client
POST /api/clients/delete

TRANSACTIONS API
✔ GET transactions
GET /api/transactions

✔ POST add transaction
POST /api/transactions/create

Body:

client_id
amount
type
description
date

INVOICE API
✔ Create invoice
POST /api/invoices/create

✔ Get all invoices
GET /api/invoices

✔ Get invoice details with items
GET /api/invoices/view?id=1

✔ Generate invoice number (format: INV-20250130-0001)
auto-generated in PHP

6. FRONTEND STRUCTURE
   public/
   index.html
   dashboard.html
   clients.html
   transactions.html
   invoices.html
   invoice-create.html
   css/
   js/
   service-worker.js
   manifest.json

Bootstrap Pages:

Dashboard

Clients CRUD

Transactions CRUD

Invoice Builder

Invoice Viewer

7. PWA DOCUMENTATION
   manifest.json
   {
   "name": "Business Manager",
   "short_name": "BizManager",
   "start_url": "/public/index.html",
   "display": "standalone",
   "background_color": "#ffffff",
   "theme_color": "#0d6efd",
   "icons": [
   {
   "src": "/public/icons/icon-192.png",
   "sizes": "192x192",
   "type": "image/png"
   },
   {
   "src": "/public/icons/icon-512.png",
   "sizes": "512x512",
   "type": "image/png"
   }
   ]
   }

service-worker.js
self.addEventListener("install", (event) => {
event.waitUntil(
caches.open("biz-cache").then(cache => {
return cache.addAll([
"/public/index.html",
"/public/css/style.css",
"/public/js/app.js"
]);
})
);
});

self.addEventListener("fetch", (event) => {
event.respondWith(
caches.match(event.request).then(response => {
return response || fetch(event.request);
})
);
});

8. WEEKLY SUMMARY (MySQL Query)
   SELECT
   SUM(amount) FILTER (WHERE type='income') AS weekly_income,
   SUM(amount) FILTER (WHERE type='expense') AS weekly_expense,
   COUNT(\*) AS total_transactions
   FROM transactions
   WHERE date >= CURDATE() - INTERVAL 7 DAY;

9. MONTHLY SUMMARY
   SELECT
   SUM(amount) AS total_amount,
   type,
   COUNT(\*) AS count
   FROM transactions
   WHERE MONTH(date) = MONTH(CURDATE())
   AND YEAR(date) = YEAR(CURDATE())
   GROUP BY type;

10. INVOICE GENERATION LOGIC
    Invoice Number
    INV-YYYYMMDD-0001

Generated inside PHP:

$invoice_number = "INV-" . date("Ymd") . "-" . str_pad($nextId, 4, "0", STR_PAD_LEFT);

Invoice PDF

We will use:
HTML template + window.print()
or
PHP + DOMPDF library (better)
