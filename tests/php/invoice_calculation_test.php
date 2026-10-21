<?php
require_once __DIR__.'/../../includes/invoice.php';

function check(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

$ordinary = invoice_calculate([
  ['name'=>'Design work','qty'=>'2','price'=>'125.50'],
], '10.25');
check($ordinary['subtotal'] === '251.00', 'ordinary subtotal');
check($ordinary['tax'] === '10.25', 'fixed tax amount');
check($ordinary['total'] === '261.25', 'ordinary total');

$multiple = invoice_calculate([
  ['name'=>'Print A','qty'=>'3','price'=>'0.10'],
  ['name'=>'Print B','qty'=>'2','price'=>'5.25'],
], '0');
check($multiple['items'][0]['total'] === '0.30', 'fractional line multiplication');
check($multiple['subtotal'] === '10.80', 'multiple line subtotal');
check($multiple['total'] === '10.80', 'zero tax case');

$zero = invoice_calculate([
  ['name'=>'Complimentary service','qty'=>'1','price'=>'0.00'],
], '0.00');
check($zero['subtotal'] === '0.00' && $zero['total'] === '0.00', 'zero-value invoice');

foreach ([
  [[], '1', 'empty invoice'],
  [[['name'=>'','qty'=>'1','price'=>'1.00']], '0', 'empty item description'],
  [[['name'=>'Bad quantity','qty'=>'1.5','price'=>'1.00']], '0', 'fractional quantity'],
  [[['name'=>'Negative price','qty'=>'1','price'=>'-1.00']], '0', 'negative price'],
  [[['name'=>'Malformed price','qty'=>'1','price'=>'1.001']], '0', 'too many decimals'],
  [[['name'=>'Overflow','qty'=>'2','price'=>'99999999.99']], '0', 'line total exceeds storage limit'],
  [[['name'=>'Valid','qty'=>'1','price'=>'1.00']], '-1.00', 'negative tax'],
  [[['name'=>'Overflow','qty'=>'100000000','price'=>'99,999,999.99']], '0', 'malformed overflow price'],
] as [$items, $tax, $case]) {
  try {
    invoice_calculate($items, $tax);
    throw new RuntimeException('expected validation failure: '.$case);
  } catch (InvalidArgumentException $expected) {
    // Expected invalid input.
  }
}

check(invoice_money_cents('0.01') === 1, 'one-cent amount');
check(invoice_money_cents('10.2') === 1020, 'one-place decimal normalization');
check(invoice_money_cents('1e2') === null, 'scientific notation rejected');
check(invoice_valid_date('2024-02-29'), 'leap date validation');
check(!invoice_valid_date('2025-02-29'), 'invalid calendar date rejection');

fwrite(STDOUT, "Invoice calculation tests passed.\n");
