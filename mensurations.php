<?php
require 'includes/functions.php';
$titre = 'Mensurations';
$libs = libelles_mesures($pdo); $unite = unite_mesures($pdo);

$lot = (int)($_GET['lot'] ?? 0);
$tous = !empty($_GET['tous']);          // par défaut : jeunes de moins de 12 mois
$stade = max(0, (int)($_GET['stade'] ?? -1));
$url = fn(array $p = []) => 'mensurations.php?' . http_build_query(array_filter(array_merge(['lot' => $lot ?: null, 'tous' => $tous ?: null, 'stade' => $stade], $p), fn($v) => $v !== null));

// ---------- Enregistrement d'une séance ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exiger_role('admin', 'eleveur');
    check_csrf();
    $date = post('date_mesure'); $st = (int)post('stade');
    $n = 0; $erreurs = [];
    foreach ((array)($_POST['m'] ?? []) as $aid => $vals) {
        $vals = array_map(fn($v) => ($v = trim(str_replace(',', '.', (string)$v))) === '' ? null : $v, (array)$vals);
        if (!array_filter($vals, fn($v) => $v !== null)) continue;
        try {
            if (array_filter($vals, fn($v) => $v !== null && !is_numeric($v))) throw new InvalidArgumentException('valeur non numérique');
            $pdo->beginTransaction();
            enregistrer_mensuration($pdo, ['animal_id' => (int)$aid, 'type' => 'croissance', 'stade' => $st, 'date_mesure' => $date,
                'poids' => $vals['poids'] ?? null] + array_combine(MESURES, array_map(fn($k) => $vals[$k] ?? null, MESURES)));
            $pdo->commit(); $n++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $q = $pdo->prepare('SELECT numero_identification FROM animaux WHERE id=?'); $q->execute([(int)$aid]);
            $erreurs[] = $q->fetchColumn() . ' : ' . ($e instanceof PDOException && $e->getCode() == 23000 ? 'stade W' . $st . ' déjà mesuré' : $e->getMessage());
        }
    }
    if ($n) maj_indices_auto($pdo, $date);
    flash("$n mensuration(s) W$st enregistrée(s)." . ($erreurs ? ' Non enregistrées : ' . implode(' ; ', $erreurs) : ''), $erreurs ? 'warning' : 'success');
    redirect($url(['stade' => $st + 1]));
}

// ---------- Animaux concernés ----------
$sql = "SELECT a.id, a.numero_identification, a.nom, a.categorie, a.date_naissance, a.poids_naissance, l.nom lot
    FROM animaux a LEFT JOIN lots l ON l.id=a.lot_id WHERE a.statut='actif'"
    . ($lot ? ' AND a.lot_id=' . $lot : '') . ($tous ? '' : ' AND a.date_naissance >= CURDATE() - INTERVAL 12 MONTH')
    . ' ORDER BY a.date_naissance DESC, a.numero_identification';
$animaux = $pdo->query($sql)->fetchAll();
$ids = array_column($animaux, 'id') ?: [0];
$mesures = [];
foreach ($pdo->query("SELECT * FROM mensurations WHERE type='croissance' AND animal_id IN (" . implode(',', array_map('intval', $ids)) . ') ORDER BY stade') as $m)
    $mesures[$m['animal_id']][(int)$m['stade']] = $m;
$maxStade = max([-1, ...array_map(fn($x) => max(array_keys($x)), $mesures ?: [[-1 => 0]])]);
// Stade proposé : le prochain stade manquant le plus fréquent dans la sélection
if (!isset($_GET['stade'])) {
    $suivants = array_map(fn($a) => min(array_diff(range(0, $maxStade + 1), array_keys($mesures[$a['id']] ?? []))), $animaux);
    $freq = array_count_values($suivants ?: [0]); arsort($freq);
    $stade = (int)array_key_first($freq);
}
require 'includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h3 mb-0">Mensurations des chevreaux</h1>
  <form class="d-flex gap-2 align-items-center">
    <input type="hidden" name="stade" value="<?= $stade ?>">
    <select name="lot" class="form-select form-select-sm w-auto" onchange="this.form.submit()"><option value="0">Tous les lots</option>
      <?php foreach (lots($pdo) as $x): ?><option value="<?= $x['id'] ?>" <?= $lot == $x['id'] ? 'selected' : '' ?>><?= e($x['nom']) ?></option><?php endforeach; ?></select>
    <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" name="tous" value="1" id="tous" <?= $tous ? 'checked' : '' ?> onchange="this.form.submit()">
      <label class="form-check-label small text-nowrap" for="tous">Tous les âges</label></div>
  </form>
</div>

