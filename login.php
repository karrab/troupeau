<?php
// Page de connexion personnalisée
define('PAGE_PUBLIQUE', true);
require 'includes/functions.php';

$retour = safe_retour($_GET['retour'] ?? $_POST['retour'] ?? '');
if (user()) redirect($retour);

$erreur = null;
$email  = $_COOKIE['gt_email'] ?? '';
$bloqueJusqua = (int)($_SESSION['login_bloque_jusqua'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $email = trim((string)($_POST['email'] ?? ''));
    $mdp   = (string)($_POST['mot_de_passe'] ?? '');

    if ($bloqueJusqua > time()) {
        $erreur = 'Trop de tentatives. Réessayez dans ' . ceil(($bloqueJusqua - time()) / 60) . ' minute(s).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || $mdp === '') {
        $erreur = 'Veuillez saisir une adresse e-mail valide et votre mot de passe.';
    } else {
        $st = $pdo->prepare('SELECT * FROM utilisateurs WHERE email = ?');
        $st->execute([$email]);
        $u = $st->fetch();

        // Vérification à temps constant, même si l'utilisateur n'existe pas
        $hash = $u['mot_de_passe'] ?? '$2y$10$Dj.eQt0g4I7LzpQ3Pc2BIucP0t0yKUm.9W9YyBSux3C1LhJpEVzWW';
        if (password_verify($mdp, $hash) && $u) {
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                $pdo->prepare('UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?')
                    ->execute([password_hash($mdp, PASSWORD_DEFAULT), $u['id']]);
            }
            login_user($u);

            if (!empty($_POST['souvenir'])) {
                setcookie('gt_email', $email, ['expires' => time() + 30 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
            } else {
                setcookie('gt_email', '', ['expires' => time() - 3600, 'path' => '/']);
            }
            flash('Bienvenue, ' . $u['nom'] . ' !');
            redirect($retour);
        }

        $_SESSION['login_essais'] = ($_SESSION['login_essais'] ?? 0) + 1;
        if ($_SESSION['login_essais'] >= LOGIN_MAX_ESSAIS) {
            $_SESSION['login_bloque_jusqua'] = time() + LOGIN_BLOCAGE;
            $_SESSION['login_essais'] = 0;
            $erreur = 'Trop de tentatives. Compte temporairement bloqué pendant ' . (LOGIN_BLOCAGE / 60) . ' minutes.';
        } else {
            $reste  = LOGIN_MAX_ESSAIS - $_SESSION['login_essais'];
            $erreur = "Identifiants incorrects. Il vous reste $reste tentative" . ($reste > 1 ? 's' : '') . '.';
        }
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Connexion · <?= APP_NAME ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="assets/style.css" rel="stylesheet">
  <link href="assets/login.css" rel="stylesheet">
</head>
<body class="login-page">
<div class="container-fluid p-0">
  <div class="row g-0 min-vh-100">

    <!-- Panneau de présentation -->
    <div class="col-lg-6 d-none d-lg-flex login-hero text-white">
      <div class="login-hero-inner d-flex flex-column justify-content-between p-5 w-100">
        <a href="login.php" class="text-white text-decoration-none fs-4 fw-semibold">
          <i class="bi bi-flower3"></i> <?= APP_NAME ?>
        </a>

        <div>
          <h1 class="display-5 fw-bold mb-3">Suivez votre troupeau,<br>simplement.</h1>
          <p class="lead mb-4" style="color:rgba(255,255,255,.8)">Ovins et caprins : identité, pesées, contrôle laitier, reproduction et santé réunis au même endroit.</p>
          <ul class="list-unstyled login-features">
            <li><i class="bi bi-graph-up-arrow"></i> GMQ et courbes de croissance automatiques</li>
            <li><i class="bi bi-droplet-half"></i> Production laitière, TB/TP et alertes cellules</li>
            <li><i class="bi bi-calendar-heart"></i> Mises bas prévues et suivi de la reproduction</li>
            <li><i class="bi bi-shield-plus"></i> Traitements, rappels et délais d'attente</li>
          </ul>
        </div>

        <small class="text-white-50">&copy; <?= date('Y') ?> <?= APP_NAME ?> · Système d'information de suivi des troupeaux</small>
      </div>
    </div>

    <!-- Formulaire -->
    <div class="col-lg-6 d-flex align-items-center justify-content-center py-5 px-3">
      <div class="login-card w-100">
        <div class="text-center mb-4">
          <div class="login-logo mx-auto mb-3"><i class="bi bi-flower3"></i></div>
          <h2 class="h3 fw-bold mb-1">Connexion</h2>
          <p class="text-muted mb-0">Accédez à votre espace <?= APP_NAME ?></p>
        </div>

        <?= show_flash() ?>
        <?php if ($erreur): ?>
          <div class="alert alert-danger d-flex align-items-center gap-2" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i><div><?= e($erreur) ?></div>
          </div>
        <?php endif; ?>

        <form method="post" action="login.php" class="needs-validation" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="retour" value="<?= e($retour) ?>">

          <div class="form-floating mb-3">
            <input type="email" class="form-control" id="email" name="email" placeholder="nom@exemple.com"
                   value="<?= e($email) ?>" required autocomplete="username" <?= $email ? '' : 'autofocus' ?>>
            <label for="email"><i class="bi bi-envelope me-1"></i> Adresse e-mail</label>
            <div class="invalid-feedback">Saisissez une adresse e-mail valide.</div>
          </div>

          <div class="input-group mb-3 has-validation">
            <div class="form-floating">
              <input type="password" class="form-control" id="mot_de_passe" name="mot_de_passe" placeholder="Mot de passe"
                     required autocomplete="current-password" <?= $email ? 'autofocus' : '' ?>>
              <label for="mot_de_passe"><i class="bi bi-lock me-1"></i> Mot de passe</label>
            </div>
            <button class="btn btn-outline-secondary px-3" type="button" id="togglePwd" aria-label="Afficher le mot de passe">
              <i class="bi bi-eye"></i>
            </button>
            <div class="invalid-feedback">Le mot de passe est obligatoire.</div>
          </div>
          <div id="capsWarn" class="form-text text-warning d-none mb-3"><i class="bi bi-capslock-fill"></i> Verrouillage majuscules activé</div>

          <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="souvenir" name="souvenir" value="1" <?= $email ? 'checked' : '' ?>>
              <label class="form-check-label" for="souvenir">Se souvenir de moi</label>
            </div>
            <a href="#" class="small text-decoration-none" data-bs-toggle="tooltip"
               data-bs-title="Contactez l'administrateur pour réinitialiser votre mot de passe.">Mot de passe oublié ?</a>
          </div>

          <button type="submit" class="btn btn-primary btn-lg w-100" id="btnLogin">
            <span class="spinner-border spinner-border-sm me-2 d-none" aria-hidden="true"></span>
            <i class="bi bi-box-arrow-in-right me-1"></i> Se connecter
          </button>
        </form>

        <p class="text-center text-muted small mt-4 mb-0 d-lg-none">&copy; <?= date('Y') ?> <?= APP_NAME ?></p>
      </div>
    </div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
  // Afficher / masquer le mot de passe
  const pwd = document.getElementById('mot_de_passe');
  const btn = document.getElementById('togglePwd');
  btn.addEventListener('click', () => {
    const visible = pwd.type === 'text';
    pwd.type = visible ? 'password' : 'text';
    btn.innerHTML = visible ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
    btn.setAttribute('aria-label', visible ? 'Afficher le mot de passe' : 'Masquer le mot de passe');
    pwd.focus();
  });

  // Avertissement verrouillage majuscules
  const caps = document.getElementById('capsWarn');
  pwd.addEventListener('keyup', ev => caps.classList.toggle('d-none', !ev.getModifierState('CapsLock')));

  // Validation Bootstrap + indicateur de chargement
  const form = document.querySelector('.needs-validation');
  form.addEventListener('submit', ev => {
    if (!form.checkValidity()) { ev.preventDefault(); ev.stopPropagation(); }
    else {
      const b = document.getElementById('btnLogin');
      b.disabled = true;
      b.querySelector('.spinner-border').classList.remove('d-none');
    }
    form.classList.add('was-validated');
  });

  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
})();
</script>
</body>
</html>
