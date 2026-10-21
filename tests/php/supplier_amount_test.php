<?php
require_once __DIR__.'/../../includes/suppliers.php';

function supplier_check(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

foreach ([
  ['0.01', 1],
  ['10.2', 1020],
  ['5000.00', 500000],
  ['99999999.99', 9999999999],
] as [$amount, $expectedCents]) {
  supplier_check(supplier_amount_cents($amount) === $expectedCents, 'amount conversion: '.$amount);
}

supplier_check(supplier_cents_decimal(203005) === '2030.05', 'cents formatting');

foreach (['-1.00', '1.001', '1e2', '100000000.00', ''] as $invalidAmount) {
  try {
    supplier_amount_cents($invalidAmount);
    throw new RuntimeException('Expected invalid amount: '.$invalidAmount);
  } catch (InvalidArgumentException $expected) {
  }
}

fwrite(STDOUT, "Supplier amount tests passed.\n");
