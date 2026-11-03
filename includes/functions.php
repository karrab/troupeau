<?php
declare(strict_types=1);
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();
require_once __DIR__ . '/../config/db.php';

/** Échappement HTML */
function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

/** Date FR */
function dfr(?string $d): string { return $d ? date('d/m/Y', strtotime($d)) : '—'; }

/** Nombre FR */
function nfr($n, int $dec = 1): string { return $n === null || $n === '' ? '—' : number_format((float)$n, $dec, ',', ' '); }

/** Âge lisible à partir de la date de naissance */
function age(?string $naissance): string {
    if (!$naissance) return '—';
    $i = (new DateTime($naissance))->diff(new DateTime());
    if ($i->y > 0) return $i->y . ' an' . ($i->y > 1 ? 's' : '') . ($i->m ? ' ' . $i->m . ' mois' : '');
    if ($i->m > 0) return $i->m . ' mois';
    return $i->d . ' j';
}

/** Message flash */
function flash(string $msg, string $type = 'success'): void { $_SESSION['flash'] = compact('msg', 'type'); }
function show_flash(): string {
    if (empty($_SESSION['flash'])) return '';
    $f = $_SESSION['flash']; unset($_SESSION['flash']);
    return '<div class="alert alert-' . e($f['type']) . ' alert-dismissible fade show">' . e($f['msg'])
         . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}

/** Protection CSRF */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf_token() . '">'; }
function check_csrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(403); exit('Jeton CSRF invalide.'); }
}

function redirect(string $url): never { header('Location: ' . $url); exit; }

/** Valeur POST nettoyée (chaîne vide => NULL) */
function post(string $k) { $v = trim((string)($_POST[$k] ?? '')); return $v === '' ? null : $v; }

/** Listes de référence */
function especes(PDO $pdo): array { return $pdo->query('SELECT * FROM especes ORDER BY nom')->fetchAll(); }
function races(PDO $pdo): array   { return $pdo->query('SELECT r.*, e.nom espece FROM races r JOIN especes e ON e.id=r.espece_id ORDER BY e.nom, r.nom')->fetchAll(); }
function lots(PDO $pdo): array    { return $pdo->query('SELECT * FROM lots ORDER BY nom')->fetchAll(); }

/** Libellés */
const STATUTS = ['actif' => ['Actif', 'success'], 'vendu' => ['Vendu', 'secondary'], 'mort' => ['Mort', 'dark'], 'reforme' => ['Réformé', 'warning']];
const TYPES_REPRO = ['chaleur' => 'Chaleur', 'saillie' => 'Saillie', 'insemination' => 'Insémination', 'diagnostic' => 'Diagnostic gestation',
                     'mise_bas' => 'Mise bas', 'avortement' => 'Avortement', 'tarissement' => 'Tarissement'];
const TYPES_SANTE = ['vaccination' => 'Vaccination', 'traitement' => 'Traitement', 'vermifuge' => 'Vermifuge', 'visite' => 'Visite', 'chirurgie' => 'Chirurgie', 'autre' => 'Autre'];

function badge_statut(string $s): string {
    [$l, $c] = STATUTS[$s] ?? [$s, 'secondary'];
    return '<span class="badge text-bg-' . $c . '">' . $l . '</span>';
}

/** Seuil de cellules (milliers/mL) au-delà duquel on alerte – mammite subclinique */
const SEUIL_CELLULES = 300;

// ------------------------------------------------------------------
// Authentification
// ------------------------------------------------------------------
const ROLES = ['admin' => ['Administrateur', 'danger'], 'eleveur' => ['Éleveur', 'success'], 'veterinaire' => ['Vétérinaire', 'info']];
const LOGIN_MAX_ESSAIS = 5;     // tentatives avant blocage temporaire
const LOGIN_BLOCAGE    = 300;   // durée du blocage (secondes)
const SESSION_INACTIVITE = 7200; // déconnexion automatique après 2 h d'inactivité

/** Utilisateur connecté ou null */
function user(): ?array { return $_SESSION['user'] ?? null; }

/** Ouvre la session de l'utilisateur après vérification du mot de passe */
function login_user(array $u): void {
    session_regenerate_id(true);
    $_SESSION['user'] = ['id' => (int)$u['id'], 'nom' => $u['nom'], 'email' => $u['email'], 'role' => $u['role']];
    $_SESSION['last_activity'] = time();
    unset($_SESSION['login_essais'], $_SESSION['login_bloque_jusqua']);
}

function logout_user(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Redirige vers la page de connexion si personne n'est connecté */
function require_login(): void {
    if (user() && time() - ($_SESSION['last_activity'] ?? 0) > SESSION_INACTIVITE) {
        logout_user();
        session_start();
        flash('Votre session a expiré, veuillez vous reconnecter.', 'warning');
    }
    if (!user()) {
        $retour = $_SERVER['REQUEST_METHOD'] === 'GET' ? ($_SERVER['REQUEST_URI'] ?? '') : '';
        redirect('login.php' . ($retour ? '?retour=' . urlencode($retour) : ''));
    }
    $_SESSION['last_activity'] = time();
}

/** Restreint une page à certains rôles */
function require_role(string ...$roles): void {
    require_login();
    if (!in_array(user()['role'], $roles, true)) { http_response_code(403); exit('Accès refusé.'); }
}

/** URL de retour sûre (chemin relatif interne uniquement) */
function safe_retour(?string $url): string {
    $url = (string)$url;
    // Accepte "/chemin…" (mais pas "//domaine" ni "/\domaine") ou "page.php…"
    $interne = preg_match('#^/(?![/\x5c])#', $url) || preg_match('#^[a-z0-9_]+\.php(\?|$)#i', $url);
    if (!$interne || str_contains($url, 'login.php') || str_contains($url, 'logout.php')) return 'index.php';
    return $url;
}

// Toutes les pages sont protégées, sauf celles déclarées publiques
if (!defined('PAGE_PUBLIQUE')) require_login();
