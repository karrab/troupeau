<?php
require 'includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT a.*, e.nom espece, e.gestation_jours, r.nom race, l.nom lot,
    m.numero_identification mere_num, m.nom mere_nom, p.numero_identification pere_num, p.nom pere_nom
  FROM animaux a JOIN especes e ON e.id=a.espece_id
  LEFT JOIN races r ON r.id=a.race_id LEFT JOIN lots l ON l.id=a.lot_id
  LEFT JOIN animaux m ON m.id=a.mere_id LEFT JOIN animaux p ON p.id=a.pere_id
  WHERE a.id=?");
$st->execute([$id]);
$a = $st->fetch() ?: redirect('animaux.php');
$titre = 'Fiche ' . $a['numero_identification'];
$tab = $_GET['tab'] ?? 'identite';

function lignes(PDO $pdo, string $sql, int $id): array { $s = $pdo->prepare($sql); $s->execute([$id]); return $s->fetchAll(); }

$pesees  = lignes($pdo, 'SELECT * FROM pesees WHERE animal_id=? ORDER BY date_pesee', $id);
$laits   = lignes($pdo, 'SELECT * FROM controles_laitiers WHERE animal_id=? ORDER BY date_controle', $id);
$repros  = lignes($pdo, 'SELECT r.*, m.numero_identification male_num FROM reproductions r LEFT JOIN animaux m ON m.id=r.male_id WHERE r.animal_id=? ORDER BY date_evenement DESC, r.id DESC', $id);
$soins   = lignes($pdo, 'SELECT * FROM sante WHERE animal_id=? ORDER BY date_intervention DESC', $id);
$mvts    = lignes($pdo, 'SELECT m.*, o.nom lot_o, d.nom lot_d FROM mouvements m LEFT JOIN lots o ON o.id=m.lot_origine LEFT JOIN lots d ON d.id=m.lot_destination WHERE animal_id=? ORDER BY date_mouvement DESC, m.id DESC', $id);
$s = $pdo->prepare('SELECT id, numero_identification, nom, sexe, date_naissance, statut FROM animaux WHERE mere_id=? OR pere_id=? ORDER BY date_naissance DESC');
$s->execute([$id, $id]); $petits = $s->fetchAll();
$males = $pdo->prepare("SELECT id, numero_identification, nom FROM animaux WHERE sexe='M' AND statut='actif' AND espece_id=? ORDER BY numero_identification");
$males->execute([$a['espece_id']]); $males = $males->fetchAll();

// ---------- Indicateurs de croissance ----------
$gmq = []; // GMQ entre deux pesées successives (g/jour)
for ($i = 1; $i < count($pesees); $i++) {
    $j = (strtotime($pesees[$i]['date_pesee']) - strtotime($pesees[$i-1]['date_pesee'])) / 86400;
    $gmq[$pesees[$i]['id']] = $j > 0 ? round(($pesees[$i]['poids'] - $pesees[$i-1]['poids']) / $j * 1000) : null;
}
$dernierePesee = $pesees ? end($pesees) : null;
$gmqGlobal = null;
if (count($pesees) >= 2) {
    $j = (strtotime($dernierePesee['date_pesee']) - strtotime($pesees[0]['date_pesee'])) / 86400;
    if ($j > 0) $gmqGlobal = round(($dernierePesee['poids'] - $pesees[0]['poids']) / $j * 1000);
}

// ---------- Reproduction : état actuel ----------
$misesBas = array_values(array_filter($repros, fn($r) => $r['type_evenement'] === 'mise_bas'));
$derniereMB = $misesBas[0]['date_evenement'] ?? null;
$etatRepro = 'Vide'; $mbPrevue = null;
foreach ($repros as $r) { // du plus récent au plus ancien
    if (in_array($r['type_evenement'], ['mise_bas','avortement'])) break;
    if ($r['type_evenement'] === 'diagnostic' && $r['resultat'] === 'negatif') { $etatRepro = 'Vide (diagnostic négatif)'; break; }
    if ($r['type_evenement'] === 'diagnostic' && $r['resultat'] === 'positif') $etatRepro = 'Gestante';
    if (in_array($r['type_evenement'], ['insemination','saillie'])) {
        if ($etatRepro !== 'Gestante') $etatRepro = 'Mise à la reproduction (à confirmer)';
        $mbPrevue = $r['date_mise_bas_prevue']; break;
    }
}
// Intervalle entre mises bas (IVV)
$ivv = null;
if (count($misesBas) >= 2) {
    $tot = 0;
    for ($i = 0; $i < count($misesBas) - 1; $i++) $tot += (strtotime($misesBas[$i]['date_evenement']) - strtotime($misesBas[$i+1]['date_evenement'])) / 86400;
    $ivv = round($tot / (count($misesBas) - 1));
}

