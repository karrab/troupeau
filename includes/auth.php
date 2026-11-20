<?php
// =====================================================================
//  Authentification et droits d'accès
//  - toutes les pages exigent une connexion, sauf celles qui définissent
//    la constante PAGE_PUBLIQUE avant d'inclure functions.php ;
//  - mots de passe chiffrés avec password_hash() ;
//  - compte bloqué 15 min après 5 échecs ; session expirée après 2 h
//    d'inactivité.
// =====================================================================

/** Catalogue des permissions, par module : code => [libellé, sensible] */
const PERMISSIONS = [
    'Tableau de bord' => [
        'tableau_bord.voir'      => ['Voir le tableau de bord et les alertes', false],
    ],
    'Animaux' => [
        'animaux.voir'           => ['Voir la liste et les fiches des animaux', false],
        'animaux.creer'          => ['Créer un animal (naissance, achat)', false],
        'animaux.modifier'       => ['Modifier l\'identité, le lot, le statut, la sortie, la photo', false],
        'animaux.supprimer'      => ['Supprimer définitivement un animal et tout son historique', true],
    ],
    'Pesées' => [
        'pesees.voir'            => ['Voir les pesées et la croissance', false],
        'pesees.saisir'          => ['Saisir des pesées (fiche et séance par lot)', false],
        'pesees.supprimer'       => ['Supprimer une pesée', false],
    ],
    'Mensurations' => [
        'mensurations.voir'      => ['Voir les mensurations (PMB, W0…Wn, L1…L6)', false],
        'mensurations.saisir'    => ['Saisir des mensurations (fiche et séance par lot)', false],
        'mensurations.supprimer' => ['Supprimer une mensuration', false],
    ],
    'Production laitière' => [
        'lait.voir'              => ['Voir les contrôles laitiers et la synthèse', false],
        'lait.saisir'            => ['Saisir un contrôle laitier', false],
        'lait.supprimer'         => ['Supprimer un contrôle laitier', false],
    ],
    'Reproduction' => [
        'repro.voir'             => ['Voir les événements de reproduction', false],
        'repro.saisir'           => ['Saisir chaleurs, saillies, IA, diagnostics, mises bas…', false],
        'repro.supprimer'        => ['Supprimer un événement de reproduction', false],
    ],
    'Santé' => [
        'sante.voir'             => ['Voir les actes de santé et délais d\'attente', false],
        'sante.saisir'           => ['Saisir vaccinations, traitements, visites…', false],
        'sante.supprimer'        => ['Supprimer un acte de santé', false],
    ],
    'Alimentation' => [
        'alimentation.voir'      => ['Voir l\'aliment distribué', false],
        'alimentation.saisir'    => ['Saisir une distribution d\'aliment', false],
        'alimentation.supprimer' => ['Supprimer une distribution', false],
    ],
    'Mouvements' => [
        'mouvements.voir'        => ['Voir l\'historique des mouvements', false],
        'mouvements.saisir'      => ['Transférer un animal de lot', false],
        'mouvements.supprimer'   => ['Supprimer un mouvement', false],
    ],
    'Indices' => [
        'indices.voir'           => ['Voir les indices de productivité', false],
        'indices.calculer'       => ['Lancer le calcul / recalcul des indices', false],
        'indices.exporter'       => ['Exporter les indices (CSV)', false],
        'indices.configurer'     => ['Modifier les formules, seuils et la campagne', true],
    ],
    'Administration' => [
        'parametres.gerer'       => ['Gérer espèces, races, lots et libellés des mensurations', true],
        'utilisateurs.gerer'     => ['Gérer les comptes utilisateurs (sauf administrateurs)', true],
    ],
];
/** Module de la fiche animal correspondant à chaque type de saisie (actions.php) */
const MODULE_SAISIE = ['pesee' => 'pesees', 'lait' => 'lait', 'repro' => 'repro', 'sante' => 'sante', 'mvt' => 'mouvements',
                       'alim' => 'alimentation', 'mens' => 'mensurations'];
const COULEURS_PROFILS = ['secondary' => 'Gris', 'success' => 'Vert', 'primary' => 'Vert foncé', 'info' => 'Bleu', 'warning' => 'Orange', 'dark' => 'Noir', 'danger' => 'Rouge'];
const MDP_LONGUEUR_MIN  = 8;
const ECHECS_MAX        = 5;
const BLOCAGE_MINUTES   = 15;
const SESSION_INACTIVITE = 7200; // secondes

function liste_permissions(): array { $l = []; foreach (PERMISSIONS as $g) $l += $g; return $l; }

