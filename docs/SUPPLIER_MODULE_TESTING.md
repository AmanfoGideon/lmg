# Supplier Management Module - Testing Guide

## Prerequisites

Before testing, ensure the database migrations have been applied:

### MySQL Migration:
```sql
-- In MySQL console or PHPMyAdmin
source D:\wamp64\www\CRM\business-management-system\migrations\20261021_suppliers.sql;
source D:\wamp64\www\CRM\business-management-system\migrations\20261021_supplier_reversals.sql;
```

### Supabase Migration:
Since Supabase CLI is not available in the environment:
1. Apply `supabase/migrations/202610210003_suppliers.sql`
2. Then apply `supabase/migrations/202610210004_supplier_reversals.sql`

## Test Scenarios

### 1. Supplier Management (public/suppliers.php)

**Test 1.1: Create a new supplier**
- Navigate to Suppliers page
- Fill in supplier details:
  - Business Name: "Test Supplier Inc."
  - Contact Person: "John Doe"
  - Phone: "0123456789"
  - Email: "test@example.com"
  - Address: "123 Test Street"
  - Category: "IT Services"
  - Tax ID: "GH-123456"
  - Notes: "Test supplier for verification"
- Click "Add Supplier"
- **Expected:** Supplier created successfully, appears in list

**Test 1.2: Edit a supplier**
- Click edit button on the newly created supplier
- Change business name to "Test Supplier Ltd."
- Update phone number
- Click "Update Supplier"
- **Expected:** Supplier updated successfully, changes reflected in list

**Test 1.3: Deactivate a supplier**
- Edit the supplier
- Change status to "inactive"
- Click "Update Supplier"
- **Expected:** Supplier status changes to inactive

**Test 1.4: Search suppliers**
- Use search box to search for "Test"
- **Expected:** Only matching suppliers displayed

**Test 1.5: View financial summary**
- Check the Purchases, Paid, and Balance columns
- **Expected:** Should show 0 for new supplier

**Test 1.6: Delete supplier without history**
- Create a new test supplier
- Delete it immediately
- **Expected:** Supplier deleted successfully

**Test 1.7: Attempt to delete supplier with history**
- Create a supplier
- Create a bill for this supplier (see Test 2.1)
- Attempt to delete the supplier
- **Expected:** Error message: "Cannot delete supplier with financial history. Deactivate instead."

### 2. Supplier Bills (public/supplier_bills.php)

**Test 2.1: Create a supplier bill**
- Navigate to Supplier Bills page
- Select the test supplier
- Fill in bill details:
  - Bill Number: (leave empty for auto-generation)
  - Bill Date: today's date
  - Due Date: 30 days from today
  - Description: "Office supplies purchase"
  - Category: "Office Supplies"
  - Subtotal: 4500.00
  - Tax: 500.00
  - Total: (auto-calculated to 5000.00)
  - Payment Terms: "Net 30"
- Click "Add Bill"
- **Expected:** Bill created successfully with auto-generated number

**Test 2.2: Verify bill status**
- Check the bill status
- **Expected:** Status should be "unpaid"

**Test 2.3: Edit a bill**
- Click edit button on the bill
- Change description
- Update subtotal
- **Expected:** Total auto-recalculates, bill updated successfully

**Test 2.4: Filter bills by supplier**
- Use supplier filter dropdown
- Select the test supplier
- **Expected:** Only bills for that supplier displayed

**Test 2.5: Filter bills by status**
- Use status filter dropdown
- Select "Unpaid"
- **Expected:** Only unpaid bills displayed

**Test 2.6: Cancel a bill**
- Click cancel button on a bill
- Enter cancellation reason: "Testing cancellation"
- **Expected:** Bill cancelled successfully, status changes to "cancelled"

**Test 2.7: Attempt to cancel bill with allocations**
- Create a payment for the bill (see Test 3.1)
- Attempt to cancel the bill
- **Expected:** Error message: "Cannot cancel bill with allocated payments. Remove allocations first."

### 3. Supplier Payments (public/supplier_payments.php)

**Test 3.1: Create a supplier payment**
- Navigate to Supplier Payments page
- Select the test supplier
- Fill in payment details:
  - Payment Number: (leave empty for auto-generation)
  - Payment Date: today's date
  - Amount: 2000.00
  - Payment Method: "Cash"
  - Reference Number: "REF-001"
  - Description: "Partial payment for bill"
