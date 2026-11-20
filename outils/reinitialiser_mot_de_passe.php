<?php
// =====================================================================
//  Secours : réinitialiser le mot de passe d'un compte en ligne de commande
//  (par exemple si le seul administrateur a oublié le sien).
//
//    php outils/reinitialiser_mot_de_passe.php adresse@exemple.fr
//
//  Un mot de passe provisoire est affiché ; il devra être changé à la
//  connexion suivante. Le compte est aussi débloqué et réactivé.
// =====================================================================
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/functions.php';

$email = mb_strtolower(trim($argv[1] ?? ''));
if ($email === '') {
    echo "Usage : php outils/reinitialiser_mot_de_passe.php adresse@exemple.fr\n\nComptes existants :\n";
    foreach ($pdo->query('SELECT u.email, u.actif, p.nom profil FROM utilisateurs u LEFT JOIN profils p ON p.id=u.profil_id ORDER BY p.systeme DESC, u.email') as $u)
        echo '  - ' . $u['email'] . ' (' . ($u['profil'] ?? 'sans profil') . ($u['actif'] ? '' : ', désactivé') . ")\n";
    exit(1);
}
$st = $pdo->prepare('SELECT id FROM utilisateurs WHERE email=?'); $st->execute([$email]);
if (!$id = $st->fetchColumn()) { fwrite(STDERR, "Aucun compte « $email ».\n"); exit(1); }
$mdp = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(12))), 0, 10) . random_int(10, 99);
$pdo->prepare('UPDATE utilisateurs SET mot_de_passe=?, doit_changer_mdp=1, actif=1, echecs=0, bloque_jusqua=NULL WHERE id=?')
    ->execute([password_hash($mdp, PASSWORD_DEFAULT), $id]);
echo "Mot de passe provisoire pour $email : $mdp\n(à changer à la prochaine connexion)\n";
