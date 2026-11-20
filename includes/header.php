<?php $page = basename($_SERVER['PHP_SELF'], '.php'); ?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($titre ?? APP_NAME) ?> · <?= APP_NAME ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="assets/style.css" rel="stylesheet">
</head>
<body>
<?php $moi = utilisateur(); ?>
<nav class="navbar navbar-expand-lg navbar-dark border-bottom no-print" style="background:#1f5130!important">
  <div class="container-fluid">
    <a class="navbar-brand fw-semibold" href="<?= peut('tableau_bord.voir') ? 'index.php' : 'mon_compte.php' ?>"><i class="bi bi-flower3"></i> <?= APP_NAME ?></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto">
        <?php foreach ([
            ['tableau_bord.voir', ['index'], 'index.php', 'speedometer2', 'Tableau de bord'],
            ['animaux.voir', ['animaux', 'animal', 'animal_form'], 'animaux.php', 'list-ul', 'Animaux'],
            ['lait.voir', ['lait'], 'lait.php', 'droplet', 'Production laitière'],
            ['pesees.voir', ['pesees'], 'pesees.php', 'speedometer', 'Pesées'],
            ['mensurations.voir', ['mensurations'], 'mensurations.php', 'rulers', 'Mensurations'],
            ['indices.voir', ['indices', 'indice_form'], 'indices.php', 'calculator', 'Indices'],
          ] as [$perm, $pages, $href, $ico, $lib]) if (peut($perm)): ?>
          <li class="nav-item"><a class="nav-link <?= in_array($page, $pages, true) ? 'active' : '' ?>" href="<?= $href ?>"><i class="bi bi-<?= $ico ?>"></i> <?= $lib ?></a></li>
        <?php endif; ?>
      </ul>
      <?php if (peut('animaux.creer')): ?><a href="animal_form.php" class="btn btn-light btn-sm me-2"><i class="bi bi-plus-lg"></i> Nouvel animal</a><?php endif; ?>
      <?php if ($moi): ?>
      <div class="dropdown">
        <button class="btn btn-sm btn-outline-light dropdown-toggle d-flex align-items-center gap-2" data-bs-toggle="dropdown" aria-expanded="false">
          <?= avatar($moi, 24) ?> <span><?= e($moi['nom']) ?></span></button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><div class="dropdown-item-text small"><?= badge_profil($moi) ?><div class="text-muted"><?= e($moi['email']) ?></div></div></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item <?= $page === 'mon_compte' ? 'active' : '' ?>" href="mon_compte.php"><i class="bi bi-person"></i> Mon profil</a></li>
          <?php if (peut_un('parametres.gerer', 'indices.configurer') || peut_un('utilisateurs.gerer', 'profils.gerer')): ?><li><h6 class="dropdown-header">Administration</h6></li><?php endif; ?>
          <?php if (peut_un('parametres.gerer', 'indices.configurer')): ?><li><a class="dropdown-item <?= $page === 'parametres' ? 'active' : '' ?>" href="parametres.php"><i class="bi bi-gear"></i> Paramètres</a></li><?php endif; ?>
          <?php if (peut_un('utilisateurs.gerer', 'profils.gerer')): ?><li><a class="dropdown-item <?= in_array($page, ['utilisateurs', 'utilisateur'], true) ? 'active' : '' ?>" href="utilisateurs.php"><i class="bi bi-people"></i> Utilisateurs</a></li><?php endif; ?>
          <?php if (peut('profils.gerer')): ?><li><a class="dropdown-item <?= $page === 'profils' ? 'active' : '' ?>" href="profils.php"><i class="bi bi-shield-lock"></i> Profils et permissions</a></li><?php endif; ?>
          <li><hr class="dropdown-divider"></li>
          <li><form method="post" action="logout.php"><?= csrf_field() ?><button class="dropdown-item text-danger"><i class="bi bi-box-arrow-right"></i> Se déconnecter</button></form></li>
        </ul>
      </div>
      <?php endif; ?>
    </div>
  </div>
</nav>
<main class="container-fluid py-4 px-lg-4">
<?= show_flash() ?>