// ---------- Lait : lactation en cours ----------
$lactation = $derniereMB ? array_values(array_filter($laits, fn($c) => $c['date_controle'] >= $derniereMB)) : $laits;
$joursLait = $derniereMB ? (int)((time() - strtotime($derniereMB)) / 86400) : null;
$prodLactation = 0; // intégration par trapèzes (méthode simplifiée du contrôle laitier)
if ($lactation) {
    $debut = $derniereMB ?: $lactation[0]['date_controle'];
    $prev = ['date_controle' => $debut, 'quantite_totale' => $lactation[0]['quantite_totale']];
    foreach ($lactation as $c) {
        $j = (strtotime($c['date_controle']) - strtotime($prev['date_controle'])) / 86400;
        $prodLactation += $j * ($c['quantite_totale'] + $prev['quantite_totale']) / 2;
        $prev = $c;
    }
}
$qte  = array_map(fn($c) => (float)$c['quantite_totale'], $lactation);
$pic  = $qte ? max($qte) : null;
$moyL = $qte ? array_sum($qte) / count($qte) : null;
$tb   = array_filter(array_column($lactation, 'taux_butyreux'));
$tp   = array_filter(array_column($lactation, 'taux_proteique'));

// Délai d'attente en cours ?
$attenteLait = null; $attenteViande = null;
foreach ($soins as $so) {
    $fl = date('Y-m-d', strtotime($so['date_intervention'] . " +{$so['delai_attente_lait']} days"));
    $fv = date('Y-m-d', strtotime($so['date_intervention'] . " +{$so['delai_attente_viande']} days"));
    if ($so['delai_attente_lait'] && $fl >= date('Y-m-d') && $fl > (string)$attenteLait) $attenteLait = $fl;
    if ($so['delai_attente_viande'] && $fv >= date('Y-m-d') && $fv > (string)$attenteViande) $attenteViande = $fv;
}

require 'includes/header.php';
$active = fn($t) => $tab === $t ? 'active' : '';
$show   = fn($t) => $tab === $t ? 'show active' : '';
$del = function (string $type, int $rid) use ($id) {
    return '<form method="post" action="actions.php" class="d-inline" onsubmit="return confirm(\'Supprimer cette ligne ?\')">' . csrf_field()
      . '<input type="hidden" name="op" value="del"><input type="hidden" name="type" value="' . $type . '"><input type="hidden" name="animal_id" value="' . $id . '">'
      . '<input type="hidden" name="id" value="' . $rid . '"><button class="btn btn-sm btn-link text-danger p-0" title="Supprimer"><i class="bi bi-trash"></i></button></form>';
};
?>
<!-- En-tête de fiche -->
<div class="card p-3 mb-3">
  <div class="row g-3 align-items-center">
    <div class="col-auto">
      <?php if ($a['photo']): ?><img src="uploads/<?= e($a['photo']) ?>" class="photo-animal" style="max-width:120px" alt="">
      <?php else: ?><div class="photo-animal photo-vide" style="width:120px"><i class="bi bi-image"></i></div><?php endif; ?>
    </div>
    <div class="col">
      <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
        <h1 class="h3 mb-0"><?= e($a['numero_identification']) ?> <span class="fw-normal"><?= e($a['nom']) ?></span></h1>
        <?= badge_statut($a['statut']) ?>
        <?php if ($attenteLait): ?><span class="badge text-bg-danger"><i class="bi bi-droplet-half"></i> Lait interdit jusqu'au <?= dfr($attenteLait) ?></span><?php endif; ?>
        <?php if ($attenteViande): ?><span class="badge text-bg-warning">Viande interdite jusqu'au <?= dfr($attenteViande) ?></span><?php endif; ?>
      </div>
      <div class="text-muted"><?= e($a['espece']) ?> · <?= e($a['race'] ?? 'race non renseignée') ?> · <?= $a['sexe']==='F'?'Femelle':'Mâle' ?> · <?= e($a['categorie']) ?> · <?= age($a['date_naissance']) ?> · <?= e($a['lot'] ?? 'sans lot') ?></div>
    </div>
    <div class="col-12 col-md-auto no-print">
      <a href="animal_form.php?id=<?= $id ?>" class="btn btn-primary btn-sm"><i class="bi bi-pencil"></i> Modifier</a>
      <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer"></i> Imprimer</button>
    </div>
  </div>
  <div class="row g-3 mt-1 text-center">
    <div class="col-6 col-md-3 kpi"><div class="lbl">Dernier poids</div><div class="val fs-4"><?= $dernierePesee ? nfr($dernierePesee['poids'],0).' kg' : '—' ?></div><div class="small text-muted"><?= $dernierePesee ? dfr($dernierePesee['date_pesee']) : '' ?></div></div>
    <div class="col-6 col-md-3 kpi"><div class="lbl">GMQ global</div><div class="val fs-4"><?= $gmqGlobal !== null ? $gmqGlobal.' g/j' : '—' ?></div></div>
    <?php if ($a['sexe'] === 'F'): ?>
    <div class="col-6 col-md-3 kpi"><div class="lbl">Lait (dernier contrôle)</div><div class="val fs-4"><?= $laits ? nfr(end($laits)['quantite_totale']).' L' : '—' ?></div><div class="small text-muted"><?= $joursLait !== null ? $joursLait . ' j de lactation' : '' ?></div></div>
    <div class="col-6 col-md-3 kpi"><div class="lbl">Reproduction</div><div class="val fs-6 pt-2"><?= e($etatRepro) ?></div><div class="small text-muted"><?= $mbPrevue ? 'Mise bas prévue ' . dfr($mbPrevue) : '' ?></div></div>
    <?php endif; ?>
  </div>
