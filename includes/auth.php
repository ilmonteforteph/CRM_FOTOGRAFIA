<?php
require_once __DIR__ . '/db.php';

// ==========================
// AUTENTICAZIONE & UTILITIES
// ==========================

if (session_status() === PHP_SESSION_NONE) session_start();

function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

function is_admin(): bool {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

function current_user(): array {
    return [
        'user_id' => $_SESSION['user_id'] ?? null,
        'id_operatore' => $_SESSION['id_operatore'] ?? null,
        'user_name' => $_SESSION['user_name'] ?? null,
        'user_role' => $_SESSION['user_role'] ?? null
    ];
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function require_admin(): void {
    if (!is_admin()) {
        http_response_code(403);
        echo 'Accesso negato. Solo amministratore.';
        exit;
    }
}

// CSRF token helper
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf_token'];
}
function csrf_verify($token): bool {
    return !empty($token) && hash_equals($_SESSION['csrf_token'] ?? '', (string)$token);
}

/**
 * attempt_login
 * Cerca l'utente nella tabella users, verifica password e popola sessione.
 */
function attempt_login(string $username, string $password): bool {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT u.id_user, u.id_operatore, u.username, u.password, u.ruolo,
               o.nome AS nome_operatore, o.cognome AS cognome_operatore
        FROM users u
        LEFT JOIN operatori o ON u.id_operatore = o.id_operatore
        WHERE u.username = ?
        LIMIT 1
    ");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
	if ($user && password_verify($password, $user['password'])) {
		
        // Set session
        $_SESSION['user_id'] = $user['id_user'];
        $_SESSION['id_operatore'] = $user['id_operatore'];
        $_SESSION['user_name'] = trim(($user['nome_operatore'] ?? '') . ' ' . ($user['cognome_operatore'] ?? ''));
        $_SESSION['user_role'] = $user['ruolo'];
        // regenerate token
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
        return true;
    }
    return false;
}

function logout(): void {
    session_unset();
    session_destroy();
}
