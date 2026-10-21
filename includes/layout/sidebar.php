<?php
$role = $_SESSION['role'] ?? 'staff';
$active_page = $active_page ?? '';
$current = basename($_SERVER['PHP_SELF']);
$dashActive = ($active_page==='dashboard' || $current==='dashboard.php') ? ' active' : '';
$clientsActive = ($active_page==='clients' || $current==='clients.php') ? ' active' : '';
$txActive = ($active_page==='transactions' || $current==='transactions.php') ? ' active' : '';
$smsActive = ($active_page==='sms' || $current==='sms.php') ? ' active' : '';
$productsActive = ($active_page==='products' || $current==='products.php') ? ' active' : '';
$invoicesActive = ($active_page==='invoices' || $current==='invoices.php' || $current==='invoice_create.php' || $current==='invoice_view.php') ? ' active' : '';
$usersActive = ($active_page==='users' || $current==='users.php') ? ' active' : '';
$auditActive = ($active_page==='audit' || $current==='audit_logs.php') ? ' active' : '';
$profileActive = ($active_page==='profile' || $current==='profile.php') ? ' active' : '';
$userName = htmlspecialchars($_SESSION['full_name'] ?? '');
$openManagement = ($clientsActive || $txActive || $smsActive || $invoicesActive || $productsActive) ? ' open' : '';
$openReports = ($dashActive && isset($_GET['section']) && $_GET['section']==='reports') ? ' open' : '';
?>
<aside id="appSidebar" class="sidebar d-flex flex-column p-3">
  <div class="brand d-flex align-items-center mb-3">
    <a href="dashboard.php" class="d-flex align-items-center text-decoration-none">
      <img src="images/logo.png" alt="LMG" class="brand-logo me-2">
      <div>
        <div class="brand-name">LMG</div>
        <small class="text-muted">Creative Media</small>
      </div>
    </a>
  </div>
  <div class="user-panel d-flex align-items-center mb-3">
    <img src="images/logo.png" alt="avatar" class="avatar me-2">
    <div class="flex-grow-1">
      <div class="fw-bold"><?= $userName ?></div>
      <div class="small">
        <a class="link-light me-2" href="profile.php">Profile</a>
        <a class="link-danger" id="btnLogout" href="logout.php">Logout</a>
      </div>
    </div>
  </div>
  <nav class="nav flex-column" aria-label="Main navigation">
    <a href="dashboard.php" class="nav-link<?= $dashActive ?>" title="Dashboard"<?= $dashActive ? ' aria-current="page"' : '' ?>>
      <i class="bi bi-speedometer2"></i> <span class="nav-text">Dashboard</span>
    </a>
    <div class="menu-group<?= $openManagement ?>">
      <button type="button" class="nav-link w-100 text-start menu-group-toggle" data-group="management">
        <span><i class="bi bi-grid"></i> <span class="nav-text">Management</span></span>
        <i class="bi bi-chevron-down caret"></i>
      </button>
      <div class="submenu">
        <a href="clients.php" class="nav-link<?= $clientsActive ?>" title="Clients"<?= $clientsActive ? ' aria-current="page"' : '' ?>>
          <i class="bi bi-people"></i> <span class="nav-text">Clients</span>
        </a>
        <a href="products.php" class="nav-link<?= $productsActive ?>" title="Products"<?= $productsActive ? ' aria-current="page"' : '' ?>>
          <i class="bi bi-box-seam"></i> <span class="nav-text">Products</span>
        </a>
        <a href="transactions.php" class="nav-link<?= $txActive ?>" title="Transactions"<?= $txActive ? ' aria-current="page"' : '' ?>>
          <i class="bi bi-wallet2"></i> <span class="nav-text">Transactions</span>
        </a>
        <a href="sms.php" class="nav-link<?= $smsActive ?>" title="SMS"<?= $smsActive ? ' aria-current="page"' : '' ?>>
          <i class="bi bi-chat-dots"></i> <span class="nav-text">SMS</span>
        </a>
        <a href="invoice_create.php" class="nav-link<?= ($current==='invoice_create.php')?' active':'' ?>" title="New Invoice"<?= ($current==='invoice_create.php')?' aria-current="page"':'' ?>>
          <i class="bi bi-plus-square"></i> <span class="nav-text">New Invoice</span>
        </a>
        <a href="invoices.php" class="nav-link<?= ($current==='invoices.php')?' active':'' ?>" title="Invoices List"<?= ($current==='invoices.php')?' aria-current="page"':'' ?>>
          <i class="bi bi-receipt"></i> <span class="nav-text">Invoices</span>
        </a>
      </div>
    </div>
    <div class="menu-group<?= $openReports ?>">
      <button type="button" class="nav-link w-100 text-start menu-group-toggle" data-group="reports">
        <span><i class="bi bi-bar-chart-line"></i> <span class="nav-text">Reports</span></span>
        <i class="bi bi-chevron-down caret"></i>
      </button>
      <div class="submenu">
        <a href="dashboard.php#monthlyChartSection" class="nav-link" title="Revenue & Trends">
          <i class="bi bi-graph-up-arrow"></i> <span class="nav-text">Revenue & Trends</span>
        </a>
        <a href="reports.php" class="nav-link<?= ($current==='reports.php')?' active':'' ?>" title="Reports">
          <i class="bi bi-file-earmark-bar-graph"></i> <span class="nav-text">Financial Reports</span>
        </a>
      </div>
    </div>
    <?php if ($role === 'admin'): ?>
    <div class="menu-group<?= ($usersActive || $auditActive) ? ' open':'' ?>">
      <button type="button" class="nav-link w-100 text-start menu-group-toggle" data-group="admin">
        <span><i class="bi bi-shield-lock"></i> <span class="nav-text">Administration</span></span>
        <i class="bi bi-chevron-down caret"></i>
      </button>
      <div class="submenu">
        <a href="users.php" class="nav-link<?= $usersActive ?>" title="Manage Users"<?= $usersActive ? ' aria-current="page"' : '' ?>>
          <i class="bi bi-people-gear"></i> <span class="nav-text">Users</span>
        </a>
        <a href="audit_logs.php" class="nav-link<?= $auditActive ?>" title="Audit Trail"<?= $auditActive ? ' aria-current="page"' : '' ?>>
          <i class="bi bi-journal-text"></i> <span class="nav-text">Audit Trail</span>
        </a>
        <a href="backup.php" class="nav-link<?= ($current==='backup.php')?' active':'' ?>" title="Backup">
          <i class="bi bi-database-lock"></i> <span class="nav-text">Backup</span>
        </a>
      </div>
    </div>
    <?php endif; ?>
    <a href="profile.php" class="nav-link<?= $profileActive ?>" title="Profile"<?= $profileActive ? ' aria-current="page"' : '' ?>>
      <i class="bi bi-person-circle"></i> <span class="nav-text">Profile</span>
    </a>
  </nav>
</aside>