<form method="post" class="card mb-3">
  <?= csrf_field() ?>
  <div class="card-header bg-white d-flex flex-wrap gap-2 align-items-center">
    <strong class="me-auto">Séance de mensuration</strong>
    <label class="small">Stade</label>
    <div class="input-group input-group-sm w-auto"><span class="input-group-text">W</span>
      <input type="number" name="stade" min="0" max="999" value="<?= $stade ?>" class="form-control" style="width:70px"
        onchange="location.href='<?= e($url(['stade' => null])) ?>&stade='+this.value"></div>
    <input type="date" name="date_mesure" value="<?= date('Y-m-d') ?>" class="form-control form-control-sm w-auto" required>
    <?php if (peut_saisir()): ?><button class="btn btn-primary btn-sm"><i class="bi bi-save"></i> Enregistrer W<?= $stade ?></button><?php endif; ?>
  </div>
  <div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
    <thead><tr><th>Animal</th><th>Âge</th><th>Lot</th><th class="text-center">Dernier stade</th><th style="width:95px">Poids (kg)</th>
      <?php foreach ($libs as $k => $l): ?><th style="width:85px" class="small" title="<?= e($l) ?>"><?= e(explode(' – ', $l)[0]) ?> <span class="text-muted fw-normal">(<?= e($unite) ?>)</span></th><?php endforeach; ?></tr></thead>
    <tbody><?php foreach ($animaux as $a): $deja = $mesures[$a['id']][$stade] ?? null; $hist = $mesures[$a['id']] ?? []; $der = $hist ? end($hist) : null; ?>
      <tr class="<?= $deja ? 'table-light text-muted' : '' ?>">
        <td><a href="animal.php?id=<?= $a['id'] ?>&tab=mens"><?= e($a['numero_identification'] . ' ' . $a['nom']) ?></a></td>
        <td class="small"><?= age($a['date_naissance']) ?></td><td class="small"><?= e($a['lot']) ?></td>
        <td class="text-center small"><?= $der ? '<span class="badge text-bg-success">' . libelle_stade((int)$der['stade']) . '</span> ' . ($der['poids'] !== null ? nfr($der['poids']) . ' kg' : '') : '—' ?></td>
        <?php if ($deja): ?>
          <td class="small"><?= $deja['poids'] !== null ? nfr($deja['poids']) : '—' ?></td>
          <?php foreach (MESURES as $k): ?><td class="small"><?= nfr($deja[$k]) ?></td><?php endforeach; ?>
        <?php else: ?>
          <td><input type="number" step="0.01" min="0" name="m[<?= $a['id'] ?>][poids]" class="form-control form-control-sm"
            value="<?= $stade === 0 && $a['poids_naissance'] ? e($a['poids_naissance']) : '' ?>"></td>
          <?php foreach (MESURES as $k): ?><td><input type="number" step="0.1" min="0" name="m[<?= $a['id'] ?>][<?= $k ?>]" class="form-control form-control-sm"></td><?php endforeach; ?>
        <?php endif; ?></tr>
    <?php endforeach; if (!$animaux): ?><tr><td colspan="11" class="text-center text-muted py-4">Aucun animal<?= $tous ? '' : ' de moins de 12 mois' ?> dans cette sélection.</td></tr><?php endif; ?></tbody>
  </table></div>
  <div class="card-footer bg-white small text-muted">Les lignes grisées sont déjà mesurées au stade W<?= $stade ?>. Le poids est aussi enregistré comme pesée.</div>
</form>

<?php if ($mesures): ?>
<div class="card"><div class="card-header bg-white fw-semibold">Suivi par stade : poids (kg) <span class="fw-normal text-muted small">– survoler pour voir L1 à L6</span></div>
  <div class="table-responsive"><table class="table table-sm mb-0 align-middle text-center">
    <thead><tr><th class="text-start">Animal</th><?php for ($s = 0; $s <= $maxStade; $s++): ?><th>W<?= $s ?></th><?php endfor; ?></tr></thead>
    <tbody><?php foreach ($animaux as $a): if (!isset($mesures[$a['id']])) continue; ?>
      <tr><td class="text-start"><a href="animal.php?id=<?= $a['id'] ?>&tab=mens"><?= e($a['numero_identification'] . ' ' . $a['nom']) ?></a></td>
        <?php for ($s = 0; $s <= $maxStade; $s++): $m = $mesures[$a['id']][$s] ?? null; ?>
          <td <?= $m ? 'title="' . e(dfr($m['date_mesure']) . ' · ' . implode(' · ', array_map(fn($k) => strtoupper($k) . ' ' . nfr($m[$k]), MESURES))) . '"' : '' ?>>
            <?= $m ? ($m['poids'] !== null ? nfr($m['poids']) : '<i class="bi bi-rulers text-muted"></i>') : '<span class="text-muted">·</span>' ?></td>
        <?php endfor; ?></tr>
    <?php endforeach; ?></tbody>
  </table></div></div>
<?php endif; ?>
<?php require 'includes/footer.php';
