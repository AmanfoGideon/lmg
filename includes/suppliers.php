<?php
declare(strict_types=1);

/**
 * Supplier Management Functions
 * Handles supplier CRUD, bills, payments, and allocations
 */

// Create a new supplier
function supplier_amount_cents(mixed $value): int {
    $amount = trim((string)$value);
    if (!preg_match('/^(\d{1,8})(?:\.(\d{1,2}))?$/', $amount, $matches)) {
        throw new InvalidArgumentException('Enter a valid amount with up to two decimal places.');
    }
    return ((int)$matches[1] * 100) + (int)str_pad($matches[2] ?? '', 2, '0');
}

function supplier_cents_decimal(int $cents): string {
    return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}

function supplier_create(mysqli $conn, array $supplier): int {
    $business_name = trim((string)($supplier['business_name'] ?? ''));
    if ($business_name === '') {
        throw new InvalidArgumentException('Business name is required.');
    }

    $contact_person = trim((string)($supplier['contact_person'] ?? ''));
    $phone = trim((string)($supplier['phone'] ?? ''));
    $email = trim((string)($supplier['email'] ?? ''));
    $address = trim((string)($supplier['address'] ?? ''));
    $category = trim((string)($supplier['category'] ?? ''));
    $tax_id = trim((string)($supplier['tax_id'] ?? ''));
    $notes = trim((string)($supplier['notes'] ?? ''));
    $status = in_array($supplier['status'] ?? 'active', ['active', 'inactive']) ? $supplier['status'] : 'active';
    $created_by = (int)($_SESSION['user_id'] ?? 0);

    $stmt = $conn->prepare("INSERT INTO suppliers (business_name, contact_person, phone, email, address, category, tax_id, notes, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('sssssssssi', $business_name, $contact_person, $phone, $email, $address, $category, $tax_id, $notes, $status, $created_by);
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();

    log_action($conn, 'supplier_create', $business_name, [
        'entity_id' => (string)$id,
        'new_values' => ['business_name' => $business_name, 'contact_person' => $contact_person, 'phone' => $phone, 'email' => $email]
    ]);

    return $id;
}

// Update a supplier
function supplier_update(mysqli $conn, int $supplier_id, array $supplier): void {
    $business_name = trim((string)($supplier['business_name'] ?? ''));
    if ($business_name === '') {
        throw new InvalidArgumentException('Business name is required.');
    }

    $contact_person = trim((string)($supplier['contact_person'] ?? ''));
    $phone = trim((string)($supplier['phone'] ?? ''));
    $email = trim((string)($supplier['email'] ?? ''));
    $address = trim((string)($supplier['address'] ?? ''));
    $category = trim((string)($supplier['category'] ?? ''));
    $tax_id = trim((string)($supplier['tax_id'] ?? ''));
    $notes = trim((string)($supplier['notes'] ?? ''));
    $status = in_array($supplier['status'] ?? 'active', ['active', 'inactive']) ? $supplier['status'] : 'active';
    $updated_by = (int)($_SESSION['user_id'] ?? 0);

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT id, business_name FROM suppliers WHERE id=? FOR UPDATE");
        $stmt->bind_param('i', $supplier_id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$existing) {
            throw new InvalidArgumentException('Supplier not found.');
        }

        $stmt = $conn->prepare("UPDATE suppliers SET business_name=?, contact_person=?, phone=?, email=?, address=?, category=?, tax_id=?, notes=?, status=?, updated_by=? WHERE id=?");
        $stmt->bind_param('ssssssssisi', $business_name, $contact_person, $phone, $email, $address, $category, $tax_id, $notes, $status, $updated_by, $supplier_id);
        $stmt->execute();
        $stmt->close();

        log_action($conn, 'supplier_update', $existing['business_name'], [
            'entity_id' => (string)$supplier_id,
            'old_values' => ['business_name' => $existing['business_name']],
            'new_values' => ['business_name' => $business_name, 'contact_person' => $contact_person, 'phone' => $phone, 'email' => $email]
        ]);

        $conn->commit();
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        throw $e;
    }
}