- Click "Add Payment"
- **Expected:** Payment created successfully, status "unallocated"

**Test 3.2: Allocate payment to bill**
- Click allocate button on the payment
- Select the outstanding bill from dropdown
- Verify allocation amount (should show 2000.00)
- Click "Allocate"
- **Expected:** Payment allocated successfully, status changes to "allocated"

**Test 3.3: Verify bill status after allocation**
- Navigate to Supplier Bills page
- Check the bill status
- **Expected:** Status should be "partial"
- Balance should show 3000.00 (5000 - 2000)

**Test 3.4: Create another partial payment**
- Create another payment for 1000.00
- Allocate it to the same bill
- **Expected:** Bill balance now 2000.00, status still "partial"

**Test 3.5: Settle remaining balance**
- Create final payment for 2000.00
- Allocate it to the bill
- **Expected:** Bill status changes to "paid", balance 0.00

**Test 3.6: Create payment for multiple bills**
- Create two unpaid bills for the same supplier
- Create a payment for 3000.00
- Allocate 1500.00 to each bill
- **Expected:** Both bills show partial status, payment fully allocated

**Test 3.7: Test overdue status**
- Create a bill with due date in the past
- Wait for the date to pass (or manually update due_date in database)
- Check bill status
- **Expected:** Status should be "overdue"

**Test 3.8: Cancel a payment**
- Create a payment
- Click cancel button
- Enter cancellation reason
- **Expected:** Payment cancelled successfully, status "cancelled"

**Test 3.9: Attempt to cancel allocated payment**
- Create and allocate a payment
- Attempt to cancel it
- **Expected:** Error message: "Cannot cancel payment with allocations. Remove allocations first."

**Test 3.10: Reverse allocation with reason**
- On an allocated payment, view allocations
- Enter a reason and reverse an allocation
- **Expected:** Reversal is retained in the payment history, and payment/bill balances are recalculated

### 4. Expense Integration (public/transactions.php)

**Test 4.1: Add supplier to expense**
- Navigate to Transactions page
- Select "Expense" as transaction type
- Supplier dropdown should appear
- Select a supplier
- Fill in other expense details
- Save the transaction
- **Expected:** Expense saved with supplier_id

**Test 4.2: Add new supplier from expense form**
- Click "+" button next to supplier dropdown
- Fill in supplier details in modal
- Click "Add Supplier"
- **Expected:** Modal closes, supplier auto-selected in dropdown

**Test 4.3: Verify supplier not shown for income**
- Select "Income" as transaction type
- **Expected:** Supplier dropdown hidden

**Test 4.4: Verify supplier not shown for other types**
- Select "Invoice" or "Payment" as transaction type
- **Expected:** Supplier dropdown hidden

### 5. Dashboard Integration (public/dashboard.php)

**Test 5.1: Verify supplier summary cards**
- Navigate to Dashboard
- Check for 4 supplier summary cards:
  - Active Suppliers
  - Supplier Purchases
  - Supplier Payments
  - Outstanding Payables
- **Expected:** All cards displayed with correct values

**Test 5.2: Verify recent supplier bills**
- Check sidebar for "Recent Supplier Bills" section
- **Expected:** Recent bills displayed with status badges

**Test 5.3: Verify outstanding calculation**
- Create unpaid bills totaling 5000.00
- Pay 2000.00
- Check dashboard "Outstanding Payables"
- **Expected:** Should show 3000.00

**Test 5.4: Verify overdue calculation**
- Create overdue bill of 1000.00
- Check dashboard overdue breakdown
- **Expected:** Overdue amount shown in Outstanding Payables card

### 6. Financial Calculations

**Test 6.1: Verify bill balance calculation**
- Create bill for 5000.00
- Pay 2000.00
- Check bill balance
- **Expected:** Balance = 3000.00

**Test 6.2: Verify payment status calculation**
- Create payment for 2000.00
- Allocate 2000.00
- Check payment status
- **Expected:** Status = "allocated"

**Test 6.3: Verify partial allocation**
- Create payment for 5000.00
- Allocate 2000.00
- Check payment status
- **Expected:** Status = "unallocated", balance = 3000.00

**Test 6.4: Verify supplier outstanding balance**
- Create 2 unpaid bills: 3000.00 and 2000.00
- Check supplier summary
- **Expected:** Outstanding balance = 5000.00

