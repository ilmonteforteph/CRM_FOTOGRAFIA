<?php
require_once __DIR__ . '/auth.php';
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CRM</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    body { background-color: #f8f9fa; }
    .navbar-brand { font-weight: bold; font-size: 1.5rem; display: flex; align-items: center; }
    .navbar-brand i { margin-right: 0.5rem; color: #ffc107; }
    .nav-link { transition: all 0.2s; }
    .nav-link:hover { background-color: #495057; border-radius: 0.25rem; }
    .badge-role { font-size: 0.75rem; vertical-align: text-top; margin-left: 0.25rem; }
    .user-info { display: flex; align-items: center; }
    .user-info i { margin-right: 0.25rem; }
    
    /* Correzione per il Dropdown: forza la visualizzazione */
    .dropdown-menu { z-index: 1050; }
  </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm mb-4">
  <div class="container-fluid">
    <a class="navbar-brand" href="index.php">
      <i class="bi bi-camera-fill"></i> DASHBOARD
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <?php if(is_logged_in()): ?>
          <li class="nav-item"><a class="nav-link" href="../public/clients.php"><i class="bi bi-people-fill me-1"></i>Clienti</a></li>
          <li class="nav-item"><a class="nav-link" href="../public/events.php"><i class="bi bi-calendar-event-fill me-1"></i>Eventi</a></li>
          <li class="nav-item"><a class="nav-link" href="../public/movements.php"><i class="bi bi-currency-euro me-1"></i>Movimentazioni</a></li>
          <?php if(is_admin()): ?>
            <li class="nav-item"><a class="nav-link" href="../public/operators.php"><i class="bi bi-person-badge-fill me-1"></i>Operatori</a></li>
          <?php endif; ?>
          <li class="nav-item"><a class="nav-link" href="../public/promozioni.php"><i class="bi bi-megaphone-fill me-1"></i>Promozioni</a></li>
          <li class="nav-item"><a class="nav-link" href="../public/promozioni_stats.php"><i class="bi bi-bar-chart-line-fill me-1"></i>Stats Promozioni</a></li>
          
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownMarketing" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              <i class="bi bi-graph-up-arrow me-1"></i>Marketing
            </a>
            <ul class="dropdown-menu" aria-labelledby="navbarDropdownMarketing">
              <li><a class="dropdown-item" href="../public/marketing_maternity.php">Marketing Maternity</a></li>
            </ul>
          </li>
        <?php endif; ?>
      </ul>

      <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
        <?php if(is_logged_in()): ?>
          <li class="nav-item user-info me-3 text-light">
            <i class="bi bi-person-circle"></i>
            <span><?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?></span>
            <span class="badge bg-warning text-dark badge-role"><?php echo htmlspecialchars($_SESSION['user_role']); ?></span>
          </li>
          <li class="nav-item">
            <a class="btn btn-outline-danger btn-sm" href="/logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
          </li>
        <?php else: ?>
          <li class="nav-item"><a class="btn btn-outline-primary btn-sm" href="/login.php"><i class="bi bi-box-arrow-in-right"></i> Login</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>

<div class="container-fluid bg-light border-bottom mb-4 py-2">
    <div class="container">
        <?php if(is_logged_in()): ?>
            <div class="d-flex justify-content-start align-items-center">
                <span class="me-3 text-muted" style="font-size: 0.9rem;">Accesso Rapido:</span>
                <a class="btn btn-sm btn-success me-2" href="../public/event_form.php"><i class="bi bi-calendar-plus-fill me-1"></i> Nuovo Evento</a>
                <a class="btn btn-sm btn-primary me-2" href="../public/client_form.php"><i class="bi bi-person-plus-fill me-1"></i> Nuovo Cliente</a>
                <a class="btn btn-sm btn-success" href="../public/movement_form.php"><i class="bi bi-currency-euro me-1"></i> Nuovo movimento</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="container py-3">
    </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>