// Get supplier by ID
function supplier_get(mysqli $conn, int $supplier_id): ?array {
    $stmt = $conn->prepare("SELECT * FROM suppliers WHERE id=?");
    $stmt->bind_param('i', $supplier_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result ?: null;
}

// List suppliers with pagination
function supplier_list(mysqli $conn, string $search = '', int $limit = 50, int $offset = 0): array {
    $where = '';
    $params = [];
    $types = '';

    if ($search !== '') {
        $where = "WHERE business_name LIKE ? OR contact_person LIKE ? OR phone LIKE ? OR email LIKE ?";
        $search_param = "%$search%";
        $params = [$search_param, $search_param, $search_param, $search_param];
        $types = 'ssss';
    }

    $sql = "SELECT s.*,
        COALESCE(spending.total_spent, 0) AS total_spent,
        COALESCE(bills.total_purchases, 0) AS total_purchases,
        COALESCE(bills.total_paid, 0) AS total_paid,
        COALESCE(bills.outstanding_balance, 0) AS outstanding_balance,
        COALESCE(bills.overdue_balance, 0) AS overdue_balance
      FROM suppliers s
      LEFT JOIN (
        SELECT supplier_id, SUM(amount) AS total_spent
        FROM transactions
        WHERE type='expense' AND supplier_id IS NOT NULL
        GROUP BY supplier_id
      ) spending ON spending.supplier_id=s.id
      LEFT JOIN (
        SELECT sb.supplier_id,
          SUM(CASE WHEN sb.status <> 'cancelled' THEN sb.total ELSE 0 END) AS total_purchases,
          SUM(CASE WHEN sb.status <> 'cancelled' THEN COALESCE(a.allocated, 0) ELSE 0 END) AS total_paid,
          SUM(CASE WHEN sb.status IN ('unpaid','partial','overdue') THEN GREATEST(sb.total-COALESCE(a.allocated,0),0) ELSE 0 END) AS outstanding_balance,
          SUM(CASE WHEN sb.status IN ('unpaid','partial','overdue') AND sb.due_date<CURRENT_DATE THEN GREATEST(sb.total-COALESCE(a.allocated,0),0) ELSE 0 END) AS overdue_balance
        FROM supplier_bills sb
        LEFT JOIN (
          SELECT bill_id, SUM(amount) AS allocated
          FROM supplier_payment_allocations WHERE reversed_at IS NULL GROUP BY bill_id
        ) a ON a.bill_id=sb.id
        GROUP BY sb.supplier_id
      ) bills ON bills.supplier_id=s.id
      $where ORDER BY s.business_name ASC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $result;
}

// Count suppliers
function supplier_count(mysqli $conn, string $search = ''): int {
    $where = '';
    $params = [];
    $types = '';

    if ($search !== '') {
        $where = "WHERE business_name LIKE ? OR contact_person LIKE ? OR phone LIKE ? OR email LIKE ?";
        $search_param = "%$search%";
        $params = [$search_param, $search_param, $search_param, $search_param];
        $types = 'ssss';
    }

    $sql = "SELECT COUNT(*) as count FROM suppliers $where";
    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();

    return $count;
}

// Get supplier financial summary
function supplier_get_summary(mysqli $conn, int $supplier_id): array {
    $stmt = $conn->prepare("SELECT
      COALESCE(SUM(CASE WHEN sb.status <> 'cancelled' THEN sb.total ELSE 0 END), 0) AS total_purchases,
      COALESCE(SUM(CASE WHEN sb.status <> 'cancelled' THEN COALESCE(a.allocated, 0) ELSE 0 END), 0) AS total_paid,
      COALESCE(SUM(CASE WHEN sb.status IN ('unpaid','partial','overdue') THEN GREATEST(sb.total - COALESCE(a.allocated, 0), 0) ELSE 0 END), 0) AS outstanding_balance,
      COALESCE(SUM(CASE WHEN sb.status IN ('unpaid','partial','overdue') AND sb.due_date < CURRENT_DATE THEN GREATEST(sb.total - COALESCE(a.allocated, 0), 0) ELSE 0 END), 0) AS overdue_balance
      FROM supplier_bills sb
      LEFT JOIN (SELECT bill_id, SUM(amount) AS allocated FROM supplier_payment_allocations WHERE reversed_at IS NULL GROUP BY bill_id) a ON a.bill_id=sb.id
      WHERE sb.supplier_id=?");
    $stmt->bind_param('i', $supplier_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $creditStmt = $conn->prepare("SELECT GREATEST(COALESCE(SUM(sp.amount), 0) - COALESCE((SELECT SUM(amount) FROM supplier_payment_allocations spa JOIN supplier_payments sp2 ON sp2.id=spa.payment_id WHERE sp2.supplier_id=? AND sp2.status<>'cancelled' AND spa.reversed_at IS NULL), 0), 0) AS credit_balance FROM supplier_payments sp WHERE sp.supplier_id=? AND sp.status<>'cancelled'");
    $creditStmt->bind_param('ii', $supplier_id, $supplier_id);
    $creditStmt->execute();
    $row['unallocated_credit'] = (float)$creditStmt->get_result()->fetch_assoc()['credit_balance'];
    $creditStmt->close();
    return array_map(static fn($amount) => (float)$amount, $row);
}

// Create supplier bill
function supplier_bill_create(mysqli $conn, array $bill): int {
    $supplier_id = (int)($bill['supplier_id'] ?? 0);
    if ($supplier_id <= 0) {
        throw new InvalidArgumentException('Supplier is required.');
    }

    $bill_number = trim((string)($bill['bill_number'] ?? ''));
    $bill_date = trim((string)($bill['bill_date'] ?? date('Y-m-d')));
    $due_date = trim((string)($bill['due_date'] ?? ''));
    $description = trim((string)($bill['description'] ?? ''));
    $category = trim((string)($bill['category'] ?? ''));
    $subtotalCents = supplier_amount_cents($bill['subtotal'] ?? '0');
    $taxCents = supplier_amount_cents($bill['tax'] ?? '0');
    $totalCents = supplier_amount_cents($bill['total'] ?? '0');
    $subtotal = supplier_cents_decimal($subtotalCents);
    $tax = supplier_cents_decimal($taxCents);
    $total = supplier_cents_decimal($totalCents);
    $payment_terms = trim((string)($bill['payment_terms'] ?? ''));
    $notes = trim((string)($bill['notes'] ?? ''));
    $created_by = (int)($_SESSION['user_id'] ?? 0);

    if ($bill_number === '') {
        // Auto-generate bill number
        $bill_number = 'BILL-' . date('Ym') . '-' . str_pad((string)rand(1, 9999), 4, '0', STR_PAD_LEFT);
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bill_date)) {
        throw new InvalidArgumentException('Invalid bill date.');
    }

    if ($due_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
        throw new InvalidArgumentException('Invalid due date.');
    }

    if ($totalCents <= 0 || $subtotalCents + $taxCents !== $totalCents) {
        throw new InvalidArgumentException('Bill amounts must be positive and total must equal subtotal plus tax.');
    }

    $conn->begin_transaction();
    try {
        // Check for duplicate bill number
        $check = $conn->prepare("SELECT id FROM supplier_bills WHERE supplier_id=? AND bill_number=? LIMIT 1");
        $check->bind_param('is', $supplier_id, $bill_number);
        $check->execute();
        if ($check->get_result()->fetch_row()) {
            throw new InvalidArgumentException('Bill number already exists for this supplier.');
        }
        $check->close();

        $stmt = $conn->prepare("INSERT INTO supplier_bills (supplier_id, bill_number, bill_date, due_date, description, category, subtotal, tax, total, payment_terms, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('issssssssssi', $supplier_id, $bill_number, $bill_date, $due_date, $description, $category, $subtotal, $tax, $total, $payment_terms, $notes, $created_by);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();

        log_action($conn, 'supplier_bill_create', $bill_number, [
            'entity_id' => (string)$id,
            'new_values' => ['supplier_id' => $supplier_id, 'bill_number' => $bill_number, 'total' => $total]
        ]);

        $conn->commit();
        return $id;
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        throw $e;
    }
}

// Create supplier payment
function supplier_payment_create(mysqli $conn, array $payment): int {
    $supplier_id = (int)($payment['supplier_id'] ?? 0);
    if ($supplier_id <= 0) {
        throw new InvalidArgumentException('Supplier is required.');
    }

    $payment_number = trim((string)($payment['payment_number'] ?? ''));
    $payment_date = trim((string)($payment['payment_date'] ?? date('Y-m-d')));
    $amountCents = supplier_amount_cents($payment['amount'] ?? '0');
    $amount = supplier_cents_decimal($amountCents);
    $payment_method = trim((string)($payment['payment_method'] ?? ''));
    $reference_number = trim((string)($payment['reference_number'] ?? ''));
    $description = trim((string)($payment['description'] ?? ''));
    $notes = trim((string)($payment['notes'] ?? ''));
    $created_by = (int)($_SESSION['user_id'] ?? 0);

    if (!in_array($payment_method, ['cash', 'mobile', 'card', 'bank_transfer', 'cheque'])) {
        throw new InvalidArgumentException('Invalid payment method.');
    }

    if ($amountCents <= 0) {
        throw new InvalidArgumentException('Payment amount must be positive.');
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payment_date)) {
        throw new InvalidArgumentException('Invalid payment date.');
    }

    if ($payment_number === '') {
        // Auto-generate payment number
        $payment_number = 'PAY-' . date('Ym') . '-' . str_pad((string)rand(1, 9999), 4, '0', STR_PAD_LEFT);
    }

    $conn->begin_transaction();
    try {
        // Check for duplicate payment number
        $check = $conn->prepare("SELECT id FROM supplier_payments WHERE supplier_id=? AND payment_number=? LIMIT 1");
        $check->bind_param('is', $supplier_id, $payment_number);
        $check->execute();
        if ($check->get_result()->fetch_row()) {
            throw new InvalidArgumentException('Payment number already exists for this supplier.');
        }
        $check->close();

        $stmt = $conn->prepare("INSERT INTO supplier_payments (supplier_id, payment_number, payment_date, amount, payment_method, reference_number, description, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('isssssssi', $supplier_id, $payment_number, $payment_date, $amount, $payment_method, $reference_number, $description, $notes, $created_by);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();

        log_action($conn, 'supplier_payment_create', $payment_number, [
            'entity_id' => (string)$id,
            'new_values' => ['supplier_id' => $supplier_id, 'payment_number' => $payment_number, 'amount' => $amount]
        ]);

        $conn->commit();
        return $id;
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        throw $e;
    }
}

// Allocate payment to bill
function supplier_payment_allocate(mysqli $conn, int $payment_id, int $bill_id, mixed $amountInput): void {
    $amountCents = supplier_amount_cents($amountInput);
    if ($amountCents <= 0) {
        throw new InvalidArgumentException('Allocation amount must be positive.');
    }
    $amount = supplier_cents_decimal($amountCents);

    $conn->begin_transaction();
    try {
        // Get payment details
        $stmt = $conn->prepare("SELECT supplier_id, amount, status FROM supplier_payments WHERE id=? FOR UPDATE");
        $stmt->bind_param('i', $payment_id);
        $stmt->execute();
        $payment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$payment) {
            throw new InvalidArgumentException('Payment not found.');
        }

        if ($payment['status'] === 'cancelled') {
            throw new InvalidArgumentException('Cannot allocate cancelled payments.');
        }

        // Get bill details
        $stmt = $conn->prepare("SELECT supplier_id, total, status FROM supplier_bills WHERE id=? FOR UPDATE");
        $stmt->bind_param('i', $bill_id);
        $stmt->execute();
        $bill = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$bill) {
            throw new InvalidArgumentException('Bill not found.');
        }

        if ($bill['status'] === 'cancelled') {
            throw new InvalidArgumentException('Cannot allocate to cancelled bills.');
        }

        if ($payment['supplier_id'] != $bill['supplier_id']) {
            throw new InvalidArgumentException('Payment and bill must belong to the same supplier.');
        }

        // Check existing allocations
        $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as allocated FROM supplier_payment_allocations WHERE payment_id=? AND reversed_at IS NULL");
        $stmt->bind_param('i', $payment_id);
        $stmt->execute();
        $already_allocated = (string)$stmt->get_result()->fetch_assoc()['allocated'];
        $stmt->close();

        if (supplier_amount_cents($already_allocated) + $amountCents > supplier_amount_cents($payment['amount'])) {
            throw new InvalidArgumentException('Allocation amount exceeds payment total.');
        }

        // Check bill remaining balance
        $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as allocated FROM supplier_payment_allocations WHERE bill_id=? AND reversed_at IS NULL");
        $stmt->bind_param('i', $bill_id);
        $stmt->execute();
        $bill_allocated = (string)$stmt->get_result()->fetch_assoc()['allocated'];
        $stmt->close();

        if (supplier_amount_cents($bill_allocated) + $amountCents > supplier_amount_cents($bill['total'])) {
            throw new InvalidArgumentException('Allocation amount exceeds bill total.');
        }

        // Check for duplicate allocation
        $stmt = $conn->prepare("SELECT id FROM supplier_payment_allocations WHERE payment_id=? AND bill_id=? AND reversed_at IS NULL LIMIT 1");
        $stmt->bind_param('ii', $payment_id, $bill_id);
        $stmt->execute();
        if ($stmt->get_result()->fetch_row()) {
            throw new InvalidArgumentException('This payment is already allocated to this bill.');
        }
        $stmt->close();

        // Create allocation
        $allocated_by = (int)($_SESSION['user_id'] ?? 0);
        $stmt = $conn->prepare("INSERT INTO supplier_payment_allocations (payment_id, bill_id, amount, allocated_by) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('iisi', $payment_id, $bill_id, $amount, $allocated_by);
        $stmt->execute();
        $stmt->close();

        // Update payment status
        $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as allocated FROM supplier_payment_allocations WHERE payment_id=? AND reversed_at IS NULL");
        $stmt->bind_param('i', $payment_id);
        $stmt->execute();
        $new_allocated = (string)$stmt->get_result()->fetch_assoc()['allocated'];
        $stmt->close();

        $newAllocatedCents = supplier_amount_cents($new_allocated);
        $new_status = $newAllocatedCents >= supplier_amount_cents($payment['amount']) ? 'allocated' : ($newAllocatedCents > 0 ? 'partially_allocated' : 'unallocated');
        $stmt = $conn->prepare("UPDATE supplier_payments SET status=? WHERE id=?");
        $stmt->bind_param('si', $new_status, $payment_id);
        $stmt->execute();
        $stmt->close();

        // Recalculate bill status
        supplier_bill_recalculate_status($conn, $bill_id);

        log_action($conn, 'supplier_payment_allocate', "$payment_id|$bill_id|$amount", [
            'entity_id' => (string)$payment_id,
            'new_values' => ['payment_id' => $payment_id, 'bill_id' => $bill_id, 'amount' => $amount]
        ]);

        $conn->commit();
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        throw $e;
    }
}

// Recalculate bill status
function supplier_bill_recalculate_status(mysqli $conn, int $bill_id): void {
    $stmt = $conn->prepare("SELECT total, due_date, status FROM supplier_bills WHERE id=?");
    $stmt->bind_param('i', $bill_id);
    $stmt->execute();
    $bill = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$bill) {
        return;
    }

    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as allocated FROM supplier_payment_allocations WHERE bill_id=? AND reversed_at IS NULL");
    $stmt->bind_param('i', $bill_id);
    $stmt->execute();
    $allocated = (string)$stmt->get_result()->fetch_assoc()['allocated'];
    $stmt->close();

    if ($bill['status'] === 'cancelled') return;
    $allocatedCents = supplier_amount_cents($allocated);
    $totalCents = supplier_amount_cents($bill['total']);
    $status = $allocatedCents >= $totalCents ? 'paid' : ($allocatedCents > 0 ? 'partial' : 'unpaid');

    // Check if overdue
    if (($status === 'unpaid' || $status === 'partial') && $bill['due_date'] && $bill['due_date'] < date('Y-m-d')) {
        $status = 'overdue';
    }

    $stmt = $conn->prepare("UPDATE supplier_bills SET status=? WHERE id=?");
    $stmt->bind_param('si', $status, $bill_id);
    $stmt->execute();
    $stmt->close();
}

// Update supplier bill
function supplier_bill_update(mysqli $conn, int $bill_id, array $bill): void {
  $supplier_id = (int)($bill['supplier_id'] ?? 0);
  if ($supplier_id <= 0) {
    throw new InvalidArgumentException('Supplier is required.');
  }

  $bill_number = trim((string)($bill['bill_number'] ?? ''));
  $bill_date = trim((string)($bill['bill_date'] ?? ''));
  $due_date = trim((string)($bill['due_date'] ?? ''));
  $description = trim((string)($bill['description'] ?? ''));
  $category = trim((string)($bill['category'] ?? ''));
  $subtotalCents = supplier_amount_cents($bill['subtotal'] ?? '0');
  $taxCents = supplier_amount_cents($bill['tax'] ?? '0');
  $totalCents = supplier_amount_cents($bill['total'] ?? '0');
  $subtotal = supplier_cents_decimal($subtotalCents);
  $tax = supplier_cents_decimal($taxCents);
  $total = supplier_cents_decimal($totalCents);
  $payment_terms = trim((string)($bill['payment_terms'] ?? ''));
  $notes = trim((string)($bill['notes'] ?? ''));
  $updated_by = (int)($_SESSION['user_id'] ?? 0);

  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bill_date)) {
    throw new InvalidArgumentException('Invalid bill date.');
  }

  if ($due_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
    throw new InvalidArgumentException('Invalid due date.');
  }

  if ($totalCents <= 0 || $subtotalCents + $taxCents !== $totalCents) {
    throw new InvalidArgumentException('Bill amounts must be positive and total must equal subtotal plus tax.');
  }

  $conn->begin_transaction();
  try {
    $stmt = $conn->prepare("SELECT id, bill_number, supplier_id, total, status FROM supplier_bills WHERE id=? FOR UPDATE");
    $stmt->bind_param('i', $bill_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$existing) {
      throw new InvalidArgumentException('Bill not found.');
    }

    if ($existing['status'] === 'cancelled') {
      throw new InvalidArgumentException('Cannot update cancelled bills.');
    }

    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS allocated FROM supplier_payment_allocations WHERE bill_id=? AND reversed_at IS NULL");
    $stmt->bind_param('i', $bill_id);
    $stmt->execute();
    $allocated = (string)$stmt->get_result()->fetch_assoc()['allocated'];
    $stmt->close();
    if ($supplier_id !== (int)$existing['supplier_id'] && supplier_amount_cents($allocated) > 0) {
      throw new InvalidArgumentException('A bill with allocated payments cannot be moved to another supplier.');
    }
    if ($totalCents < supplier_amount_cents($allocated)) {
      throw new InvalidArgumentException('Bill total cannot be reduced below its allocated payments.');
    }

    // Check for duplicate bill number if changed
    if ($bill_number !== $existing['bill_number']) {
      $check = $conn->prepare("SELECT id FROM supplier_bills WHERE supplier_id=? AND bill_number=? AND id!=? LIMIT 1");
      $check->bind_param('isi', $supplier_id, $bill_number, $bill_id);
      $check->execute();
      if ($check->get_result()->fetch_row()) {
        throw new InvalidArgumentException('Bill number already exists for this supplier.');
      }
      $check->close();
    }

    $stmt = $conn->prepare("UPDATE supplier_bills SET supplier_id=?, bill_number=?, bill_date=?, due_date=?, description=?, category=?, subtotal=?, tax=?, total=?, payment_terms=?, notes=?, updated_by=? WHERE id=?");
    $stmt->bind_param('issssssssssii', $supplier_id, $bill_number, $bill_date, $due_date, $description, $category, $subtotal, $tax, $total, $payment_terms, $notes, $updated_by, $bill_id);
    $stmt->execute();
    $stmt->close();

    log_action($conn, 'supplier_bill_update', $existing['bill_number'], [
      'entity_id' => (string)$bill_id,
      'old_values' => ['bill_number' => $existing['bill_number'], 'total' => $existing['total']],
      'new_values' => ['bill_number' => $bill_number, 'total' => $total]
    ]);

    // Recalculate status
    supplier_bill_recalculate_status($conn, $bill_id);

    $conn->commit();
  } catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    throw $e;
  }
}

