<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ── CORS Headers ───────────────────────────────────────────
function setCorsHeaders(): void {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Max-Age: 3600');
}

// Send JSON response with CORS headers
function jsonResponse($data, int $statusCode = 200): void {
    setCorsHeaders();
    http_response_code($statusCode);
    echo json_encode($data);
}

// Convert snake_case array keys to camelCase (non-recursive, for flat DB rows)
function toCamel(array $row): array {
    $out = [];
    foreach ($row as $k => $v) {
        $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $k))));
        $out[$camel] = $v;
    }
    return $out;
}

// Format a user row for API output
function fmtUser(array $u): array {
    return [
        'id'        => (int)$u['id'],
        'name'      => $u['name'],
        'email'     => $u['email'],
        'role'      => $u['role'],
        'isActive'  => (bool)($u['is_active'] ?? $u['isActive'] ?? false),
        'avatar'    => $u['avatar'] ?? '',
        'createdAt' => $u['created_at'] ?? null,
    ];
}

// Format a category row for API output
function fmtCategory(array $c): array {
    return [
        'id'        => (int)$c['id'],
        'name'      => $c['name'],
        'icon'      => $c['icon'],
        'color'     => $c['color'],
        'isDefault' => (bool)($c['is_default'] ?? false),
        'createdBy' => isset($c['created_by']) ? (int)$c['created_by'] : null,
        'createdAt' => $c['created_at'] ?? null,
    ];
}

// Format a budget row + enrichment for API output
function fmtBudget(array $b, PDO $db): array {
    // Owner
    $st = $db->prepare('SELECT id, name, email, avatar FROM users WHERE id = ?');
    $st->execute([$b['owner_id']]);
    $owner = $st->fetch() ?: null;

    // Members
    $st = $db->prepare('SELECT u.id, u.name, u.email, u.avatar FROM users u JOIN budget_members bm ON u.id = bm.user_id WHERE bm.budget_id = ?');
    $st->execute([$b['id']]);
    $members = $st->fetchAll();

    return [
        'id'             => (int)$b['id'],
        'name'           => $b['name'],
        'isShared'       => (bool)$b['is_shared'],
        'ownerId'        => (int)$b['owner_id'],
        'owner'          => $owner ? ['id' => (int)$owner['id'], 'name' => $owner['name'], 'email' => $owner['email'], 'avatar' => $owner['avatar']] : null,
        'members'        => array_map(fn($m) => ['id' => (int)$m['id'], 'name' => $m['name'], 'email' => $m['email'], 'avatar' => $m['avatar']], $members),
        'period'         => $b['period'],
        'startDate'      => $b['start_date'],
        'endDate'        => $b['end_date'],
        'globalLimit'    => $b['global_limit'] !== null ? (float)$b['global_limit'] : null,
        'alertThreshold' => (int)$b['alert_threshold'],
        'createdAt'      => $b['created_at'],
    ];
}

// Format a transaction row for API output
function fmtTransaction(array $r): array {
    return [
        'id'          => (int)$r['id'],
        'type'        => $r['type'],
        'amount'      => (float)$r['amount'],
        'description' => $r['description'],
        'date'        => $r['date'],
        'comment'     => $r['comment'],
        'budgetId'    => isset($r['budget_id']) && $r['budget_id'] ? (int)$r['budget_id'] : null,
        'createdAt'   => $r['created_at'],
        'category'    => [
            'id'    => (int)$r['category_id'],
            'name'  => $r['category_name'] ?? null,
            'icon'  => $r['category_icon'] ?? null,
            'color' => $r['category_color'] ?? null,
        ],
        'user' => [
            'id'     => (int)$r['user_id'],
            'name'   => $r['user_name'] ?? null,
            'avatar' => $r['user_avatar'] ?? null,
        ],
    ];
}

function sendStatusEmail($userEmail, $username, $status) {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'rayen14865@gmail.com';
        $mail->Password   = 'bgge xetl itiu fjer';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Destinataires
        $mail->setFrom('noreply@budgetcollab.com', 'Budget Collab Admin');
        $mail->addAddress($userEmail, $username);

        // Contenu
        $mail->isHTML(true);
        $mail->Subject = "Mise a jour de votre compte - Budget Collab";

        if ($status == 'active') {
            $mail->Body = "
                <div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
                    <h2 style='color: #22c55e;'>Compte Activé !</h2>
                    <p>Bonjour <strong>$username</strong>,</p>
                    <p>Bonne nouvelle ! Votre compte a été activé par l'administrateur.</p>
                    <p>Vous pouvez maintenant vous connecter et profiter de nos services.</p>
                    <br>
                    <p>Cordialement,<br>L'équipe Budget Collab</p>
                </div>";
        } else {
            $mail->Body = "
                <div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
                    <h2 style='color: #ef4444;'>Compte Désactivé</h2>
                    <p>Bonjour <strong>$username</strong>,</p>
                    <p>Nous vous informons que votre compte a été désactivé par l'administrateur.</p>
                    <p>Si vous pensez qu'il s'agit d'une erreur, veuillez contacter le support.</p>
                    <br>
                    <p>Cordialement,<br>L'équipe Budget Collab</p>
                </div>";
        }

        $mail->send();
        return true;
    } catch (Exception $e) {
        return "L'e-mail n'a pas pu être envoyé. Erreur: {$mail->ErrorInfo}";
    }
}

