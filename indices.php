<?php
require 'includes/functions.php';
$titre = 'Indices';

$annees = annees_indices($pdo);
$annee  = (int)($_GET['annee'] ?? $annees[0]);
$niveau = isset(NIVEAUX_INDICES[$_GET['niveau'] ?? '']) ? $_GET['niveau'] : 'animal';
$filtreEspece = (int)($_GET['espece'] ?? 0);
$url = fn(array $p = []) => 'indices.php?' . http_build_query(array_merge(['annee' => $annee, 'niveau' => $niveau, 'espece' => $filtreEspece ?: null], $p));

// ---------- Calcul / recalcul ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $t = microtime(true);
        $n = ($_POST['portee'] ?? '') === 'toutes' ? recalculer_indices($pdo) : calculer_indices($pdo, (int)$_POST['annee']);
        flash(sprintf('%d résultats calculés, interprétés et enregistrés (%.2f s).', $n, microtime(true) - $t));
    } catch (Throwable $e) {
        flash('Erreur de calcul : ' . $e->getMessage(), 'danger');
    }
    redirect($url());
}

// Premier affichage : aucun résultat encore enregistré => calcul initial de toutes les années
if (!$pdo->query('SELECT 1 FROM indices_resultats LIMIT 1')->fetchColumn()) maj_indices_auto($pdo, ...array_map(fn($y) => "$y-01-01", $annees));

// ---------- Définitions des indices applicables au niveau choisi ----------
$st = $pdo->prepare("SELECT i.*, e.nom espece FROM indices i LEFT JOIN especes e ON e.id=i.espece_id
    WHERE i.actif=1 AND FIND_IN_SET(?, i.niveaux) " . ($filtreEspece ? 'AND (i.espece_id IS NULL OR i.espece_id=?)' : '') . ' ORDER BY i.ordre, i.nom');
$st->execute($filtreEspece ? [$niveau, $filtreEspece] : [$niveau]);
$indices = $st->fetchAll();
$regles = interpretations_indices($pdo);

// ---------- Libellés des cibles ----------
$cibles = match ($niveau) {
    'animal'   => $pdo->query("SELECT a.id, CONCAT(a.numero_identification, ' ', IFNULL(a.nom,'')) lib, a.espece_id, a.statut FROM animaux a ORDER BY a.numero_identification")->fetchAll(PDO::FETCH_UNIQUE),
    'lot'      => $pdo->query('SELECT id, nom lib FROM lots ORDER BY nom')->fetchAll(PDO::FETCH_UNIQUE),
    'espece'   => $pdo->query('SELECT id, nom lib, id espece_id FROM especes ORDER BY nom')->fetchAll(PDO::FETCH_UNIQUE),
    'troupeau' => [0 => ['lib' => 'Troupeau']],
};

// ---------- Résultats enregistrés ----------
$res = []; $calcule = [];
if ($niveau === 'troupeau') { // Évolution pluriannuelle
    $rows = $pdo->query("SELECT * FROM indices_resultats WHERE niveau='troupeau' ORDER BY annee")->fetchAll();
    foreach ($rows as $r) { $res[$r['indice_id']][$r['annee']] = $r; $calcule[$r['indice_id']] = max($calcule[$r['indice_id']] ?? '', $r['calcule_le']); }
    $colAnnees = array_values(array_unique(array_column($rows, 'annee')));
} else {
    $st = $pdo->prepare('SELECT * FROM indices_resultats WHERE niveau=? AND annee=?');
    $st->execute([$niveau, $annee]);
    foreach ($st->fetchAll() as $r) { $res[$r['cible_id']][$r['indice_id']] = $r; $calcule[$r['indice_id']] = max($calcule[$r['indice_id']] ?? '', $r['calcule_le']); }
    $lignes = array_filter(array_keys($res), fn($c) => isset($cibles[$c]) && (!$filtreEspece || $niveau === 'lot' || $cibles[$c]['espece_id'] == $filtreEspece));
    usort($lignes, fn($x, $y) => strnatcasecmp($cibles[$x]['lib'], $cibles[$y]['lib']));
}
$perime = fn(array $i) => isset($calcule[$i['id']]) && $i['updated_at'] > $calcule[$i['id']];

// ---------- Export CSV ----------
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="indices_' . $niveau . ($niveau !== 'troupeau' ? '_' . $annee : '') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Niveau', 'Cible', 'Année', 'Indice', 'Valeur', 'Unité', 'Interprétation', 'Nb animaux', 'Formule'], ';');
    $where = $niveau === 'troupeau' ? '' : ' AND r.annee=' . $annee;
    $st = $pdo->prepare("SELECT r.*, i.nom, i.unite, i.decimales FROM indices_resultats r JOIN indices i ON i.id=r.indice_id WHERE r.niveau=? $where ORDER BY r.annee, r.cible_id, i.ordre");
    $st->execute([$niveau]);
    foreach ($st as $r) fputcsv($out, [NIVEAUX_INDICES[$niveau], $cibles[$r['cible_id']]['lib'] ?? $r['cible_id'], $r['annee'], $r['nom'],
        number_format((float)$r['valeur'], (int)$r['decimales'], ',', ''), $r['unite'], $r['interpretation'], $r['nb_animaux'], $r['formule']], ';');
    exit;
}

