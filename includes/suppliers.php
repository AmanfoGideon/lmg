<?php
declare(strict_types=1);

/**
 * Supplier Management Functions
 * Handles supplier CRUD, bills, payments, and allocations
 */

// Create a new supplier
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

    $sql = "SELECT * FROM suppliers $where ORDER BY business_name ASC LIMIT ? OFFSET ?";
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
    $summary = [
        'total_purchases' => 0,
        'total_paid' => 0,
        'outstanding_balance' => 0,
        'overdue_balance' => 0
    ];

    // Total purchases (bills)
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total), 0) as total FROM supplier_bills WHERE supplier_id=? AND status!='cancelled'");
    $stmt->bind_param('i', $supplier_id);
    $stmt->execute();
    $summary['total_purchases'] = (float)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    // Total paid (payments)
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM supplier_payments WHERE supplier_id=? AND status!='cancelled'");
    $stmt->bind_param('i', $supplier_id);
    $stmt->execute();
    $summary['total_paid'] = (float)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    // Outstanding balance
    $summary['outstanding_balance'] = max(0, $summary['total_purchases'] - $summary['total_paid']);

    // Overdue balance
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total - COALESCE((SELECT COALESCE(SUM(amount), 0) FROM supplier_payment_allocations WHERE bill_id=sb.id), 0)), 0) as overdue FROM supplier_bills sb WHERE sb.supplier_id=? AND sb.status='overdue'");
    $stmt->bind_param('i', $supplier_id);
    $stmt->execute();
    $summary['overdue_balance'] = (float)$stmt->get_result()->fetch_assoc()['overdue'];
    $stmt->close();

    return $summary;
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
    $subtotal = (float)($bill['subtotal'] ?? 0);
    $tax = (float)($bill['tax'] ?? 0);
    $total = (float)($bill['total'] ?? 0);
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

    if ($total < 0) {
        throw new InvalidArgumentException('Total cannot be negative.');
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
        $stmt->bind_param('isssssddsss', $supplier_id, $bill_number, $bill_date, $due_date, $description, $category, $subtotal, $tax, $total, $payment_terms, $notes, $created_by);
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
    $amount = (float)($payment['amount'] ?? 0);
    $payment_method = trim((string)($payment['payment_method'] ?? ''));
    $reference_number = trim((string)($payment['reference_number'] ?? ''));
    $description = trim((string)($payment['description'] ?? ''));
    $notes = trim((string)($payment['notes'] ?? ''));
    $created_by = (int)($_SESSION['user_id'] ?? 0);

    if (!in_array($payment_method, ['cash', 'mobile', 'card', 'bank_transfer', 'cheque'])) {
        throw new InvalidArgumentException('Invalid payment method.');
    }

    if ($amount <= 0) {
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
        $stmt->bind_param('issdssssi', $supplier_id, $payment_number, $payment_date, $amount, $payment_method, $reference_number, $description, $notes, $created_by);
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
function supplier_payment_allocate(mysqli $conn, int $payment_id, int $bill_id, float $amount): void {
    if ($amount <= 0) {
        throw new InvalidArgumentException('Allocation amount must be positive.');
    }

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
        $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as allocated FROM supplier_payment_allocations WHERE payment_id=?");
        $stmt->bind_param('i', $payment_id);
        $stmt->execute();
        $already_allocated = (float)$stmt->get_result()->fetch_assoc()['allocated'];
        $stmt->close();

        if ($already_allocated + $amount > $payment['amount']) {
            throw new InvalidArgumentException('Allocation amount exceeds payment total.');
        }

        // Check bill remaining balance
        $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as allocated FROM supplier_payment_allocations WHERE bill_id=?");
        $stmt->bind_param('i', $bill_id);
        $stmt->execute();
        $bill_allocated = (float)$stmt->get_result()->fetch_assoc()['allocated'];
        $stmt->close();

        if ($bill_allocated + $amount > $bill['total']) {
            throw new InvalidArgumentException('Allocation amount exceeds bill total.');
        }

        // Check for duplicate allocation
        $stmt = $conn->prepare("SELECT id FROM supplier_payment_allocations WHERE payment_id=? AND bill_id=? LIMIT 1");
        $stmt->bind_param('ii', $payment_id, $bill_id);
        $stmt->execute();
        if ($stmt->get_result()->fetch_row()) {
            throw new InvalidArgumentException('This payment is already allocated to this bill.');
        }
        $stmt->close();

        // Create allocation
        $allocated_by = (int)($_SESSION['user_id'] ?? 0);
        $stmt = $conn->prepare("INSERT INTO supplier_payment_allocations (payment_id, bill_id, amount, allocated_by) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('iidi', $payment_id, $bill_id, $amount, $allocated_by);
        $stmt->execute();
        $stmt->close();

        // Update payment status
        $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as allocated FROM supplier_payment_allocations WHERE payment_id=?");
        $stmt->bind_param('i', $payment_id);
        $stmt->execute();
        $new_allocated = (float)$stmt->get_result()->fetch_assoc()['allocated'];
        $stmt->close();

        $new_status = $new_allocated >= $payment['amount'] ? 'allocated' : 'unallocated';
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

    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as allocated FROM supplier_payment_allocations WHERE bill_id=?");
    $stmt->bind_param('i', $bill_id);
    $stmt->execute();
    $allocated = (float)$stmt->get_result()->fetch_assoc()['allocated'];
    $stmt->close();

    $status = $allocated >= $bill['total'] ? 'paid' : ($allocated > 0 ? 'partial' : 'unpaid');

    // Check if overdue
    if (($status === 'unpaid' || $status === 'partial') && $bill['due_date'] && $bill['due_date'] < date('Y-m-d')) {
        $status = 'overdue';
    }

    $stmt = $conn->prepare("UPDATE supplier_bills SET status=? WHERE id=?");
    $stmt->bind_param('si', $status, $bill_id);
    $stmt->execute();
    $stmt->close();
}