</div>

<ul class="nav nav-tabs" role="tablist">
  <li class="nav-item"><button class="nav-link <?= $active('identite') ?>" data-bs-toggle="tab" data-bs-target="#identite"><i class="bi bi-card-text"></i> Identité</button></li>
  <li class="nav-item"><button class="nav-link <?= $active('poids') ?>" data-bs-toggle="tab" data-bs-target="#poids"><i class="bi bi-speedometer"></i> Poids <span class="badge text-bg-light"><?= count($pesees) ?></span></button></li>
  <?php if ($a['sexe'] === 'F'): ?>
  <li class="nav-item"><button class="nav-link <?= $active('lait') ?>" data-bs-toggle="tab" data-bs-target="#lait"><i class="bi bi-droplet"></i> Lait <span class="badge text-bg-light"><?= count($laits) ?></span></button></li>
  <li class="nav-item"><button class="nav-link <?= $active('repro') ?>" data-bs-toggle="tab" data-bs-target="#repro"><i class="bi bi-heart"></i> Reproduction <span class="badge text-bg-light"><?= count($repros) ?></span></button></li>
  <?php endif; ?>
  <li class="nav-item"><button class="nav-link <?= $active('sante') ?>" data-bs-toggle="tab" data-bs-target="#sante"><i class="bi bi-capsule"></i> Santé <span class="badge text-bg-light"><?= count($soins) ?></span></button></li>
  <li class="nav-item"><button class="nav-link <?= $active('mvt') ?>" data-bs-toggle="tab" data-bs-target="#mvt"><i class="bi bi-arrow-left-right"></i> Mouvements</button></li>
</ul>