$entete = function (array $i) use ($perime) {
    return '<th class="text-center" title="' . e($i['description'] . ' — ' . $i['formule']) . '">' . e($i['nom'])
        . ($i['espece'] ? '<br><small class="text-muted fw-normal">' . e($i['espece']) . '</small>' : '')
        . ($perime($i) ? ' <i class="bi bi-exclamation-triangle-fill text-warning" title="Formule modifiée depuis le dernier calcul : recalculez"></i>' : '') . '</th>';
};
require 'includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h3 mb-0">Indices zootechniques</h1>
  <div class="d-flex gap-2 no-print">
    <a href="parametres.php#indices" class="btn btn-outline-secondary btn-sm"><i class="bi bi-sliders"></i> Formules et seuils</a>
    <a href="<?= e($url(['export' => 'csv'])) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-filetype-csv"></i> Export CSV</a>
    <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="annee" value="<?= $annee ?>">
      <button class="btn btn-primary btn-sm"><i class="bi bi-calculator"></i> Calculer <?= $annee ?></button></form>
    <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="portee" value="toutes">
      <button class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-repeat"></i> Recalculer toutes les années</button></form>
  </div>
</div>

<div class="card p-3 mb-3 no-print">
  <form class="row g-2 align-items-end">
    <div class="col-auto"><label class="form-label small mb-1">Niveau</label>
      <div class="btn-group d-flex" role="group">
        <?php foreach (NIVEAUX_INDICES as $k => $l): ?>
          <a href="<?= e($url(['niveau' => $k])) ?>" class="btn btn-sm <?= $k === $niveau ? 'btn-primary' : 'btn-outline-primary' ?>"><?= $l ?></a>
        <?php endforeach; ?>
      </div></div>
    <input type="hidden" name="niveau" value="<?= e($niveau) ?>">
    <?php if ($niveau !== 'troupeau'): ?>
    <div class="col-auto"><label class="form-label small mb-1">Année</label>
      <select name="annee" class="form-select form-select-sm" onchange="this.form.submit()">
        <?php foreach ($annees as $y): ?><option <?= $y == $annee ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?></select></div>
    <?php else: ?><input type="hidden" name="annee" value="<?= $annee ?>"><?php endif; ?>
    <div class="col-auto"><label class="form-label small mb-1">Espèce</label>
      <select name="espece" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">Toutes</option>
        <?php foreach (especes($pdo) as $x): ?><option value="<?= $x['id'] ?>" <?= $x['id'] == $filtreEspece ? 'selected' : '' ?>><?= e($x['nom']) ?></option><?php endforeach; ?></select></div>
  </form>
</div>

<?php if (!$indices): ?>
  <div class="alert alert-info">Aucun indice actif pour ce niveau. <a href="indice_form.php">Créer un indice</a>.</div>
