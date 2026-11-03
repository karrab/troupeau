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
        <li class="nav-item"><a class="nav-link <?= $page==='parametres'?'active':'' ?>" href="parametres.php"><i class="bi bi-gear"></i> Paramètres</a></li>
      </ul>
      <a href="animal_form.php" class="btn btn-light btn-sm"><i class="bi bi-plus-lg"></i> Nouvel animal</a>
      <?php if ($u = user()): [$roleLib, $roleCol] = ROLES[$u['role']] ?? [$u['role'], 'secondary']; ?>
      <div class="dropdown ms-lg-3 mt-2 mt-lg-0">
        <a class="nav-link dropdown-toggle text-white d-flex align-items-center gap-2" href="#" data-bs-toggle="dropdown" aria-expanded="false">
          <span class="avatar-initiale"><?= e(mb_strtoupper(mb_substr($u['nom'], 0, 1))) ?></span>
          <span class="d-lg-none d-xl-inline"><?= e($u['nom']) ?></span>
        </a>
        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
          <li class="px-3 py-2">
            <div class="fw-semibold"><?= e($u['nom']) ?></div>
            <div class="small text-muted"><?= e($u['email']) ?></div>
            <span class="badge text-bg-<?= $roleCol ?> mt-1"><?= $roleLib ?></span>
          </li>
          <li><hr class="dropdown-divider"></li>
          <li>
            <form method="post" action="logout.php" class="m-0">
              <?= csrf_field() ?>
              <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right"></i> Déconnexion</button>
            </form>
          </li>
        </ul>
      </div>
      <?php endif; ?>
    </div>
  </div>
</nav>
<main class="container-fluid py-4 px-lg-4">
<?= show_flash() ?>
