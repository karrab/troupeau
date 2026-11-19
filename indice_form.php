<?php
require 'includes/functions.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$vide = ['id' => 0, 'code' => '', 'nom' => '', 'description' => '', 'formule' => '', 'unite' => '', 'decimales' => 1, 'espece_id' => null,
         'sexe' => null, 'niveaux' => 'animal,lot,espece,troupeau', 'agregation' => 'moyenne', 'ordre' => 0, 'actif' => 1];
$i = $vide; $regles = [];
if ($id) {
    $st = $pdo->prepare('SELECT * FROM indices WHERE id=?'); $st->execute([$id]);
    $i = $st->fetch() ?: redirect('parametres.php#indices');
    $st = $pdo->prepare('SELECT * FROM indices_interpretations WHERE indice_id=? ORDER BY ordre, id'); $st->execute([$id]);
    $regles = $st->fetchAll();
}
$titre = $id ? 'Indice ' . $i['nom'] : 'Nouvel indice';
$erreurs = []; $apercu = null;
$num = fn($v) => ($v = str_replace([',', ' '], ['.', ''], trim((string)$v))) === '' ? null : (is_numeric($v) ? (float)$v : false);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $niv = array_values(array_intersect(array_keys(NIVEAUX_INDICES), (array)($_POST['niveaux'] ?? [])));
    $i = [
        'id' => $id,
        'code' => strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', (string)post('code'))),
        'nom' => post('nom'), 'description' => post('description'), 'formule' => trim((string)post('formule')),
        'unite' => post('unite'), 'decimales' => max(0, min(4, (int)post('decimales'))),
        'espece_id' => post('espece_id') ? (int)post('espece_id') : null,
        'sexe' => in_array(post('sexe'), ['M', 'F'], true) ? post('sexe') : null,
        'niveaux' => implode(',', $niv),
        'agregation' => isset(AGREGATIONS[post('agregation')]) ? post('agregation') : 'moyenne',
        'ordre' => (int)post('ordre'), 'actif' => isset($_POST['actif']) ? 1 : 0,
    ];
    // Règles d'interprétation
    $regles = [];
    foreach ((array)($_POST['r_libelle'] ?? []) as $k => $lib) {
        $lib = trim((string)$lib);
        $mn = $num($_POST['r_min'][$k] ?? ''); $mx = $num($_POST['r_max'][$k] ?? '');
        if ($lib === '' && $mn === null && $mx === null) continue;
        if ($lib === '') $erreurs[] = 'Chaque seuil d\'interprétation doit avoir un libellé.';
        if ($mn === false || $mx === false) $erreurs[] = "Seuil « $lib » : bornes numériques attendues.";
        elseif ($mn !== null && $mx !== null && $mn >= $mx) $erreurs[] = "Seuil « $lib » : le minimum doit être inférieur au maximum.";
        $c = $_POST['r_couleur'][$k] ?? 'secondary';
        $regles[] = ['borne_min' => $mn === false ? null : $mn, 'borne_max' => $mx === false ? null : $mx, 'libelle' => $lib,
                     'couleur' => isset(COULEURS_INDICES[$c]) ? $c : 'secondary'];
    }
    if (!preg_match('/^[A-Z][A-Z0-9_]{1,29}$/', $i['code'])) $erreurs[] = 'Code : 2 à 30 caractères (lettres, chiffres, _), commençant par une lettre.';
    if (!$i['nom']) $erreurs[] = 'Le nom est obligatoire.';
    if (!$niv) $erreurs[] = 'Choisissez au moins un niveau de calcul.';
    try { $f = new Formule($i['formule']); }
    catch (InvalidArgumentException $ex) { $erreurs[] = 'Formule : ' . $ex->getMessage(); $f = null; }
    $dup = $pdo->prepare('SELECT id FROM indices WHERE code=? AND id<>?'); $dup->execute([$i['code'], $id]);
    if ($dup->fetch()) $erreurs[] = 'Ce code est déjà utilisé par un autre indice.';

    // ---------- Test de la formule sans enregistrer ----------
    if (($_POST['op'] ?? '') === 'tester' && $f) {
        $erreurs = array_filter($erreurs, fn($m) => str_starts_with($m, 'Formule'));
        $ya = (int)post('annee_test') ?: (int)date('Y');
        $data = variables_annee($pdo, $ya);
        $lignes = [];
        foreach ($data as $aid => $d) {
            if ($i['espece_id'] && $d['animal']['espece_id'] != $i['espece_id']) continue;
            if ($i['sexe'] && $d['animal']['sexe'] !== $i['sexe']) continue;
            $val = $f->evaluer($d['vars']);
            $lignes[$aid] = ['a' => $d['animal'], 'val' => $val, 'interp' => interpreter($regles, $val), 'vars' => array_intersect_key($d['vars'], array_flip($f->variables))];
        }
        $vals = array_filter(array_column($lignes, 'val'), fn($v) => $v !== null);
        $groupe = $lignes ? agreger($i, $f, array_keys($lignes), array_map(fn($l) => $l['val'], $lignes), $data)[0] : null;
        $apercu = compact('ya', 'lignes', 'vals', 'groupe');
    }
    // ---------- Enregistrement ----------
    elseif (!$erreurs) {
        $cols = ['code', 'nom', 'description', 'formule', 'unite', 'decimales', 'espece_id', 'sexe', 'niveaux', 'agregation', 'ordre', 'actif'];
        $vals = array_map(fn($c) => $i[$c], $cols);
        $pdo->beginTransaction();
        if ($id) {
            $pdo->prepare('UPDATE indices SET ' . implode(',', array_map(fn($c) => "$c=?", $cols)) . ', updated_at=NOW() WHERE id=?')->execute([...$vals, $id]);
        } else {
            $pdo->prepare('INSERT INTO indices (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute($vals);
            $id = (int)$pdo->lastInsertId();
        }
        $pdo->prepare('DELETE FROM indices_interpretations WHERE indice_id=?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO indices_interpretations (indice_id, borne_min, borne_max, libelle, couleur, ordre) VALUES (?,?,?,?,?,?)');
        foreach ($regles as $k => $r) $ins->execute([$id, $r['borne_min'], $r['borne_max'], $r['libelle'], $r['couleur'], $k + 1]);
        if (!$i['actif']) $pdo->prepare('DELETE FROM indices_resultats WHERE indice_id=?')->execute([$id]);
        $pdo->commit();
        try {
            $n = $i['actif'] ? recalculer_indices($pdo, $id) : 0;
            flash('Indice enregistré.' . ($i['actif'] ? " $n résultats recalculés avec la nouvelle formule sur toutes les années." : ' Indice désactivé : résultats supprimés.'));
        } catch (Throwable $ex) {
            flash('Indice enregistré, mais le recalcul a échoué : ' . $ex->getMessage(), 'warning');
        }
        redirect('parametres.php#indices');
    }
}
$niveauxSel = explode(',', (string)$i['niveaux']);
while (count($regles) < 3) $regles[] = ['borne_min' => null, 'borne_max' => null, 'libelle' => '', 'couleur' => 'secondary'];
require 'includes/header.php';
?>
<nav class="small mb-2"><a href="parametres.php#indices">Paramètres</a> › Indices</nav>
<h1 class="h3 mb-3"><?= e($titre) ?></h1>
<?php if ($erreurs): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach (array_unique($erreurs) as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<form method="post" id="formIndice"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card p-3 mb-3">
      <div class="row g-2">
        <div class="col-md-3"><label class="form-label small">Code *</label><input name="code" value="<?= e($i['code']) ?>" class="form-control text-uppercase" required maxlength="30"></div>
        <div class="col-md-6"><label class="form-label small">Nom *</label><input name="nom" value="<?= e($i['nom']) ?>" class="form-control" required></div>
        <div class="col-md-3"><label class="form-label small">Unité</label><input name="unite" value="<?= e($i['unite']) ?>" class="form-control" placeholder="L/j, %, kg…"></div>
        <div class="col-12"><label class="form-label small">Description</label><input name="description" value="<?= e($i['description']) ?>" class="form-control"></div>
        <div class="col-12">
          <label class="form-label small">Formule *</label>
          <textarea name="formule" id="formule" rows="3" class="form-control font-monospace" required placeholder="ex. lait_cumul * (tb_moyen + tp_moyen) / 1000"><?= e($i['formule']) ?></textarea>
          <div class="form-text">Opérateurs <code>+ - * / % ^ ( )</code>, comparaisons <code>&lt; &gt; &lt;= &gt;= == !=</code>, <code>et ou non</code>.
            Fonctions <code>min(a,b,…)</code> <code>max(a,b,…)</code> <code>abs(x)</code> <code>arrondi(x,n)</code> <code>racine(x)</code> <code>log(x)</code>
            <code>si(condition, alors, sinon)</code> <code>si(condition, valeur)</code> <code>sinon(x, valeur_si_absent)</code>. Le point est le séparateur décimal.
            Si une donnée manque (ou division par zéro), l'indice n'est pas calculé pour l'animal.</div>
        </div>
      </div>
    </div>

    <div class="card p-3 mb-3">
      <h2 class="h6">Interprétation automatique</h2>
      <p class="small text-muted mb-2">Le premier seuil qui correspond est retenu. Minimum inclus, maximum exclu ; laissez vide pour « sans limite ».</p>
      <table class="table table-sm mb-2"><thead><tr><th>Minimum (≥)</th><th>Maximum (&lt;)</th><th>Libellé</th><th>Couleur</th><th></th></tr></thead>
        <tbody id="regles"><?php foreach ($regles as $r): ?>
          <tr><td><input name="r_min[]" value="<?= $r['borne_min'] === null ? '' : e(fmt_borne($r['borne_min'])) ?>" class="form-control form-control-sm" inputmode="decimal"></td>
            <td><input name="r_max[]" value="<?= $r['borne_max'] === null ? '' : e(fmt_borne($r['borne_max'])) ?>" class="form-control form-control-sm" inputmode="decimal"></td>
            <td><input name="r_libelle[]" value="<?= e($r['libelle']) ?>" class="form-control form-control-sm" placeholder="Bon, à surveiller…"></td>
            <td><select name="r_couleur[]" class="form-select form-select-sm"><?php foreach (COULEURS_INDICES as $k => $l): ?><option value="<?= $k ?>" <?= $r['couleur'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></td>
            <td><button type="button" class="btn btn-sm btn-link text-danger p-0 suppr"><i class="bi bi-x-circle"></i></button></td></tr>
        <?php endforeach; ?></tbody></table>
      <button type="button" id="ajoutRegle" class="btn btn-sm btn-outline-secondary align-self-start"><i class="bi bi-plus"></i> Ajouter un seuil</button>
    </div>

    <div class="card p-3 mb-3">
      <h2 class="h6">Champ d'application</h2>
      <div class="row g-2">
        <div class="col-md-4"><label class="form-label small">Espèce</label><select name="espece_id" class="form-select form-select-sm"><option value="">Toutes</option>
          <?php foreach (especes($pdo) as $x): ?><option value="<?= $x['id'] ?>" <?= $x['id'] == $i['espece_id'] ? 'selected' : '' ?>><?= e($x['nom']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label small">Sexe</label><select name="sexe" class="form-select form-select-sm">
          <option value="">Les deux</option><option value="F" <?= $i['sexe'] === 'F' ? 'selected' : '' ?>>Femelles</option><option value="M" <?= $i['sexe'] === 'M' ? 'selected' : '' ?>>Mâles</option></select></div>
        <div class="col-md-2"><label class="form-label small">Décimales</label><input type="number" min="0" max="4" name="decimales" value="<?= (int)$i['decimales'] ?>" class="form-control form-control-sm"></div>
        <div class="col-md-2"><label class="form-label small">Ordre</label><input type="number" name="ordre" value="<?= (int)$i['ordre'] ?>" class="form-control form-control-sm"></div>
        <div class="col-md-6"><label class="form-label small d-block">Calculer et enregistrer par</label>
          <?php foreach (NIVEAUX_INDICES as $k => $l): ?><div class="form-check form-check-inline">
            <input class="form-check-input" type="checkbox" name="niveaux[]" value="<?= $k ?>" id="n_<?= $k ?>" <?= in_array($k, $niveauxSel, true) ? 'checked' : '' ?>>
            <label class="form-check-label small" for="n_<?= $k ?>"><?= $l ?></label></div><?php endforeach; ?></div>
        <div class="col-md-6"><label class="form-label small">Calcul pour les lots, espèces et troupeau</label><select name="agregation" class="form-select form-select-sm">
          <?php foreach (AGREGATIONS as $k => $l): ?><option value="<?= $k ?>" <?= $i['agregation'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
          <div class="form-text">« Ratio » : les variables sont totalisées sur le groupe puis la formule est appliquée (ex. taux de gestation du lot = Σ diagnostics positifs / Σ diagnostics réalisés, et non la moyenne des taux individuels).</div></div>
        <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="actif" id="actif" <?= $i['actif'] ? 'checked' : '' ?>><label class="form-check-label" for="actif">Indice actif</label></div></div>
      </div>
    </div>

    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
      <button name="op" value="enregistrer" class="btn btn-primary"><i class="bi bi-check-lg"></i> Enregistrer et recalculer</button>
      <span class="ms-2 small text-muted">Tester sur l'année</span>
      <select name="annee_test" class="form-select form-select-sm w-auto"><?php foreach (annees_indices($pdo) as $y): ?><option <?= (int)($apercu['ya'] ?? 0) === $y ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?></select>
      <button name="op" value="tester" class="btn btn-outline-primary" formnovalidate><i class="bi bi-play"></i> Tester la formule</button>
      <a href="parametres.php#indices" class="btn btn-link">Annuler</a>
    </div>

    <?php if ($apercu): ?>
    <div class="card mb-3"><div class="card-header bg-white fw-semibold">Aperçu <?= $apercu['ya'] ?> (non enregistré) ·
      <?= count($apercu['vals']) ?> / <?= count($apercu['lignes']) ?> animaux calculés ·
      Troupeau : <?= $apercu['groupe'] === null ? '—' : nfr($apercu['groupe'], (int)$i['decimales']) . ' ' . e($i['unite']) ?>
      <?php [$gl, $gc] = interpreter($regles, $apercu['groupe']); if ($gl): ?><span class="badge text-bg-<?= e($gc) ?>"><?= e($gl) ?></span><?php endif; ?></div>
      <div class="table-responsive" style="max-height:360px"><table class="table table-sm mb-0 small">
        <thead><tr><th>Animal</th><th>Variables</th><th class="text-end">Résultat</th><th>Interprétation</th></tr></thead>
        <tbody><?php foreach ($apercu['lignes'] as $aid => $l): ?><tr>
          <td><a href="animal.php?id=<?= $aid ?>"><?= e($l['a']['numero_identification'] . ' ' . $l['a']['nom']) ?></a></td>
          <td class="text-muted"><?= e(implode(' · ', array_map(fn($k, $v) => "$k = " . ($v === null ? 'absent' : fmt_borne($v)), array_keys($l['vars']), $l['vars']))) ?></td>
          <td class="text-end fw-semibold"><?= $l['val'] === null ? '<span class="text-muted">non calculable</span>' : nfr($l['val'], (int)$i['decimales']) ?></td>
          <td><?= $l['interp'][0] ? '<span class="badge text-bg-' . e($l['interp'][1]) . '">' . e($l['interp'][0]) . '</span>' : '' ?></td></tr><?php endforeach; ?>
        <?php if (!$apercu['lignes']): ?><tr><td colspan="4" class="text-muted text-center">Aucun animal concerné cette année.</td></tr><?php endif; ?></tbody>
      </table></div></div>
    <?php endif; ?>
  </div>

  <div class="col-xl-4">
    <div class="card p-3 position-sticky" style="top:1rem">
      <h2 class="h6">Variables disponibles <small class="text-muted fw-normal">(cliquer pour insérer)</small></h2>
      <div style="max-height:70vh;overflow:auto">
      <?php foreach (VARIABLES_INDICES as $groupe => $vars): ?>
        <div class="small fw-semibold text-muted mt-2"><?= e($groupe) ?></div>
        <?php foreach ($vars as $k => $desc): ?>
          <div class="d-flex gap-2 align-items-start py-1 border-bottom"><button type="button" class="btn btn-sm btn-outline-primary py-0 font-monospace ins" data-v="<?= $k ?>"><?= $k ?></button><span class="small text-muted"><?= e($desc) ?></span></div>
        <?php endforeach; ?>
      <?php endforeach; ?></div>
    </div>
  </div>
</div>
</form>
<?php
$scripts = <<<'JS'
<script>
const fx = document.getElementById('formule');
document.querySelectorAll('.ins').forEach(b => b.onclick = () => {
  const s = fx.selectionStart ?? fx.value.length, e = fx.selectionEnd ?? s, v = b.dataset.v;
  fx.value = fx.value.slice(0, s) + v + fx.value.slice(e); fx.focus(); fx.selectionStart = fx.selectionEnd = s + v.length;
});
const tb = document.getElementById('regles');
document.getElementById('ajoutRegle').onclick = () => {
  const tr = tb.rows[0].cloneNode(true);
  tr.querySelectorAll('input').forEach(i => i.value = ''); tr.querySelector('select').value = 'secondary'; tb.appendChild(tr);
};
tb.addEventListener('click', ev => { const b = ev.target.closest('.suppr'); if (!b) return;
  const tr = b.closest('tr'); if (tb.rows.length > 1) tr.remove(); else tr.querySelectorAll('input').forEach(i => i.value = ''); });
</script>
JS;
require 'includes/footer.php';