// Cancel supplier bill
function supplier_bill_cancel(mysqli $conn, int $bill_id, string $reason): void {
  if (trim($reason) === '') {
    throw new InvalidArgumentException('Cancellation reason is required.');
  }

  $conn->begin_transaction();
  try {
    $stmt = $conn->prepare("SELECT id, bill_number, supplier_id, total, status FROM supplier_bills WHERE id=? FOR UPDATE");
    $stmt->bind_param('i', $bill_id);
    $stmt->execute();
    $bill = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$bill) {
      throw new InvalidArgumentException('Bill not found.');
    }

    if ($bill['status'] === 'cancelled') {
      throw new InvalidArgumentException('Bill is already cancelled.');
    }

    // Check if there are allocated payments
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as allocated FROM supplier_payment_allocations WHERE bill_id=? AND reversed_at IS NULL");
    $stmt->bind_param('i', $bill_id);
    $stmt->execute();
    $allocated = (string)$stmt->get_result()->fetch_assoc()['allocated'];
    $stmt->close();

      if (supplier_amount_cents($allocated) > 0) {
      throw new InvalidArgumentException('Cannot cancel bill with allocated payments. Remove allocations first.');
    }

    $stmt = $conn->prepare("UPDATE supplier_bills SET status='cancelled', notes=CONCAT(COALESCE(notes, ''), '\n\nCancellation: ', ?) WHERE id=?");
    $stmt->bind_param('si', $reason, $bill_id);
    $stmt->execute();
    $stmt->close();

    log_action($conn, 'supplier_bill_cancel', $bill['bill_number'], [
      'entity_id' => (string)$bill_id,
      'old_values' => ['status' => $bill['status'], 'total' => $bill['total']],
      'new_values' => ['status' => 'cancelled', 'reason' => $reason]
    ]);

    $conn->commit();
  } catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    throw $e;
  }
}

