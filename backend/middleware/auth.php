<?php
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';

// Send a 401 that NEVER triggers the browser Basic-Auth dialog.
// Apache shows the dialog only for "WWW-Authenticate: Basic …".
// Sending "Bearer" suppresses it completely.
function authFail(string $message): void {
    header('WWW-Authenticate: Bearer realm="BudgetCollab"');
    jsonResponse(['success' => false, 'message' => $message], 401);
    exit;
}

function authenticate(): array {
    $headers = getallheaders();
    $auth = '';
    foreach ($headers as $k => $v) {
        if (strtolower($k) === 'authorization') { $auth = $v; break; }
    }

    if (!preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
        authFail('Non autorisé. Connectez-vous.');
    }

    $decoded = jwt_verify($m[1]);
    if (!$decoded) {
        authFail('Token invalide ou expiré.');
    }

    $stmt = getDB()->prepare('SELECT id, name, email, role, is_active, avatar FROM users WHERE id = ?');
    $stmt->execute([$decoded['id']]);
    $user = $stmt->fetch();

    if (!$user) {
        authFail('Utilisateur introuvable.');
    }
    if (!$user['is_active']) {
        jsonResponse(['success' => false, 'message' => 'Compte non activé par l\'administrateur.'], 403);
        exit;
    }

    return $user;
}

function requireAdmin(array $user): void {
    if ($user['role'] !== 'admin') {
        jsonResponse(['success' => false, 'message' => 'Accès refusé : droits insuffisants.'], 403);
        exit;
    }
}
