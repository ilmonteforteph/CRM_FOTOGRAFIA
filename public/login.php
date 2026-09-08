<?php
// public/login.php

// include configurazioni e funzioni comuni
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// Se utente già loggato -> redirect alla dashboard
if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$err = null;

// GENERA CSRF token se non presente
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Controllo CSRF
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], (string)$token)) {
        $err = 'Richiesta non valida (CSRF). Ricarica la pagina e riprova.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $err = 'Inserisci username e password.';
        } else {
            if (attempt_login($username, $password)) {
                // Rigenera CSRF token dopo login
                $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
                header('Location: index.php');
                exit;
            } else {
                $err = 'Credenziali non valide.';
            }
        }
    }
}

// include header (mostra navbar, login link se non loggato)
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center mt-5">
  <div class="col-md-5">
    <div class="card p-4 shadow-lg border-0 rounded-4">
      <div class="text-center mb-4">
        <img src="logo.png" alt="Logo Azienda" class="img-fluid mb-3" style="max-height: 50px;">
        <h3 class="mb-0 text-primary fw-bold d-flex align-items-center justify-content-center">
            <i class="bi bi-person-circle me-2"></i> Accedi al Sistema
        </h3>
      </div>

      <?php if ($err): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <?php echo htmlspecialchars($err); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <form method="post" autocomplete="off" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

        <div class="mb-3">
          <label for="username" class="form-label visually-hidden">Username</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
            <input id="username" name="username" class="form-control form-control-lg" placeholder="Username" required value="<?php echo isset($username) ? htmlspecialchars($username) : ''; ?>">
          </div>
        </div>

        <div class="mb-4">
          <label for="password" class="form-label visually-hidden">Password</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
            <input id="password" type="password" name="password" class="form-control form-control-lg" placeholder="Password" required>
          </div>
        </div>

        <div class="d-grid mb-3">
          <button class="btn btn-primary btn-lg" type="submit">
            <i class="bi bi-box-arrow-in-right me-2"></i>Accedi
          </button>
        </div>
        <div class="text-center">
          <a class="small text-muted" href="#" onclick="alert('Funzione recupero password non implementata.'); return false;">Password dimenticata?</a>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>