// Get supplier bill by ID
function supplier_bill_get(mysqli $conn, int $bill_id): ?array {
  $stmt = $conn->prepare("SELECT sb.*, s.business_name FROM supplier_bills sb LEFT JOIN suppliers s ON sb.supplier_id = s.id WHERE sb.id=?");
  $stmt->bind_param('i', $bill_id);
  $stmt->execute();
  $result = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $result ?: null;
}

// List supplier bills with pagination
function supplier_bill_list(mysqli $conn, ?int $supplier_id = null, string $status_filter = '', string $search = '', int $limit = 50, int $offset = 0): array {
  $where = 'WHERE 1=1';
  $params = [];
  $types = '';

  if ($supplier_id > 0) {
    $where .= ' AND sb.supplier_id=?';
    $params[] = $supplier_id;
    $types .= 'i';
  }

  if ($status_filter !== '' && in_array($status_filter, ['unpaid', 'partial', 'paid', 'overdue', 'cancelled'])) {
    $where .= ' AND sb.status=?';
    $params[] = $status_filter;
    $types .= 's';
  }

  if ($search !== '') {
    $where .= ' AND (sb.bill_number LIKE ? OR sb.description LIKE ? OR s.business_name LIKE ?)';
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'sss';
  }

  $sql = "SELECT sb.*, s.business_name FROM supplier_bills sb LEFT JOIN suppliers s ON sb.supplier_id = s.id $where ORDER BY sb.bill_date DESC, sb.id DESC LIMIT ? OFFSET ?";
  $params[] = $limit;
  $params[] = $offset;
  $types .= 'ii';

  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  return $result;
}

