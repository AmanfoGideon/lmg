<?php
$page_title = $page_title ?? '';
$page_subtitle = $page_subtitle ?? '';
$header_actions = $header_actions ?? '';
$userName = htmlspecialchars($_SESSION['full_name'] ?? '');
if (!isset($_SESSION['currency_symbol']) || $_SESSION['currency_symbol'] === '') {
  $cs = '';
  if (isset($_SERVER['CURRENCY_SYMBOL']) && $_SERVER['CURRENCY_SYMBOL'] !== '') { $cs = $_SERVER['CURRENCY_SYMBOL']; }
  else {
    $ge = getenv('CURRENCY_SYMBOL');
    if ($ge !== false && $ge !== '') { $cs = $ge; }
    else {
      $paths = [
        dirname(dirname(__DIR__)).'/includes.env',
        dirname(__DIR__).'/../includes.env',
        dirname(dirname(__DIR__)).'/.env',
        dirname(__DIR__).'/../.env'
      ];
      foreach ($paths as $p) {
        if (is_file($p)) {
          $lines = @file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
          if (is_array($lines)) {
            foreach ($lines as $line) {
              if ($line === '' || $line[0] === '#') { continue; }
              $parts = explode('=', $line, 2);
              if (count($parts) === 2 && trim($parts[0]) === 'CURRENCY_SYMBOL') {
                $val = trim($parts[1]);
                if ($val !== '') { $cs = $val; break; }
              }
            }
          }
        }
        if ($cs !== '') { break; }
      }
    }
  }
  if ($cs !== '') { $_SESSION['currency_symbol'] = $cs; }
}
$currency_symbol = $_SESSION['currency_symbol'] ?? '₵';
function fmtCurrency($amount) {
  $symbol = $_SESSION['currency_symbol'] ?? '₵';
  return $symbol . number_format((float)$amount, 2);
}
?>
<link rel="manifest" href="pwa-manifest.webmanifest">
<meta name="theme-color" content="#0f1720">
<script>window.currencySymbol=<?php echo json_encode($currency_symbol, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;</script>
<script>if('serviceWorker' in navigator){navigator.serviceWorker.register('sw.js');}</script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/datatables.net-bs5@1.13.6/css/dataTables.bootstrap5.min.css">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/datatables.net@1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/datatables.net-bs5@1.13.6/js/dataTables.bootstrap5.min.js"></script>
<header class="d-flex align-items-center justify-content-between mb-4">
  <div>
    <h2 class="mb-0"><?= htmlspecialchars($page_title) ?></h2>
    <?php if ($page_subtitle): ?><small class="text-muted"><?= htmlspecialchars($page_subtitle) ?></small><?php endif; ?>
  </div>
  <div class="d-flex align-items-center">
    <button id="btnInstallApp" class="btn btn-outline-light me-2" style="display:none" aria-label="Install app">Install App</button>
    <button id="btnSidebarToggle" class="btn btn-outline-light me-2" aria-label="Toggle sidebar" aria-controls="appSidebar" aria-expanded="false"><i class="bi bi-chevron-left"></i></button>
    <?= $header_actions ?>
    <div class="me-3 text-end">
      <div class="fs-6">Welcome back,</div>
      <div class="fw-bold"><?= $userName ?></div>
    </div>
    <img src="images/logo.png" alt="avatar" class="avatar">
  </div>
</header>