<div class="tab-content card border-top-0 rounded-top-0 p-3">

  <!-- ================= IDENTITÉ ================= -->
  <div class="tab-pane fade <?= $show('identite') ?>" id="identite">
    <div class="row">
      <div class="col-md-4"><dl class="fiche">
        <dt>N° identification</dt><dd><?= e($a['numero_identification']) ?></dd>
        <dt>Nom</dt><dd><?= e($a['nom'] ?: '—') ?></dd>
        <dt>Espèce / race</dt><dd><?= e($a['espece']) ?> / <?= e($a['race'] ?? '—') ?></dd>
        <dt>Sexe / catégorie</dt><dd><?= $a['sexe']==='F'?'Femelle':'Mâle' ?> / <?= e($a['categorie'] ?: '—') ?></dd>
        <dt>Robe</dt><dd><?= e($a['robe'] ?: '—') ?></dd>
      </dl></div>
      <div class="col-md-4"><dl class="fiche">
        <dt>Naissance</dt><dd><?= dfr($a['date_naissance']) ?> (<?= age($a['date_naissance']) ?>)</dd>
        <dt>Poids de naissance</dt><dd><?= $a['poids_naissance'] ? nfr($a['poids_naissance']).' kg' : '—' ?></dd>
        <dt>Mère</dt><dd><?= $a['mere_id'] ? '<a href="animal.php?id='.$a['mere_id'].'">'.e($a['mere_num'].' '.$a['mere_nom']).'</a>' : '—' ?></dd>
        <dt>Père</dt><dd><?= $a['pere_id'] ? '<a href="animal.php?id='.$a['pere_id'].'">'.e($a['pere_num'].' '.$a['pere_nom']).'</a>' : '—' ?></dd>
      </dl></div>
      <div class="col-md-4"><dl class="fiche">
        <dt>Origine</dt><dd><?= $a['origine']==='achat' ? 'Achat' . ($a['prix_achat'] ? ' – ' . nfr($a['prix_achat'],2) : '') : 'Né à la ferme' ?></dd>
        <dt>Date d'entrée</dt><dd><?= dfr($a['date_entree']) ?></dd>
        <dt>Lot actuel</dt><dd><?= e($a['lot'] ?? '—') ?></dd>
        <?php if ($a['statut'] !== 'actif'): ?><dt>Sortie</dt><dd><?= dfr($a['date_sortie']) ?> – <?= e($a['motif_sortie']) ?></dd><?php endif; ?>
      </dl></div>
    </div>
    <?php if ($a['observations']): ?><div class="alert alert-light"><?= nl2br(e($a['observations'])) ?></div><?php endif; ?>
    <h3 class="h6 mt-2">Descendance (<?= count($petits) ?>)</h3>
    <?php if ($petits): ?><ul class="list-inline"><?php foreach ($petits as $p): ?>
      <li class="list-inline-item"><a class="btn btn-sm btn-outline-secondary mb-1" href="animal.php?id=<?= $p['id'] ?>"><?= $p['sexe']==='F'?'♀':'♂' ?> <?= e($p['numero_identification'].' '.$p['nom']) ?> · <?= dfr($p['date_naissance']) ?></a></li>
    <?php endforeach; ?></ul><?php else: ?><p class="text-muted small">Aucun descendant enregistré.</p><?php endif; ?>
  </div>

  <!-- ================= POIDS ================= -->
  <div class="tab-pane fade <?= $show('poids') ?>" id="poids">
    <div class="row g-3">
      <div class="col-lg-7"><div class="chart-box"><canvas id="chartPoids"></canvas></div></div>
      <div class="col-lg-5">
        <form method="post" action="actions.php" class="border rounded p-3 bg-light">
          <?= csrf_field() ?><input type="hidden" name="op" value="add"><input type="hidden" name="type" value="pesee"><input type="hidden" name="animal_id" value="<?= $id ?>">
          <h3 class="h6">Nouvelle pesée</h3>
          <div class="row g-2">
            <div class="col-6"><label class="form-label small">Date</label><input type="date" name="date_pesee" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-6"><label class="form-label small">Poids (kg)</label><input type="number" step="0.1" min="0" name="poids" class="form-control form-control-sm" required></div>
            <div class="col-6"><label class="form-label small">Note d'état corporel (1–5)</label><input type="number" step="0.25" min="1" max="5" name="note_etat_corporel" class="form-control form-control-sm"></div>
            <div class="col-6"><label class="form-label small">Méthode</label><select name="methode" class="form-select form-select-sm"><option value="balance">Balance</option><option value="ruban">Ruban barymétrique</option><option value="estimation">Estimation</option></select></div>
            <div class="col-12"><input name="observations" class="form-control form-control-sm" placeholder="Observations"></div>
            <div class="col-12"><button class="btn btn-primary btn-sm w-100">Ajouter</button></div>
          </div>
        </form>
      </div>
    </div>
    <div class="table-responsive mt-3"><table class="table table-sm">
      <thead><tr><th>Date</th><th>Âge</th><th class="text-end">Poids</th><th class="text-end">Variation</th><th class="text-end">GMQ</th><th>NEC</th><th>Méthode</th><th>Observations</th><th></th></tr></thead>
      <tbody><?php $prev = null; foreach (array_reverse($pesees, true) as $k => $p): $pp = $pesees[$k-1] ?? null; ?>
        <tr><td><?= dfr($p['date_pesee']) ?></td>
          <td class="text-muted"><?= $a['date_naissance'] ? round((strtotime($p['date_pesee']) - strtotime($a['date_naissance'])) / 86400) . ' j' : '—' ?></td>
          <td class="text-end fw-semibold"><?= nfr($p['poids']) ?> kg</td>
          <td class="text-end"><?= $pp ? sprintf('%+.1f kg', $p['poids'] - $pp['poids']) : '' ?></td>
          <td class="text-end"><?php $g = $gmq[$p['id']] ?? null; echo $g === null ? '' : '<span class="'.($g < 0 ? 'text-danger' : 'text-success').'">'.$g.' g/j</span>'; ?></td>
          <td><?= $p['note_etat_corporel'] ? nfr($p['note_etat_corporel'], 2) : '' ?></td>
          <td><?= e($p['methode']) ?></td><td class="small"><?= e($p['observations']) ?></td>
          <td class="text-end no-print"><?= $del('pesee', $p['id']) ?></td></tr>
      <?php endforeach; if (!$pesees): ?><tr><td colspan="9" class="text-muted text-center">Aucune pesée.</td></tr><?php endif; ?></tbody>
    </table></div>
  </div>

  <?php if ($a['sexe'] === 'F'): ?>
  <!-- ================= LAIT ================= -->
  <div class="tab-pane fade <?= $show('lait') ?>" id="lait">
    <div class="row g-2 mb-3 text-center">
      <div class="col-6 col-md-2 kpi"><div class="lbl">Jours de lactation</div><div class="val fs-5"><?= $joursLait ?? '—' ?></div></div>
      <div class="col-6 col-md-2 kpi"><div class="lbl">Moyenne</div><div class="val fs-5"><?= nfr($moyL) ?> L/j</div></div>
      <div class="col-6 col-md-2 kpi"><div class="lbl">Pic</div><div class="val fs-5"><?= nfr($pic) ?> L/j</div></div>
      <div class="col-6 col-md-2 kpi"><div class="lbl">Cumul lactation*</div><div class="val fs-5"><?= nfr($prodLactation, 0) ?> L</div></div>
      <div class="col-6 col-md-2 kpi"><div class="lbl">TB moyen</div><div class="val fs-5"><?= $tb ? nfr(array_sum($tb)/count($tb)) : '—' ?></div></div>
      <div class="col-6 col-md-2 kpi"><div class="lbl">TP moyen</div><div class="val fs-5"><?= $tp ? nfr(array_sum($tp)/count($tp)) : '—' ?></div></div>
    </div>
    <div class="row g-3">
      <div class="col-lg-7"><div class="chart-box"><canvas id="chartLait"></canvas></div>
        <p class="small text-muted mt-1">* Estimation depuis la dernière mise bas (<?= dfr($derniereMB) ?>) par la méthode des trapèzes entre contrôles.</p></div>
      <div class="col-lg-5">
        <form method="post" action="actions.php" class="border rounded p-3 bg-light">
          <?= csrf_field() ?><input type="hidden" name="op" value="add"><input type="hidden" name="type" value="lait"><input type="hidden" name="animal_id" value="<?= $id ?>">
          <h3 class="h6">Nouveau contrôle laitier</h3>
          <div class="row g-2">
            <div class="col-12"><label class="form-label small">Date</label><input type="date" name="date_controle" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-6"><label class="form-label small">Traite matin (L)</label><input type="number" step="0.1" min="0" name="traite_matin" class="form-control form-control-sm"></div>
            <div class="col-6"><label class="form-label small">Traite soir (L)</label><input type="number" step="0.1" min="0" name="traite_soir" class="form-control form-control-sm"></div>
            <div class="col-4"><label class="form-label small">TB (g/L)</label><input type="number" step="0.1" min="0" name="taux_butyreux" class="form-control form-control-sm"></div>
            <div class="col-4"><label class="form-label small">TP (g/L)</label><input type="number" step="0.1" min="0" name="taux_proteique" class="form-control form-control-sm"></div>
            <div class="col-4"><label class="form-label small">Cellules (×1000)</label><input type="number" min="0" name="cellules" class="form-control form-control-sm"></div>
            <div class="col-12"><input name="observations" class="form-control form-control-sm" placeholder="Observations"></div>
            <div class="col-12"><button class="btn btn-primary btn-sm w-100">Ajouter</button></div>
          </div>
        </form>
      </div>
    </div>
    <div class="table-responsive mt-3"><table class="table table-sm">
      <thead><tr><th>Date</th><th class="text-end">Matin</th><th class="text-end">Soir</th><th class="text-end">Total</th><th class="text-end">TB</th><th class="text-end">TP</th><th class="text-end">Cellules</th><th>Observations</th><th></th></tr></thead>
      <tbody><?php foreach (array_reverse($laits) as $c): ?>
        <tr><td><?= dfr($c['date_controle']) ?></td><td class="text-end"><?= nfr($c['traite_matin']) ?></td><td class="text-end"><?= nfr($c['traite_soir']) ?></td>
          <td class="text-end fw-semibold"><?= nfr($c['quantite_totale']) ?> L</td><td class="text-end"><?= nfr($c['taux_butyreux']) ?></td><td class="text-end"><?= nfr($c['taux_proteique']) ?></td>
          <td class="text-end"><?= $c['cellules'] === null ? '—' : '<span class="badge text-bg-'.($c['cellules'] >= SEUIL_CELLULES ? 'danger' : ($c['cellules'] >= 200 ? 'warning' : 'success')).'">'.$c['cellules'].'</span>' ?></td>
          <td class="small"><?= e($c['observations']) ?></td><td class="text-end no-print"><?= $del('lait', $c['id']) ?></td></tr>
      <?php endforeach; if (!$laits): ?><tr><td colspan="9" class="text-muted text-center">Aucun contrôle laitier.</td></tr><?php endif; ?></tbody>
    </table></div>
  </div>

  <!-- ================= REPRODUCTION ================= -->
  <div class="tab-pane fade <?= $show('repro') ?>" id="repro">
    <div class="row g-3">
      <div class="col-lg-7">
        <div class="row g-2 text-center mb-3">
          <div class="col-4 kpi"><div class="lbl">État</div><div class="val fs-6 pt-1"><?= e($etatRepro) ?></div></div>
          <div class="col-4 kpi"><div class="lbl">Mises bas</div><div class="val fs-5"><?= count($misesBas) ?></div></div>
          <div class="col-4 kpi"><div class="lbl">Intervalle moyen</div><div class="val fs-5"><?= $ivv ? $ivv . ' j' : '—' ?></div></div>
        </div>
        <div class="table-responsive"><table class="table table-sm">
          <thead><tr><th>Date</th><th>Événement</th><th>Détail</th><th>Mise bas prévue</th><th>Intervenant</th><th></th></tr></thead>
          <tbody><?php foreach ($repros as $r): ?>
            <tr><td><?= dfr($r['date_evenement']) ?></td><td><?= e(TYPES_REPRO[$r['type_evenement']]) ?></td>
              <td class="small"><?= e(trim(($r['male_num'] ? 'Mâle '.$r['male_num'].' ' : '') . ($r['semence'] ?? '') . ' ' .
                  ($r['resultat'] ? ucfirst($r['resultat']) : '') . ' ' . ($r['nb_petits'] ? $r['nb_petits'].' petit(s)' : '') . ' ' . ($r['observations'] ?? ''))) ?></td>
              <td><?= $r['date_mise_bas_prevue'] ? dfr($r['date_mise_bas_prevue']) : '' ?></td><td class="small"><?= e($r['intervenant']) ?></td>
              <td class="text-end no-print"><?= $del('repro', $r['id']) ?></td></tr>
          <?php endforeach; if (!$repros): ?><tr><td colspan="6" class="text-muted text-center">Aucun événement.</td></tr><?php endif; ?></tbody>
        </table></div>
      </div>
      <div class="col-lg-5">
        <form method="post" action="actions.php" class="border rounded p-3 bg-light">
          <?= csrf_field() ?><input type="hidden" name="op" value="add"><input type="hidden" name="type" value="repro"><input type="hidden" name="animal_id" value="<?= $id ?>">
          <h3 class="h6">Nouvel événement</h3>
          <div class="row g-2">
            <div class="col-6"><label class="form-label small">Type</label><select name="type_evenement" id="typeRepro" class="form-select form-select-sm">
              <?php foreach (TYPES_REPRO as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="col-6"><label class="form-label small">Date</label><input type="date" name="date_evenement" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-6 r r-saillie"><label class="form-label small">Mâle</label><select name="male_id" class="form-select form-select-sm"><option value="">—</option>
              <?php foreach ($males as $m): ?><option value="<?= $m['id'] ?>"><?= e($m['numero_identification'].' '.$m['nom']) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 r r-insemination"><label class="form-label small">Semence / taureau</label><input name="semence" class="form-control form-control-sm"></div>
            <div class="col-6 r r-diagnostic"><label class="form-label small">Résultat</label><select name="resultat" class="form-select form-select-sm"><option value="">—</option><option value="positif">Positif</option><option value="negatif">Négatif</option></select></div>
            <div class="col-6 r r-mise_bas"><label class="form-label small">Nombre de petits</label><input type="number" min="0" max="10" name="nb_petits" class="form-control form-control-sm" value="1"></div>
            <div class="col-6"><label class="form-label small">Intervenant</label><input name="intervenant" class="form-control form-control-sm"></div>
            <div class="col-12"><input name="observations" class="form-control form-control-sm" placeholder="Observations"></div>
            <div class="col-12 small text-muted r r-saillie r-insemination">La mise bas prévue est calculée automatiquement (<?= (int)$a['gestation_jours'] ?> j de gestation).</div>
            <div class="col-12"><button class="btn btn-primary btn-sm w-100">Ajouter</button></div>
          </div>
        </form>
        <p class="small text-muted mt-2 mb-0">Après une mise bas, pensez à créer la fiche du petit avec cette femelle comme mère.
          <a href="animal_form.php?mere=<?= $id ?>" class="no-print">Créer le petit →</a></p>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ================= SANTÉ ================= -->
  <div class="tab-pane fade <?= $show('sante') ?>" id="sante">
    <div class="row g-3">
      <div class="col-lg-8"><div class="table-responsive"><table class="table table-sm">
        <thead><tr><th>Date</th><th>Type</th><th>Diagnostic</th><th>Produit / dose / voie</th><th>Attente lait / viande</th><th class="text-end">Coût</th><th>Rappel</th><th></th></tr></thead>
        <tbody><?php foreach ($soins as $so): ?>
          <tr><td><?= dfr($so['date_intervention']) ?></td><td><?= e(TYPES_SANTE[$so['type']]) ?></td><td class="small"><?= e($so['diagnostic']) ?><?= $so['veterinaire'] ? '<br><span class="text-muted">'.e($so['veterinaire']).'</span>' : '' ?></td>
            <td class="small"><?= e(implode(' · ', array_filter([$so['produit'], $so['dose'], $so['voie']]))) ?></td>
            <td class="small"><?= $so['delai_attente_lait'] ?> j / <?= $so['delai_attente_viande'] ?> j</td>
            <td class="text-end"><?= $so['cout'] ? nfr($so['cout'], 2) : '' ?></td>
            <td><?= $so['date_rappel'] ? '<span class="'.($so['date_rappel'] < date('Y-m-d') ? 'text-danger' : '').'">'.dfr($so['date_rappel']).'</span>' : '' ?></td>
            <td class="text-end no-print"><?= $del('sante', $so['id']) ?></td></tr>
        <?php endforeach; if (!$soins): ?><tr><td colspan="8" class="text-muted text-center">Aucune intervention.</td></tr><?php endif; ?></tbody>
        <?php if ($soins): ?><tfoot><tr><th colspan="5" class="text-end">Coût total</th><th class="text-end"><?= nfr(array_sum(array_column($soins, 'cout')), 2) ?></th><th colspan="2"></th></tr></tfoot><?php endif; ?>
      </table></div></div>
      <div class="col-lg-4">
        <form method="post" action="actions.php" class="border rounded p-3 bg-light">
          <?= csrf_field() ?><input type="hidden" name="op" value="add"><input type="hidden" name="type" value="sante"><input type="hidden" name="animal_id" value="<?= $id ?>">
          <h3 class="h6">Nouvelle intervention</h3>
          <div class="row g-2">
            <div class="col-6"><label class="form-label small">Date</label><input type="date" name="date_intervention" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-6"><label class="form-label small">Type</label><select name="type_sante" class="form-select form-select-sm"><?php foreach (TYPES_SANTE as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="col-12"><input name="diagnostic" class="form-control form-control-sm" placeholder="Diagnostic / motif"></div>
            <div class="col-12"><input name="produit" class="form-control form-control-sm" placeholder="Produit"></div>
            <div class="col-6"><input name="dose" class="form-control form-control-sm" placeholder="Dose"></div>
            <div class="col-6"><input name="voie" class="form-control form-control-sm" placeholder="Voie (IM, SC…)"></div>
            <div class="col-6"><label class="form-label small">Attente lait (j)</label><input type="number" min="0" name="delai_attente_lait" class="form-control form-control-sm" value="0"></div>
            <div class="col-6"><label class="form-label small">Attente viande (j)</label><input type="number" min="0" name="delai_attente_viande" class="form-control form-control-sm" value="0"></div>
            <div class="col-6"><label class="form-label small">Coût</label><input type="number" step="0.01" min="0" name="cout" class="form-control form-control-sm"></div>
            <div class="col-6"><label class="form-label small">Date de rappel</label><input type="date" name="date_rappel" class="form-control form-control-sm"></div>
            <div class="col-12"><input name="veterinaire" class="form-control form-control-sm" placeholder="Vétérinaire"></div>
            <div class="col-12"><button class="btn btn-primary btn-sm w-100">Ajouter</button></div>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ================= MOUVEMENTS ================= -->
  <div class="tab-pane fade <?= $show('mvt') ?>" id="mvt">
    <div class="row g-3">
      <div class="col-lg-8"><table class="table table-sm">
        <thead><tr><th>Date</th><th>Type</th><th>De</th><th>Vers</th><th>Motif</th><th class="text-end">Montant</th><th></th></tr></thead>
        <tbody><?php foreach ($mvts as $m): ?>
          <tr><td><?= dfr($m['date_mouvement']) ?></td><td><?= ucfirst(str_replace('entree','entrée',$m['type'])) ?></td><td><?= e($m['lot_o'] ?? '—') ?></td><td><?= e($m['lot_d'] ?? '—') ?></td>
            <td class="small"><?= e($m['motif']) ?></td><td class="text-end"><?= $m['montant'] ? nfr($m['montant'], 2) : '' ?></td><td class="text-end no-print"><?= $del('mvt', $m['id']) ?></td></tr>
        <?php endforeach; if (!$mvts): ?><tr><td colspan="7" class="text-muted text-center">Aucun mouvement.</td></tr><?php endif; ?></tbody>
      </table></div>
      <div class="col-lg-4">
        <form method="post" action="actions.php" class="border rounded p-3 bg-light">
          <?= csrf_field() ?><input type="hidden" name="op" value="add"><input type="hidden" name="type" value="mvt"><input type="hidden" name="animal_id" value="<?= $id ?>">
          <h3 class="h6">Transfert de lot</h3>
          <div class="row g-2">
            <div class="col-6"><input type="date" name="date_mouvement" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-6"><select name="lot_destination" class="form-select form-select-sm" required><option value="">Vers…</option>
              <?php foreach (lots($pdo) as $x) if ($x['id'] != $a['lot_id']): ?><option value="<?= $x['id'] ?>"><?= e($x['nom']) ?></option><?php endif; ?></select></div>
            <div class="col-12"><input name="motif" class="form-control form-control-sm" placeholder="Motif (sevrage, mise à l'herbe…)"></div>
            <div class="col-12"><button class="btn btn-primary btn-sm w-100">Transférer</button></div>
          </div>
        </form>
        <p class="small text-muted mt-2">Pour une vente, une mort ou une réforme, changez le statut dans <a href="animal_form.php?id=<?= $id ?>">Modifier</a> : la sortie est enregistrée automatiquement.</p>
      </div>
    </div>
  </div>
</div>
<?php
$jsPoids = json_encode(array_map(fn($p) => ['x' => $p['date_pesee'], 'y' => (float)$p['poids']], $pesees));
$jsLait  = json_encode(array_map(fn($c) => ['d' => dfr($c['date_controle']), 'q' => (float)$c['quantite_totale'], 'tb' => $c['taux_butyreux'] !== null ? (float)$c['taux_butyreux'] : null, 'tp' => $c['taux_proteique'] !== null ? (float)$c['taux_proteique'] : null], $laits));
$scripts = <<<JS
<script>
const P = $jsPoids, L = $jsLait;
if (document.getElementById('chartPoids') && P.length) new Chart(chartPoids, {type:'line',
  data:{labels:P.map(p=>new Date(p.x).toLocaleDateString('fr-FR')),datasets:[{label:'Poids (kg)',data:P.map(p=>p.y),borderColor:'#1f5130',backgroundColor:'rgba(46,125,79,.12)',fill:true,tension:.3}]},
  options:{maintainAspectRatio:false,plugins:{legend:{display:false}}}});
if (document.getElementById('chartLait') && L.length) new Chart(chartLait, {data:{labels:L.map(c=>c.d),datasets:[
  {type:'bar',label:'Lait (L/j)',data:L.map(c=>c.q),backgroundColor:'#7fb3d5',yAxisID:'y'},
  {type:'line',label:'TB (g/L)',data:L.map(c=>c.tb),borderColor:'#e0a100',yAxisID:'y2',tension:.3},
  {type:'line',label:'TP (g/L)',data:L.map(c=>c.tp),borderColor:'#b03a2e',yAxisID:'y2',tension:.3}]},
  options:{maintainAspectRatio:false,scales:{y:{beginAtZero:true,title:{display:true,text:'L/jour'}},y2:{position:'right',grid:{drawOnChartArea:false},title:{display:true,text:'g/L'}}}}});
// Champs du formulaire reproduction selon le type d'événement
const tr = document.getElementById('typeRepro');
if (tr) { const f = () => document.querySelectorAll('.r').forEach(el => el.style.display = el.classList.contains('r-'+tr.value) ? '' : 'none'); tr.onchange = f; f(); }
// Garde l'onglet actif dans l'URL
document.querySelectorAll('[data-bs-toggle=tab]').forEach(b => b.addEventListener('shown.bs.tab', e => {
  const u = new URL(location); u.searchParams.set('tab', e.target.dataset.bsTarget.slice(1)); history.replaceState(null,'',u); }));
</script>
JS;
require 'includes/footer.php';
