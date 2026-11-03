<?php
declare(strict_types=1);
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
