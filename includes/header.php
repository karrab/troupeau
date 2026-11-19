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
<body class="role-<?= e(utilisateur()['role'] ?? 'aucun') ?>">
<nav class="navbar navbar-expand-lg navbar-dark bg-success-subtle border-bottom no-print" style="background:#1f5130!important">
  <div class="container-fluid">
    <a class="navbar-brand fw-semibold" href="index.php"><i class="bi bi-flower3"></i> <?= APP_NAME ?></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link <?= $page==='index'?'active':'' ?>" href="index.php"><i class="bi bi-speedometer2"></i> Tableau de bord</a></li>
        <li class="nav-item"><a class="nav-link <?= in_array($page,['animaux','animal','animal_form'])?'active':'' ?>" href="animaux.php"><i class="bi bi-list-ul"></i> Animaux</a></li>
        <li class="nav-item"><a class="nav-link <?= $page==='lait'?'active':'' ?>" href="lait.php"><i class="bi bi-droplet"></i> Production laitière</a></li>
        <li class="nav-item"><a class="nav-link <?= $page==='pesees'?'active':'' ?>" href="pesees.php"><i class="bi bi-speedometer"></i> Pesées</a></li>
        <li class="nav-item"><a class="nav-link <?= $page==='mensurations'?'active':'' ?>" href="mensurations.php"><i class="bi bi-rulers"></i> Mensurations</a></li>
        <li class="nav-item"><a class="nav-link <?= in_array($page,['indices','indice_form'])?'active':'' ?>" href="indices.php"><i class="bi bi-calculator"></i> Indices</a></li>
        <?php if (a_role('admin')): ?>
        <li class="nav-item"><a class="nav-link <?= $page==='parametres'?'active':'' ?>" href="parametres.php"><i class="bi bi-gear"></i> Paramètres</a></li>
        <?php endif; ?>
      </ul>
      <?php if (peut_saisir()): ?><a href="animal_form.php" class="btn btn-light btn-sm me-2"><i class="bi bi-plus-lg"></i> Nouvel animal</a><?php endif; ?>
      <?php if ($moi = utilisateur()): ?>
      <div class="dropdown">
        <button class="btn btn-sm btn-outline-light dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
          <i class="bi bi-person-circle"></i> <?= e($moi['nom']) ?> <span class="badge text-bg-light ms-1"><?= ROLES[$moi['role']][0] ?></span></button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="mon_compte.php"><i class="bi bi-key"></i> Mon compte</a></li>
          <?php if (a_role('admin')): ?><li><a class="dropdown-item" href="utilisateurs.php"><i class="bi bi-people"></i> Utilisateurs</a></li><?php endif; ?>
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
