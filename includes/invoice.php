<?php
declare(strict_types=1);

const INVOICE_MAX_CENTS = 9999999999; // DECIMAL(10,2) storage limit.

/** Parse a non-negative monetary value without using binary floats. */
function invoice_money_cents($value): ?int {
  if (!is_string($value) && !is_int($value)) return null;
  $value = trim((string)$value);
  if (!preg_match('/^(\d{1,16})(?:\.(\d{1,2}))?$/', $value, $parts)) return null;
  if (strlen($parts[1]) > strlen((string)intdiv(PHP_INT_MAX, 100))) return null;
  $whole = (int)$parts[1];
  $fraction = (int)str_pad($parts[2] ?? '', 2, '0');
  if ($whole > intdiv(PHP_INT_MAX - $fraction, 100)) return null;
  $cents = $whole * 100 + $fraction;
  return $cents;
}

function invoice_cents_decimal(int $cents): string {
  return intdiv($cents, 100).'.'.str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}

function invoice_quantity($value): ?int {
  if (!is_string($value) && !is_int($value)) return null;
  $value = trim((string)$value);
  if (!preg_match('/^[1-9]\d{0,8}$/', $value)) return null;
  $quantity = (int)$value;
  return $quantity <= 100000000 ? $quantity : null;
}

function invoice_valid_date($value, bool $allowEmpty = false): bool {
  if ($allowEmpty && ($value === null || $value === '')) return true;
  if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
  $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
  return $date !== false && $date->format('Y-m-d') === $value;
}

/** Validate and calculate line totals in cents. The existing fixed tax amount is preserved. */
function invoice_calculate(array $items, $taxValue): array {
  $tax = invoice_money_cents($taxValue);
  if ($tax === null || $tax > INVOICE_MAX_CENTS) throw new InvalidArgumentException('Tax must be a non-negative amount with at most two decimal places.');
  if (!$items || count($items) > 500) throw new InvalidArgumentException('Add between 1 and 500 invoice items.');

  $subtotal = 0;
  $parsed = [];
  foreach ($items as $item) {
    if (!is_array($item)) throw new InvalidArgumentException('Invoice item data is invalid.');
    $name = trim((string)($item['name'] ?? ''));
    $quantity = invoice_quantity($item['qty'] ?? null);
    $price = invoice_money_cents($item['price'] ?? null);
    $nameLength = preg_match_all('/./us', $name, $matches);
    if ($name === '' || $nameLength === false || $nameLength > 255 || $quantity === null || $price === null || $price > INVOICE_MAX_CENTS) {
      throw new InvalidArgumentException('Each item needs a description, positive whole quantity, and valid non-negative unit price.');
    }
    $line = $quantity * $price;
    if ($line > INVOICE_MAX_CENTS || $subtotal > INVOICE_MAX_CENTS - $line) {
      throw new InvalidArgumentException('Invoice total exceeds the supported amount.');
    }
    $subtotal += $line;
    $parsed[] = [
      'name' => $name,
      'qty' => $quantity,
      'price' => invoice_cents_decimal($price),
      'total' => invoice_cents_decimal($line),
    ];
  }
  if ($subtotal > INVOICE_MAX_CENTS - $tax) throw new InvalidArgumentException('Invoice total exceeds the supported amount.');
  return [
    'items' => $parsed,
    'subtotal_cents' => $subtotal,
    'tax_cents' => $tax,
    'total_cents' => $subtotal + $tax,
    'subtotal' => invoice_cents_decimal($subtotal),
    'tax' => invoice_cents_decimal($tax),
    'total' => invoice_cents_decimal($subtotal + $tax),
  ];
}

