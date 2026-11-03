<?php
require 'includes/functions.php';
$titre = 'Paramètres';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        switch ($_POST['action'] ?? '') {
            case 'espece':
                $pdo->prepare('INSERT INTO especes (nom, gestation_jours) VALUES (?,?)')->execute([post('nom'), (int)post('gestation_jours') ?: 150]); break;
            case 'gestation':
                $pdo->prepare('UPDATE especes SET gestation_jours=? WHERE id=?')->execute([(int)post('gestation_jours'), (int)post('id')]); break;
            case 'race':
                $pdo->prepare('INSERT INTO races (espece_id, nom) VALUES (?,?)')->execute([(int)post('espece_id'), post('nom')]); break;
            case 'lot':
                $pdo->prepare('INSERT INTO lots (nom, type, description) VALUES (?,?,?)')->execute([post('nom'), post('type'), post('description')]); break;
            case 'del_race':
                $pdo->prepare('DELETE FROM races WHERE id=?')->execute([(int)post('id')]); break;
            case 'del_lot':
                $pdo->prepare('DELETE FROM lots WHERE id=?')->execute([(int)post('id')]); break;
        }
        flash('Paramètres mis à jour.');
    } catch (PDOException $e) {
        flash($e->getCode() == 23000 ? 'Cet élément existe déjà ou est utilisé par des animaux.' : $e->getMessage(), 'danger');
    }
    redirect('parametres.php');
}

$lots = $pdo->query("SELECT l.*, COUNT(a.id) nb FROM lots l LEFT JOIN animaux a ON a.lot_id=l.id AND a.statut='actif' GROUP BY l.id ORDER BY l.nom")->fetchAll();
$delBtn = fn($action, $id) => '<form method="post" class="d-inline" onsubmit="return confirm(\'Supprimer ?\')">' . csrf_field()
  . '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="id" value="' . $id . '"><button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-x-circle"></i></button></form>';
require 'includes/header.php';
?>
<h1 class="h3 mb-4">Paramètres</h1>
<div class="row g-3">
  <div class="col-lg-4"><div class="card p-3 h-100">
    <h2 class="h6">Espèces et durée de gestation</h2>
    <?php foreach (especes($pdo) as $x): ?>
      <form method="post" class="d-flex gap-2 align-items-center mb-2"><?= csrf_field() ?><input type="hidden" name="action" value="gestation"><input type="hidden" name="id" value="<?= $x['id'] ?>">
        <span class="flex-grow-1"><?= e($x['nom']) ?></span>
        <input type="number" name="gestation_jours" value="<?= $x['gestation_jours'] ?>" class="form-control form-control-sm" style="width:80px"> <small>j</small>
        <button class="btn btn-sm btn-outline-primary"><i class="bi bi-check"></i></button></form>
    <?php endforeach; ?>
    <form method="post" class="d-flex gap-2 mt-3 border-top pt-3"><?= csrf_field() ?><input type="hidden" name="action" value="espece">
      <input name="nom" class="form-control form-control-sm" placeholder="Nouvelle espèce" required>
      <input type="number" name="gestation_jours" class="form-control form-control-sm" style="width:80px" placeholder="j">
      <button class="btn btn-sm btn-primary"><i class="bi bi-plus"></i></button></form>
  </div></div>

  <div class="col-lg-4"><div class="card p-3 h-100">
    <h2 class="h6">Races</h2>
    <ul class="list-group list-group-flush small mb-3"><?php foreach (races($pdo) as $x): ?>
      <li class="list-group-item d-flex justify-content-between px-0"><span><?= e($x['nom']) ?> <span class="text-muted">· <?= e($x['espece']) ?></span></span><?= $delBtn('del_race', $x['id']) ?></li>
    <?php endforeach; ?></ul>
    <form method="post" class="d-flex gap-2 mt-auto"><?= csrf_field() ?><input type="hidden" name="action" value="race">
      <select name="espece_id" class="form-select form-select-sm"><?php foreach (especes($pdo) as $x): ?><option value="<?= $x['id'] ?>"><?= e($x['nom']) ?></option><?php endforeach; ?></select>
      <input name="nom" class="form-control form-control-sm" placeholder="Race" required>
      <button class="btn btn-sm btn-primary"><i class="bi bi-plus"></i></button></form>
  </div></div>

  <div class="col-lg-4"><div class="card p-3 h-100">
    <h2 class="h6">Lots, bâtiments et pâturages</h2>
    <ul class="list-group list-group-flush small mb-3"><?php foreach ($lots as $x): ?>
      <li class="list-group-item d-flex justify-content-between px-0"><span><a href="animaux.php?lot=<?= $x['id'] ?>"><?= e($x['nom']) ?></a> <span class="badge text-bg-light"><?= $x['nb'] ?></span><br><span class="text-muted"><?= e($x['type']) ?> · <?= e($x['description']) ?></span></span><?= $delBtn('del_lot', $x['id']) ?></li>
    <?php endforeach; ?></ul>
    <form method="post" class="row g-2 mt-auto"><?= csrf_field() ?><input type="hidden" name="action" value="lot">
      <div class="col-6"><input name="nom" class="form-control form-control-sm" placeholder="Nom" required></div>
      <div class="col-6"><select name="type" class="form-select form-select-sm"><option value="batiment">Bâtiment</option><option value="paturage">Pâturage</option><option value="lot">Lot</option></select></div>
      <div class="col-9"><input name="description" class="form-control form-control-sm" placeholder="Description"></div>
      <div class="col-3 d-grid"><button class="btn btn-sm btn-primary"><i class="bi bi-plus"></i></button></div></form>
  </div></div>
</div>
<?php require 'includes/footer.php';
