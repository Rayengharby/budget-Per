<?php
// ── Load Composer Autoloader ──────────────────────────────
require_once __DIR__ . '/vendor/autoload.php';

// ── CORS Headers (always needed) ─────────────────────────
require_once __DIR__ . '/config/helpers.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── Load controllers ──────────────────────────────────────
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/TransactionController.php';
require_once __DIR__ . '/controllers/BudgetController.php';
require_once __DIR__ . '/controllers/CategoryController.php';
require_once __DIR__ . '/controllers/UserController.php';
require_once __DIR__ . '/controllers/AdminController.php';

// ── Parse URI ──────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$uri    = trim($_SERVER['PATH_INFO'] ?? '', '/');

// Fallback: parse from REQUEST_URI when no PATH_INFO (mod_rewrite mode)
if ($uri === '') {
    $uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $uri  = trim($uri, '/');
    $base = trim(dirname($_SERVER['SCRIPT_NAME']), '/');
    if ($base && str_starts_with($uri, $base)) {
        $uri = trim(substr($uri, strlen($base)), '/');
    }
    // Strip the script filename itself if present
    $script = basename($_SERVER['SCRIPT_FILENAME']);
    if (str_starts_with($uri, $script)) {
        $uri = trim(substr($uri, strlen($script)), '/');
    }
}

// Remove optional leading 'api/' prefix
if (str_starts_with($uri, 'api/')) $uri = substr($uri, 4);

$seg = array_values(array_filter(explode('/', $uri)));

$r0 = $seg[0] ?? '';   // resource
$r1 = $seg[1] ?? '';   // id or sub-resource
$r2 = $seg[2] ?? '';   // sub-sub-resource
$r3 = $seg[3] ?? '';   // action

// Set default content type for all routes except PDF export
if (!($r0 === 'transactions' && $r1 === 'pdf')) {
    header('Content-Type: application/json; charset=utf-8');
}

// ── Router ─────────────────────────────────────────────────
try {
    switch ($r0) {


        // ── Auth ──────────────────────────────────────────
        case 'auth':
            match([$method, $r1]) {
                ['POST', 'register'] => AuthController::register(),
                ['POST', 'login']    => AuthController::login(),
                ['GET',  'me']       => AuthController::me(),
                default              => notFound(),
            };
            break;

        // ── Transactions ──────────────────────────────────
        case 'transactions':
            if ($r1 === 'pdf') {
                $method === 'GET' ? TransactionController::exportPdf() : notFound();
            } elseif ($r1 === 'stats' && $r2 === 'dashboard') {
                $method === 'GET' ? TransactionController::stats() : notFound();
            } elseif ($r1 !== '') {
                match($method) {
                    'PUT'    => TransactionController::update((int)$r1),
                    'DELETE' => TransactionController::delete((int)$r1),
                    default  => notFound(),
                };
            } else {
                match($method) {
                    'GET'  => TransactionController::index(),
                    'POST' => TransactionController::create(),
                    default => notFound(),
                };
            }
            break;

        // ── Budgets ───────────────────────────────────────
        case 'budgets':
            if ($r1 !== '' && $r2 === 'members') {
                $method === 'POST' ? BudgetController::addMember((int)$r1) : notFound();
            } elseif ($r1 !== '' && $r2 === 'summary') {
                $method === 'GET' ? BudgetController::summary((int)$r1) : notFound();
            } elseif ($r1 !== '') {
                $method === 'DELETE' ? BudgetController::delete((int)$r1) : notFound();
            } else {
                match($method) {
                    'GET'  => BudgetController::index(),
                    'POST' => BudgetController::create(),
                    default => notFound(),
                };
            }
            break;

        // ── Categories ────────────────────────────────────
        case 'categories':
            if ($r1 !== '') {
                match($method) {
                    'PUT'    => CategoryController::update((int)$r1),
                    'DELETE' => CategoryController::delete((int)$r1),
                    default  => notFound(),
                };
            } else {
                match($method) {
                    'GET'  => CategoryController::index(),
                    'POST' => CategoryController::create(),
                    default => notFound(),
                };
            }
            break;

        // ── Users ─────────────────────────────────────────
        case 'users':
            if ($r1 === 'me' && $r2 === 'password') {
                $method === 'PUT' ? UserController::updatePassword() : notFound();
            } elseif ($r1 === 'me') {
                match($method) {
                    'GET' => UserController::me(),
                    'PUT' => UserController::update(),
                    default => notFound(),
                };
            } else {
                notFound();
            }
            break;

        // ── Admin ─────────────────────────────────────────
        case 'admin':
            switch ($r1) {
                case 'stats':
                    $method === 'GET' ? AdminController::stats() : notFound();
                    break;
                case 'users':
                    if ($r2 !== '' && $r3 === 'activate') {
                        $method === 'PATCH' ? AdminController::activate((int)$r2) : notFound();
                    } elseif ($r2 !== '' && $r3 === 'deactivate') {
                        $method === 'PATCH' ? AdminController::deactivate((int)$r2) : notFound();
                    } elseif ($r2 !== '' && $r3 === 'role') {
                        $method === 'PATCH' ? AdminController::changeRole((int)$r2) : notFound();
                    } elseif ($r2 !== '') {
                        $method === 'DELETE' ? AdminController::deleteUser((int)$r2) : notFound();
                    } else {
                        $method === 'GET' ? AdminController::users() : notFound();
                    }
                    break;
                case 'budgets':
                    $method === 'GET' ? AdminController::budgets() : notFound();
                    break;
                default:
                    notFound();
            }
            break;

        default:
            notFound();
    }
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => 'Erreur serveur : ' . $e->getMessage()], 500);
}

function notFound(): void {
    jsonResponse(['success' => false, 'message' => 'Route introuvable.'], 404);
    exit;
}
