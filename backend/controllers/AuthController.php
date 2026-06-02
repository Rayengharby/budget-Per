<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../config/helpers.php';

class AuthController {

    private static function authFail(string $msg): void {
        header('WWW-Authenticate: Bearer realm="BudgetCollab"');
        jsonResponse(['success' => false, 'message' => $msg], 401);
    }

    public static function register(): void {
        $d        = json_decode(file_get_contents('php://input'), true) ?? [];
        $name     = trim($d['name']     ?? '');
        $email    = strtolower(trim($d['email']    ?? ''));
        $password = $d['password'] ?? '';

        if (!$name || !$email || !$password) { jsonResponse(['success'=>false,'message'=>'Tous les champs sont requis.'], 400); return; }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { jsonResponse(['success'=>false,'message'=>'Email invalide.'], 400); return; }
        if (strlen($password) < 6) { jsonResponse(['success'=>false,'message'=>'Le mot de passe doit contenir au moins 6 caractères.'], 400); return; }

        $db = getDB();
        $st = $db->prepare('SELECT id FROM users WHERE email = ?');
        $st->execute([$email]);
        if ($st->fetch()) { jsonResponse(['success'=>false,'message'=>'Email déjà utilisé.'], 409); return; }

        $db->prepare('INSERT INTO users (name, email, password) VALUES (?, ?, ?)')->execute([$name, $email, password_hash($password, PASSWORD_BCRYPT, ['cost'=>12])]);
        jsonResponse(['success'=>true,'message'=>"Compte créé. En attente de validation par l'administrateur."], 201);
    }

    public static function login(): void {
        $d        = json_decode(file_get_contents('php://input'), true) ?? [];
        $email    = strtolower(trim($d['email']    ?? ''));
        $password = $d['password'] ?? '';

        if (!$email || !$password) { jsonResponse(['success'=>false,'message'=>'Email et mot de passe requis.'], 400); return; }

        $st = getDB()->prepare('SELECT id, name, email, password, role, is_active FROM users WHERE email = ?');
        $st->execute([$email]);
        $user = $st->fetch();

        if (!$user || !password_verify($password, $user['password'])) { self::authFail('Identifiants incorrects.'); return; }
        if (!$user['is_active']) { jsonResponse(['success'=>false,'message'=>"Compte non encore activé par l'administrateur."], 403); return; }

        $token = jwt_sign(['id' => $user['id']]);
        jsonResponse([
            'success' => true,
            'token'   => $token,
            'user'    => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role'], 'isActive' => (bool)$user['is_active']],
        ], 200);
    }

    public static function me(): void {
        $headers = getallheaders();
        $auth = '';
        foreach ($headers as $k => $v) { if (strtolower($k) === 'authorization') { $auth = $v; break; } }
        if (!preg_match('/Bearer\s+(\S+)/i', $auth, $m)) { self::authFail('Non autorisé'); return; }
        $decoded = jwt_verify($m[1]);
        if (!$decoded) { self::authFail('Token invalide'); return; }

        $st = getDB()->prepare('SELECT id, name, email, role, is_active, avatar, created_at FROM users WHERE id = ?');
        $st->execute([$decoded['id']]);
        $u = $st->fetch();
        if (!$u) { self::authFail('Utilisateur introuvable'); return; }

        jsonResponse(['success' => true, 'user' => fmtUser($u)], 200);
    }
}
