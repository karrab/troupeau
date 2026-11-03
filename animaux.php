<?php
require 'includes/functions.php';
$titre = 'Animaux';

$f = [
    'q'       => trim($_GET['q'] ?? ''),
    'espece'  => (int)($_GET['espece'] ?? 0),
    'lot'     => (int)($_GET['lot'] ?? 0),
    'sexe'    => $_GET['sexe'] ?? '',
    'statut'  => $_GET['statut'] ?? 'actif',
];
$where = []; $params = [];
if ($f['q'] !== '')  { $where[] = '(a.numero_identification LIKE ? OR a.nom LIKE ?)'; $params[] = "%{$f['q']}%"; $params[] = "%{$f['q']}%"; }
if ($f['espece'])    { $where[] = 'a.espece_id = ?'; $params[] = $f['espece']; }
if ($f['lot'])       { $where[] = 'a.lot_id = ?';    $params[] = $f['lot']; }
if (in_array($f['sexe'], ['M','F'], true)) { $where[] = 'a.sexe = ?'; $params[] = $f['sexe']; }
if (isset(STATUTS[$f['statut']])) { $where[] = 'a.statut = ?'; $params[] = $f['statut']; }

$sql = "SELECT a.*, e.nom espece, r.nom race, l.nom lot,
   (SELECT poids FROM pesees p WHERE p.animal_id=a.id ORDER BY date_pesee DESC LIMIT 1) dernier_poids,
   (SELECT quantite_totale FROM controles_laitiers c WHERE c.animal_id=a.id ORDER BY date_controle DESC LIMIT 1) dernier_lait
  FROM animaux a JOIN especes e ON e.id=a.espece_id
  LEFT JOIN races r ON r.id=a.race_id LEFT JOIN lots l ON l.id=a.lot_id"
  . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY a.numero_identification';
$st = $pdo->prepare($sql); $st->execute($params);
$animaux = $st->fetchAll();

require 'includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h3 mb-0">Animaux <span class="badge text-bg-light"><?= count($animaux) ?></span></h1>
  <a href="animal_form.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Ajouter</a>
</div>

<form class="card p-3 mb-3">
  <div class="row g-2">
    <div class="col-md-3"><input name="q" class="form-control" placeholder="N° ou nom…" value="<?= e($f['q']) ?>"></div>
    <div class="col-6 col-md-2"><select name="espece" class="form-select"><option value="">Toutes espèces</option>
      <?php foreach (especes($pdo) as $x): ?><option value="<?= $x['id'] ?>" <?= $f['espece']==$x['id']?'selected':'' ?>><?= e($x['nom']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><select name="lot" class="form-select"><option value="">Tous lots</option>
      <?php foreach (lots($pdo) as $x): ?><option value="<?= $x['id'] ?>" <?= $f['lot']==$x['id']?'selected':'' ?>><?= e($x['nom']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><select name="sexe" class="form-select"><option value="">Mâles et femelles</option>
      <option value="F" <?= $f['sexe']==='F'?'selected':'' ?>>Femelles</option><option value="M" <?= $f['sexe']==='M'?'selected':'' ?>>Mâles</option></select></div>
    <div class="col-6 col-md-2"><select name="statut" class="form-select"><option value="tous">Tous statuts</option>
      <?php foreach (STATUTS as $k => [$l]): ?><option value="<?= $k ?>" <?= $f['statut']===$k?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="col-md-1 d-grid"><button class="btn btn-outline-primary"><i class="bi bi-search"></i></button></div>
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead class="table-light"><tr><th>N° identification</th><th>Nom</th><th>Espèce / race</th><th>Sexe</th><th>Catégorie</th><th>Âge</th><th>Lot</th><th class="text-end">Dernier poids</th><th class="text-end">Dernier lait</th><th>Statut</th></tr></thead>
      <tbody>
      <?php foreach ($animaux as $a): ?>
        <tr onclick="location='animal.php?id=<?= $a['id'] ?>'" style="cursor:pointer">
          <td class="fw-semibold"><?= e($a['numero_identification']) ?></td>
          <td><?= e($a['nom']) ?></td>
          <td><?= e($a['espece']) ?> <span class="text-muted">· <?= e($a['race'] ?? '—') ?></span></td>
          <td><?= $a['sexe']==='F' ? '<i class="bi bi-gender-female text-danger"></i>' : '<i class="bi bi-gender-male text-primary"></i>' ?></td>
          <td><?= e($a['categorie']) ?></td>
          <td><?= age($a['date_naissance']) ?></td>
          <td><?= e($a['lot'] ?? '—') ?></td>
          <td class="text-end"><?= $a['dernier_poids'] ? nfr($a['dernier_poids'], 0) . ' kg' : '—' ?></td>
          <td class="text-end"><?= $a['dernier_lait'] ? nfr($a['dernier_lait']) . ' L' : '—' ?></td>
          <td><?= badge_statut($a['statut']) ?></td>
        </tr>
      <?php endforeach; if (!$animaux): ?><tr><td colspan="10" class="text-center text-muted py-4">Aucun animal trouvé.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require 'includes/footer.php';
