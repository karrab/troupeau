<?php
require 'includes/functions.php';
exiger('indices.voir');
$titre = 'Indices de productivité';

$campagnes = campagnes_indices($pdo);
$enCours = campagne_de(date('Y-m-d'));
$campagne = (int)($_GET['campagne'] ?? ($campagnes[1] ?? $campagnes[0])); // par défaut : dernière campagne terminée
$niveau = isset(NIVEAUX_INDICES[$_GET['niveau'] ?? '']) ? $_GET['niveau'] : 'troupeau';
$famille = isset(FAMILLES_INDICES[$_GET['famille'] ?? '']) ? $_GET['famille'] : '';
$vue = in_array($_GET['vue'] ?? '', ['campagne', 'lactation', 'jour'], true) ? $_GET['vue'] : 'campagne';
if ($vue === 'lactation' && $niveau !== 'animal') $vue = 'campagne';
$filtreEspece = (int)($_GET['espece'] ?? 0);
$url = fn(array $p = []) => 'indices.php?' . http_build_query(array_filter(array_merge(
    ['campagne' => $campagne, 'niveau' => $niveau, 'famille' => $famille, 'vue' => $vue, 'espece' => $filtreEspece ?: null], $p), fn($v) => $v !== null && $v !== ''));

// ---------- Calcul / recalcul ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exiger('indices.calculer');
    check_csrf();
    try {
        $t = microtime(true);
        $n = ($_POST['portee'] ?? '') === 'toutes' ? recalculer_indices($pdo) : calculer_indices($pdo, (int)$_POST['campagne']);
        flash(sprintf('%d résultats calculés, interprétés et enregistrés (%.2f s).', $n, microtime(true) - $t));
    } catch (Throwable $e) {
        flash('Erreur de calcul : ' . $e->getMessage(), 'danger');
    }
    redirect($url());
}
// Premier affichage : aucun résultat encore enregistré
if (!$pdo->query('SELECT 1 FROM indices_resultats LIMIT 1')->fetchColumn()) { try { recalculer_indices($pdo); } catch (Throwable) {} }

// ---------- Définitions ----------
$tous = $pdo->query('SELECT i.*, e.nom espece FROM indices i LEFT JOIN especes e ON e.id=i.espece_id WHERE i.actif=1 ORDER BY i.ordre, i.code')->fetchAll();
$tousParId = array_column($tous, null, 'id');
$regles = interpretations_indices($pdo);
$especesNoms = array_column(especes($pdo), 'nom', 'id');
$indices = array_values(array_filter($tous, function ($i) use ($niveau, $famille, $vue, $filtreEspece) {
    if ($famille && $i['famille'] !== $famille) return false;
    if (!in_array($niveau, explode(',', $i['niveaux']), true)) return false;
    if ($filtreEspece && $i['espece_id'] && $i['espece_id'] != $filtreEspece) return false;
    if ($niveau === 'troupeau') return $i['periode'] !== 'jour';
    if ($vue === 'campagne') return $i['periode'] === 'campagne' || ($i['periode'] === 'lactation' && $niveau !== 'animal');
    return $i['periode'] === $vue;
}));
$idsAffiches = array_column($indices, 'id');

// ---------- Libellés des cibles ----------
$cibles = match ($niveau) {
    'animal'   => $pdo->query("SELECT id, CONCAT(numero_identification, ' ', IFNULL(nom,'')) lib, espece_id, statut FROM animaux")->fetchAll(PDO::FETCH_UNIQUE),
    'lot'      => $pdo->query('SELECT id, nom lib FROM lots')->fetchAll(PDO::FETCH_UNIQUE),
    'espece'   => $pdo->query('SELECT id, nom lib, id espece_id FROM especes')->fetchAll(PDO::FETCH_UNIQUE),
    'troupeau' => [0 => ['lib' => 'Troupeau']],
};