// Count supplier bills
function supplier_bill_count(mysqli $conn, ?int $supplier_id = null, string $status_filter = '', string $search = ''): int {
  $where = 'WHERE 1=1';
  $params = [];
  $types = '';

  if ($supplier_id > 0) {
    $where .= ' AND supplier_id=?';
    $params[] = $supplier_id;
    $types .= 'i';
  }

  if ($status_filter !== '' && in_array($status_filter, ['unpaid', 'partial', 'paid', 'overdue', 'cancelled'])) {
    $where .= ' AND status=?';
    $params[] = $status_filter;
    $types .= 's';
  }

  if ($search !== '') {
    $where .= ' AND (bill_number LIKE ? OR description LIKE ?)';
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ss';
  }

  $sql = "SELECT COUNT(*) as count FROM supplier_bills $where";
  $stmt = $conn->prepare($sql);
  if ($params) {
    $stmt->bind_param($types, ...$params);
  }
  $stmt->execute();
  $count = (int)$stmt->get_result()->fetch_assoc()['count'];
  $stmt->close();

  return $count;
}

// Get bill allocation details
function supplier_bill_get_allocations(mysqli $conn, int $bill_id): array {
  $stmt = $conn->prepare("SELECT spa.*, sp.payment_number, sp.payment_date, sp.payment_method FROM supplier_payment_allocations spa LEFT JOIN supplier_payments sp ON spa.payment_id = sp.id WHERE spa.bill_id=? AND spa.reversed_at IS NULL ORDER BY spa.allocated_at DESC");
  $stmt->bind_param('i', $bill_id);
  $stmt->execute();
  $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  return $result;
}