/** Insert invoice and items inside the caller's transaction, then finalize its unique public number. */
function invoice_insert(mysqli $conn, array $invoice, array $calculation): int {
  $temporaryNumber = 'TMP-'.bin2hex(random_bytes(16));
  $requestKey = (string)($invoice['request_key'] ?? bin2hex(random_bytes(16)));
  if (!preg_match('/^[a-f0-9]{32}$/', $requestKey)) throw new InvalidArgumentException('Invoice request key is invalid. Reload the page and try again.');
  $stmt = $conn->prepare("INSERT INTO invoices (invoice_number, client_id, invoice_date, due_date, subtotal, tax, total, notes, status, request_key) VALUES (?,?,?,?,?,?,?,?,'unpaid',?)");
  $clientId = (int)$invoice['client_id'];
  $invoiceDate = (string)$invoice['invoice_date'];
  $dueDate = $invoice['due_date'] ?: null;
  $notes = (string)($invoice['notes'] ?? '');
  $stmt->bind_param('sisssssss', $temporaryNumber, $clientId, $invoiceDate, $dueDate, $calculation['subtotal'], $calculation['tax'], $calculation['total'], $notes, $requestKey);
  $stmt->execute();
  $id = (int)$stmt->insert_id;
  $stmt->close();

  $number = 'INV-'.str_replace('-', '', $invoiceDate).'-'.str_pad((string)$id, 4, '0', STR_PAD_LEFT);
  $update = $conn->prepare('UPDATE invoices SET invoice_number=? WHERE id=?');
  $update->bind_param('si', $number, $id);
  $update->execute();
  $update->close();

  $itemStmt = $conn->prepare('INSERT INTO invoice_items (invoice_id, item_name, quantity, price, total) VALUES (?,?,?,?,?)');
  foreach ($calculation['items'] as $item) {
    $name = $item['name'];
    $quantity = $item['qty'];
    $price = $item['price'];
    $total = $item['total'];
    $itemStmt->bind_param('isiss', $id, $name, $quantity, $price, $total);
    $itemStmt->execute();
  }
  $itemStmt->close();

  if ($conn->query("SHOW TABLES LIKE 'products'")->num_rows > 0) {
    $stock = $conn->prepare('UPDATE products SET stock=GREATEST(stock-?,0) WHERE name=?');
    foreach ($calculation['items'] as $item) {
      $quantity = $item['qty'];
      $name = $item['name'];
      $stock->bind_param('is', $quantity, $name);
      $stock->execute();
    }
    $stock->close();
  }
  return $id;
}

/** Update an unpaid invoice and its items atomically, preserving its public invoice number. */
function invoice_update_unpaid(mysqli $conn, int $invoiceId, array $invoice, array $calculation): void {
  $conn->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
  $conn->begin_transaction();
  try {
    $find = $conn->prepare('SELECT invoice_number, client_id, invoice_date, due_date, subtotal, tax, total, notes FROM invoices WHERE id=? FOR UPDATE');
    $find->bind_param('i', $invoiceId);
    $find->execute();
    $existing = $find->get_result()->fetch_assoc();
    $find->close();
    if (!$existing) throw new InvalidArgumentException('Invoice not found.');

    $payment = $conn->prepare("SELECT id FROM transactions WHERE invoice_id=? AND type='payment' LIMIT 1");
    $payment->bind_param('i', $invoiceId);
    $payment->execute();
    $hasPayment = (bool)$payment->get_result()->fetch_row();
    $payment->close();
    if ($hasPayment) throw new InvalidArgumentException('Invoices with recorded payments cannot be edited.');

    $oldItemsStmt = $conn->prepare('SELECT item_name, quantity FROM invoice_items WHERE invoice_id=? ORDER BY id');
    $oldItemsStmt->bind_param('i', $invoiceId);
    $oldItemsStmt->execute();
    $oldItems = $oldItemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $oldItemsStmt->close();
    $oldStockQuantities = [];
    $newStockQuantities = [];
    foreach ($oldItems as $item) {
      $key = strtolower(trim((string)$item['item_name']));
      $oldStockQuantities[$key] = ($oldStockQuantities[$key] ?? 0) + (int)$item['quantity'];
    }
    foreach ($calculation['items'] as $item) {
      $key = strtolower(trim((string)$item['name']));
      $newStockQuantities[$key] = ($newStockQuantities[$key] ?? 0) + (int)$item['qty'];
    }
    ksort($oldStockQuantities);
    ksort($newStockQuantities);
    if ($oldStockQuantities !== $newStockQuantities && $conn->query("SHOW TABLES LIKE 'products'")->num_rows > 0) {
      $stockNames = array_unique(array_merge(array_keys($oldStockQuantities), array_keys($newStockQuantities)));
      $productCheck = $conn->prepare('SELECT id FROM products WHERE LOWER(name)=? LIMIT 1');
      foreach ($stockNames as $name) {
        $productCheck->bind_param('s', $name);
        $productCheck->execute();
        if ($productCheck->get_result()->fetch_row()) {
          $productCheck->close();
          throw new InvalidArgumentException('Line items linked to inventory cannot be changed because their original stock movement is not recorded. Other invoice details can still be edited.');
        }
      }
      $productCheck->close();
    }

    $clientId = (int)$invoice['client_id'];
    $client = $conn->prepare('SELECT id FROM clients WHERE id=? LIMIT 1');
    $client->bind_param('i', $clientId);
    $client->execute();
    $clientExists = (bool)$client->get_result()->fetch_row();
    $client->close();
    if (!$clientExists) throw new InvalidArgumentException('Select a valid client.');

    $update = $conn->prepare('UPDATE invoices SET client_id=?, invoice_date=?, due_date=?, subtotal=?, tax=?, total=?, notes=? WHERE id=?');
    $invoiceDate = (string)$invoice['invoice_date'];
    $dueDate = $invoice['due_date'] ?: null;
    $notes = (string)($invoice['notes'] ?? '');
    $update->bind_param('issssssi', $clientId, $invoiceDate, $dueDate, $calculation['subtotal'], $calculation['tax'], $calculation['total'], $notes, $invoiceId);
    $update->execute();
    $update->close();

    $removeItems = $conn->prepare('DELETE FROM invoice_items WHERE invoice_id=?');
    $removeItems->bind_param('i', $invoiceId);
    $removeItems->execute();
    $removeItems->close();
    $insertItem = $conn->prepare('INSERT INTO invoice_items (invoice_id, item_name, quantity, price, total) VALUES (?,?,?,?,?)');
    foreach ($calculation['items'] as $item) {
      $name = $item['name'];
      $quantity = $item['qty'];
      $price = $item['price'];
      $total = $item['total'];
      $insertItem->bind_param('isiss', $invoiceId, $name, $quantity, $price, $total);
      $insertItem->execute();
    }
    $insertItem->close();

    log_action($conn, 'invoice_update', (string)$existing['invoice_number'], [
      'entity_id'=>(string)$invoiceId,
      'old_values'=>['client_id'=>(int)$existing['client_id'],'invoice_date'=>$existing['invoice_date'],'due_date'=>$existing['due_date'],'subtotal'=>(string)$existing['subtotal'],'tax'=>(string)$existing['tax'],'total'=>(string)$existing['total'],'notes'=>$existing['notes']],
      'new_values'=>['client_id'=>$clientId,'invoice_date'=>$invoiceDate,'due_date'=>$dueDate,'subtotal'=>$calculation['subtotal'],'tax'=>$calculation['tax'],'total'=>$calculation['total'],'item_count'=>count($calculation['items'])]
    ]);
    $conn->commit();
  } catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    throw $e;
  }
}

