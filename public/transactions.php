<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/log.php";
require_once "../includes/payment_methods.php";
require_once "../includes/invoice.php";
require_once "../includes/suppliers.php";

$message = '';
$createdSupplierId = null;
$transactionSupplierValid = true;
$transactionRequestKey = bin2hex(random_bytes(16));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) {
    $message = 'Invalid request token';
  } else {
  $action = $_POST['action'] ?? '';
  if (isset($_POST['request_key'])) $transactionRequestKey = (string)$_POST['request_key'];
  if ($action === 'create') {
    $type = is_string($_POST['type'] ?? null) ? $_POST['type'] : '';
    $amountInput = is_string($_POST['amount'] ?? null) ? $_POST['amount'] : '';
    $amountCents = invoice_money_cents($amountInput);
    $payment_method = is_string($_POST['payment_method'] ?? null) && $_POST['payment_method'] !== '' ? $_POST['payment_method'] : null;
    $date = is_string($_POST['date'] ?? null) && $_POST['date'] !== '' ? $_POST['date'] : date('Y-m-d');
    if (!in_array($type, ['income','expense','invoice','payment'], true) || $amountCents === null || $amountCents <= 0 || $amountCents > INVOICE_MAX_CENTS || !invoice_valid_date($date) || !in_array($payment_method, [null,'cash','mobile','card'], true)) {
      $message = 'Choose a valid transaction type, positive amount, date, and payment method.';
    } else {
    $amount = invoice_cents_decimal($amountCents);
    $client_id = (int)($_POST['client_id'] ?? 0);
    $client_name = is_string($_POST['client_name'] ?? null) ? trim($_POST['client_name']) : '';
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    if (!($type === 'payment' && $invoice_id > 0) && $client_id <= 0 && $client_name !== '') {
      $find = $conn->prepare("SELECT id FROM clients WHERE full_name=? LIMIT 1");
      $find->bind_param('s', $client_name);
      $find->execute();
      $find->bind_result($foundId);
      if ($find->fetch()) { $client_id = (int)$foundId; }
      $find->close();
      if ($client_id <= 0) {
        $conn->begin_transaction();
        $ins = $conn->prepare("INSERT INTO clients (full_name, phone, email, address) VALUES (?,?,?,?)");
        $empty = '';
        $ins->bind_param('ssss', $client_name, $empty, $empty, $empty);
        if ($ins->execute()) {
          $client_id = (int)$ins->insert_id;
          log_action($conn, 'client_create', $client_name, [
            'entity_id' => (string)$client_id,
            'new_values' => ['full_name'=>$client_name]
          ]);
          $conn->commit();
        } else { $conn->rollback(); }
        $ins->close();
      }
    }
    $description = is_string($_POST['description'] ?? null) ? trim($_POST['description']) : '';
    if ($type === 'payment' && $invoice_id > 0) {
      try {
        $result = invoice_record_payment($conn, $invoice_id, (string)($_POST['amount'] ?? ''), $payment_method, $description, (string)$date, $transactionRequestKey);
        $message = $result['duplicate'] ? 'Payment already recorded' : 'Payment added';
        $transactionRequestKey = bin2hex(random_bytes(16));
      } catch (InvalidArgumentException $e) {
        $message = $e->getMessage();
      } catch (Throwable $e) {
        $message = 'Payment could not be recorded. Please try again.';
        error_log('Invoice payment failed: '.$e->getMessage());
      }
    } elseif ($client_id > 0 || ($type === 'expense' && (int)($_POST['supplier_id'] ?? 0) > 0)) {
      $supplier_id = $type === 'expense' && (int)($_POST['supplier_id'] ?? 0) > 0 ? (int)$_POST['supplier_id'] : null;
      if ($supplier_id !== null) {
        $supplierCheck = $conn->prepare("SELECT id FROM suppliers WHERE id=? AND status='active'");
        $supplierCheck->bind_param('i', $supplier_id);
        $supplierCheck->execute();
        $supplierExists = (bool)$supplierCheck->get_result()->fetch_row();
        $supplierCheck->close();
        if (!$supplierExists) {
          $transactionSupplierValid = false;
          $message = 'Select an active supplier or choose No Supplier.';
        }
      }
      if (!$transactionSupplierValid) {
        // Keep the validation error and do not write an invalid supplier link.
      } elseif ($client_id <= 0 && !($type === 'expense' && $supplier_id !== null)) {
        $message = $message ?: 'Specify a client by name';
      } else {
      $transactionClientId = $client_id > 0 ? $client_id : null;
      $conn->begin_transaction();
      $stmt = $conn->prepare("INSERT INTO transactions (client_id, supplier_id, amount, type, payment_method, description, date) VALUES (?,?,?,?,?,?,?)");
      $stmt->bind_param('iidssss', $transactionClientId, $supplier_id, $amount, $type, $payment_method, $description, $date);
      $message = $stmt->execute() ? 'Transaction added' : 'Error adding transaction';
      if ($message === 'Transaction added') {
        log_action($conn, 'transaction_create', (string)($client_id ?: 'supplier-only').'|'.$type.'|'.$amount, [
          'action' => 'transaction.'.($type === 'income' ? 'income' : ($type === 'expense' ? 'expense' : $type)).'.created',
          'entity_id' => (string)$stmt->insert_id,
          'new_values' => ['client_id'=>$transactionClientId,'supplier_id'=>$supplier_id,'amount'=>$amount,'type'=>$type,'payment_method'=>$payment_method,'description'=>$description,'date'=>$date]
        ]);
        $conn->commit();
      } else {
        $conn->rollback();
      }
      $stmt->close();
      }
    } else {
      $message = 'Specify a client by name';
    }
    }
    } elseif ($action === 'create_supplier') {
      try {
        $supplier = [
          'business_name' => $_POST['business_name'] ?? '',
          'contact_person' => $_POST['contact_person'] ?? '',
          'phone' => $_POST['phone'] ?? '',
          'email' => $_POST['email'] ?? '',
          'address' => $_POST['address'] ?? '',
          'category' => $_POST['category'] ?? '',
          'tax_id' => $_POST['tax_id'] ?? '',
          'notes' => $_POST['notes'] ?? '',
          'status' => 'active'
        ];
        $supplier_id = supplier_create($conn, $supplier);
        $createdSupplierId = $supplier_id;
        $message = 'Supplier created successfully';
      } catch (InvalidArgumentException $e) {
        $message = $e->getMessage();
      } catch (Throwable $e) {
        $message = 'Supplier could not be created. Please try again.';
        error_log('Supplier creation failed: ' . $e->getMessage());
      }
      if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $createdSupplierId !== null, 'id' => $createdSupplierId, 'business_name' => $supplier['business_name'] ?? '', 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
      }
    } elseif ($action === 'delete') {
      $txId = (int)($_POST['id'] ?? 0);
      if ($txId > 0) {
        $q = $conn->prepare("SELECT id, client_id, type, amount, date, payment_method, description, invoice_id FROM transactions WHERE id=? LIMIT 1");
        $q->bind_param('i', $txId);
        $q->execute();
        $q->bind_result($foundId, $foundClientId, $foundType, $foundAmount, $foundDate, $foundMethod, $foundDescription, $foundInv);
        if ($q->fetch()) {
          $q->close();
          if ($foundType === 'payment' && $foundInv !== null) {
            $message = 'Invoice payments are preserved and cannot be deleted from the transaction list. Correct the payment or cancel the invoice with a reason.';
          } else {
          $conn->begin_transaction();
          $del = $conn->prepare("DELETE FROM transactions WHERE id=?");
          $del->bind_param('i', $txId);
          if ($del->execute() && $del->affected_rows > 0) {
            $message = 'Transaction deleted';
            log_action($conn, 'transaction_delete', (string)$txId, [
              'entity_id' => (string)$txId,
              'old_values' => ['client_id'=>$foundClientId,'amount'=>$foundAmount,'type'=>$foundType,'date'=>$foundDate,'payment_method'=>$foundMethod,'description'=>$foundDescription,'invoice_id'=>$foundInv]
            ]);
            // Recalculate invoice status if this was an invoice payment
            if ($foundType === 'payment' && $foundInv !== null) {
              $invoiceStatus = invoice_recalculate_status($conn, (int)$foundInv);
              if ($invoiceStatus['status'] !== $invoiceStatus['old_status']) {
                audit_event($conn, [
                  'action'=>'invoice.status.updated','module'=>'invoices','entity_type'=>'invoice','entity_id'=>(string)$foundInv,
                  'description'=>'Invoice payment status recalculated after payment deletion.',
                  'old_values'=>['status'=>$invoiceStatus['old_status']],
                  'new_values'=>['status'=>$invoiceStatus['status'],'paid_total'=>invoice_cents_decimal($invoiceStatus['paid_cents']),'balance'=>invoice_cents_decimal($invoiceStatus['balance_cents'])]
                ]);
              }
            }
            $conn->commit();
          } else {
            $conn->rollback();
            $message = 'Error deleting transaction';
          }
          $del->close();
          }
        } else {
          $q->close();
          $message = 'Transaction not found';
        }
      }
    } elseif ($action === 'update') {
      $txId = (int)($_POST['id'] ?? 0);
      if ($txId > 0) {
        $oldStmt = $conn->prepare("SELECT client_id, supplier_id, amount, type, date, payment_method, description, invoice_id FROM transactions WHERE id=? LIMIT 1");
        $oldStmt->bind_param('i', $txId);
        $oldStmt->execute();
        $oldResult = $oldStmt->get_result();
        $oldTx = $oldResult->fetch_assoc() ?: null;
        $oldStmt->close();
        $amountValue = (string)($_POST['amount'] ?? '');
        $amountCents = invoice_money_cents($amountValue);
        $amount = $amountCents !== null ? invoice_cents_decimal($amountCents) : '0.00';
        $type = is_string($_POST['type'] ?? null) ? $_POST['type'] : '';
        $payment_method = is_string($_POST['payment_method'] ?? null) && $_POST['payment_method'] !== '' ? $_POST['payment_method'] : null;
        $description = is_string($_POST['description'] ?? null) ? trim($_POST['description']) : '';
        $date = is_string($_POST['date'] ?? null) && $_POST['date'] !== '' ? $_POST['date'] : date('Y-m-d');
        $invoice_id = (int)($_POST['invoice_id'] ?? 0);
        $supplier_id = $type === 'expense' && (int)($_POST['supplier_id'] ?? 0) > 0 ? (int)$_POST['supplier_id'] : null;
        if (!in_array($type, ['income','expense','invoice','payment'], true)) { $type = ''; }
        if ($oldTx && $oldTx['type'] === 'payment' && !empty($oldTx['invoice_id'])) {
          if ($type !== 'payment' || $invoice_id !== (int)$oldTx['invoice_id']) {
            $message = 'An invoice payment must remain linked to its original invoice. Correct its amount or details instead.';
          } else {
            try {
              invoice_update_payment($conn, $txId, $amountValue, $payment_method !== '' ? $payment_method : null, $description, (string)$date);
              $message = 'Invoice payment corrected';
            } catch (InvalidArgumentException $e) {
              $message = $e->getMessage();
            } catch (Throwable $e) {
              $message = 'Invoice payment could not be corrected. Please try again.';
              error_log('Invoice payment correction failed: '.$e->getMessage());
            }
          }
        } elseif ($type === 'payment' && $invoice_id > 0) {
          $message = 'Record invoice payments from the invoice page so the balance and payment history stay synchronized.';
        } elseif (!in_array($type, ['income','expense','invoice','payment'], true) || $amountCents === null || $amountCents <= 0 || $amountCents > INVOICE_MAX_CENTS || !invoice_valid_date((string)$date) || !in_array($payment_method, [null,'cash','mobile','card'], true)) {
          $message = 'Choose a valid transaction type, positive amount, date, and payment method.';
        } else {
        try {
        $conn->begin_transaction();
        if ($type === 'expense' && $supplier_id !== null) {
          $supplierCheck = $conn->prepare("SELECT id FROM suppliers WHERE id=? AND (status='active' OR id=?)");
          $supplierCheck->bind_param('ii', $supplier_id, $supplier_id);
          $supplierCheck->execute();
          $supplierExists = (bool)$supplierCheck->get_result()->fetch_row();
          $supplierCheck->close();
          if (!$supplierExists) throw new InvalidArgumentException('Select a valid supplier.');
        }
        $upd = $conn->prepare("UPDATE transactions SET amount=?, type=?, payment_method=?, description=?, date=?, invoice_id=?, supplier_id=? WHERE id=?");
        $upd->bind_param('dssssiii', $amount, $type, $payment_method, $description, $date, $invoice_id, $supplier_id, $txId);
        if ($upd->execute() && $upd->affected_rows > 0) {
          $message = 'Transaction updated';
          if ($oldTx) {
            log_action($conn, 'transaction_update', (string)$txId.'|'.$type.'|'.$amount, [
              'entity_id' => (string)$txId,
              'old_values' => $oldTx,
              'new_values' => ['client_id'=>(int)$oldTx['client_id'],'supplier_id'=>$supplier_id,'amount'=>$amount,'type'=>$type,'date'=>$date,'payment_method'=>$payment_method,'description'=>$description,'invoice_id'=>$invoice_id ?: null]
            ]);
          }
          // Recalculate invoice status if this was or is an invoice payment
          $oldInvoiceId = $oldTx ? (int)($oldTx['invoice_id'] ?? 0) : 0;
          if ($oldInvoiceId > 0 && $oldTx['type'] === 'payment') {
            $oldInvoiceStatus = invoice_recalculate_status($conn, $oldInvoiceId);
            if ($oldInvoiceStatus['status'] !== $oldInvoiceStatus['old_status']) {
              audit_event($conn, [
                'action'=>'invoice.status.updated','module'=>'invoices','entity_type'=>'invoice','entity_id'=>(string)$oldInvoiceId,
                'description'=>'Invoice payment status recalculated after transaction update.',
                'old_values'=>['status'=>$oldInvoiceStatus['old_status']],
                'new_values'=>['status'=>$oldInvoiceStatus['status'],'paid_total'=>invoice_cents_decimal($oldInvoiceStatus['paid_cents']),'balance'=>invoice_cents_decimal($oldInvoiceStatus['balance_cents'])]
              ]);
            }
          }
          if ($invoice_id > 0 && $type === 'payment' && $invoice_id !== $oldInvoiceId) {
            $newInvoiceStatus = invoice_recalculate_status($conn, $invoice_id);
            if ($newInvoiceStatus['status'] !== $newInvoiceStatus['old_status']) {
              audit_event($conn, [
                'action'=>'invoice.status.updated','module'=>'invoices','entity_type'=>'invoice','entity_id'=>(string)$invoice_id,
                'description'=>'Invoice payment status recalculated after transaction update.',
                'old_values'=>['status'=>$newInvoiceStatus['old_status']],
                'new_values'=>['status'=>$newInvoiceStatus['status'],'paid_total'=>invoice_cents_decimal($newInvoiceStatus['paid_cents']),'balance'=>invoice_cents_decimal($newInvoiceStatus['balance_cents'])]
              ]);
            }
          }
          $conn->commit();
        } else {
          $conn->rollback();
          $message = 'Error updating transaction';
        }
        $upd->close();
        } catch (InvalidArgumentException $e) {
          try { $conn->rollback(); } catch (Throwable $ignored) {}
          $message = $e->getMessage();
        } catch (Throwable $e) {
          try { $conn->rollback(); } catch (Throwable $ignored) {}
          $message = 'Transaction could not be updated. Please try again.';
          error_log('Transaction update failed: '.$e->getMessage());
        }
        }
      }
  }
  }
}

