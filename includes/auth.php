<?php
// =====================================================================
//  Authentification et droits d'accès
//  - toutes les pages exigent une connexion, sauf celles qui définissent
//    la constante PAGE_PUBLIQUE avant d'inclure functions.php ;
//  - mots de passe chiffrés avec password_hash() ;
//  - compte bloqué 15 min après 5 échecs ; session expirée après 2 h
//    d'inactivité.
// =====================================================================

const ROLES = [
    'admin'       => ['Administrateur', 'danger', 'Tous les droits : paramètres, formules des indices, utilisateurs'],
    'eleveur'     => ['Éleveur', 'success', 'Saisie et modification des animaux et de tout leur suivi'],
    'veterinaire' => ['Vétérinaire', 'info', 'Consultation de tout le troupeau, saisie des actes de santé'],
];
const MDP_LONGUEUR_MIN  = 8;
const ECHECS_MAX        = 5;
const BLOCAGE_MINUTES   = 15;
const SESSION_INACTIVITE = 7200; // secondes

/** Utilisateur connecté (ou null) */
function utilisateur(): ?array { return $_SESSION['utilisateur'] ?? null; }

/** L'utilisateur connecté a-t-il l'un de ces rôles ? */
function a_role(string ...$roles): bool { return in_array(utilisateur()['role'] ?? '', $roles, true); }

/** Droit de saisie pour un type d'enregistrement de la fiche animal */
function peut_saisir(string $type = ''): bool
{
    return a_role('admin', 'eleveur') || ($type === 'sante' && a_role('veterinaire'));
}

/** Refuse l'accès si l'utilisateur n'a aucun des rôles demandés */
function exiger_role(string ...$roles): void
{
    if (a_role(...$roles)) return;
    http_response_code(403);
    flash('Accès réservé : ' . implode(', ', array_map(fn($r) => ROLES[$r][0], $roles)) . '.', 'danger');
    redirect('index.php');
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
    $_SESSION['utilisateur'] = ['id' => (int)$u['id'], 'nom' => $u['nom'], 'email' => $u['email'], 'role' => $u['role'],
                                'doit_changer_mdp' => (int)$u['doit_changer_mdp']];
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
    if (!$u) { password_verify($mdp, '$2y$10$PVIiiEZGu5YGh5FPvRVtQuTUBZaX.rYfvOB.fMSjtC8LDyVgN0ZjO'); return $refus; } // même durée qu'un vrai essai
    if ($u['bloque_jusqua'] && $u['bloque_jusqua'] > date('Y-m-d H:i:s'))
        return 'Compte temporairement bloqué après trop d\'essais. Réessayez après ' . date('H:i', strtotime($u['bloque_jusqua'])) . '.';
    if (!password_verify($mdp, $u['mot_de_passe'])) {
        $echecs = (int)$u['echecs'] + 1;
        $bloque = $echecs >= ECHECS_MAX ? date('Y-m-d H:i:s', time() + BLOCAGE_MINUTES * 60) : null;
        $pdo->prepare('UPDATE utilisateurs SET echecs=?, bloque_jusqua=? WHERE id=?')->execute([$bloque ? 0 : $echecs, $bloque, $u['id']]);
        return $bloque ? 'Trop d\'essais : compte bloqué ' . BLOCAGE_MINUTES . ' minutes.' : $refus;
    }
    if (!$u['actif']) return 'Ce compte est désactivé. Contactez l\'administrateur.';
    ouvrir_session($pdo, $u, $mdp);
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
    // Compte désactivé ou supprimé entre-temps, ou rôle modifié
    $st = $pdo->prepare('SELECT role, actif, doit_changer_mdp FROM utilisateurs WHERE id=?');
    $st->execute([utilisateur()['id']]);
    $u = $st->fetch();
    if (!$u || !$u['actif']) { deconnecter(); flash('Votre compte n\'est plus actif.', 'danger'); redirect('login.php'); }
    $_SESSION['utilisateur']['role'] = $u['role'];
    $_SESSION['utilisateur']['doit_changer_mdp'] = (int)$u['doit_changer_mdp'];
    // Mot de passe provisoire : changement obligatoire avant toute autre page
    if ($u['doit_changer_mdp'] && basename($_SERVER['PHP_SELF']) !== 'mon_compte.php' && basename($_SERVER['PHP_SELF']) !== 'logout.php') {
        flash('Choisissez votre mot de passe personnel pour continuer.', 'warning');
        redirect('mon_compte.php');
    }
}