**Test 6.5: Verify supplier paid total**
- Create bills totaling 10000.00
- Pay 7000.00
- Check supplier summary
- **Expected:** Total purchases = 10000.00, Total paid = 7000.00

### 7. Security and Audit

**Test 7.1: Verify audit logging**
- Create a supplier
- Check user_logs table
- **Expected:** Entry with action "supplier_create"

**Test 7.2: Verify bill audit logging**
- Create a bill
- Check user_logs table
- **Expected:** Entry with action "supplier_bill_create"

**Test 7.3: Verify payment audit logging**
- Create a payment
- Check user_logs table
- **Expected:** Entry with action "supplier_payment_create"

**Test 7.4: Verify allocation audit logging**
- Allocate a payment
- Check user_logs table
- **Expected:** Entry with action "supplier_payment_allocate"

**Test 7.5: Verify RLS policies (Supabase)**
- Test with different user roles
- **Expected:** Users can only see data they have access to

### 8. Edge Cases

**Test 8.1: Duplicate bill number**
- Try to create two bills with same number for same supplier
- **Expected:** Error message: "Bill number already exists for this supplier."

**Test 8.2: Duplicate payment number**
- Try to create two payments with same number for same supplier
- **Expected:** Error message: "Payment number already exists for this supplier."

**Test 8.3: Over-allocation prevention**
- Try to allocate more than payment amount
- **Expected:** Error message: "Allocation amount exceeds payment total."

**Test 8.4: Bill over-allocation**
- Try to allocate more than bill total
- **Expected:** Error message: "Allocation amount exceeds bill total."

**Test 8.5: Negative amounts**
- Try to create bill with negative total
- **Expected:** Error message: "Total cannot be negative."

**Test 8.6: Invalid dates**
- Try to create bill with invalid date format
- **Expected:** Error message: "Invalid bill date."

**Test 8.7: Invalid payment method**
- Try to use invalid payment method
- **Expected:** Error message: "Invalid payment method."

### 9. Data Integrity

**Test 9.1: Foreign key constraints**
- Try to delete supplier with bills
- **Expected:** Prevented by foreign key constraint

**Test 9.2: Cascading allocations**
- Delete a payment
- Check allocations
- **Expected:** Allocations cascade deleted

**Test 9.3: Status recalculation**
- Delete an allocation
- Check bill status
- **Expected:** Bill status recalculated automatically

### 10. UI/UX

**Test 10.1: Pagination**
- Create more than 50 bills
- Navigate through pages
- **Expected:** Pagination works correctly

**Test 10.2: Search functionality**
- Search for specific bill number
- **Expected:** Correct results displayed

**Test 10.3: Modal functionality**
- Test all modals (add supplier, allocate payment)
- **Expected:** Modals open/close correctly

**Test 10.4: Responsive design**
- Test on different screen sizes
- **Expected:** Layout adapts correctly

**Test 10.5: Empty states**
- Access pages with no data
- **Expected:** Appropriate empty state messages displayed

## Test Results Template

| Test ID | Test Name | Status | Notes |
|---------|-----------|--------|-------|
| 1.1 | Create supplier | PASS/FAIL | |
| 1.2 | Edit supplier | PASS/FAIL | |
| 1.3 | Deactivate supplier | PASS/FAIL | |
| ... | ... | ... | |

## Known Limitations

1. Supabase migration requires manual application via SQL Editor
2. No built-in supplier statement generator (can be added as enhancement)
3. No export functionality for supplier reports (can be added as enhancement)
4. No multi-currency support (single currency: GH₵)

## Post-Implementation Checklist

- [ ] All database migrations applied successfully
- [ ] All test scenarios pass
- [ ] No console errors in browser
- [ ] No PHP errors in logs
- [ ] Audit logs are being created
- [ ] Dashboard calculations are accurate
- [ ] User permissions are working correctly
- [ ] Mobile responsive design verified
- [ ] Performance acceptable with 100+ records
- [ ] Backups tested before deployment

## Rollback Plan

If critical issues are found:

1. Revert PHP changes:
   ```bash
   git revert HEAD~3
   ```

2. Rollback MySQL migration:
   ```sql
   DROP TABLE IF EXISTS supplier_payment_allocations;
   DROP TABLE IF EXISTS supplier_payments;
   DROP TABLE IF EXISTS supplier_bills;
   DROP TABLE IF EXISTS suppliers;
   ALTER TABLE transactions DROP COLUMN supplier_id;
   ```

3. Rollback Supabase migration via dashboard
