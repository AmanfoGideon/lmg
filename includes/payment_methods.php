<?php
function payment_method_label(?string $method): string {
  $value = trim((string)$method);
  if ($value === '') return 'Not specified';
  $labels = [
    'cash' => 'Cash',
    'mobile' => 'MoMo',
    'card' => 'Card',
    'bank_transfer' => 'Bank Transfer',
    'cheque' => 'Cheque'
  ];
  $key = strtolower($value);
  return $labels[$key] ?? ucwords(str_replace(['_', '-'], ' ', $value));
}