// Update supplier payment
function supplier_payment_update(mysqli $conn, int $payment_id, array $payment): void {
  $supplier_id = (int)($payment['supplier_id'] ?? 0);
  if ($supplier_id <= 0) {
    throw new InvalidArgumentException('Supplier is required.');
  }

  $payment_number = trim((string)($payment['payment_number'] ?? ''));
  $payment_date = trim((string)($payment['payment_date'] ?? ''));
  $amountCents = supplier_amount_cents($payment['amount'] ?? '0');
  $amount = supplier_cents_decimal($amountCents);
  $payment_method = trim((string)($payment['payment_method'] ?? ''));
  $reference_number = trim((string)($payment['reference_number'] ?? ''));
  $description = trim((string)($payment['description'] ?? ''));
  $notes = trim((string)($payment['notes'] ?? ''));
  $updated_by = (int)($_SESSION['user_id'] ?? 0);

  if (!in_array($payment_method, ['cash', 'mobile', 'card', 'bank_transfer', 'cheque'])) {
    throw new InvalidArgumentException('Invalid payment method.');
  }

  if ($amountCents <= 0) {
    throw new InvalidArgumentException('Payment amount must be positive.');
  }

  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payment_date)) {
    throw new InvalidArgumentException('Invalid payment date.');
  }

  $conn->begin_transaction();
  try {
    $stmt = $conn->prepare("SELECT id, payment_number, supplier_id, amount, status FROM supplier_payments WHERE id=? FOR UPDATE");
    $stmt->bind_param('i', $payment_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$existing) {
      throw new InvalidArgumentException('Payment not found.');
    }

    if ($existing['status'] === 'cancelled') {
      throw new InvalidArgumentException('Cannot update cancelled payments.');
    }

    $allocationCheck = $conn->prepare("SELECT COUNT(*) AS count FROM supplier_payment_allocations WHERE payment_id=? AND reversed_at IS NULL");
    $allocationCheck->bind_param('i', $payment_id);
    $allocationCheck->execute();
    $hasActiveAllocations = (int)$allocationCheck->get_result()->fetch_assoc()['count'] > 0;
    $allocationCheck->close();
    if ($hasActiveAllocations && $supplier_id !== (int)$existing['supplier_id']) {
      throw new InvalidArgumentException('Reverse the payment allocations before changing its supplier.');
    }

    // Check for duplicate payment number if changed
    if ($payment_number !== $existing['payment_number']) {
      $check = $conn->prepare("SELECT id FROM supplier_payments WHERE supplier_id=? AND payment_number=? AND id!=? LIMIT 1");
      $check->bind_param('isi', $supplier_id, $payment_number, $payment_id);
      $check->execute();
      if ($check->get_result()->fetch_row()) {
        throw new InvalidArgumentException('Payment number already exists for this supplier.');
      }
      $check->close();
    }

    // Check if amount is being reduced and would break allocations
    if ($amountCents < supplier_amount_cents($existing['amount'])) {
      $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as allocated FROM supplier_payment_allocations WHERE payment_id=? AND reversed_at IS NULL");
      $stmt->bind_param('i', $payment_id);
      $stmt->execute();
      $allocated = (string)$stmt->get_result()->fetch_assoc()['allocated'];
      $stmt->close();

      if (supplier_amount_cents($allocated) > $amountCents) {
        throw new InvalidArgumentException('Cannot reduce payment amount below allocated amount. Remove allocations first.');
      }
    }

    $stmt = $conn->prepare("UPDATE supplier_payments SET supplier_id=?, payment_number=?, payment_date=?, amount=?, payment_method=?, reference_number=?, description=?, notes=?, updated_by=? WHERE id=?");
    $stmt->bind_param('issssssssi', $supplier_id, $payment_number, $payment_date, $amount, $payment_method, $reference_number, $description, $notes, $updated_by, $payment_id);
    $stmt->execute();
    $stmt->close();

    log_action($conn, 'supplier_payment_update', $existing['payment_number'], [
      'entity_id' => (string)$payment_id,
      'old_values' => ['payment_number' => $existing['payment_number'], 'amount' => $existing['amount']],
      'new_values' => ['payment_number' => $payment_number, 'amount' => $amount]
    ]);

    // Recalculate payment status
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as allocated FROM supplier_payment_allocations WHERE payment_id=? AND reversed_at IS NULL");
    $stmt->bind_param('i', $payment_id);
    $stmt->execute();
    $new_allocated = (string)$stmt->get_result()->fetch_assoc()['allocated'];
    $stmt->close();

    $newAllocatedCents = supplier_amount_cents($new_allocated);
    $new_status = $newAllocatedCents >= $amountCents ? 'allocated' : ($newAllocatedCents > 0 ? 'partially_allocated' : 'unallocated');
    $stmt = $conn->prepare("UPDATE supplier_payments SET status=? WHERE id=?");
    $stmt->bind_param('si', $new_status, $payment_id);
    $stmt->execute();
    $stmt->close();

    // Recalculate all affected bill statuses
    $stmt = $conn->prepare("SELECT bill_id FROM supplier_payment_allocations WHERE payment_id=?");
    $stmt->bind_param('i', $payment_id);
    $stmt->execute();
    $bill_ids = [];
    while ($row = $stmt->get_result()->fetch_assoc()) {
      $bill_ids[] = (int)$row['bill_id'];
    }
    $stmt->close();

    foreach ($bill_ids as $bill_id) {
      supplier_bill_recalculate_status($conn, $bill_id);
    }

    $conn->commit();
  } catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    throw $e;
  }
}