/** Utilisateur connecté (ou null) */
function utilisateur(): ?array { return $_SESSION['utilisateur'] ?? null; }

/** L'utilisateur connecté appartient-il au profil Administrateur (tous les droits) ? */
function est_admin(): bool { return (bool)(utilisateur()['admin'] ?? false); }

/** L'utilisateur connecté a-t-il cette permission ? (l'administrateur les a toutes) */
function peut(string $permission): bool
{
    $u = utilisateur();
    if (!$u) return false;
    if ($permission === 'profils.gerer') return !empty($u['admin']); // réservé au profil Administrateur
    return !empty($u['admin']) || in_array($permission, $u['permissions'] ?? [], true);
}
/** Au moins une des permissions */
function peut_un(string ...$permissions): bool { foreach ($permissions as $p) if (peut($p)) return true; return false; }

/** Saisie / suppression d'un type d'enregistrement de la fiche animal (pesee, lait, repro, sante, mvt, alim, mens) */
function peut_saisir(string $type = ''): bool
{
    if ($type === '') return peut_un(...array_map(fn($m) => "$m.saisir", MODULE_SAISIE));
    return isset(MODULE_SAISIE[$type]) && peut(MODULE_SAISIE[$type] . '.saisir');
}
function peut_supprimer(string $type): bool { return isset(MODULE_SAISIE[$type]) && peut(MODULE_SAISIE[$type] . '.supprimer'); }

/** Refuse l'accès si l'utilisateur n'a aucune des permissions demandées */
function exiger(string ...$permissions): void
{
    if (peut_un(...$permissions)) return;
    http_response_code(403);
    $l = liste_permissions();
    flash('Accès refusé : votre profil n\'a pas le droit « ' . implode(' » ou « ', array_map(fn($p) => $l[$p][0] ?? $p, $permissions)) . ' ».', 'danger');
    redirect(basename($_SERVER['PHP_SELF']) === 'index.php' || !peut('tableau_bord.voir') ? 'mon_compte.php' : 'index.php');
}

/** Profils et leurs permissions */
function profils(PDO $pdo): array
{
    $p = $pdo->query('SELECT p.*, (SELECT COUNT(*) FROM utilisateurs u WHERE u.profil_id=p.id) nb_utilisateurs FROM profils p ORDER BY p.systeme DESC, p.nom')->fetchAll(PDO::FETCH_UNIQUE);
    foreach ($p as $id => &$x) { $x['id'] = $id; $x['permissions'] = []; }
    unset($x);
    foreach ($pdo->query('SELECT * FROM profil_permissions') as $r) if (isset($p[$r['profil_id']])) $p[$r['profil_id']]['permissions'][] = $r['permission'];
    return $p;
}

/** Badge du profil */
function badge_profil(?array $u): string
{
    if (!$u || empty($u['profil_nom'])) return '<span class="badge text-bg-light">Sans profil</span>';
    return '<span class="badge text-bg-' . e($u['profil_couleur'] ?: 'secondary') . '">' . e($u['profil_nom']) . '</span>';
}

/** Initiales ou photo de l'utilisateur */
function avatar(array $u, int $taille = 32): string
{
    if (!empty($u['photo']) && is_file(UPLOAD_DIR . 'utilisateurs/' . $u['photo']))
        return '<img src="uploads/utilisateurs/' . e($u['photo']) . '" class="rounded-circle object-fit-cover" width="' . $taille . '" height="' . $taille . '" alt="">';
    $ini = mb_strtoupper(implode('', array_map(fn($m) => mb_substr($m, 0, 1), array_slice(preg_split('/\s+/', trim($u['nom'] ?? '?')), 0, 2))));
    return '<span class="avatar-initiales rounded-circle" style="width:' . $taille . 'px;height:' . $taille . 'px;font-size:' . round($taille * .4) . 'px">' . e($ini) . '</span>';
}

/** Charge l'utilisateur, son profil et ses permissions depuis la base */
function charger_utilisateur(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT u.*, p.nom profil_nom, p.code profil_code, p.couleur profil_couleur, p.systeme profil_systeme
        FROM utilisateurs u LEFT JOIN profils p ON p.id=u.profil_id WHERE u.id=?');
    $st->execute([$id]);
    if (!$u = $st->fetch()) return null;
    $st = $pdo->prepare('SELECT permission FROM profil_permissions WHERE profil_id=?');
    $st->execute([(int)$u['profil_id']]);
    return ['id' => (int)$u['id'], 'nom' => $u['nom'], 'email' => $u['email'], 'photo' => $u['photo'], 'actif' => (int)$u['actif'],
            'profil_id' => $u['profil_id'] ? (int)$u['profil_id'] : null, 'profil_nom' => $u['profil_nom'], 'profil_couleur' => $u['profil_couleur'],
            'admin' => $u['profil_code'] === 'admin', 'permissions' => $st->fetchAll(PDO::FETCH_COLUMN),
            'doit_changer_mdp' => (int)$u['doit_changer_mdp']];
}

