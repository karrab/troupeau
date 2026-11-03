<?php
require 'includes/functions.php';
$titre = 'Pesées';

// Saisie groupée (séance de pesée d'un lot)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $date = post('date_pesee') ?? date('Y-m-d');
    $ins = $pdo->prepare('INSERT INTO pesees (animal_id,date_pesee,poids,note_etat_corporel,methode) VALUES (?,?,?,?,?)');
    $n = 0;
    foreach ($_POST['poids'] ?? [] as $aid => $poids) {
        if ((float)$poids > 0) {
            $nec = trim($_POST['nec'][$aid] ?? '');
            $ins->execute([(int)$aid, $date, (float)$poids, $nec === '' ? null : $nec, post('methode') ?? 'balance']);
            $n++;
        }
    }
    flash("$n pesée(s) enregistrée(s).");
    redirect('pesees.php?lot=' . (int)($_POST['lot'] ?? 0));
}

$lot = (int)($_GET['lot'] ?? 0);
$sql = "SELECT a.id, a.numero_identification, a.nom, a.categorie, a.date_naissance, l.nom lot,
    p1.poids dernier, p1.date_pesee date_dernier, p2.poids avant, p2.date_pesee date_avant
  FROM animaux a LEFT JOIN lots l ON l.id=a.lot_id
  LEFT JOIN pesees p1 ON p1.id = (SELECT id FROM pesees WHERE animal_id=a.id ORDER BY date_pesee DESC LIMIT 1)
  LEFT JOIN pesees p2 ON p2.id = (SELECT id FROM pesees WHERE animal_id=a.id ORDER BY date_pesee DESC LIMIT 1 OFFSET 1)
  WHERE a.statut='actif'" . ($lot ? ' AND a.lot_id=' . $lot : '') . ' ORDER BY a.numero_identification';
$animaux = $pdo->query($sql)->fetchAll();

require 'includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h3 mb-0">Pesées et croissance</h1>
  <form><select name="lot" class="form-select" onchange="this.form.submit()"><option value="0">Tous les lots</option>
    <?php foreach (lots($pdo) as $x): ?><option value="<?= $x['id'] ?>" <?= $lot==$x['id']?'selected':'' ?>><?= e($x['nom']) ?></option><?php endforeach; ?></select></form>
</div>

<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="lot" value="<?= $lot ?>">
  <div class="card-header bg-white d-flex flex-wrap gap-2 align-items-center">
    <strong class="me-auto">Séance de pesée</strong>
    <input type="date" name="date_pesee" value="<?= date('Y-m-d') ?>" class="form-control form-control-sm w-auto">
    <select name="methode" class="form-select form-select-sm w-auto"><option value="balance">Balance</option><option value="ruban">Ruban</option><option value="estimation">Estimation</option></select>
    <button class="btn btn-primary btn-sm"><i class="bi bi-save"></i> Enregistrer la séance</button>
  </div>
  <div class="table-responsive"><table class="table table-sm table-hover mb-0">
    <thead><tr><th>Animal</th><th>Catégorie</th><th>Âge</th><th>Lot</th><th class="text-end">Avant-dernier</th><th class="text-end">Dernier</th><th class="text-end">GMQ récent</th><th style="width:120px">Nouveau poids</th><th style="width:90px">NEC</th></tr></thead>
    <tbody><?php foreach ($animaux as $a):
      $g = null;
      if ($a['dernier'] && $a['avant']) { $j = (strtotime($a['date_dernier']) - strtotime($a['date_avant'])) / 86400; if ($j > 0) $g = round(($a['dernier'] - $a['avant']) / $j * 1000); } ?>
      <tr><td><a href="animal.php?id=<?= $a['id'] ?>&tab=poids"><?= e($a['numero_identification'].' '.$a['nom']) ?></a></td>
        <td><?= e($a['categorie']) ?></td><td><?= age($a['date_naissance']) ?></td><td><?= e($a['lot']) ?></td>
        <td class="text-end text-muted"><?= $a['avant'] ? nfr($a['avant'],0).' kg' : '—' ?></td>
        <td class="text-end"><?= $a['dernier'] ? nfr($a['dernier'],0).' kg <small class="text-muted">'.dfr($a['date_dernier']).'</small>' : '—' ?></td>
        <td class="text-end"><?= $g === null ? '—' : '<span class="'.($g<0?'text-danger':'text-success').'">'.$g.' g/j</span>' ?></td>
        <td><input type="number" step="0.1" min="0" name="poids[<?= $a['id'] ?>]" class="form-control form-control-sm"></td>
        <td><input type="number" step="0.25" min="1" max="5" name="nec[<?= $a['id'] ?>]" class="form-control form-control-sm"></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</form>
<?php require 'includes/footer.php';