// ---------- Résultats enregistrés ----------
$res = []; $lignes = []; $calcule = null;
if ($niveau === 'troupeau') {
    // Avec un filtre d'espèce, on affiche la série de cette espèce (seuils propres à l'espèce)
    $st = $pdo->prepare($filtreEspece ? "SELECT * FROM indices_resultats WHERE niveau='espece' AND cible_id=? AND periode<>'jour' ORDER BY campagne"
                                      : "SELECT * FROM indices_resultats WHERE niveau='troupeau' AND periode<>'jour' ORDER BY campagne");
    $st->execute($filtreEspece ? [$filtreEspece] : []);
    $rows = $st->fetchAll();
    foreach ($rows as $r) $res[$r['indice_id']][$r['campagne']] = $r;
    $colCampagnes = array_values(array_unique(array_column($rows, 'campagne')));
    $st = $pdo->prepare("SELECT r.periode_cle, r.valeur FROM indices_resultats r JOIN indices i ON i.id=r.indice_id
        WHERE r.niveau=? AND r.cible_id=? AND r.periode='jour' AND i.code='L01' AND r.campagne=? ORDER BY r.periode_cle");
    $st->execute([$filtreEspece ? 'espece' : 'troupeau', $filtreEspece, $campagne]); $journalier = $st->fetchAll();
} else {
    $st = $pdo->prepare('SELECT * FROM indices_resultats WHERE niveau=? AND campagne=?');
    $st->execute([$niveau, $campagne]);
    foreach ($st->fetchAll() as $r) {
        if (!in_array($r['indice_id'], $idsAffiches)) continue;
        $k = $vue === 'campagne' ? $r['cible_id'] : $r['cible_id'] . '|' . $r['periode_cle'];
        $res[$k][$r['indice_id']] = $r;
        $lignes[$k] ??= ['cible' => $r['cible_id'], 'cle' => $r['periode_cle'], 'debut' => $r['date_debut'], 'fin' => $r['date_fin']];
    }
    $lignes = array_filter($lignes, fn($l) => isset($cibles[$l['cible']]) && (!$filtreEspece || $niveau === 'lot' || $cibles[$l['cible']]['espece_id'] == $filtreEspece));
    uasort($lignes, fn($x, $y) => [strnatcasecmp($cibles[$x['cible']]['lib'], $cibles[$y['cible']]['lib']), $x['cle']] <=> [0, $y['cle']]);
}
$calcule = $pdo->query('SELECT MAX(calcule_le) FROM indices_resultats')->fetchColumn();
$perimes = array_filter($tous, fn($i) => $calcule && $i['updated_at'] > $calcule);

// ---------- Export CSV (vue SQL v_indices_resultats) ----------
if (($_GET['export'] ?? '') === 'csv') {
    exiger('indices.exporter');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="indices_' . $niveau . '_' . libelle_campagne($campagne) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Campagne', 'Période', 'Clé', 'Niveau', 'Cible', 'Code', 'Indice', 'Valeur', 'Unité', 'Interprétation', 'Nb animaux', 'Formule'], ';');
    $st = $pdo->prepare('SELECT * FROM v_indices_resultats WHERE niveau=? AND ' . ($niveau === 'troupeau' ? '1' : 'campagne=?') . ' ORDER BY campagne, code, cible, periode_cle');
    $st->execute($niveau === 'troupeau' ? [$niveau] : [$niveau, $campagne]);
    foreach ($st as $r) fputcsv($out, [libelle_campagne($r['campagne']), PERIODES_INDICES[$r['periode']], $r['periode_cle'], NIVEAUX_INDICES[$r['niveau']], $r['cible'],
        $r['code'], $r['indice'], number_format((float)$r['valeur'], 2, ',', ''), $r['unite'], $r['interpretation'], $r['nb_animaux'], $r['formule']], ';');
    exit;
}

/** En-têtes groupés par famille */
$entetes = function (array $inds, string $premiere) {
    $groupes = [];
    foreach ($inds as $i) $groupes[$i['famille']][] = $i;
    $h = '<tr><th rowspan="2" class="align-bottom">' . $premiere . '</th>';
    foreach ($groupes as $f => $l) $h .= '<th colspan="' . count($l) . '" class="text-center small fam-' . $f . '"><i class="bi bi-' . FAMILLES_INDICES[$f][2] . '"></i> ' . e(FAMILLES_INDICES[$f][0]) . '</th>';
    $h .= '</tr><tr>';
    foreach ($groupes as $l) foreach ($l as $i) $h .= entete_indice($i);
    return $h . '</tr>';
};
require 'includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div><h1 class="h3 mb-0">Indices de productivité</h1>
    <div class="small text-muted">Calculés automatiquement à partir des événements enregistrés · dernier calcul <?= $calcule ? date('d/m/Y H:i', strtotime($calcule)) : '—' ?></div></div>
  <div class="d-flex flex-wrap gap-2 no-print">
    <?php if (peut('indices.configurer')): ?><a href="parametres.php#indices" class="btn btn-outline-secondary btn-sm"><i class="bi bi-sliders"></i> Formules et seuils</a><?php endif; ?>
    <?php if (peut('indices.exporter')): ?><a href="<?= e($url(['export' => 'csv'])) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-filetype-csv"></i> Export CSV</a><?php endif; ?>
    <?php if (peut('indices.calculer')): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="campagne" value="<?= $campagne ?>">
      <button class="btn btn-primary btn-sm"><i class="bi bi-calculator"></i> Calculer <?= e(libelle_campagne($campagne)) ?></button></form>
    <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="portee" value="toutes">
      <button class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-repeat"></i> Tout recalculer</button></form><?php endif; ?>
  </div>
</div>
<?php if ($perimes): ?><div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle"></i> Formule modifiée depuis le dernier calcul :
  <?= e(implode(', ', array_column($perimes, 'code'))) ?>. Cliquez sur « Tout recalculer ».</div><?php endif; ?>

<div class="card p-3 mb-3 no-print">
  <div class="d-flex flex-wrap gap-3 align-items-end">
    <div><div class="form-label small mb-1">Niveau</div><div class="btn-group" role="group">
      <?php foreach (NIVEAUX_INDICES as $k => $l): ?><a href="<?= e($url(['niveau' => $k])) ?>" class="btn btn-sm <?= $k === $niveau ? 'btn-primary' : 'btn-outline-primary' ?>"><?= $l ?></a><?php endforeach; ?>
    </div></div>
    <?php if ($niveau !== 'troupeau'): ?>
    <div><div class="form-label small mb-1">Période de calcul</div><div class="btn-group" role="group">
      <a href="<?= e($url(['vue' => 'campagne'])) ?>" class="btn btn-sm <?= $vue === 'campagne' ? 'btn-secondary' : 'btn-outline-secondary' ?>">Campagne</a>
      <?php if ($niveau === 'animal'): ?><a href="<?= e($url(['vue' => 'lactation'])) ?>" class="btn btn-sm <?= $vue === 'lactation' ? 'btn-secondary' : 'btn-outline-secondary' ?>">Lactations</a><?php endif; ?>
      <a href="<?= e($url(['vue' => 'jour'])) ?>" class="btn btn-sm <?= $vue === 'jour' ? 'btn-secondary' : 'btn-outline-secondary' ?>">Contrôles (jour)</a>
    </div></div>
    <?php endif; ?>
    <form class="d-flex gap-2 align-items-end">
      <?php foreach (['niveau' => $niveau, 'famille' => $famille, 'vue' => $vue] as $k => $v): ?><input type="hidden" name="<?= $k ?>" value="<?= e($v) ?>"><?php endforeach; ?>
      <div><label class="form-label small mb-1">Campagne</label><select name="campagne" class="form-select form-select-sm" onchange="this.form.submit()">
        <?php foreach ($campagnes as $c): ?><option value="<?= $c ?>" <?= $c == $campagne ? 'selected' : '' ?>><?= e(libelle_campagne($c)) ?><?= $c == $enCours ? ' (en cours)' : '' ?></option><?php endforeach; ?></select></div>
      <div><label class="form-label small mb-1">Espèce</label><select name="espece" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">Toutes</option>
        <?php foreach ($especesNoms as $k => $n): ?><option value="<?= $k ?>" <?= $k == $filtreEspece ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
    </form>
  </div>
  <ul class="nav nav-pills small mt-3">
    <li class="nav-item"><a class="nav-link py-1 <?= !$famille ? 'active' : '' ?>" href="<?= e($url(['famille' => null])) ?>">Toutes les familles</a></li>
    <?php foreach (FAMILLES_INDICES as $k => [$l, $lettre, $ico]): ?>
      <li class="nav-item"><a class="nav-link py-1 <?= $famille === $k ? 'active' : '' ?>" href="<?= e($url(['famille' => $k])) ?>"><i class="bi bi-<?= $ico ?>"></i> <?= $lettre ?> · <?= e($l) ?></a></li>
    <?php endforeach; ?>
  </ul>
</div>

<?php if ($campagne == $enCours && $niveau !== 'troupeau'): ?><div class="alert alert-info py-2 small">Campagne <?= e(libelle_campagne($campagne)) ?> en cours : les indices évolueront jusqu'à sa clôture.</div><?php endif; ?>

<?php if (!$indices): ?>
  <div class="alert alert-light">Aucun indice actif pour ce niveau et ce filtre.</div>
<?php elseif ($niveau === 'troupeau'): ?>
  <div class="card"><div class="card-header bg-white fw-semibold">Évolution des indices <?= $filtreEspece ? 'du troupeau ' . e($especesNoms[$filtreEspece] ?? '') : 'du troupeau (toutes espèces)' ?> par campagne
    <?php if (!$filtreEspece): ?><div class="small fw-normal text-muted">Les seuils propres à une espèce (lait, prolificité…) ne s'appliquent qu'en choisissant l'espèce.</div><?php endif; ?></div>
  <div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
    <thead><tr><th>Indice</th><?php foreach ($colCampagnes as $c): ?><th class="text-center"><?= e(libelle_campagne($c)) ?><?= $c == $enCours ? '<br><small class="text-muted fw-normal">en cours</small>' : '' ?></th><?php endforeach; ?><th class="text-center">Évolution</th></tr></thead>
    <tbody><?php $fam = null; foreach ($indices as $i): $serie = $res[$i['id']] ?? [];
      if ($i['famille'] !== $fam): $fam = $i['famille']; ?>
      <tr class="table-light"><td colspan="<?= count($colCampagnes) + 2 ?>" class="fw-semibold small"><i class="bi bi-<?= FAMILLES_INDICES[$fam][2] ?>"></i> <?= e(FAMILLES_INDICES[$fam][0]) ?></td></tr><?php endif; ?>
      <tr><td><span class="badge text-bg-light font-monospace"><?= e($i['code']) ?></span> <a href="indice_form.php?id=<?= $i['id'] ?>" class="fw-semibold"><?= e($i['nom']) ?></a>
          <?= $i['unite'] ? '<small class="text-muted">(' . e($i['unite']) . ')</small>' : '' ?><br><small class="text-muted"><?= e($i['interpretation']) ?></small></td>
        <?php foreach ($colCampagnes as $c): ?><td class="text-center"><?= cellule_indice($i, $serie[$c] ?? null) ?></td><?php endforeach; ?>
        <td class="text-center text-nowrap"><?php
          $clos = array_values(array_filter($serie, fn($r) => $r['campagne'] != $enCours));
          if (count($clos) >= 2) {
              $d = (float)end($clos)['valeur'] - (float)$clos[count($clos) - 2]['valeur'];
              $bon = $i['sens'] === 'haut' ? $d > 0 : ($i['sens'] === 'bas' ? $d < 0 : null);
              $cl = $d == 0 || $bon === null ? 'text-muted' : ($bon ? 'text-success' : 'text-danger');
              echo '<span class="' . $cl . '"><i class="bi bi-arrow-' . ($d == 0 ? 'right' : ($d > 0 ? 'up-right' : 'down-right')) . '"></i> ' . ($d > 0 ? '+' : '') . nfr($d, (int)$i['decimales']) . '</span>';
          } else echo '<span class="text-muted">—</span>'; ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div></div>
  <?php if ($journalier && (!$famille || $famille === 'lait')): ?>
  <div class="card mt-3 p-3"><h2 class="h6">L01 · Production journalière moyenne <?= $filtreEspece ? '(' . e($especesNoms[$filtreEspece] ?? '') . ')' : 'du troupeau' ?> – campagne <?= e(libelle_campagne($campagne)) ?></h2>
    <div class="chart-box"><canvas id="chartL01"></canvas></div></div>
  <?php
    $scripts = '<script>new Chart(chartL01,{type:"line",data:{labels:' . json_encode(array_map(fn($r) => dfr($r['periode_cle']), $journalier)) . ',datasets:[{label:"kg/jour",data:'
      . json_encode(array_map(fn($r) => round((float)$r['valeur'], 2), $journalier)) . ',borderColor:"#1f5130",backgroundColor:"rgba(46,125,79,.12)",fill:true,tension:.3}]},options:{maintainAspectRatio:false,plugins:{legend:{display:false}}}});</script>';
  endif; ?>
<?php else: ?>
  <div class="card"><div class="card-header bg-white fw-semibold"><?= NIVEAUX_INDICES[$niveau] ?> · <?= ['campagne' => 'campagne', 'lactation' => 'lactations de la campagne', 'jour' => 'contrôles de la campagne'][$vue] ?>
    <?= e(libelle_campagne($campagne)) ?> <span class="badge text-bg-light"><?= count($lignes) ?></span></div>
  <div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
    <thead><?= $entetes($indices, NIVEAUX_INDICES[$niveau] . ($vue === 'lactation' ? ' · lactation' : ($vue === 'jour' ? ' · date' : ''))) ?></thead>
    <tbody><?php foreach ($lignes as $k => $l): $c = $l['cible']; ?>
      <tr><td class="text-nowrap">
        <?php if ($niveau === 'animal'): ?><a href="animal.php?id=<?= $c ?>&tab=indices"><?= e($cibles[$c]['lib']) ?></a><?= $cibles[$c]['statut'] !== 'actif' ? ' ' . badge_statut($cibles[$c]['statut']) : '' ?>
        <?php elseif ($niveau === 'lot'): ?><a href="animaux.php?lot=<?= $c ?>"><?= e($cibles[$c]['lib']) ?></a><?php else: ?><?= e($cibles[$c]['lib']) ?><?php endif; ?>
        <?php if ($vue === 'lactation'): ?><br><small class="text-muted">Mise bas <?= dfr($l['debut']) ?> → <?= $l['fin'] ? dfr($l['fin']) : 'en cours' ?></small>
        <?php elseif ($vue === 'jour'): ?><br><small class="text-muted"><?= dfr($l['cle']) ?></small>
        <?php elseif ($niveau !== 'animal'): $nb = max(array_column($res[$k], 'nb_animaux')); ?><br><small class="text-muted"><?= $nb ?> animal<?= $nb > 1 ? 'aux' : '' ?></small><?php endif; ?></td>
        <?php foreach ($indices as $i): ?><td class="text-center"><?= cellule_indice($i, $res[$k][$i['id']] ?? null) ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    <?php if (!$lignes): ?><tr><td colspan="<?= count($indices) + 1 ?>" class="text-center text-muted py-4">Aucun résultat pour cette campagne.</td></tr><?php endif; ?></tbody>
  </table></div></div>
<?php endif; ?>

<div class="card mt-3"><div class="card-header bg-white fw-semibold"><a class="text-decoration-none" data-bs-toggle="collapse" href="#defs"><i class="bi bi-info-circle"></i> Spécification des indices : formule, période, interprétation</a></div>
  <div class="collapse" id="defs"><div class="table-responsive"><table class="table table-sm mb-0 small align-middle">
    <thead><tr><th>Code</th><th>Indice</th><th>Formule</th><th>Unité</th><th>Période</th><th>Interprétation</th><th>Seuils</th></tr></thead>
    <tbody><?php foreach ($tous as $i): if ($famille && $i['famille'] !== $famille) continue; ?><tr>
      <td class="font-monospace"><?= e($i['code']) ?></td>
      <td><strong><?= e($i['nom']) ?></strong><br><span class="text-muted"><?= e($i['description']) ?></span></td>
      <td><code><?= e($i['formule']) ?></code></td><td><?= e($i['unite']) ?></td>
      <td><?= PERIODES_INDICES[$i['periode']] ?><br><span class="text-muted"><?= e(implode(', ', array_map(fn($n) => NIVEAUX_INDICES[$n], explode(',', $i['niveaux'])))) ?></span></td>
      <td><?= e(SENS_INDICES[$i['sens']]) ?><br><span class="text-muted"><?= e($i['interpretation']) ?></span></td>
      <td><?php foreach ($regles[$i['id']] ?? [] as $r): ?><span class="badge text-bg-<?= e($r['couleur']) ?> me-1 mb-1"><?= $r['espece_id'] ? e($especesNoms[$r['espece_id']] ?? '') . ' · ' : '' ?><?= e($r['libelle']) ?> <?= e(texte_regle($r)) ?></span><?php endforeach; ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div></div></div>
<?php require 'includes/footer.php';
