<?php
const PAGE_PUBLIQUE = true;
require 'includes/functions.php';

$retour = preg_match('/^[a-z_]+\.php(\?[^\s<>"]*)?$/i', $_GET['retour'] ?? '') ? $_GET['retour'] : 'index.php';
if (utilisateur()) redirect($retour);

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
            $pdo->prepare("INSERT INTO utilisateurs (nom, email, mot_de_passe, role) VALUES (?,?,?,'admin')")->execute([$nom, $email, password_hash($mdp, PASSWORD_DEFAULT)]);
            $pdo->commit();
            connecter($pdo, $email, $mdp);
            flash('Compte administrateur créé. Bienvenue ' . $nom . ' !');
            redirect('index.php');
        }
    } else {
        usleep(random_int(200000, 400000)); // ralentit les essais automatisés
        $erreur = connecter($pdo, $email, $mdp);
        if (!$erreur) redirect($retour);
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
<main class="d-flex align-items-center justify-content-center min-vh-100 p-3">
  <div class="card p-4 shadow-sm" style="width:100%;max-width:400px">
    <div class="text-center mb-3">
      <div class="logo-connexion mb-2"><i class="bi bi-flower3"></i></div>
      <h1 class="h4 mb-0"><?= APP_NAME ?></h1>
      <div class="text-muted small">Gestion et suivi des troupeaux</div>
    </div>
    <?= show_flash() ?>
    <?php if ($erreur): ?><div class="alert alert-danger py-2 small"><?= e($erreur) ?></div><?php endif; ?>

    <?php if ($installation): ?>
      <div class="alert alert-info small py-2"><strong>Première connexion.</strong> Créez le compte administrateur de l'application.</div>
      <form method="post" autocomplete="off"><?= csrf_field() ?>
        <div class="mb-2"><label class="form-label small" for="nom">Nom</label><input id="nom" name="nom" class="form-control" required value="<?= e($_POST['nom'] ?? '') ?>" autofocus></div>
        <div class="mb-2"><label class="form-label small" for="email">Adresse e-mail (identifiant)</label><input id="email" type="email" name="email" class="form-control" required value="<?= e($email) ?>"></div>
        <div class="mb-2"><label class="form-label small" for="mdp">Mot de passe</label><input id="mdp" type="password" name="mot_de_passe" class="form-control" required minlength="<?= MDP_LONGUEUR_MIN ?>" autocomplete="new-password">
          <div class="form-text"><?= MDP_LONGUEUR_MIN ?> caractères minimum, avec au moins une lettre et un chiffre.</div></div>
        <div class="mb-3"><label class="form-label small" for="conf">Confirmation</label><input id="conf" type="password" name="confirmation" class="form-control" required autocomplete="new-password"></div>
        <button class="btn btn-primary w-100"><i class="bi bi-person-check"></i> Créer le compte administrateur</button>
      </form>
    <?php else: ?>
      <form method="post"><?= csrf_field() ?>
        <div class="mb-2"><label class="form-label small" for="email">Adresse e-mail</label>
          <input id="email" type="email" name="email" class="form-control" required value="<?= e($email) ?>" autocomplete="username" <?= $email ? '' : 'autofocus' ?>></div>
        <div class="mb-3"><label class="form-label small" for="mdp">Mot de passe</label>
          <div class="input-group"><input id="mdp" type="password" name="mot_de_passe" class="form-control" required autocomplete="current-password" <?= $email ? 'autofocus' : '' ?>>
            <button type="button" class="btn btn-outline-secondary" id="voir" title="Afficher le mot de passe"><i class="bi bi-eye"></i></button></div></div>
        <button class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-right"></i> Se connecter</button>
      </form>
      <p class="small text-muted text-center mt-3 mb-0">Mot de passe oublié ? Demandez à l'administrateur de le réinitialiser.</p>
    <?php endif; ?>
  </div>
</main>
<script>
const v = document.getElementById('voir');
if (v) v.onclick = () => { const m = document.getElementById('mdp'); m.type = m.type === 'password' ? 'text' : 'password';
  v.firstElementChild.className = m.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash'; };
</script>
</body>
</html>
