<?php
require 'includes/functions.php';
exiger_role('admin');
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
            case 'del_indice':
                $st = $pdo->prepare('SELECT code FROM indices WHERE id=?'); $st->execute([(int)post('id')]);
                $code = (string)$st->fetchColumn();
                $st = $pdo->prepare('SELECT code, formule FROM indices WHERE id<>?'); $st->execute([(int)post('id')]);
                $dep = array_keys(array_filter($st->fetchAll(PDO::FETCH_KEY_PAIR), fn($f) => preg_match('/\b' . preg_quote($code, '/') . '\b/i', $f)));
                if ($dep) throw new RuntimeException("L'indice $code est utilisé par " . implode(', ', $dep) . ' : modifiez ces formules avant de le supprimer.');
                $pdo->prepare('DELETE FROM indices WHERE id=?')->execute([(int)post('id')]);
                break;
            case 'actif_indice':
                $pdo->prepare('UPDATE indices SET actif=1-actif, updated_at=NOW() WHERE id=?')->execute([(int)post('id')]);
                recalculer_indices($pdo);
                break;
            case 'campagne':
                $m = max(1, min(12, (int)post('debut_campagne_mois')));
                $pdo->prepare("INSERT INTO parametres_app (cle, valeur, libelle) VALUES ('debut_campagne_mois', ?, 'Mois de début de la campagne') ON DUPLICATE KEY UPDATE valeur=VALUES(valeur)")->execute([$m]);
                redirect('parametres.php?recalcul=1#indices');
        }
        flash('Paramètres mis à jour.');
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'danger');
    } catch (PDOException $e) {
        flash($e->getCode() == 23000 ? 'Cet élément existe déjà ou est utilisé par des animaux.' : $e->getMessage(), 'danger');
    }
    redirect('parametres.php');
}

$lots = $pdo->query("SELECT l.*, COUNT(a.id) nb FROM lots l LEFT JOIN animaux a ON a.lot_id=l.id AND a.statut='actif' GROUP BY l.id ORDER BY l.nom")->fetchAll();
if (isset($_GET['recalcul'])) {
    try { $n = recalculer_indices($pdo); flash("Campagne : début en " . MOIS_FR[mois_campagne()] . ". $n résultats recalculés."); }
    catch (Throwable $e) { flash('Recalcul impossible : ' . $e->getMessage(), 'danger'); }
    redirect('parametres.php#indices');
}
$listeIndices = $pdo->query('SELECT i.*, e.nom espece, (SELECT COUNT(*) FROM indices_resultats r WHERE r.indice_id=i.id) nb_res,
    (SELECT MAX(calcule_le) FROM indices_resultats r WHERE r.indice_id=i.id) calcule_le FROM indices i LEFT JOIN especes e ON e.id=i.espece_id ORDER BY i.ordre, i.code')->fetchAll();
$reglesIndices = interpretations_indices($pdo);
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
<div class="card mt-3" id="indices">
  <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
    <span class="fw-semibold"><i class="bi bi-calculator"></i> Indices de productivité : formules et interprétation</span>
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <form method="post" class="d-flex gap-1 align-items-center"><?= csrf_field() ?><input type="hidden" name="action" value="campagne">
        <label class="small text-muted text-nowrap">Début de campagne</label>
        <select name="debut_campagne_mois" class="form-select form-select-sm w-auto"><?php foreach (MOIS_FR as $k => $m): ?><option value="<?= $k ?>" <?= $k === mois_campagne() ? 'selected' : '' ?>><?= $m ?></option><?php endforeach; ?></select>
        <button class="btn btn-sm btn-outline-primary" title="Enregistrer et recalculer"><i class="bi bi-check"></i></button></form>
      <a href="indices.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-table"></i> Résultats</a>
      <a href="indice_form.php" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Nouvel indice</a>
    </div>
  </div>
  <div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle small">
    <thead><tr><th>Code</th><th>Indice</th><th>Formule</th><th>Unité</th><th>Période</th><th>Interprétation</th><th class="text-end">Résultats</th><th></th></tr></thead>
    <tbody><?php $fam = null; foreach ($listeIndices as $x): if ($x['famille'] !== $fam): $fam = $x['famille']; ?>
      <tr class="table-light"><td colspan="8" class="fw-semibold"><i class="bi bi-<?= FAMILLES_INDICES[$fam][2] ?>"></i> <?= FAMILLES_INDICES[$fam][1] ?> · <?= e(FAMILLES_INDICES[$fam][0]) ?>
        <a href="indice_form.php?famille=<?= $fam ?>" class="ms-2 small fw-normal no-print"><i class="bi bi-plus"></i> ajouter</a></td></tr><?php endif; ?>
      <tr class="<?= $x['actif'] ? '' : 'text-muted' ?>">
        <td class="font-monospace fw-semibold"><?= e($x['code']) ?></td>
        <td><a href="indice_form.php?id=<?= $x['id'] ?>" class="fw-semibold"><?= e($x['nom']) ?></a><br><span class="text-muted"><?= e($x['description']) ?></span></td>
        <td><code><?= e($x['formule']) ?></code></td>
        <td><?= e($x['unite']) ?></td>
        <td><?= PERIODES_INDICES[$x['periode']] ?><br><span class="text-muted"><?= e(implode(', ', array_map(fn($n) => NIVEAUX_INDICES[$n] ?? $n, explode(',', $x['niveaux'])))) ?>
          <?= $x['espece'] ? ' · ' . e($x['espece']) : '' ?><?= $x['sexe'] ? ' · ' . ($x['sexe'] === 'F' ? 'femelles' : 'mâles') : '' ?></span></td>
        <td><?= e($x['interpretation']) ?><br><?php foreach ($reglesIndices[$x['id']] ?? [] as $r): if ($r['espece_id']) continue; ?><span class="badge text-bg-<?= e($r['couleur']) ?> me-1" title="<?= e(texte_regle($r)) ?>"><?= e($r['libelle']) ?></span><?php endforeach; ?>
          <?php $nbEsp = count(array_unique(array_filter(array_column($reglesIndices[$x['id']] ?? [], 'espece_id')))); if ($nbEsp): ?><span class="text-muted">+ seuils pour <?= $nbEsp ?> espèce<?= $nbEsp > 1 ? 's' : '' ?></span><?php endif; ?></td>
        <td class="text-end"><?= $x['nb_res'] ?><?= $x['calcule_le'] && $x['updated_at'] > $x['calcule_le'] ? ' <i class="bi bi-exclamation-triangle-fill text-warning" title="Formule modifiée depuis le dernier calcul"></i>' : '' ?></td>
        <td class="text-end text-nowrap no-print">
          <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="actif_indice"><input type="hidden" name="id" value="<?= $x['id'] ?>">
            <button class="btn btn-sm btn-link p-0 me-2" title="<?= $x['actif'] ? 'Désactiver' : 'Activer' ?>"><i class="bi bi-toggle-<?= $x['actif'] ? 'on text-success' : 'off text-muted' ?> fs-5"></i></button></form>
          <a href="indice_form.php?id=<?= $x['id'] ?>" class="me-2" title="Modifier la formule"><i class="bi bi-pencil"></i></a><?= $delBtn('del_indice', $x['id']) ?></td>
      </tr>
    <?php endforeach; if (!$listeIndices): ?><tr><td colspan="8" class="text-center text-muted">Aucun indice défini.</td></tr><?php endif; ?></tbody>
  </table></div>
</div>
<?php require 'includes/footer.php';