// Cancel supplier payment
function supplier_payment_cancel(mysqli $conn, int $payment_id, string $reason): void {
  if (trim($reason) === '') {
    throw new InvalidArgumentException('Cancellation reason is required.');
  }

  $conn->begin_transaction();
  try {
    $stmt = $conn->prepare("SELECT id, payment_number, supplier_id, amount, status FROM supplier_payments WHERE id=? FOR UPDATE");
    $stmt->bind_param('i', $payment_id);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$payment) {
      throw new InvalidArgumentException('Payment not found.');
    }

    if ($payment['status'] === 'cancelled') {
      throw new InvalidArgumentException('Payment is already cancelled.');
    }

    // Check if there are allocations
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM supplier_payment_allocations WHERE payment_id=? AND reversed_at IS NULL");
    $stmt->bind_param('i', $payment_id);
    $stmt->execute();
    $allocation_count = (int)$stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();

    if ($allocation_count > 0) {
      throw new InvalidArgumentException('A payment with allocations cannot be cancelled. Reverse its allocations first to preserve the payment history.');
    }

    $stmt = $conn->prepare("UPDATE supplier_payments SET status='cancelled', notes=CONCAT(COALESCE(notes, ''), '\n\nCancellation: ', ?) WHERE id=?");
    $stmt->bind_param('si', $reason, $payment_id);
    $stmt->execute();
    $stmt->close();

    log_action($conn, 'supplier_payment_cancel', $payment['payment_number'], [
      'entity_id' => (string)$payment_id,
      'old_values' => ['status' => $payment['status'], 'amount' => $payment['amount']],
      'new_values' => ['status' => 'cancelled', 'reason' => $reason]
    ]);

    $conn->commit();
  } catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    throw $e;
  }
}

// Remove payment allocation
function supplier_payment_remove_allocation(mysqli $conn, int $payment_id, int $bill_id, string $reason): void {
  if (trim($reason) === '') throw new InvalidArgumentException('Allocation reversal reason is required.');
  $conn->begin_transaction();
  try {
    $stmt = $conn->prepare("SELECT id, amount FROM supplier_payment_allocations WHERE payment_id=? AND bill_id=? AND reversed_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $stmt->bind_param('ii', $payment_id, $bill_id);
    $stmt->execute();
    $allocation = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$allocation) {
      throw new InvalidArgumentException('Allocation not found.');
    }

    $allocationId = (int)$allocation['id'];
    $reason = trim($reason);
    $actorId = (int)($_SESSION['user_id'] ?? 0);
    $stmt = $conn->prepare("UPDATE supplier_payment_allocations SET reversed_at=UTC_TIMESTAMP(), reversed_by=?, reversal_reason=? WHERE id=? AND reversed_at IS NULL");
    $stmt->bind_param('isi', $actorId, $reason, $allocationId);
    $stmt->execute();
    $stmt->close();

    // Update payment status
    $stmt = $conn->prepare("SELECT sp.amount, COALESCE(SUM(CASE WHEN spa.reversed_at IS NULL THEN spa.amount ELSE 0 END), 0) as allocated FROM supplier_payments sp LEFT JOIN supplier_payment_allocations spa ON sp.id = spa.payment_id WHERE sp.id=? GROUP BY sp.id, sp.amount");
    $stmt->bind_param('i', $payment_id);
    $stmt->execute();
    $payment_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($payment_data) {
      $paymentAllocatedCents = supplier_amount_cents($payment_data['allocated'] ?? '0');
      $paymentAmountCents = supplier_amount_cents($payment_data['amount']);
      $new_status = $paymentAllocatedCents >= $paymentAmountCents ? 'allocated' : ($paymentAllocatedCents > 0 ? 'partially_allocated' : 'unallocated');
      $stmt = $conn->prepare("UPDATE supplier_payments SET status=? WHERE id=?");
      $stmt->bind_param('si', $new_status, $payment_id);
      $stmt->execute();
      $stmt->close();
    }

    // Recalculate bill status
    supplier_bill_recalculate_status($conn, $bill_id);

    log_action($conn, 'supplier_payment_allocation_reversal', "$payment_id|$bill_id", [
      'entity_id' => (string)$payment_id,
      'old_values' => ['payment_id' => $payment_id, 'bill_id' => $bill_id, 'amount' => $allocation['amount']],
      'new_values' => ['status' => 'reversed', 'reason' => $reason]
    ]);

    $conn->commit();
  } catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    throw $e;
  }
}

// Get supplier payment by ID
function supplier_payment_get(mysqli $conn, int $payment_id): ?array {
  $stmt = $conn->prepare("SELECT sp.*, s.business_name FROM supplier_payments sp LEFT JOIN suppliers s ON sp.supplier_id = s.id WHERE sp.id=?");
  $stmt->bind_param('i', $payment_id);
  $stmt->execute();
  $result = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $result ?: null;
}

