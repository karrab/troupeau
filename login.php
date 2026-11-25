<?php
const PAGE_PUBLIQUE = true;
require 'includes/functions.php';

$retour = preg_match('/^[a-z_]+\.php(\?[^\s<>"]*)?$/i', $_GET['retour'] ?? '') ? $_GET['retour'] : 'index.php';
if (utilisateur() || connexion_par_jeton($pdo)) redirect($retour);

// Première installation : aucun utilisateur => création du compte administrateur
$installation = !(int)$pdo->query('SELECT COUNT(*) FROM utilisateurs')->fetchColumn();
$erreur = null; $email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $mdp = (string)($_POST['mot_de_passe'] ?? '');
    if ($installation) {
        $nom = trim((string)($_POST['nom'] ?? ''));
        if ($nom === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $erreur = 'Indiquez un nom et une adresse e-mail valide.';
        else $erreur = erreur_mot_de_passe($mdp, (string)($_POST['confirmation'] ?? ''));
        if (!$erreur) {
            // Verrou : un seul compte créé par ce formulaire, même en cas de double envoi
            $pdo->beginTransaction();
            if ((int)$pdo->query('SELECT COUNT(*) FROM utilisateurs FOR UPDATE')->fetchColumn()) { $pdo->rollBack(); redirect('login.php'); }
            $pdo->prepare("INSERT INTO utilisateurs (nom, email, mot_de_passe, profil_id) VALUES (?,?,?,(SELECT id FROM profils WHERE code='admin'))")
                ->execute([$nom, $email, password_hash($mdp, PASSWORD_DEFAULT)]);
            $pdo->commit();
            connecter($pdo, $email, $mdp);
            flash('Compte administrateur créé. Bienvenue ' . $nom . ' !');
            redirect('index.php');
        }
    } else {
        usleep(random_int(200000, 400000)); // ralentit les essais automatisés
        $erreur = connecter($pdo, $email, $mdp);
        if (!$erreur && !empty($_POST['souvenir'])) { creer_jeton_souvenir($pdo, (int)utilisateur()['id']); $_SESSION['souvenir'] = true; }
        if (!$erreur) redirect($retour === 'index.php' && !peut('tableau_bord.voir') ? (peut('animaux.voir') ? 'animaux.php' : 'mon_compte.php') : $retour);
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $installation ? 'Installation' : 'Connexion' ?> · <?= APP_NAME ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="assets/style.css" rel="stylesheet">
</head>
<body class="page-connexion">
<div class="row g-0 min-vh-100">

  <!-- Présentation -->
  <aside class="col-lg-6 cx-presentation d-none d-lg-flex flex-column">
    <div class="cx-colline cx-colline-1" aria-hidden="true"></div>
    <div class="cx-colline cx-colline-2" aria-hidden="true"></div>
    <div class="cx-marque"><i class="bi bi-flower3"></i> <?= APP_NAME ?></div>
    <div class="my-auto">
      <h2 class="cx-titre">Suivez votre troupeau,<br>simplement.</h2>
      <p class="cx-accroche">Bovins, ovins et caprins : identité, pesées, contrôle laitier, reproduction et santé réunis au même endroit.</p>
      <ul class="cx-atouts list-unstyled mb-0">
        <li><span><i class="bi bi-graph-up"></i></span> GMQ et courbes de croissance automatiques</li>
        <li><span><i class="bi bi-droplet-half"></i></span> Production laitière, TB/TP et alertes cellules</li>
        <li><span><i class="bi bi-calendar-heart"></i></span> Mises bas prévues et suivi de la reproduction</li>
        <li><span><i class="bi bi-shield-plus"></i></span> Traitements, rappels et délais d'attente</li>
      </ul>
    </div>
    <div class="cx-pied">© <?= date('Y') ?> <?= APP_NAME ?> · Système d'information de suivi des troupeaux</div>
  </aside>

  <!-- Formulaire -->
  <main class="col-lg-6 d-flex align-items-center justify-content-center p-3 py-5">
    <div class="cx-carte card border-0 w-100">
      <div class="text-center mb-4">
        <div class="cx-logo mb-3"><i class="bi bi-flower3"></i></div>
        <h1 class="h3 fw-bold mb-1"><?= $installation ? 'Installation' : 'Connexion' ?></h1>
        <p class="text-muted mb-0"><?= $installation ? 'Créez le compte administrateur de ' . APP_NAME : 'Accédez à votre espace ' . APP_NAME ?></p>
      </div>
      <?= show_flash() ?>
      <?php if ($erreur): ?><div class="alert alert-danger py-2 small" role="alert"><i class="bi bi-exclamation-circle"></i> <?= e($erreur) ?></div><?php endif; ?>

      <?php if ($installation): ?>
      <form method="post" autocomplete="off" class="cx-form"><?= csrf_field() ?>
        <div class="form-floating mb-3"><input id="nom" name="nom" class="form-control" placeholder="Nom" required value="<?= e($_POST['nom'] ?? '') ?>" autofocus>
          <label for="nom"><i class="bi bi-person"></i> Nom</label></div>
        <div class="form-floating mb-3"><input id="email" type="email" name="email" class="form-control" placeholder="Adresse e-mail" required value="<?= e($email) ?>">
          <label for="email"><i class="bi bi-envelope"></i> Adresse e-mail</label></div>
        <div class="input-group mb-1">
          <div class="form-floating"><input id="mdp" type="password" name="mot_de_passe" class="form-control" placeholder="Mot de passe" required minlength="<?= MDP_LONGUEUR_MIN ?>" autocomplete="new-password">
            <label for="mdp"><i class="bi bi-lock"></i> Mot de passe</label></div>
          <button type="button" class="btn btn-outline-secondary cx-oeil" data-cible="mdp" aria-label="Afficher le mot de passe"><i class="bi bi-eye"></i></button>
        </div>
        <div class="form-text mb-3"><?= MDP_LONGUEUR_MIN ?> caractères minimum, avec au moins une lettre et un chiffre.</div>
        <div class="form-floating mb-4"><input id="conf" type="password" name="confirmation" class="form-control" placeholder="Confirmation" required autocomplete="new-password">
          <label for="conf"><i class="bi bi-lock"></i> Confirmation du mot de passe</label></div>
        <button class="btn btn-primary btn-lg w-100"><i class="bi bi-person-check"></i> Créer le compte administrateur</button>
      </form>

      <?php else: ?>
      <form method="post" class="cx-form"><?= csrf_field() ?>
        <div class="form-floating mb-3"><input id="email" type="email" name="email" class="form-control" placeholder="Adresse e-mail" required value="<?= e($email) ?>" autocomplete="username" <?= $email ? '' : 'autofocus' ?>>
          <label for="email"><i class="bi bi-envelope"></i> Adresse e-mail</label></div>
        <div class="input-group mb-3">
          <div class="form-floating"><input id="mdp" type="password" name="mot_de_passe" class="form-control" placeholder="Mot de passe" required autocomplete="current-password" <?= $email ? 'autofocus' : '' ?>>
            <label for="mdp"><i class="bi bi-lock"></i> Mot de passe</label></div>
          <button type="button" class="btn btn-outline-secondary cx-oeil" data-cible="mdp" aria-label="Afficher le mot de passe"><i class="bi bi-eye"></i></button>
        </div>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
          <div class="form-check mb-0 text-nowrap"><input class="form-check-input" type="checkbox" name="souvenir" value="1" id="souvenir" <?= !empty($_POST['souvenir']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="souvenir" title="Rester connecté <?= SOUVENIR_JOURS ?> jours sur cet appareil">Se souvenir de moi</label></div>
          <a href="#" class="cx-lien small text-nowrap" data-bs-toggle="modal" data-bs-target="#oubli">Mot de passe oublié ?</a>
        </div>
        <button class="btn btn-primary btn-lg w-100"><i class="bi bi-box-arrow-in-right"></i> Se connecter</button>
      </form>
      <?php endif; ?>
    </div>
  </main>
</div>

<!-- Mot de passe oublié -->
<div class="modal fade" id="oubli" tabindex="-1" aria-labelledby="oubliTitre" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title h5" id="oubliTitre"><i class="bi bi-key"></i> Mot de passe oublié</h2>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div>
    <div class="modal-body">
      <p>Pour des raisons de sécurité, les mots de passe sont réinitialisés par l'<strong>administrateur</strong> de l'application.</p>
      <ol class="mb-3">
        <li>Contactez l'administrateur de votre élevage.</li>
        <li>Il génère un <strong>mot de passe provisoire</strong> depuis la fiche de votre compte.</li>
        <li>Connectez-vous avec ce mot de passe : vous choisirez aussitôt votre nouveau mot de passe.</li>
      </ol>
      <p class="small text-muted mb-0">Administrateur sans accès ? Sur le serveur : <code>php outils/reinitialiser_mot_de_passe.php votre@adresse</code>.</p>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-primary" data-bs-dismiss="modal">Compris</button></div>
  </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.cx-oeil').forEach(b => b.onclick = () => {
  const m = document.getElementById(b.dataset.cible), cache = m.type === 'password';
  m.type = cache ? 'text' : 'password';
  b.firstElementChild.className = cache ? 'bi bi-eye-slash' : 'bi bi-eye';
  b.setAttribute('aria-label', cache ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
});
</script>
</body>
</html>
