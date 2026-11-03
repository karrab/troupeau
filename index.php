<?php
require 'includes/functions.php';
$titre = 'Tableau de bord';

// Effectifs
$effectifs = $pdo->query("SELECT e.nom, COUNT(a.id) nb,
    SUM(a.sexe='F') femelles, SUM(a.sexe='M') males
    FROM especes e LEFT JOIN animaux a ON a.espece_id=e.id AND a.statut='actif'
    GROUP BY e.id ORDER BY e.nom")->fetchAll();
$total = array_sum(array_column($effectifs, 'nb'));

// Lait : dernier mois contrôlé
$lait = $pdo->query("SELECT DATE_FORMAT(MAX(date_controle),'%Y-%m') m FROM controles_laitiers")->fetchColumn();
$laitStats = $lait ? $pdo->query("SELECT COUNT(*) nb, AVG(quantite_totale) moy, SUM(quantite_totale) tot,
    AVG(taux_butyreux) tb, AVG(taux_proteique) tp
    FROM controles_laitiers WHERE DATE_FORMAT(date_controle,'%Y-%m')='$lait'")->fetch() : null;

// Alertes
$rappels = $pdo->query("SELECT s.*, a.numero_identification, a.nom FROM sante s JOIN animaux a ON a.id=s.animal_id
    WHERE a.statut='actif' AND s.date_rappel IS NOT NULL AND s.date_rappel <= CURDATE() + INTERVAL 30 DAY
    ORDER BY s.date_rappel")->fetchAll();

$misesBas = $pdo->query("SELECT r.*, a.numero_identification, a.nom FROM reproductions r JOIN animaux a ON a.id=r.animal_id
    WHERE a.statut='actif' AND r.date_mise_bas_prevue BETWEEN CURDATE() - INTERVAL 7 DAY AND CURDATE() + INTERVAL 60 DAY
      AND NOT EXISTS (SELECT 1 FROM reproductions x WHERE x.animal_id=r.animal_id AND x.date_evenement>r.date_evenement
                      AND x.type_evenement IN ('mise_bas','avortement') )
      AND NOT EXISTS (SELECT 1 FROM reproductions d WHERE d.animal_id=r.animal_id AND d.type_evenement='diagnostic'
                      AND d.resultat='negatif' AND d.date_evenement>r.date_evenement)
    ORDER BY r.date_mise_bas_prevue")->fetchAll();

$attente = $pdo->query("SELECT s.*, a.numero_identification, a.nom,
      s.date_intervention + INTERVAL s.delai_attente_lait DAY fin_lait,
      s.date_intervention + INTERVAL s.delai_attente_viande DAY fin_viande
    FROM sante s JOIN animaux a ON a.id=s.animal_id
    WHERE a.statut='actif' AND (s.date_intervention + INTERVAL s.delai_attente_lait DAY >= CURDATE()
       OR s.date_intervention + INTERVAL s.delai_attente_viande DAY >= CURDATE())
      AND (s.delai_attente_lait>0 OR s.delai_attente_viande>0)
    ORDER BY s.date_intervention DESC")->fetchAll();

$cellules = $pdo->query("SELECT c.*, a.numero_identification, a.nom FROM controles_laitiers c JOIN animaux a ON a.id=c.animal_id
    WHERE a.statut='actif' AND c.cellules >= " . SEUIL_CELLULES . "
      AND c.date_controle = (SELECT MAX(date_controle) FROM controles_laitiers WHERE animal_id=c.animal_id)")->fetchAll();

// Évolution laitière du troupeau (12 derniers mois)
$evol = $pdo->query("SELECT DATE_FORMAT(date_controle,'%Y-%m') m, ROUND(AVG(quantite_totale),1) moy, COUNT(*) nb
    FROM controles_laitiers WHERE date_controle >= CURDATE() - INTERVAL 12 MONTH GROUP BY m ORDER BY m")->fetchAll();

require 'includes/header.php';
?>
<h1 class="h3 mb-4">Tableau de bord</h1>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="card kpi p-3"><div class="lbl">Animaux actifs</div><div class="val"><?= $total ?></div>
    <div class="small text-muted"><?php foreach ($effectifs as $ef) if ($ef['nb']) echo e($ef['nom']) . ' : ' . $ef['nb'] . ' &nbsp;'; ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="card kpi p-3"><div class="lbl">Lait moyen / animal / jour</div>
    <div class="val"><?= nfr($laitStats['moy'] ?? null) ?> <small class="fs-6">L</small></div>
    <div class="small text-muted"><?= $lait ? 'Contrôle ' . date('m/Y', strtotime($lait . '-01')) . ' · ' . $laitStats['nb'] . ' femelles' : 'Aucun contrôle' ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="card kpi p-3"><div class="lbl">TB / TP moyens</div>
    <div class="val"><?= nfr($laitStats['tb'] ?? null) ?> <small class="fs-6">/</small> <?= nfr($laitStats['tp'] ?? null) ?></div>
    <div class="small text-muted">g/L</div></div></div>
  <div class="col-6 col-lg-3"><div class="card kpi p-3"><div class="lbl">Alertes</div>
    <div class="val text-danger"><?= count($rappels) + count($misesBas) + count($attente) + count($cellules) ?></div>
    <div class="small text-muted">santé, reproduction, qualité du lait</div></div></div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card p-3 h-100">
      <h2 class="h6 mb-3">Production laitière moyenne du troupeau (L/jour)</h2>
      <div class="chart-box"><canvas id="chartLait"></canvas></div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card p-3 h-100">
      <h2 class="h6 mb-3"><i class="bi bi-bell text-danger"></i> Alertes à traiter</h2>
      <ul class="list-group list-group-flush small">
        <?php foreach ($cellules as $c): ?>
          <li class="list-group-item px-0"><span class="badge text-bg-danger">Cellules</span>
            <a href="animal.php?id=<?= $c['animal_id'] ?>&tab=lait"><?= e($c['numero_identification'].' '.$c['nom']) ?></a> :
            <?= $c['cellules'] ?> 000/mL le <?= dfr($c['date_controle']) ?> — suspicion de mammite</li>
        <?php endforeach; foreach ($attente as $s): ?>
          <li class="list-group-item px-0"><span class="badge text-bg-warning">Délai d'attente</span>
            <a href="animal.php?id=<?= $s['animal_id'] ?>&tab=sante"><?= e($s['numero_identification'].' '.$s['nom']) ?></a> :
            <?php if ($s['delai_attente_lait'] && $s['fin_lait'] >= date('Y-m-d')) echo 'lait interdit jusqu\'au ' . dfr($s['fin_lait']) . ' ';
                  if ($s['delai_attente_viande'] && $s['fin_viande'] >= date('Y-m-d')) echo '· viande jusqu\'au ' . dfr($s['fin_viande']); ?></li>
        <?php endforeach; foreach ($misesBas as $r): ?>
          <li class="list-group-item px-0"><span class="badge text-bg-info">Mise bas</span>
            <a href="animal.php?id=<?= $r['animal_id'] ?>&tab=repro"><?= e($r['numero_identification'].' '.$r['nom']) ?></a> :
            prévue le <?= dfr($r['date_mise_bas_prevue']) ?></li>
        <?php endforeach; foreach ($rappels as $s): ?>
          <li class="list-group-item px-0"><span class="badge text-bg-primary">Rappel</span>
            <a href="animal.php?id=<?= $s['animal_id'] ?>&tab=sante"><?= e($s['numero_identification'].' '.$s['nom']) ?></a> :
            <?= e(TYPES_SANTE[$s['type']]) ?> <?= e($s['produit']) ?> — <?= dfr($s['date_rappel']) ?>
            <?= $s['date_rappel'] < date('Y-m-d') ? '<span class="text-danger">(en retard)</span>' : '' ?></li>
        <?php endforeach; ?>
        <?php if (!$rappels && !$misesBas && !$attente && !$cellules): ?><li class="list-group-item px-0 text-muted">Aucune alerte.</li><?php endif; ?>
      </ul>
    </div>
  </div>
</div>
<?php
$scripts = '<script>new Chart(document.getElementById("chartLait"),{type:"bar",data:{labels:' .
  json_encode(array_map(fn($r) => date('m/Y', strtotime($r['m'] . '-01')), $evol)) . ',datasets:[{label:"L/jour",data:' .
  json_encode(array_map(fn($r) => (float)$r['moy'], $evol)) . ',backgroundColor:"#2e7d4f",borderRadius:4}]},
  options:{maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}});</script>';
require 'includes/footer.php';
