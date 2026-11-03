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
    </div>
  </div>
</nav>
<main class="container-fluid py-4 px-lg-4">
<?= show_flash() ?>