/** Trace une tentative de connexion */
function journaliser(PDO $pdo, ?int $uid, string $email, bool $succes, string $motif = ''): void
{
    try {
        $pdo->prepare('INSERT INTO journal_connexions (utilisateur_id, email, succes, motif, ip, navigateur) VALUES (?,?,?,?,?,?)')
            ->execute([$uid, mb_substr($email, 0, 150), (int)$succes, $motif ?: null, $_SERVER['REMOTE_ADDR'] ?? null, mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255) ?: null]);
    } catch (PDOException) {}
}

/** Vérifie la robustesse d'un mot de passe ; renvoie un message d'erreur ou null */
function erreur_mot_de_passe(string $mdp, ?string $confirmation = null): ?string
{
    if (mb_strlen($mdp) < MDP_LONGUEUR_MIN) return 'Le mot de passe doit contenir au moins ' . MDP_LONGUEUR_MIN . ' caractères.';
    if (!preg_match('/[A-Za-z]/', $mdp) || !preg_match('/\d/', $mdp)) return 'Le mot de passe doit contenir au moins une lettre et un chiffre.';
    if ($confirmation !== null && $mdp !== $confirmation) return 'Les deux mots de passe ne correspondent pas.';
    return null;
}

/** Ouvre la session de l'utilisateur après une authentification réussie */
function ouvrir_session(PDO $pdo, array $u, ?string $mdp = null): void
{
    session_regenerate_id(true);
    $_SESSION['utilisateur'] = charger_utilisateur($pdo, (int)$u['id']);
    $_SESSION['activite'] = time();
    unset($_SESSION['csrf']);
    $pdo->prepare('UPDATE utilisateurs SET echecs=0, bloque_jusqua=NULL, derniere_connexion=NOW() WHERE id=?')->execute([$u['id']]);
    if ($mdp !== null && password_needs_rehash($u['mot_de_passe'], PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE utilisateurs SET mot_de_passe=? WHERE id=?')->execute([password_hash($mdp, PASSWORD_DEFAULT), $u['id']]);
    }
}

/**
 * Tente une connexion.
 * @return string|null message d'erreur, null si la connexion a réussi
 */
function connecter(PDO $pdo, string $email, string $mdp): ?string
{
    $st = $pdo->prepare('SELECT * FROM utilisateurs WHERE email=?');
    $st->execute([mb_strtolower(trim($email))]);
    $u = $st->fetch();
    $refus = 'Adresse e-mail ou mot de passe incorrect.';
    if (!$u) { journaliser($pdo, null, $email, false, 'compte inconnu'); password_verify($mdp, '$2y$10$PVIiiEZGu5YGh5FPvRVtQuTUBZaX.rYfvOB.fMSjtC8LDyVgN0ZjO'); return $refus; } // même durée qu'un vrai essai
    if ($u['bloque_jusqua'] && $u['bloque_jusqua'] > date('Y-m-d H:i:s')) {
        journaliser($pdo, (int)$u['id'], $email, false, 'compte bloqué');
        return 'Compte temporairement bloqué après trop d\'essais. Réessayez après ' . date('H:i', strtotime($u['bloque_jusqua'])) . '.';
    }
    if (!password_verify($mdp, $u['mot_de_passe'])) {
        $echecs = (int)$u['echecs'] + 1;
        $bloque = $echecs >= ECHECS_MAX ? date('Y-m-d H:i:s', time() + BLOCAGE_MINUTES * 60) : null;
        $pdo->prepare('UPDATE utilisateurs SET echecs=?, bloque_jusqua=? WHERE id=?')->execute([$bloque ? 0 : $echecs, $bloque, $u['id']]);
        journaliser($pdo, (int)$u['id'], $email, false, $bloque ? 'mot de passe incorrect – compte bloqué' : 'mot de passe incorrect');
        return $bloque ? 'Trop d\'essais : compte bloqué ' . BLOCAGE_MINUTES . ' minutes.' : $refus;
    }
    if (!$u['actif']) { journaliser($pdo, (int)$u['id'], $email, false, 'compte désactivé'); return 'Ce compte est désactivé. Contactez l\'administrateur.'; }
    ouvrir_session($pdo, $u, $mdp);
    journaliser($pdo, (int)$u['id'], $email, true);
    return null;
}

/** Vide la session et change son identifiant (l'ancien est détruit) */
function deconnecter(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

/** Contrôle d'accès exécuté au chargement de chaque page */
function controle_acces(PDO $pdo): void
{
    if (PHP_SAPI === 'cli' || defined('PAGE_PUBLIQUE')) return;
    $retour = 'login.php?retour=' . rawurlencode(basename($_SERVER['REQUEST_URI'] ?? 'index.php'));
    if (!utilisateur()) redirect($retour);
    if (time() - ($_SESSION['activite'] ?? 0) > SESSION_INACTIVITE) {
        deconnecter();
        flash('Session expirée après inactivité. Reconnectez-vous.', 'warning');
        redirect($retour);
    }
    $_SESSION['activite'] = time();
    // Compte désactivé ou supprimé entre-temps ; profil et permissions rechargés à chaque page
    $u = charger_utilisateur($pdo, (int)utilisateur()['id']);
    if (!$u || !$u['actif']) { deconnecter(); flash('Votre compte n\'est plus actif.', 'danger'); redirect('login.php'); }
    $_SESSION['utilisateur'] = $u;
    // Mot de passe provisoire : changement obligatoire avant toute autre page
    if ($u['doit_changer_mdp'] && basename($_SERVER['PHP_SELF']) !== 'mon_compte.php' && basename($_SERVER['PHP_SELF']) !== 'logout.php') {
        flash('Choisissez votre mot de passe personnel pour continuer.', 'warning');
        redirect('mon_compte.php');
    }
}

// ---------------------------------------------------------------------
//  Règles de gestion des comptes
//  - seul un administrateur peut gérer un compte administrateur ou
//    attribuer le profil Administrateur ;
//  - il reste toujours au moins un administrateur actif.
// ---------------------------------------------------------------------
function profil_admin_id(PDO $pdo): int { return (int)$pdo->query("SELECT id FROM profils WHERE code='admin'")->fetchColumn(); }

function nb_admins_actifs(PDO $pdo): int
{
    return (int)$pdo->query("SELECT COUNT(*) FROM utilisateurs u JOIN profils p ON p.id=u.profil_id WHERE p.code='admin' AND u.actif=1")->fetchColumn();
}

/** L'utilisateur connecté peut-il gérer ce compte ? ($cible : ligne utilisateurs) */
function peut_gerer_compte(PDO $pdo, array $cible): bool
{
    if (est_admin()) return true;
    return peut('utilisateurs.gerer') && (int)$cible['profil_id'] !== profil_admin_id($pdo);
}

/** Profils que l'utilisateur connecté peut attribuer */
function profils_attribuables(PDO $pdo): array
{
    return array_filter(profils($pdo), fn($p) => est_admin() || !$p['systeme']);
}

/** Le compte est-il le dernier administrateur actif ? */
function dernier_admin(PDO $pdo, array $cible): bool
{
    return (int)$cible['profil_id'] === profil_admin_id($pdo) && $cible['actif'] && nb_admins_actifs($pdo) <= 1;
}

/** Mot de passe provisoire lisible (sans caractères ambigus) */
function mdp_provisoire(): string
{
    $l = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ'; $c = '23456789'; $m = '';
    for ($i = 0; $i < 8; $i++) $m .= $l[random_int(0, strlen($l) - 1)];
    return $m . $c[random_int(0, 7)] . $c[random_int(0, 7)];
}

/** Enregistre la photo envoyée pour un utilisateur ; renvoie le nouveau nom de fichier */
function enregistrer_photo_utilisateur(array $fichier, ?string $ancienne): string
{
    if ($fichier['error'] !== UPLOAD_ERR_OK) throw new InvalidArgumentException('Envoi de la photo impossible.');
    $ext = strtolower(pathinfo($fichier['name'], PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($fichier['tmp_name']);
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true))
        throw new InvalidArgumentException('Photo : format JPG, PNG ou WEBP uniquement.');
    if ($fichier['size'] > 2 * 1024 * 1024) throw new InvalidArgumentException('Photo : 2 Mo maximum.');
    $dir = UPLOAD_DIR . 'utilisateurs/';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $nom = bin2hex(random_bytes(12)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    if (!move_uploaded_file($fichier['tmp_name'], $dir . $nom)) throw new InvalidArgumentException('Enregistrement de la photo impossible.');
    if ($ancienne && is_file($dir . basename($ancienne))) unlink($dir . basename($ancienne));
    return $nom;
}