$clients = $conn->query("SELECT id, full_name FROM clients ORDER BY full_name ASC");
$clientsArr = [];
while ($cRow = $clients->fetch_assoc()) { $clientsArr[] = $cRow; }

$suppliers = $conn->query("SELECT id, business_name, contact_person, phone FROM suppliers WHERE status='active' ORDER BY business_name ASC");
$suppliersArr = [];
while ($sRow = $suppliers->fetch_assoc()) { $suppliersArr[] = $sRow; }
$allSuppliersResult = $conn->query("SELECT id, business_name, contact_person, phone, status FROM suppliers ORDER BY business_name ASC");
$allSuppliersArr = [];
while ($sRow = $allSuppliersResult->fetch_assoc()) { $allSuppliersArr[] = $sRow; }
$txIsJson = isset($_GET['format']) && $_GET['format'] === 'json';
$txSearch = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 120) : '';
$txType = in_array($_GET['type'] ?? '', ['income','expense','invoice','payment'], true) ? (string)$_GET['type'] : '';
$txSortOptions = ['date_desc'=>'t.date DESC, t.id DESC','date_asc'=>'t.date ASC, t.id ASC','amount_desc'=>'t.amount DESC, t.id DESC','amount_asc'=>'t.amount ASC, t.id ASC','type'=>'t.type ASC, t.date DESC, t.id DESC','client'=>'c.full_name ASC, t.date DESC, t.id DESC','supplier'=>'s.business_name ASC, t.date DESC, t.id DESC'];
$txSort = is_string($_GET['sort'] ?? null) && isset($txSortOptions[$_GET['sort']]) ? $_GET['sort'] : 'date_desc';
$txPage = max(1, (int)($_GET['page'] ?? 1));
$txPageSize = in_array((int)($_GET['page_size'] ?? 25), [10,25,50,100], true) ? (int)($_GET['page_size'] ?? 25) : 25;
$txStart = is_string($_GET['start'] ?? null) ? $_GET['start'] : '';
$txEnd = is_string($_GET['end'] ?? null) ? $_GET['end'] : '';
$txMinAmount = is_string($_GET['min_amount'] ?? null) ? trim($_GET['min_amount']) : '';
$txMaxAmount = is_string($_GET['max_amount'] ?? null) ? trim($_GET['max_amount']) : '';
$txMethod = in_array($_GET['method'] ?? '', ['cash','mobile','card',''], true) ? (string)($_GET['method'] ?? '') : '';
$txSupplier = filter_var($_GET['supplier'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
$txClient = filter_var($_GET['client'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
$txNoSupplier = ($_GET['no_supplier'] ?? '') === '1';
$txNoClient = ($_GET['no_client'] ?? '') === '1';
$txFilterError = '';
$validDate = static function ($value): bool {
  if ($value === '') return true;
  if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
  $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
  return $date !== false && $date->format('Y-m-d') === $value;
};
$validAmount = static function ($value): bool { return $value === '' || (is_string($value) && preg_match('/^\d{1,10}(\.\d{1,2})?$/', $value)); };
if (!$validDate($txStart) || !$validDate($txEnd) || ($txStart !== '' && $txEnd !== '' && $txStart > $txEnd)) $txFilterError = 'Choose a valid date range.';
if (!$validAmount($txMinAmount) || !$validAmount($txMaxAmount)) $txFilterError = 'Enter valid minimum and maximum amounts.';
if ($txMinAmount !== '' && $txMaxAmount !== '' && (float)$txMinAmount > (float)$txMaxAmount) $txFilterError = 'Minimum amount cannot exceed maximum amount.';
$txWhere = [];
$txParams = [];
$txTypes = '';
if ($txSearch !== '' && !$txIsJson) {
  $txWhere[] = "(CAST(t.id AS CHAR) LIKE ? OR c.full_name LIKE ? OR s.business_name LIKE ? OR i.invoice_number LIKE ? OR t.description LIKE ? OR t.payment_method LIKE ?)";
  $searchLike = '%'.$txSearch.'%';
  $paymentSearchLike = stripos($txSearch, 'momo') !== false ? '%mobile%' : $searchLike;
  array_push($txParams, $searchLike, $searchLike, $searchLike, $searchLike, $searchLike, $paymentSearchLike);
  $txTypes .= 'ssssss';
}
if ($txFilterError === '') {
  foreach ([['t.date >= ?', $txStart, 's'], ['t.date <= ?', $txEnd, 's'], ['t.amount >= ?', $txMinAmount, 'd'], ['t.amount <= ?', $txMaxAmount, 'd'], ['t.payment_method = ?', $txMethod, 's'], ['t.supplier_id = ?', $txSupplier, 'i'], ['t.client_id = ?', $txClient, 'i']] as [$clause, $value, $bindType]) {
    if ($value !== '' && $value !== 0) { $txWhere[] = $clause; $txParams[] = $value; $txTypes .= $bindType; }
  }
  if ($txNoSupplier) $txWhere[] = 't.supplier_id IS NULL';
  if ($txNoClient) $txWhere[] = 't.client_id IS NULL';
}
if ($txFilterError !== '') $txWhere[] = '1=0';
if ($txType !== '' && !$txIsJson) {
  $txWhere[] = 't.type=?';
  $txParams[] = $txType;
  $txTypes .= 's';
}
$txEditId = filter_var($_GET['edit_transaction'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
if ($txEditId > 0 && !$txIsJson) {
  $txWhere[] = 't.id=?';
  $txParams[] = $txEditId;
  $txTypes .= 'i';
}
$txWhereSql = $txWhere ? ' WHERE '.implode(' AND ', $txWhere) : '';
$txTotal = 0;
$txOffset = 0;
$txSummary = ['count'=>0,'income'=>0.0,'expense'=>0.0];
if (!$txIsJson) {
  if ($txFilterError === '') {
    $summaryTx = $conn->prepare("SELECT COUNT(*), COALESCE(SUM(CASE WHEN t.type IN ('income','payment') THEN t.amount ELSE 0 END),0), COALESCE(SUM(CASE WHEN t.type='expense' THEN t.amount ELSE 0 END),0) FROM transactions t LEFT JOIN clients c ON c.id=t.client_id LEFT JOIN invoices i ON i.id=t.invoice_id LEFT JOIN suppliers s ON s.id=t.supplier_id".$txWhereSql);
    if ($txTypes !== '') $summaryTx->bind_param($txTypes, ...$txParams);
    $summaryTx->execute();
    $summaryCount = 0; $summaryIncome = 0.0; $summaryExpense = 0.0;
    $summaryTx->bind_result($summaryCount, $summaryIncome, $summaryExpense);
    $summaryTx->fetch();
    $txSummary = ['count'=>$summaryCount,'income'=>$summaryIncome,'expense'=>$summaryExpense];
    $summaryTx->close();
  }
  $txTotal = (int)$txSummary['count'];
  $txTotal = (int)$txTotal;
  $txPages = max(1, (int)ceil($txTotal / $txPageSize));
  $txPage = min($txPage, $txPages);
  $txOffset = ($txPage - 1) * $txPageSize;
}
$txTotal = $txFilterError !== '' ? 0 : (int)$txTotal;
$txSummary['count'] = (int)$txSummary['count'];
$txSummary['income'] = (float)$txSummary['income'];
$txSummary['expense'] = (float)$txSummary['expense'];
$txQueryParams = array_filter([
  'q'=>$txSearch, 'type'=>$txType, 'sort'=>$txSort, 'page_size'=>$txPageSize,
  'start'=>$txStart, 'end'=>$txEnd, 'min_amount'=>$txMinAmount, 'max_amount'=>$txMaxAmount,
  'method'=>$txMethod, 'supplier'=>$txSupplier ?: '', 'client'=>$txClient ?: '',
  'no_supplier'=>$txNoSupplier ? '1' : '', 'no_client'=>$txNoClient ? '1' : '',
], static fn($value) => $value !== '' && $value !== 0);
if (!$txIsJson && ($_GET['export'] ?? '') === 'csv') {
  if ($txFilterError !== '') { http_response_code(422); exit($txFilterError); }
  $exportSql = "SELECT t.id, t.date, t.type, t.description, c.full_name AS client, s.business_name AS supplier, i.invoice_number, t.payment_method, t.amount FROM transactions t LEFT JOIN clients c ON c.id=t.client_id LEFT JOIN invoices i ON i.id=t.invoice_id LEFT JOIN suppliers s ON s.id=t.supplier_id".$txWhereSql." ORDER BY ".$txSortOptions[$txSort];
  $exportStmt = $conn->prepare($exportSql);
  if ($txTypes !== '') $exportStmt->bind_param($txTypes, ...$txParams);
  $exportStmt->execute();
  $exportRows = $exportStmt->get_result();
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="transactions_'.date('Ymd').'.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['ID','Date','Type','Description','Client','Supplier','Invoice','Payment Method','Amount']);
  $csvSafe = static function ($value): string { $value = (string)($value ?? ''); return preg_match('/^\s*[=+\-@]/', $value) ? "'".$value : $value; };
  while ($row = $exportRows->fetch_assoc()) fputcsv($out, [$row['id'],$row['date'],$row['type'],$csvSafe($row['description']),$csvSafe($row['client']),$csvSafe($row['supplier']),$csvSafe($row['invoice_number']),$csvSafe(payment_method_label($row['payment_method'] ?? null)),number_format((float)$row['amount'],2,'.','')]);
  fclose($out);
  $exportStmt->close();
  exit;
}
if (!$txIsJson && ($_GET['print'] ?? '') === '1') {
  if ($txFilterError !== '') { http_response_code(422); exit(htmlspecialchars($txFilterError, ENT_QUOTES, 'UTF-8')); }
  $printSql = "SELECT t.id, t.date, t.type, t.description, c.full_name AS client, s.business_name AS supplier, i.invoice_number, t.payment_method, t.amount FROM transactions t LEFT JOIN clients c ON c.id=t.client_id LEFT JOIN invoices i ON i.id=t.invoice_id LEFT JOIN suppliers s ON s.id=t.supplier_id".$txWhereSql." ORDER BY ".$txSortOptions[$txSort];
  $printStmt = $conn->prepare($printSql);
  if ($txTypes !== '') $printStmt->bind_param($txTypes, ...$txParams);
  $printStmt->execute();
  $printRows = $printStmt->get_result();
  header('Content-Type: text/html; charset=utf-8');
  ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Transactions - <?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?></title><style>
    body{font:12px/1.4 Arial,sans-serif;color:#111;margin:24px}h1{font-size:20px;margin:0 0 4px}.meta{color:#555;margin-bottom:16px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #bbb;padding:6px;text-align:left;vertical-align:top}th{background:#eee}td.amount{text-align:right;white-space:nowrap}thead{display:table-header-group}tr{break-inside:avoid}@page{size:landscape;margin:12mm}@media print{body{margin:0}.no-print{display:none}}
  </style></head><body><div class="no-print"><button onclick="window.print()">Print</button></div><h1>Transactions</h1><div class="meta">Filtered results: <?= number_format((int)$txSummary['count']) ?> records · Generated <?= htmlspecialchars(date('Y-m-d H:i'), ENT_QUOTES, 'UTF-8') ?></div><table><thead><tr><th>ID</th><th>Date</th><th>Type</th><th>Description</th><th>Client</th><th>Supplier</th><th>Invoice</th><th>Payment Method</th><th>Amount</th></tr></thead><tbody><?php while ($row = $printRows->fetch_assoc()): ?><tr><td><?= (int)$row['id'] ?></td><td><?= htmlspecialchars((string)$row['date'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars(ucfirst((string)$row['type']), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string)($row['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string)($row['client'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string)($row['supplier'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string)($row['invoice_number'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars(payment_method_label($row['payment_method'] ?? null), ENT_QUOTES, 'UTF-8') ?></td><td class="amount"><?= htmlspecialchars(strip_tags(fmtCurrency((float)$row['amount'])), ENT_QUOTES, 'UTF-8') ?></td></tr><?php endwhile; ?></tbody></table><script>window.addEventListener('load',()=>window.print());</script></body></html><?php
  $printStmt->close();
  exit;
}
$txSql = "SELECT t.id, t.type, t.amount, t.date, t.payment_method, t.description, t.invoice_id, t.supplier_id, c.full_name AS client, i.invoice_number, s.business_name AS supplier_name
                    FROM transactions t
                    LEFT JOIN clients c ON c.id=t.client_id
                    LEFT JOIN invoices i ON i.id=t.invoice_id
                    LEFT JOIN suppliers s ON s.id=t.supplier_id
                    ".$txWhereSql." ORDER BY ".$txSortOptions[$txSort]." LIMIT ? OFFSET ?";
$txStmt = $conn->prepare($txSql);
$txListTypes = $txTypes.'ii';
$txListParams = $txParams;
$txListParams[] = $txIsJson ? 100 : $txPageSize;
$txListParams[] = $txIsJson ? 0 : $txOffset;
$txStmt->bind_param($txListTypes, ...$txListParams);
$txStmt->execute();
$tx = $txStmt->get_result();
$userName = htmlspecialchars($_SESSION['full_name']);
$role = $_SESSION['role'] ?? 'staff';
$payload = [];
if (isset($_GET['format']) && $_GET['format'] === 'json') {
  while ($row = $tx->fetch_assoc()) {
    $payload[] = [
      'id' => (int)$row['id'],
      'client' => (string)$row['client'],
      'type' => (string)$row['type'],
      'invoice_id' => isset($row['invoice_id']) ? (int)$row['invoice_id'] : null,
      'invoice_number' => (string)($row['invoice_number'] ?? ''),
      'supplier_id' => isset($row['supplier_id']) ? (int)$row['supplier_id'] : null,
      'amount' => (float)$row['amount'],
      'date' => (string)$row['date'],
      'payment_method' => (string)($row['payment_method'] ?? ''),
      'description' => (string)($row['description'] ?? '')
    ];
  }
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['items'=>$payload], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_NUMERIC_CHECK);
  exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Transactions — LMG Creative Media</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="css/dashboard.css">
  <style>
    .transaction-type-filters .btn {
      transition: background-color .15s ease, border-color .15s ease, color .15s ease, box-shadow .15s ease;
    }
    @media (prefers-reduced-motion: reduce) {
      .transaction-type-filters .btn { transition: none; }
    }
  </style>
  <link rel="icon" href="../favicon/favicon.ico" sizes="any">
  <link rel="icon" type="image/svg+xml" href="../favicon/favicon.svg">
  <link rel="icon" type="image/png" sizes="96x96" href="../favicon/favicon-96x96.png">
  <link rel="apple-touch-icon" sizes="180x180" href="../favicon/apple-touch-icon.png">
</head>
<body>
<div class="app-shell d-flex">
  <?php $active_page='transactions'; include "../includes/layout/sidebar.php"; ?>

  <main class="main flex-grow-1 p-4">
    <?php $page_title='Transactions'; $page_subtitle='Record income, expenses, invoices and payments'; $header_actions=''; include "../includes/layout/header.php"; ?>

    <?php if ($message): ?>
      <div class="alert alert-info py-2" role="alert" aria-live="polite"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="row g-2 mb-3" aria-label="Filtered transaction totals">
      <div class="col-6 col-xl-3"><div class="card p-3 h-100"><div class="text-muted small">Matching transactions</div><div class="h5 mb-0"><?= number_format($txSummary['count']) ?></div><small class="text-muted">All filtered results</small></div></div>
      <div class="col-6 col-xl-3"><div class="card p-3 h-100"><div class="text-muted small">Income</div><div class="h5 mb-0"><?= fmtCurrency($txSummary['income']) ?></div><small class="text-muted">Income and received payments</small></div></div>
      <div class="col-6 col-xl-3"><div class="card p-3 h-100"><div class="text-muted small">Expenses</div><div class="h5 mb-0"><?= fmtCurrency($txSummary['expense']) ?></div><small class="text-muted">Matching expense records</small></div></div>
      <div class="col-6 col-xl-3"><div class="card p-3 h-100"><div class="text-muted small">Net Movement</div><div class="h5 mb-0"><?= fmtCurrency($txSummary['income'] - $txSummary['expense']) ?></div><small class="text-muted">Income minus expenses</small></div></div>
    </div>

    <div class="row g-3">
      <div class="col-lg-4">
        <div id="addTx" class="card p-3">
          <h6 class="mb-3">Add Transaction</h6>
          <form method="POST" class="row g-2">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="request_key" value="<?= htmlspecialchars($transactionRequestKey, ENT_QUOTES) ?>">
            <div class="col-12">
              <label class="form-label">Client</label>
              <input class="form-control" list="clientsDatalist" name="client_name" id="clientNameTx" placeholder="Type name to search or add new" required>
              <datalist id="clientsDatalist">
                <?php foreach($clientsArr as $c): ?>
                  <option value="<?= htmlspecialchars($c['full_name']) ?>"></option>
                <?php endforeach; ?>
              </datalist>
              <input type="hidden" name="client_id" id="clientIdTx">
            </div>
            <div class="col-12">
              <label class="form-label">Supplier (Optional)</label>
              <input class="form-control form-control-sm mb-1" type="search" id="supplierSearchTx" placeholder="Search suppliers by name, contact, or phone">
              <div class="input-group">
                <select class="form-select" name="supplier_id" id="supplierIdTx">
                  <option value="">No Supplier</option>
                  <?php foreach($suppliersArr as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" data-search="<?= htmlspecialchars(strtolower($s['business_name'].' '.$s['contact_person'].' '.$s['phone']), ENT_QUOTES) ?>"><?= htmlspecialchars($s['business_name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-outline-light" id="addSupplierBtn" title="Add New Supplier">+</button>
              </div>
            </div>
            <div class="col-6"><input class="form-control" type="number" step="0.01" name="amount" placeholder="Amount" required></div>
            <div class="col-6">
              <select class="form-select" name="type" id="typeSelect">
                <option value="income">Income</option>
                <option value="expense">Expense</option>
                <option value="invoice">Invoice</option>
                <option value="payment">Payment</option>
              </select>
            </div>
            <div class="col-12" id="invoiceLinkRow" style="display:none">
              <label class="form-label">Invoice # (for Payment)</label>
              <input class="form-control" list="invoicesDatalist" name="invoice_number" id="invoiceNumberTx" placeholder="Type invoice number to link">
              <datalist id="invoicesDatalist">
                <?php
                  $invRes = $conn->query("SELECT id, invoice_number, client_id, total, status FROM invoices WHERE cancelled_at IS NULL ORDER BY id DESC LIMIT 200");
                  while($iv = $invRes->fetch_assoc()):
                ?>
                  <option value="<?= htmlspecialchars($iv['invoice_number']) ?>"></option>
                <?php endwhile; ?>
              </datalist>
              <input type="hidden" name="invoice_id" id="invoiceIdTx">
            </div>
            <div class="col-6">
              <select class="form-select" name="payment_method">
                <option value="">Payment Method</option>
                <option value="cash">Cash</option>
                <option value="mobile">MoMo</option>
                <option value="card">Card</option>
              </select>
            </div>
            <div class="col-6"><input class="form-control" type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-12"><textarea class="form-control" name="description" placeholder="Description"></textarea></div>
            <div class="col-12"><button class="btn btn-primary w-100">Save</button></div>
          </form>
        </div>
      </div>
      <div class="col-lg-8">
        <div id="listTx" class="card p-3">
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h6 mb-1">Transactions</h2><p class="small text-muted mb-0">Search and filter posted financial activity.</p></div><div class="d-flex flex-wrap gap-2"><a class="btn btn-sm btn-outline-light" href="<?= htmlspecialchars('transactions.php?'.http_build_query($txQueryParams + ['export'=>'csv']), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-download"></i> Export CSV</a><a class="btn btn-sm btn-outline-light" target="_blank" rel="noopener" href="<?= htmlspecialchars('transactions.php?'.http_build_query($txQueryParams + ['print'=>'1']), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-printer"></i> Print</a><div class="dropdown"><button class="btn btn-sm btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Choose visible columns">Columns</button><div class="dropdown-menu dropdown-menu-end p-2" id="transactionColumnMenu"><div class="small fw-semibold px-2 pb-1">Visible columns</div><?php foreach (['client'=>'Client','invoice'=>'Invoice','supplier'=>'Supplier','method'=>'Payment method','description'=>'Description'] as $key=>$label): ?><label class="dropdown-item-text d-flex align-items-center gap-2 py-1"><input class="form-check-input m-0 transaction-column-toggle" type="checkbox" data-column="<?= $key ?>" checked> <?= $label ?></label><?php endforeach; ?></div></div><a class="btn btn-sm btn-primary" href="#addTx"><i class="bi bi-plus-lg"></i> New Transaction</a><a class="btn btn-sm btn-outline-light" href="<?= htmlspecialchars('transactions.php?'.http_build_query($txQueryParams), ENT_QUOTES, 'UTF-8') ?>" aria-label="Refresh transaction results" title="Refresh"><i class="bi bi-arrow-clockwise"></i></a></div></div>
          <div class="table-responsive">
            <form method="GET" class="row g-2 mb-3" role="search" aria-label="Filter transactions">
              <div class="col-md-7"><label class="visually-hidden" for="transactionSearch">Search transactions</label><div class="input-group input-group-sm"><input class="form-control" type="search" id="transactionSearch" name="q" value="<?= htmlspecialchars($txSearch, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search ID, client, supplier, invoice, method or description"><?php if ($txSearch !== ''): ?><a class="btn btn-outline-light" href="<?= htmlspecialchars('transactions.php?'.http_build_query(array_diff_key($txQueryParams, ['q'=>true])), ENT_QUOTES, 'UTF-8') ?>" aria-label="Clear search">Clear</a><?php endif; ?></div></div>
              <div class="col-md-3"><label class="visually-hidden" for="transactionTypeFilter">Transaction type</label><select class="form-select form-select-sm" id="transactionTypeFilter" name="type"><option value="">All types</option><?php foreach (['income'=>'Income','expense'=>'Expense','invoice'=>'Invoice','payment'=>'Payment'] as $value=>$label): ?><option value="<?= $value ?>" <?= $txType===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
              <div class="col-md-2"><label class="visually-hidden" for="transactionSort">Sort transactions</label><select class="form-select form-select-sm" id="transactionSort" name="sort"><option value="date_desc" <?= $txSort==='date_desc'?'selected':'' ?>>Newest</option><option value="date_asc" <?= $txSort==='date_asc'?'selected':'' ?>>Oldest</option><option value="amount_desc" <?= $txSort==='amount_desc'?'selected':'' ?>>Highest amount</option><option value="amount_asc" <?= $txSort==='amount_asc'?'selected':'' ?>>Lowest amount</option><option value="type" <?= $txSort==='type'?'selected':'' ?>>Type</option><option value="client" <?= $txSort==='client'?'selected':'' ?>>Client</option><option value="supplier" <?= $txSort==='supplier'?'selected':'' ?>>Supplier</option></select></div>
              <div class="col-12"><details <?= ($txStart || $txEnd || $txMinAmount !== '' || $txMaxAmount !== '' || $txMethod !== '' || $txSupplier || $txClient || $txNoSupplier || $txNoClient) ? 'open' : '' ?>><summary class="small fw-semibold py-2">Advanced filters</summary><div class="row g-2 pt-2">
                <div class="col-sm-6 col-lg-3"><label class="form-label small" for="transactionDatePreset">Date preset</label><select class="form-select form-select-sm" id="transactionDatePreset"><option value="">Custom range</option><option value="today">Today</option><option value="yesterday">Yesterday</option><option value="this_week">This week</option><option value="last_week">Last week</option><option value="this_month">This month</option><option value="last_month">Last month</option><option value="this_year">This year</option></select></div>
                <div class="col-sm-6 col-lg-3"><label class="form-label small" for="transactionStart">From date</label><input class="form-control form-control-sm" type="date" id="transactionStart" name="start" value="<?= htmlspecialchars($txStart, ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="col-sm-6 col-lg-3"><label class="form-label small" for="transactionEnd">To date</label><input class="form-control form-control-sm" type="date" id="transactionEnd" name="end" value="<?= htmlspecialchars($txEnd, ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="col-sm-6 col-lg-3"><label class="form-label small" for="transactionMinAmount">Minimum amount</label><input class="form-control form-control-sm" type="number" min="0" step="0.01" id="transactionMinAmount" name="min_amount" value="<?= htmlspecialchars($txMinAmount, ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="col-sm-6 col-lg-3"><label class="form-label small" for="transactionMaxAmount">Maximum amount</label><input class="form-control form-control-sm" type="number" min="0" step="0.01" id="transactionMaxAmount" name="max_amount" value="<?= htmlspecialchars($txMaxAmount, ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="col-sm-6 col-lg-3"><label class="form-label small" for="transactionMethodFilter">Payment method</label><select class="form-select form-select-sm" id="transactionMethodFilter" name="method"><option value="">All methods</option><option value="cash" <?= $txMethod==='cash'?'selected':'' ?>>Cash</option><option value="mobile" <?= $txMethod==='mobile'?'selected':'' ?>>MoMo</option><option value="card" <?= $txMethod==='card'?'selected':'' ?>>Card</option></select></div>
                <div class="col-sm-6 col-lg-3"><label class="form-label small" for="transactionSupplierSearch">Find supplier</label><input class="form-control form-control-sm mb-1" type="search" id="transactionSupplierSearch" placeholder="Type supplier name"><select class="form-select form-select-sm" id="transactionSupplierFilter" name="supplier"><option value="">All suppliers</option><?php foreach($allSuppliersArr as $s): ?><option value="<?= (int)$s['id'] ?>" data-search="<?= htmlspecialchars(mb_strtolower($s['business_name'].' '.$s['contact_person'].' '.$s['phone']), ENT_QUOTES, 'UTF-8') ?>" <?= $txSupplier===(int)$s['id']?'selected':'' ?>><?= htmlspecialchars($s['business_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="no_supplier" value="1" id="transactionNoSupplier" <?= $txNoSupplier?'checked':'' ?>><label class="form-check-label small" for="transactionNoSupplier">No supplier linked</label></div></div>
                <div class="col-sm-6 col-lg-3"><label class="form-label small" for="transactionClientSearch">Find client</label><input class="form-control form-control-sm mb-1" type="search" id="transactionClientSearch" placeholder="Type client name"><select class="form-select form-select-sm" id="transactionClientFilter" name="client"><option value="">All clients</option><?php foreach($clientsArr as $c): ?><option value="<?= (int)$c['id'] ?>" data-search="<?= htmlspecialchars(mb_strtolower($c['full_name']), ENT_QUOTES, 'UTF-8') ?>" <?= $txClient===(int)$c['id']?'selected':'' ?>><?= htmlspecialchars($c['full_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="no_client" value="1" id="transactionNoClient" <?= $txNoClient?'checked':'' ?>><label class="form-check-label small" for="transactionNoClient">No client linked</label></div></div>
                <div class="col-12 d-flex flex-wrap gap-2"><button class="btn btn-sm btn-primary">Apply Filters</button><a class="btn btn-sm btn-outline-light" href="transactions.php">Clear All Filters</a><label class="ms-auto small d-flex align-items-center gap-2" for="transactionPageSize">Rows per page<select class="form-select form-select-sm" id="transactionPageSize" name="page_size" style="width:auto"><option <?= $txPageSize===10?'selected':'' ?>>10</option><option <?= $txPageSize===25?'selected':'' ?>>25</option><option <?= $txPageSize===50?'selected':'' ?>>50</option><option <?= $txPageSize===100?'selected':'' ?>>100</option></select></label></div>
              </div></details></div>
            </form>
            <?php if ($txFilterError !== ''): ?><div class="alert alert-warning py-2" role="alert"><?= htmlspecialchars($txFilterError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <div class="small text-muted mb-2">Active filters: <?= $txType !== '' || $txSearch !== '' || $txStart !== '' || $txEnd !== '' || $txMinAmount !== '' || $txMaxAmount !== '' || $txMethod !== '' || $txSupplier || $txClient || $txNoSupplier || $txNoClient ? 'Yes' : 'None' ?><?php if ($txType !== '' || $txSearch !== '' || $txStart !== '' || $txEnd !== '' || $txMinAmount !== '' || $txMaxAmount !== '' || $txMethod !== '' || $txSupplier || $txClient || $txNoSupplier || $txNoClient): ?> · <a href="transactions.php">Clear All Filters</a><?php endif; ?></div>
            <?php
              $activeFilterLabels = [];
              if ($txSearch !== '') $activeFilterLabels['q'] = 'Search: '.$txSearch;
              if ($txType !== '') $activeFilterLabels['type'] = 'Type: '.ucfirst($txType);
              if ($txStart !== '') $activeFilterLabels['start'] = 'From: '.$txStart;
              if ($txEnd !== '') $activeFilterLabels['end'] = 'To: '.$txEnd;
              if ($txMinAmount !== '') $activeFilterLabels['min_amount'] = 'Min: '.$txMinAmount;
              if ($txMaxAmount !== '') $activeFilterLabels['max_amount'] = 'Max: '.$txMaxAmount;
              if ($txMethod !== '') $activeFilterLabels['method'] = 'Method: '.payment_method_label($txMethod);
              if ($txSupplier) $activeFilterLabels['supplier'] = 'Supplier #'.$txSupplier;
              if ($txClient) $activeFilterLabels['client'] = 'Client #'.$txClient;
              if ($txNoSupplier) $activeFilterLabels['no_supplier'] = 'No supplier';
              if ($txNoClient) $activeFilterLabels['no_client'] = 'No client';
            ?>
            <?php if ($activeFilterLabels): ?><div class="d-flex flex-wrap gap-2 mb-2" aria-label="Active filter chips"><?php foreach ($activeFilterLabels as $filterKey=>$filterLabel): $withoutFilter = $txQueryParams; unset($withoutFilter[$filterKey]); ?><a class="badge rounded-pill text-bg-secondary text-decoration-none" href="<?= htmlspecialchars('transactions.php?'.http_build_query($withoutFilter), ENT_QUOTES, 'UTF-8') ?>" aria-label="Remove filter <?= htmlspecialchars($filterLabel, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($filterLabel, ENT_QUOTES, 'UTF-8') ?> ×</a><?php endforeach; ?></div><?php endif; ?>
            <table id="transactionsTable" class="table table-borderless table-sm table-striped table-hover table-brand">
              <thead>
                <tr class="text-muted small">
                  <th>#</th>
                  <th class="tx-col-client">Client</th>
                  <th>Type</th>
                  <th class="tx-col-invoice">Invoice #</th>
                  <th class="tx-col-supplier">Supplier</th>
                  <th>Amount</th>
                  <th>Date</th>
                  <th class="tx-col-method">Method</th>
                  <th class="tx-col-description">Description</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($txTotal === 0): ?>
                  <tr><td colspan="10" class="text-center text-muted py-4">No transactions match this search. <a href="transactions.php">Clear filters</a> or record a transaction.</td></tr>
                <?php endif; ?>
                <?php while($t = $tx->fetch_assoc()): ?>
                <tr>
                  <td><?= (int)$t['id'] ?></td>
                  <td class="tx-col-client"><?= htmlspecialchars($t['client'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                  <td class="text-capitalize"><?= htmlspecialchars($t['type']) ?></td>
                  <td class="text-muted small tx-col-invoice">
                    <?php if (!empty($t['invoice_number'])): ?>
                      <a class="link-light" href="invoice_view.php?id=<?= (int)$t['invoice_id'] ?>"><?= htmlspecialchars($t['invoice_number']) ?></a>
                    <?php endif; ?>
                  </td>
                  <td class="text-muted small tx-col-supplier"><?= htmlspecialchars($t['supplier_name'] ?? '') ?></td>
                  <td><?= fmtCurrency((float)$t['amount']) ?></td>
                  <td class="text-muted small"><?= htmlspecialchars($t['date']) ?></td>
                  <td class="text-muted small tx-col-method"><?= htmlspecialchars(payment_method_label($t['payment_method'] ?? null)) ?></td>
                  <td class="text-muted small tx-col-description"><?= htmlspecialchars($t['description'] ?? '') ?></td>
                  <td>
                    <button type="button" class="btn btn-sm btn-outline-light transaction-actions"
                      data-id="<?= (int)$t['id'] ?>" data-type="<?= htmlspecialchars($t['type'], ENT_QUOTES, 'UTF-8') ?>"
                      data-date="<?= htmlspecialchars($t['date'], ENT_QUOTES, 'UTF-8') ?>" data-description="<?= htmlspecialchars($t['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                      data-client="<?= htmlspecialchars($t['client'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-supplier="<?= htmlspecialchars($t['supplier_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                      data-invoice="<?= htmlspecialchars($t['invoice_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-amount="<?= htmlspecialchars(strip_tags(fmtCurrency((float)$t['amount'])), ENT_QUOTES, 'UTF-8') ?>"
                      data-method="<?= htmlspecialchars(payment_method_label($t['payment_method'] ?? null), ENT_QUOTES, 'UTF-8') ?>"
                      aria-label="Actions for transaction <?= (int)$t['id'] ?>"><i class="bi bi-three-dots"></i> Actions</button>
                    <div class="d-none">
                    <?php if (in_array($t['type'], ['income','payment'])): ?>
                      <a class="transaction-receipt" href="receipt_view.php?id=<?= (int)$t['id'] ?>">Receipt</a>
                    <?php endif; ?>
                    <?php if (!empty($t['invoice_id'])): ?><a class="transaction-invoice" href="invoice_view.php?id=<?= (int)$t['invoice_id'] ?>">Invoice</a><?php endif; ?>
                    <button type="button" class="btn-edit"
                      data-id="<?= (int)$t['id'] ?>"
                      data-client="<?= htmlspecialchars($t['client'] ?? '', ENT_QUOTES) ?>"
                      data-type="<?= htmlspecialchars($t['type'], ENT_QUOTES) ?>"
                      data-invoice-id="<?= (int)($t['invoice_id'] ?? 0) ?>"
                      data-invoice-number="<?= htmlspecialchars($t['invoice_number'] ?? '', ENT_QUOTES) ?>"
                      data-supplier-id="<?= (int)($t['supplier_id'] ?? 0) ?>"
                      data-amount="<?= htmlspecialchars($t['amount']) ?>"
                      data-date="<?= htmlspecialchars($t['date']) ?>"
                      data-method="<?= htmlspecialchars($t['payment_method'] ?? '', ENT_QUOTES) ?>"
                      data-desc="<?= htmlspecialchars($t['description'] ?? '', ENT_QUOTES) ?>"
                      aria-label="Edit transaction">Edit</button>
                    <?php if ($t['type'] === 'payment' && !empty($t['invoice_id'])): ?><span class="badge bg-secondary" title="Posted invoice payments are preserved in financial history">Protected payment</span><?php else: ?><form method="POST" class="d-inline-block ms-1 tx-delete-form" data-type="<?= htmlspecialchars($t['type'], ENT_QUOTES) ?>" data-invoice-id="<?= (int)($t['invoice_id'] ?? 0) ?>" data-invoice-number="<?= htmlspecialchars($t['invoice_number'] ?? '', ENT_QUOTES) ?>">
                      <?= csrf_input() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                      <button class="btn btn-sm btn-danger" aria-label="Delete transaction"><i class="bi bi-trash"></i></button>
                    </form><?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php endwhile; ?>
              </tbody>
              </table>
          </div>
          <div class="d-flex justify-content-between align-items-center mt-3">
            <small class="text-muted" role="status">Showing <?= $txTotal ? $txOffset + 1 : 0 ?>–<?= min($txOffset + $txPageSize, $txTotal) ?> of <?= $txTotal ?> transactions</small>
            <?php if ($txTotal > $txPageSize): ?><nav aria-label="Transaction pages"><ul class="pagination pagination-sm mb-0">
              <?php foreach (['First'=>$txPages > 1 ? 1 : $txPage,'Previous'=>$txPage-1,'Next'=>$txPage+1,'Last'=>$txPages] as $label=>$targetPage): if (($label==='First' || $label==='Previous') && $txPage<=1) continue; if (($label==='Next' || $label==='Last') && $txPage>=$txPages) continue; ?>
                <li class="page-item"><a class="page-link" href="?<?= htmlspecialchars(http_build_query($txQueryParams + ['page'=>$targetPage]), ENT_QUOTES, 'UTF-8') ?>"><?= $label ?></a></li>
              <?php endforeach; ?>
              <li class="page-item disabled"><span class="page-link">Page <?= $txPage ?> of <?= $txPages ?></span></li>
            </ul></nav><?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <footer class="mt-4 text-center text-muted small">© <?= date('Y') ?> LMG Creative Media</footer>
  </main>
</div>

<div class="modal fade" id="transactionActionsModal" tabindex="-1" aria-labelledby="transactionActionsTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title h5" id="transactionActionsTitle">Transaction details</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><dl class="row mb-0" id="transactionDetailsList"></dl><div class="alert alert-secondary py-2 mt-3 mb-0 d-none" id="protectedPaymentNotice">This posted invoice payment cannot be deleted. A validated correction is available through Edit Transaction.</div></div>
    <div class="modal-footer flex-wrap justify-content-start"><a class="btn btn-sm btn-outline-light d-none" id="transactionInvoiceLink">View Invoice</a><a class="btn btn-sm btn-outline-light d-none" id="transactionReceiptLink">View Receipt</a><button type="button" class="btn btn-sm btn-outline-primary" id="transactionEditAction">Edit Transaction</button><button type="button" class="btn btn-sm btn-outline-danger" id="transactionDeleteAction">Delete Transaction</button></div>
  </div></div>
</div>

<!-- Add Supplier Modal -->
<div class="modal fade" id="addSupplierModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" id="addSupplierForm">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="create_supplier">
        <div class="modal-header">
          <h5 class="modal-title">Add New Supplier</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label">Business Name *</label>
            <input class="form-control" name="business_name" required>
          </div>
          <div class="mb-2">
            <label class="form-label">Contact Person</label>
            <input class="form-control" name="contact_person">
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label">Phone</label>
              <input class="form-control" name="phone">
            </div>
            <div class="col-6">
              <label class="form-label">Email</label>
              <input class="form-control" type="email" name="email">
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label">Address</label>
            <textarea class="form-control" name="address" rows="2"></textarea>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label">Category</label>
              <input class="form-control" name="category" placeholder="e.g., IT Services">
            </div>
            <div class="col-6">
              <label class="form-label">Tax ID</label>
              <input class="form-control" name="tax_id">
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label">Notes</label>
            <textarea class="form-control" name="notes" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary">Add Supplier</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Transaction Modal -->
<div class="modal fade" id="editTxModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" id="editTxForm">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" id="editTxId">
        <div class="modal-header">
          <h5 class="modal-title">Edit Transaction</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label">Client</label>
            <input class="form-control" id="editClient" readonly>
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label">Amount</label>
              <input class="form-control" type="number" step="0.01" name="amount" id="editAmount" required>
            </div>
            <div class="col-6">
              <label class="form-label">Type</label>
              <select class="form-select" name="type" id="editType">
                <option value="income">Income</option>
                <option value="expense">Expense</option>
                <option value="invoice">Invoice</option>
                <option value="payment">Payment</option>
              </select>
            </div>
            <div class="col-12" id="editInvoiceRow">
              <label class="form-label">Invoice # (for Payment)</label>
              <input class="form-control" list="invoicesDatalist" name="invoice_number" id="editInvoiceNumber" placeholder="Type invoice number to link">
              <input type="hidden" name="invoice_id" id="editInvoiceId">
            </div>
            <div class="col-12" id="editSupplierRow">
              <label class="form-label">Supplier (Optional)</label>
              <input class="form-control form-control-sm mb-1" type="search" id="editSupplierSearch" placeholder="Search suppliers by name, contact, or phone">
              <select class="form-select" name="supplier_id" id="editSupplierId">
                <option value="">No Supplier</option>
                <?php foreach($allSuppliersArr as $s): ?>
                  <option value="<?= (int)$s['id'] ?>" data-search="<?= htmlspecialchars(strtolower($s['business_name'].' '.$s['contact_person'].' '.$s['phone']), ENT_QUOTES) ?>"><?= htmlspecialchars($s['business_name']) ?><?= $s['status'] === 'inactive' ? ' (Inactive)' : '' ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label">Method</label>
              <select class="form-select" name="payment_method" id="editMethod">
                <option value="">Payment Method</option>
                <option value="cash">Cash</option>
                <option value="mobile">MoMo</option>
                <option value="card">Card</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label">Date</label>
              <input class="form-control" type="date" name="date" id="editDate">
            </div>
            <div class="col-12">
              <label class="form-label">Description</label>
              <textarea class="form-control" name="description" id="editDesc"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary">Save changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  (function(){
    const map = new Map(<?php echo json_encode(array_map(fn($c)=>[$c['full_name'],(int)$c['id']], $clientsArr), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>);
    const nameInput = document.getElementById('clientNameTx');
    const idInput = document.getElementById('clientIdTx');
    const typeSelect = document.querySelector('select[name="type"]');
    const invoiceRow = document.getElementById('invoiceLinkRow');
    const invNumberInput = document.getElementById('invoiceNumberTx');
    const invIdInput = document.getElementById('invoiceIdTx');
    const invMap = new Map(<?php
      $invMapArr = [];
      $invRes2 = $conn->query("SELECT id, invoice_number FROM invoices WHERE cancelled_at IS NULL ORDER BY id DESC LIMIT 500");
      while($iv2 = $invRes2->fetch_assoc()) { $invMapArr[] = [$iv2['invoice_number'], (int)$iv2['id']]; }
      echo json_encode($invMapArr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    ?>);
    function syncId(){
      const name = (nameInput.value||'').trim();
      const id = map.get(name) || '';
      idInput.value = id;
    }
    function syncInvoice(){
      const n = (invNumberInput?.value||'').trim();
      const id = invMap.get(n) || '';
      if (invIdInput) invIdInput.value = id;
    }
    function toggleInvoiceRow(){
      invoiceRow.style.display = (typeSelect.value === 'payment') ? '' : 'none';
    }
    nameInput.addEventListener('change', syncId);
    nameInput.addEventListener('input', syncId);
    invNumberInput?.addEventListener('change', syncInvoice);
    invNumberInput?.addEventListener('input', syncInvoice);
    typeSelect?.addEventListener('change', toggleInvoiceRow);
    toggleInvoiceRow();
  })();
</script>
<script>
  // Handle supplier modal
  const addSupplierModalEl = document.getElementById('addSupplierModal');
  const addSupplierBsModal = new bootstrap.Modal(addSupplierModalEl);
  const addSupplierBtn = document.getElementById('addSupplierBtn');
  const addSupplierForm = document.getElementById('addSupplierForm');
  const supplierSelect = document.getElementById('supplierIdTx');
  const typeSelect = document.getElementById('typeSelect');
  const supplierSearch = document.getElementById('supplierSearchTx');

  supplierSearch.addEventListener('input', function() {
    const term = this.value.trim().toLowerCase();
    Array.from(supplierSelect.options).forEach(function(option) {
      if (option.value) option.hidden = !option.dataset.search.includes(term);
    });
  });

  addSupplierBtn.addEventListener('click', function() {
    addSupplierBsModal.show();
  });

  addSupplierForm.addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Adding...';
    
    fetch('transactions.php', {
      method: 'POST',
      body: formData,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(result => {
      if (result.success) {
        const option = new Option(result.business_name, result.id, true, true);
        supplierSelect.add(option);
        supplierSelect.value = result.id;
        addSupplierForm.reset();
        submitBtn.disabled = false;
        submitBtn.textContent = 'Add Supplier';
        addSupplierBsModal.hide();
      } else {
        alert(result.message || 'Supplier could not be created.');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Add Supplier';
      }
    })
    .catch(error => {
      alert('Supplier could not be created. Check your connection and try again.');
      submitBtn.disabled = false;
      submitBtn.textContent = 'Add Supplier';
    });
  });

  // Show supplier dropdown only for expenses
  function toggleSupplierRow() {
    const supplierRow = supplierSelect.closest('.col-12');
    const clientName = document.getElementById('clientNameTx');
    if (typeSelect.value === 'expense') {
      supplierRow.style.display = '';
    } else {
      supplierRow.style.display = 'none';
      supplierSelect.value = '';
    }
    if (clientName) clientName.required = !(typeSelect.value === 'expense' && supplierSelect.value !== '');
  }

  typeSelect.addEventListener('change', toggleSupplierRow);
  supplierSelect.addEventListener('change', toggleSupplierRow);
  toggleSupplierRow(); // Initialize on page load

  (function(){
    const editModalEl = document.getElementById('editTxModal');
    const bsModal = new bootstrap.Modal(editModalEl);
    const fId = document.getElementById('editTxId');
    const fClient = document.getElementById('editClient');
    const fAmount = document.getElementById('editAmount');
    const fType = document.getElementById('editType');
    const fInvoiceNumber = document.getElementById('editInvoiceNumber');
    const fInvoiceId = document.getElementById('editInvoiceId');
    const fMethod = document.getElementById('editMethod');
    const fDate = document.getElementById('editDate');
    const fDesc = document.getElementById('editDesc');
    const fSupplier = document.getElementById('editSupplierId');
    const supplierRow = document.getElementById('editSupplierRow');
    const supplierSearchEdit = document.getElementById('editSupplierSearch');
    const invoiceRow = document.getElementById('editInvoiceRow');

    document.addEventListener('click', function(e){
      const btn = e.target.closest && e.target.closest('.btn-edit');
      if (!btn) return;
      const dataset = btn.dataset || {};
      fId.value = dataset.id || '';
      fClient.value = dataset.client || '';
      fAmount.value = dataset.amount || '';
      fType.value = dataset.type || 'income';
      fInvoiceNumber.value = dataset.invoiceNumber || '';
      fInvoiceId.value = dataset.invoiceId || '';
      fMethod.value = dataset.method || '';
      fDate.value = dataset.date || '';
      fDesc.value = dataset.desc || '';
      fSupplier.value = dataset.supplierId || '';
      supplierRow.style.display = (fType.value === 'expense') ? '' : 'none';
      invoiceRow.style.display = (fType.value === 'payment') ? '' : 'none';
      bsModal.show();
    });

    // toggle invoice row when type changes inside modal
    fType.addEventListener('change', function(){
      invoiceRow.style.display = (this.value === 'payment') ? '' : 'none';
      supplierRow.style.display = (this.value === 'expense') ? '' : 'none';
    });
    supplierSearchEdit.addEventListener('input', function() {
      const term = this.value.trim().toLowerCase();
      Array.from(fSupplier.options).forEach(function(option) {
        if (option.value) option.hidden = !option.dataset.search.includes(term);
      });
    });
  })();

  (function(){
    const modalElement = document.getElementById('transactionActionsModal');
    const modal = new bootstrap.Modal(modalElement);
    const title = document.getElementById('transactionActionsTitle');
    const details = document.getElementById('transactionDetailsList');
    const editAction = document.getElementById('transactionEditAction');
    const deleteAction = document.getElementById('transactionDeleteAction');
    const receiptLink = document.getElementById('transactionReceiptLink');
    const invoiceLink = document.getElementById('transactionInvoiceLink');
    const protectedNotice = document.getElementById('protectedPaymentNotice');
    let activeRow = null;
    document.querySelectorAll('.transaction-actions').forEach(button => button.addEventListener('click', () => {
      activeRow = button.closest('tr');
      const data = button.dataset;
      title.textContent = `Transaction #${data.id}`;
      details.replaceChildren();
      [['Date',data.date],['Type',data.type],['Description',data.description],['Client',data.client],['Supplier',data.supplier],['Invoice',data.invoice],['Payment method',data.method],['Amount',data.amount]].forEach(([label,value]) => {
        const term = document.createElement('dt'); term.className = 'col-5 text-muted'; term.textContent = label;
        const detail = document.createElement('dd'); detail.className = 'col-7'; detail.textContent = value || '—';
        details.append(term, detail);
      });
      const receipt = activeRow.querySelector('.transaction-receipt');
      receiptLink.classList.toggle('d-none', !receipt);
      if (receipt) receiptLink.href = receipt.href;
      const invoice = activeRow.querySelector('.transaction-invoice');
      invoiceLink.classList.toggle('d-none', !invoice);
      if (invoice) invoiceLink.href = invoice.href;
      const protectedPayment = Boolean(activeRow.querySelector('.badge[title*="preserved"]'));
      protectedNotice.classList.toggle('d-none', !protectedPayment);
      editAction.disabled = false;
      deleteAction.disabled = protectedPayment;
      deleteAction.textContent = protectedPayment ? 'Protected payment' : 'Delete Transaction';
      modal.show();
    }));
    editAction.addEventListener('click', () => { const edit = activeRow?.querySelector('.btn-edit'); if (edit) { modalElement.addEventListener('hidden.bs.modal', () => edit.click(), { once: true }); modal.hide(); } });
    deleteAction.addEventListener('click', () => { const form = activeRow?.querySelector('.tx-delete-form'); if (form) form.requestSubmit(); });
  })();

  // Handle transaction delete confirmations with appropriate warnings
  document.querySelectorAll('.tx-delete-form').forEach(function(form) {
    form.addEventListener('submit', function(event) {
      const type = form.dataset.type;
      const invoiceId = form.dataset.invoiceId;
      const invoiceNumber = form.dataset.invoiceNumber;
      let message = 'Delete this transaction? This cannot be undone.';
      if (type === 'payment' && invoiceId && invoiceId !== '0') {
        message = `Delete payment for invoice ${invoiceNumber}? This will affect the invoice balance and payment status. This cannot be undone.`;
      }
      if (!window.confirm(message)) {
        event.preventDefault();
      }
    });
  });
</script>
<script>
  (function(){
    const toggles = document.querySelectorAll('.transaction-column-toggle');
    const key = 'lmg.transaction-columns.v1';
    let saved = {};
    try { saved = JSON.parse(localStorage.getItem(key) || '{}') || {}; } catch (_) {}
    function applyColumn(name, visible) {
      document.querySelectorAll(`.tx-col-${name}`).forEach(cell => { cell.hidden = !visible; });
    }
    toggles.forEach(toggle => {
      const name = toggle.dataset.column;
      if (Object.prototype.hasOwnProperty.call(saved, name)) toggle.checked = Boolean(saved[name]);
      applyColumn(name, toggle.checked);
      toggle.addEventListener('change', () => {
        applyColumn(name, toggle.checked);
        saved[name] = toggle.checked;
        try { localStorage.setItem(key, JSON.stringify(saved)); } catch (_) {}
      });
    });
  })();
  const transactionFiltersForm = document.querySelector('form[aria-label="Filter transactions"]');
  const transactionSearchInput = document.getElementById('transactionSearch');
  let transactionSearchTimer;
  transactionSearchInput?.addEventListener('input', () => {
    window.clearTimeout(transactionSearchTimer);
    transactionSearchTimer = window.setTimeout(() => transactionFiltersForm?.requestSubmit(), 350);
  });
  ['transactionTypeFilter','transactionSort','transactionPageSize'].forEach(id => {
    document.getElementById(id)?.addEventListener('change', () => transactionFiltersForm?.requestSubmit());
  });
  [['transactionSupplierSearch','transactionSupplierFilter'],['transactionClientSearch','transactionClientFilter']].forEach(([searchId, selectId]) => {
    const search = document.getElementById(searchId);
    const select = document.getElementById(selectId);
    search?.addEventListener('input', () => {
      const term = search.value.trim().toLocaleLowerCase();
      Array.from(select.options).forEach(option => {
        option.hidden = Boolean(option.value) && !String(option.dataset.search || option.textContent).toLocaleLowerCase().includes(term);
      });
    });
  });
  const datePreset = document.getElementById('transactionDatePreset');
  datePreset?.addEventListener('change', function() {
    if (!this.value) return;
    const startInput = document.getElementById('transactionStart');
    const endInput = document.getElementById('transactionEnd');
    const today = new Date();
    const iso = date => `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
    const start = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    let end = new Date(start);
    if (this.value === 'yesterday') start.setDate(start.getDate()-1), end = new Date(start);
    if (this.value === 'this_week') start.setDate(start.getDate()-((start.getDay()+6)%7));
    if (this.value === 'last_week') { start.setDate(start.getDate()-((start.getDay()+6)%7)-7); end = new Date(start); end.setDate(end.getDate()+6); }
    if (this.value === 'this_month') start.setDate(1);
    if (this.value === 'last_month') { start.setDate(1); start.setMonth(start.getMonth()-1); end = new Date(start.getFullYear(), start.getMonth()+1, 0); }
    if (this.value === 'this_year') start.setMonth(0,1);
    startInput.value = iso(start);
    endInput.value = iso(end);
  });
</script>
<?php if (isset($_GET['edit_transaction']) && filter_var($_GET['edit_transaction'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]])): ?>
<script>
  document.addEventListener('DOMContentLoaded', function() {
    document.querySelector('#transactionsTable .btn-edit')?.click();
  });
</script>
<?php endif; ?>
<script src="js/ui.js"></script>
</body>
</html>