// List supplier payments with pagination
function supplier_payment_list(mysqli $conn, ?int $supplier_id = null, string $status_filter = '', string $search = '', int $limit = 50, int $offset = 0): array {
  $where = 'WHERE 1=1';
  $params = [];
  $types = '';

  if ($supplier_id > 0) {
    $where .= ' AND sp.supplier_id=?';
    $params[] = $supplier_id;
    $types .= 'i';
  }

  if ($status_filter !== '' && in_array($status_filter, ['pending', 'allocated', 'partially_allocated', 'unallocated', 'cancelled'])) {
    $where .= ' AND sp.status=?';
    $params[] = $status_filter;
    $types .= 's';
  }

  if ($search !== '') {
    $where .= ' AND (sp.payment_number LIKE ? OR sp.description LIKE ? OR s.business_name LIKE ?)';
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'sss';
  }

  $sql = "SELECT sp.*, s.business_name FROM supplier_payments sp LEFT JOIN suppliers s ON sp.supplier_id = s.id $where ORDER BY sp.payment_date DESC, sp.id DESC LIMIT ? OFFSET ?";
  $params[] = $limit;
  $params[] = $offset;
  $types .= 'ii';

  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  return $result;
}

// Count supplier payments
function supplier_payment_count(mysqli $conn, ?int $supplier_id = null, string $status_filter = '', string $search = ''): int {
  $where = 'WHERE 1=1';
  $params = [];
  $types = '';

  if ($supplier_id > 0) {
    $where .= ' AND supplier_id=?';
    $params[] = $supplier_id;
    $types .= 'i';
  }

  if ($status_filter !== '' && in_array($status_filter, ['pending', 'allocated', 'partially_allocated', 'unallocated', 'cancelled'])) {
    $where .= ' AND status=?';
    $params[] = $status_filter;
    $types .= 's';
  }

  if ($search !== '') {
    $where .= ' AND (payment_number LIKE ? OR description LIKE ?)';
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ss';
  }

  $sql = "SELECT COUNT(*) as count FROM supplier_payments $where";
  $stmt = $conn->prepare($sql);
  if ($params) {
    $stmt->bind_param($types, ...$params);
  }
  $stmt->execute();
  $count = (int)$stmt->get_result()->fetch_assoc()['count'];
  $stmt->close();

  return $count;
}

// Get payment allocation details
function supplier_payment_get_allocations(mysqli $conn, int $payment_id): array {
  $stmt = $conn->prepare("SELECT spa.*, sb.bill_number, sb.bill_date, sb.total as bill_total, sp.payment_number FROM supplier_payment_allocations spa LEFT JOIN supplier_bills sb ON spa.bill_id = sb.id LEFT JOIN supplier_payments sp ON spa.payment_id=sp.id WHERE spa.payment_id=? ORDER BY spa.allocated_at DESC");
  $stmt->bind_param('i', $payment_id);
  $stmt->execute();
  $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  return $result;
}

// Get overall supplier statistics for dashboard
function supplier_get_stats(mysqli $conn): array {
  $stats = [
    'total_suppliers' => 0,
    'active_suppliers' => 0,
    'total_purchases' => 0,
    'total_payments' => 0,
    'total_outstanding' => 0,
    'total_overdue' => 0,
    'recent_bills' => [],
    'recent_payments' => []
  ];

  // Total and active suppliers
  $res = $conn->query("SELECT COUNT(*) as total, SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active FROM suppliers");
  if ($row = $res->fetch_assoc()) {
    $stats['total_suppliers'] = (int)$row['total'];
    $stats['active_suppliers'] = (int)$row['active'];
  }

  // Total purchases and payments
  $res = $conn->query("SELECT COALESCE(SUM(total), 0) as purchases FROM supplier_bills WHERE status!='cancelled'");
  if ($row = $res->fetch_assoc()) {
    $stats['total_purchases'] = (float)$row['purchases'];
  }

  $res = $conn->query("SELECT COALESCE(SUM(amount), 0) as payments FROM supplier_payments WHERE status!='cancelled'");
  if ($row = $res->fetch_assoc()) {
    $stats['total_payments'] = (float)$row['payments'];
  }

  // Total outstanding
  $res = $conn->query("SELECT COALESCE(SUM(GREATEST(sb.total - COALESCE(a.allocated, 0), 0)), 0) AS outstanding FROM supplier_bills sb LEFT JOIN (SELECT bill_id, SUM(amount) AS allocated FROM supplier_payment_allocations WHERE reversed_at IS NULL GROUP BY bill_id) a ON a.bill_id=sb.id WHERE sb.status IN ('unpaid', 'partial', 'overdue')");
  if ($row = $res->fetch_assoc()) {
    $stats['total_outstanding'] = (float)$row['outstanding'];
  }

  // Total overdue
  $res = $conn->query("SELECT COALESCE(SUM(GREATEST(sb.total - COALESCE(a.allocated, 0), 0)), 0) AS overdue FROM supplier_bills sb LEFT JOIN (SELECT bill_id, SUM(amount) AS allocated FROM supplier_payment_allocations WHERE reversed_at IS NULL GROUP BY bill_id) a ON a.bill_id=sb.id WHERE sb.status IN ('unpaid','partial','overdue') AND sb.due_date < CURRENT_DATE");
  if ($row = $res->fetch_assoc()) {
    $stats['total_overdue'] = (float)$row['overdue'];
  }

  // Recent bills
  $res = $conn->query("SELECT sb.id, sb.bill_number, sb.total, sb.bill_date, sb.status, s.business_name FROM supplier_bills sb LEFT JOIN suppliers s ON sb.supplier_id = s.id ORDER BY sb.bill_date DESC, sb.id DESC LIMIT 5");
  while ($row = $res->fetch_assoc()) {
    $stats['recent_bills'][] = $row;
  }

  // Recent payments
  $res = $conn->query("SELECT sp.id, sp.payment_number, sp.amount, sp.payment_date, sp.status, s.business_name FROM supplier_payments sp LEFT JOIN suppliers s ON sp.supplier_id = s.id WHERE sp.status <> 'cancelled' ORDER BY sp.payment_date DESC, sp.id DESC LIMIT 5");
  while ($row = $res->fetch_assoc()) {
    $stats['recent_payments'][] = $row;
  }

  return $stats;
}
