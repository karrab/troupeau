<?php
require 'includes/functions.php';
$titre = 'Production laitière';

$annee = (int)($_GET['annee'] ?? date('Y'));
$annees = $pdo->query('SELECT DISTINCT YEAR(date_controle) y FROM controles_laitiers ORDER BY y DESC')->fetchAll(PDO::FETCH_COLUMN) ?: [date('Y')];

// Synthèse mensuelle du troupeau
$st = $pdo->prepare("SELECT MONTH(date_controle) m, COUNT(*) nb, AVG(quantite_totale) moy, SUM(quantite_totale) tot,
      AVG(taux_butyreux) tb, AVG(taux_proteique) tp, AVG(cellules) cel
    FROM controles_laitiers WHERE YEAR(date_controle)=? GROUP BY m ORDER BY m");
$st->execute([$annee]); $mois = $st->fetchAll();

// Classement des femelles sur l'année
$st = $pdo->prepare("SELECT a.id, a.numero_identification, a.nom, e.nom espece, COUNT(c.id) nb,
      AVG(c.quantite_totale) moy, MAX(c.quantite_totale) pic, AVG(c.taux_butyreux) tb, AVG(c.taux_proteique) tp,
      (SELECT cellules FROM controles_laitiers x WHERE x.animal_id=a.id ORDER BY date_controle DESC LIMIT 1) der_cel
    FROM controles_laitiers c JOIN animaux a ON a.id=c.animal_id JOIN especes e ON e.id=a.espece_id
    WHERE YEAR(c.date_controle)=? GROUP BY a.id ORDER BY moy DESC");
$st->execute([$annee]); $classement = $st->fetchAll();

$noms = ['', 'Janv.', 'Févr.', 'Mars', 'Avr.', 'Mai', 'Juin', 'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.'];
require 'includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h3 mb-0">Production laitière</h1>
  <form><select name="annee" class="form-select" onchange="this.form.submit()">
    <?php foreach ($annees as $y): ?><option <?= $y==$annee?'selected':'' ?>><?= $y ?></option><?php endforeach; ?></select></form>
</div>

<div class="card p-3 mb-3"><div class="chart-box"><canvas id="c"></canvas></div></div>

<div class="row g-3">
  <div class="col-xl-5"><div class="card">
    <div class="card-header bg-white fw-semibold">Synthèse mensuelle</div>
    <div class="table-responsive"><table class="table table-sm mb-0">
      <thead><tr><th>Mois</th><th class="text-end">Contrôles</th><th class="text-end">Moy. L/j</th><th class="text-end">TB</th><th class="text-end">TP</th><th class="text-end">Cellules</th></tr></thead>
      <tbody><?php foreach ($mois as $m): ?><tr><td><?= $noms[$m['m']] ?></td><td class="text-end"><?= $m['nb'] ?></td><td class="text-end fw-semibold"><?= nfr($m['moy']) ?></td>
        <td class="text-end"><?= nfr($m['tb']) ?></td><td class="text-end"><?= nfr($m['tp']) ?></td><td class="text-end"><?= nfr($m['cel'], 0) ?></td></tr><?php endforeach; ?>
      <?php if (!$mois): ?><tr><td colspan="6" class="text-center text-muted">Aucun contrôle pour <?= $annee ?>.</td></tr><?php endif; ?></tbody>
    </table></div></div></div>
  <div class="col-xl-7"><div class="card">
    <div class="card-header bg-white fw-semibold">Classement des femelles</div>
    <div class="table-responsive"><table class="table table-sm table-hover mb-0">
      <thead><tr><th>Animal</th><th>Espèce</th><th class="text-end">Contrôles</th><th class="text-end">Moy. L/j</th><th class="text-end">Pic</th><th class="text-end">TB</th><th class="text-end">TP</th><th class="text-end">Dern. cellules</th></tr></thead>
      <tbody><?php foreach ($classement as $c): ?><tr>
        <td><a href="animal.php?id=<?= $c['id'] ?>&tab=lait"><?= e($c['numero_identification'].' '.$c['nom']) ?></a></td><td><?= e($c['espece']) ?></td>
        <td class="text-end"><?= $c['nb'] ?></td><td class="text-end fw-semibold"><?= nfr($c['moy']) ?></td><td class="text-end"><?= nfr($c['pic']) ?></td>
        <td class="text-end"><?= nfr($c['tb']) ?></td><td class="text-end"><?= nfr($c['tp']) ?></td>
        <td class="text-end"><?= $c['der_cel'] === null ? '—' : '<span class="badge text-bg-'.($c['der_cel'] >= SEUIL_CELLULES ? 'danger' : 'success').'">'.$c['der_cel'].'</span>' ?></td></tr><?php endforeach; ?></tbody>
    </table></div></div></div>
</div>
<?php
$labels = json_encode(array_map(fn($m) => $noms[$m['m']], $mois));
$moy = json_encode(array_map(fn($m) => round((float)$m['moy'], 1), $mois));
$cel = json_encode(array_map(fn($m) => round((float)$m['cel']), $mois));
$scripts = "<script>new Chart(c,{data:{labels:$labels,datasets:[
 {type:'bar',label:'Lait moyen (L/j)',data:$moy,backgroundColor:'#2e7d4f',yAxisID:'y'},
 {type:'line',label:'Cellules moyennes (×1000/mL)',data:$cel,borderColor:'#b03a2e',yAxisID:'y2',tension:.3}]},
 options:{maintainAspectRatio:false,scales:{y:{beginAtZero:true},y2:{position:'right',beginAtZero:true,grid:{drawOnChartArea:false}}}}});</script>";
require 'includes/footer.php';