<?php elseif ($niveau === 'troupeau'): ?>
  <div class="card"><div class="card-header bg-white fw-semibold">Évolution des indices du troupeau par année</div>
  <div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
    <thead><tr><th>Indice</th><?php foreach ($colAnnees as $y): ?><th class="text-center"><?= $y ?></th><?php endforeach; ?><th class="text-center">Tendance</th></tr></thead>
    <tbody><?php foreach ($indices as $i): $serie = $res[$i['id']] ?? []; ?>
      <tr><td><a href="indice_form.php?id=<?= $i['id'] ?>" class="fw-semibold"><?= e($i['nom']) ?></a><?= $perime($i) ? ' <i class="bi bi-exclamation-triangle-fill text-warning" title="Formule modifiée : recalculez"></i>' : '' ?>
        <br><code class="small"><?= e($i['formule']) ?></code></td>
        <?php foreach ($colAnnees as $y): ?><td class="text-center"><?= cellule_indice($i, $serie[$y] ?? null) ?></td><?php endforeach; ?>
        <td class="text-center"><?php
          $v = array_values(array_map(fn($r) => (float)$r['valeur'], $serie));
          if (count($v) >= 2) { $d = end($v) - $v[count($v) - 2];
            echo $d == 0 ? '<i class="bi bi-arrow-right text-muted"></i>' : ($d > 0 ? '<i class="bi bi-arrow-up-right text-primary"></i>' : '<i class="bi bi-arrow-down-right text-primary"></i>') . ' ' . sprintf('%+s', nfr($d, (int)$i['decimales']));
          } else echo '—'; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$colAnnees): ?><tr><td colspan="2" class="text-center text-muted py-4">Aucun résultat enregistré : cliquez sur « Recalculer toutes les années ».</td></tr><?php endif; ?></tbody>
  </table></div></div>
<?php else: ?>
  <div class="card"><div class="card-header bg-white fw-semibold"><?= NIVEAUX_INDICES[$niveau] ?>s · <?= $annee ?> <span class="badge text-bg-light"><?= count($lignes) ?></span></div>
  <div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
    <thead><tr><th><?= NIVEAUX_INDICES[$niveau] ?></th><?php foreach ($indices as $i) echo $entete($i); ?></tr></thead>
    <tbody><?php foreach ($lignes as $c): ?>
      <tr><td class="text-nowrap"><?php if ($niveau === 'animal'): ?><a href="animal.php?id=<?= $c ?>&tab=indices"><?= e($cibles[$c]['lib']) ?></a><?= $cibles[$c]['statut'] !== 'actif' ? ' ' . badge_statut($cibles[$c]['statut']) : '' ?>
        <?php elseif ($niveau === 'lot'): ?><a href="animaux.php?lot=<?= $c ?>"><?= e($cibles[$c]['lib']) ?></a><?php else: ?><?= e($cibles[$c]['lib']) ?><?php endif; ?>
        <?php if ($niveau !== 'animal'): $nb = max(array_column($res[$c], 'nb_animaux')); ?><br><small class="text-muted"><?= $nb ?> animal<?= $nb > 1 ? 'aux' : '' ?></small><?php endif; ?></td>
        <?php foreach ($indices as $i): ?><td class="text-center"><?= cellule_indice($i, $res[$c][$i['id']] ?? null) ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    <?php if (!$lignes): ?><tr><td colspan="<?= count($indices) + 1 ?>" class="text-center text-muted py-4">Aucun résultat enregistré pour <?= $annee ?> : cliquez sur « Calculer <?= $annee ?> ».</td></tr><?php endif; ?></tbody>
  </table></div></div>
<?php endif; ?>

<div class="card mt-3"><div class="card-header bg-white fw-semibold"><a class="text-decoration-none" data-bs-toggle="collapse" href="#defs"><i class="bi bi-info-circle"></i> Définition et interprétation des indices</a></div>
  <div class="collapse" id="defs"><div class="table-responsive"><table class="table table-sm mb-0 small">
    <thead><tr><th>Indice</th><th>Formule</th><th>Groupes</th><th>Interprétation</th></tr></thead>
    <tbody><?php foreach ($indices as $i): ?><tr>
      <td><strong><?= e($i['nom']) ?></strong> <span class="text-muted"><?= e($i['unite']) ?></span><br><span class="text-muted"><?= e($i['description']) ?></span></td>
      <td><code><?= e($i['formule']) ?></code></td>
      <td><?= e(AGREGATIONS[$i['agregation']]) ?></td>
      <td><?php foreach ($regles[$i['id']] ?? [] as $r): ?><span class="badge text-bg-<?= e($r['couleur']) ?> me-1"><?= e($r['libelle']) ?> :
        <?= texte_regle($r) ?></span><?php endforeach; ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div></div></div>
<?php require 'includes/footer.php';