/** Recompute the existing unpaid/partial/paid status from exact DECIMAL payment sums. */
function invoice_recalculate_status(mysqli $conn, int $invoiceId): array {
  $stmt = $conn->prepare("SELECT i.total, i.status, COALESCE(SUM(t.amount),0) AS paid FROM invoices i LEFT JOIN transactions t ON t.invoice_id=i.id AND t.type='payment' WHERE i.id=? GROUP BY i.id, i.total, i.status");
  $stmt->bind_param('i', $invoiceId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$row) throw new RuntimeException('Invoice not found.');

  $total = invoice_money_cents((string)$row['total']);
  $paid = invoice_money_cents((string)$row['paid']);
  if ($total === null || $paid === null) throw new RuntimeException('Invoice has an unsupported total.');
  $status = $paid <= 0 ? 'unpaid' : ($paid < $total ? 'partial' : 'paid');
  $update = $conn->prepare('UPDATE invoices SET status=? WHERE id=?');
  $update->bind_param('si', $status, $invoiceId);
  $update->execute();
  $update->close();
  return ['old_status'=>(string)$row['status'], 'status'=>$status, 'total_cents'=>$total, 'paid_cents'=>$paid, 'balance_cents'=>max(0, $total-$paid)];
}

/** Record one invoice payment, its income transaction, status and audit rows atomically. */
function invoice_record_payment(mysqli $conn, int $invoiceId, string $amountValue, ?string $method, string $description, string $date, string $requestKey): array {
  $amountCents = invoice_money_cents($amountValue);
  if ($amountCents === null || $amountCents <= 0 || $amountCents > INVOICE_MAX_CENTS) throw new InvalidArgumentException('Payment amount must be positive and valid.');
  if (!in_array($method, [null, 'cash', 'mobile', 'card'], true)) throw new InvalidArgumentException('Select a valid payment method.');
  if (!invoice_valid_date($date) || !preg_match('/^[a-f0-9]{32}$/', $requestKey)) throw new InvalidArgumentException('Payment date or request token is invalid.');

  // Use fresh committed reads after the invoice row lock waits behind another payment.
  $conn->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
  $conn->begin_transaction();
  try {
    $existing = $conn->prepare("SELECT id, invoice_id, amount, type FROM transactions WHERE request_key=? LIMIT 1");
    $existing->bind_param('s', $requestKey);
    $existing->execute();
    $existingRow = $existing->get_result()->fetch_assoc();
    $existing->close();
    if ($existingRow) {
      $samePayment = (int)$existingRow['invoice_id'] === $invoiceId
        && $existingRow['type'] === 'payment'
        && invoice_money_cents((string)$existingRow['amount']) === $amountCents;
      if (!$samePayment) throw new InvalidArgumentException('This payment token was already used for different payment details. Reload the invoice and try again.');
      $conn->commit();
      return ['transaction_id'=>(int)$existingRow['id'], 'duplicate'=>true, 'old_status'=>null, 'status'=>null, 'paid_cents'=>null, 'balance_cents'=>null];
    }

    $invoice = $conn->prepare('SELECT client_id, total, status FROM invoices WHERE id=? FOR UPDATE');
    $invoice->bind_param('i', $invoiceId);
    $invoice->execute();
    $row = $invoice->get_result()->fetch_assoc();
    $invoice->close();
    if (!$row || $row['client_id'] === null) throw new InvalidArgumentException('Invoice not found or has no client.');
    $retry = $conn->prepare("SELECT id, invoice_id, amount, type FROM transactions WHERE request_key=? LIMIT 1");
    $retry->bind_param('s', $requestKey);
    $retry->execute();
    $retryRow = $retry->get_result()->fetch_assoc();
    $retry->close();
    if ($retryRow) {
      $samePayment = (int)$retryRow['invoice_id'] === $invoiceId
        && $retryRow['type'] === 'payment'
        && invoice_money_cents((string)$retryRow['amount']) === $amountCents;
      if (!$samePayment) throw new InvalidArgumentException('This payment token was already used for different payment details. Reload the invoice and try again.');
      $conn->commit();
      return ['transaction_id'=>(int)$retryRow['id'], 'duplicate'=>true, 'old_status'=>null, 'status'=>null, 'paid_cents'=>null, 'balance_cents'=>null];
    }
    $totalCents = invoice_money_cents((string)$row['total']);
    if ($totalCents === null || $totalCents > INVOICE_MAX_CENTS) throw new RuntimeException('Invoice total is invalid.');

    $sum = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS paid FROM transactions WHERE invoice_id=? AND type='payment'");
    $sum->bind_param('i', $invoiceId);
    $sum->execute();
    $paidRow = $sum->get_result()->fetch_assoc();
    $sum->close();
    $paidCents = invoice_money_cents((string)$paidRow['paid']);
    if ($paidCents === null) throw new RuntimeException('Recorded payment total is outside the supported range.');
    $balanceCents = max(0, $totalCents - $paidCents);
    if ($balanceCents === 0 || $amountCents > $balanceCents) throw new InvalidArgumentException('Payment exceeds the invoice balance due.');

    $clientId = (int)$row['client_id'];
    $amount = invoice_cents_decimal($amountCents);
    $type = 'payment';
    $payment = $conn->prepare('INSERT INTO transactions (client_id, amount, type, payment_method, description, date, invoice_id, request_key) VALUES (?,?,?,?,?,?,?,?)');
    $payment->bind_param('isssssis', $clientId, $amount, $type, $method, $description, $date, $invoiceId, $requestKey);
    $payment->execute();
    $transactionId = (int)$payment->insert_id;
    $payment->close();

    $status = invoice_recalculate_status($conn, $invoiceId);
    audit_event($conn, [
      'action'=>'transaction.payment.created','module'=>'transactions','entity_type'=>'transaction','entity_id'=>(string)$transactionId,
      'description'=>'Invoice payment transaction recorded.',
      'new_values'=>['client_id'=>$clientId,'invoice_id'=>$invoiceId,'amount'=>$amount,'type'=>'payment','date'=>$date,'payment_method'=>$method]
    ]);
    log_action($conn, 'invoice_payment', (string)$invoiceId.'|'.$amount, [
      'entity_id'=>(string)$invoiceId,
      'old_values'=>['status'=>$status['old_status']],
      'new_values'=>['status'=>$status['status'],'paid_total'=>invoice_cents_decimal($status['paid_cents'])]
    ]);
    $conn->commit();
    return ['transaction_id'=>$transactionId,'duplicate'=>false,'old_status'=>$status['old_status'],'status'=>$status['status'],'paid_cents'=>$status['paid_cents'],'balance_cents'=>$status['balance_cents']];
  } catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    throw $e;
  }
}
