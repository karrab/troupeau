<?php
require 'includes/functions.php';
exiger('indices.configurer');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$i = ['id' => 0, 'code' => '', 'nom' => '', 'famille' => $_GET['famille'] ?? 'reproduction', 'description' => '', 'formule' => '', 'unite' => '', 'decimales' => 1,
      'periode' => 'campagne', 'niveaux' => 'animal,lot,espece,troupeau', 'agregation' => 'ratio', 'sens' => 'haut', 'interpretation' => '',
      'espece_id' => null, 'sexe' => null, 'ordre' => 0, 'actif' => 1];
$regles = [];
if ($id) {
    $st = $pdo->prepare('SELECT * FROM indices WHERE id=?'); $st->execute([$id]);
    $i = $st->fetch() ?: redirect('parametres.php#indices');
    $st = $pdo->prepare('SELECT * FROM indices_interpretations WHERE indice_id=? ORDER BY espece_id IS NULL, espece_id, ordre, id'); $st->execute([$id]);
    $regles = $st->fetchAll();
}
$ancienCode = $i['code'];
$tous = $pdo->query('SELECT * FROM indices ORDER BY ordre, code')->fetchAll();
$especes = especes($pdo);
$titre = $id ? $i['code'] . ' · ' . $i['nom'] : 'Nouvel indice';
$erreurs = []; $apercu = null;
$num = fn($v) => ($v = str_replace([',', ' '], ['.', ''], trim((string)$v))) === '' ? null : (is_numeric($v) ? (float)$v : false);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $niv = array_values(array_intersect(array_keys(NIVEAUX_INDICES), (array)($_POST['niveaux'] ?? [])));
    $choix = fn(string $k, array $liste, string $defaut) => isset($liste[post($k)]) ? post($k) : $defaut;
    $i = [
        'id' => $id, 'code' => strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string)post('code'))),
        'nom' => post('nom'), 'famille' => $choix('famille', FAMILLES_INDICES, 'reproduction'),
        'description' => post('description'), 'formule' => trim((string)post('formule')),
        'unite' => post('unite'), 'decimales' => max(0, min(4, (int)post('decimales'))),
        'periode' => $choix('periode', PERIODES_INDICES, 'campagne'), 'niveaux' => implode(',', $niv),
        'agregation' => $choix('agregation', AGREGATIONS, 'ratio'), 'sens' => $choix('sens', SENS_INDICES, 'haut'),
        'interpretation' => post('interpretation'),
        'espece_id' => post('espece_id') ? (int)post('espece_id') : null,
        'sexe' => in_array(post('sexe'), ['M', 'F'], true) ? post('sexe') : null,
        'ordre' => (int)post('ordre'), 'actif' => isset($_POST['actif']) ? 1 : 0,
    ];
    // Seuils d'interprétation
    $regles = [];
    foreach ((array)($_POST['r_libelle'] ?? []) as $k => $lib) {
        $lib = trim((string)$lib);
        $mn = $num($_POST['r_min'][$k] ?? ''); $mx = $num($_POST['r_max'][$k] ?? '');
        if ($lib === '' && $mn === null && $mx === null) continue;
        if ($lib === '') $erreurs[] = 'Chaque seuil d\'interprétation doit avoir un libellé.';
        if ($mn === false || $mx === false) $erreurs[] = "Seuil « $lib » : bornes numériques attendues.";
        elseif ($mn !== null && $mx !== null && $mn >= $mx) $erreurs[] = "Seuil « $lib » : le minimum doit être inférieur au maximum.";
        $c = $_POST['r_couleur'][$k] ?? 'secondary';
        $esp = (int)($_POST['r_espece'][$k] ?? 0);
        $regles[] = ['espece_id' => $esp ?: null, 'borne_min' => $mn === false ? null : $mn, 'borne_max' => $mx === false ? null : $mx, 'libelle' => $lib,
                     'couleur' => isset(COULEURS_INDICES[$c]) ? $c : 'secondary'];
    }
    if (!preg_match('/^[A-Z][A-Z0-9_]{1,9}$/', $i['code'])) $erreurs[] = 'Code : 2 à 10 lettres ou chiffres commençant par une lettre (ex. R11).';
    if (!$i['nom']) $erreurs[] = 'Le nom est obligatoire.';
    if (!$niv) $erreurs[] = 'Choisissez au moins un niveau d\'enregistrement.';
    if (in_array(strtolower($i['code']), array_map('strtolower', array_keys(variables_periode($i['periode']))), true)) $erreurs[] = 'Le code ne peut pas être un nom de variable.';
    foreach ($tous as $x) if ($x['code'] === $i['code'] && (int)$x['id'] !== $id) $erreurs[] = 'Ce code est déjà utilisé par l\'indice « ' . $x['nom'] . ' ».';
    // Le code a changé alors que d'autres formules l'utilisent ?
    if ($id && $ancienCode !== $i['code']) foreach ($tous as $x)
        if ((int)$x['id'] !== $id && preg_match('/\b' . preg_quote($ancienCode, '/') . '\b/i', $x['formule'])) $erreurs[] = "Le code $ancienCode est utilisé dans la formule de {$x['code']} : modifiez-la d'abord.";
    // Formule, références et dépendances circulaires
    $liste = array_values(array_filter($tous, fn($x) => (int)$x['id'] !== $id));
    $liste[] = $i + ['id' => $id ?: -1];
    try {
        $f = formule_indice($i, $liste);
        [, $errOrdre] = ordonner_indices($liste);
        if (isset($errOrdre[$i['code']])) throw new InvalidArgumentException($errOrdre[$i['code']]);
    } catch (InvalidArgumentException $ex) { $erreurs[] = 'Formule : ' . $ex->getMessage(); $f = null; }

    // ---------- Test sans enregistrer ----------
    if (($_POST['op'] ?? '') === 'tester' && $f) {
        $erreurs = array_values(array_filter($erreurs, fn($m) => str_starts_with($m, 'Formule')));
        $ct = (int)post('campagne_test') ?: campagne_de(date('Y-m-d'));
        $liste[count($liste) - 1]['actif'] = 1; // l'aperçu fonctionne même pour un indice désactivé
        $regl = interpretations_indices($pdo);
        $regl[$id ?: -1] = array_map(fn($r) => $r + ['ordre' => 0], $regles);
        $donnees = donnees_campagne($pdo, $ct);
        $res = array_values(array_filter(calculer_campagne($pdo, $ct, $liste, $regl, $donnees), fn($l) => $l['ind']['code'] === $i['code']));
        $unites = array_filter($donnees[$i['periode']], fn($x) => (!$i['espece_id'] || $x['animal']['espece_id'] == $i['espece_id']) && (!$i['sexe'] || $x['animal']['sexe'] === $i['sexe']));
        $apercu = ['c' => $ct, 'res' => $res, 'unites' => $unites];
    }
    // ---------- Enregistrement ----------
    elseif (!$erreurs) {
        $cols = ['code', 'nom', 'famille', 'description', 'formule', 'unite', 'decimales', 'periode', 'niveaux', 'agregation', 'sens', 'interpretation', 'espece_id', 'sexe', 'ordre', 'actif'];
        $vals = array_map(fn($c) => $i[$c], $cols);
        $pdo->beginTransaction();
        if ($id) {
            $pdo->prepare('UPDATE indices SET ' . implode(',', array_map(fn($c) => "$c=?", $cols)) . ', updated_at=NOW() WHERE id=?')->execute([...$vals, $id]);
        } else {
            $pdo->prepare('INSERT INTO indices (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute($vals);
            $id = (int)$pdo->lastInsertId();
        }
        $pdo->prepare('DELETE FROM indices_interpretations WHERE indice_id=?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO indices_interpretations (indice_id, espece_id, borne_min, borne_max, libelle, couleur, ordre) VALUES (?,?,?,?,?,?,?)');
        foreach ($regles as $k => $r) $ins->execute([$id, $r['espece_id'], $r['borne_min'], $r['borne_max'], $r['libelle'], $r['couleur'], $k + 1]);
        $pdo->commit();
        try {
            $n = recalculer_indices($pdo);
            flash("Indice {$i['code']} enregistré. Tous les indices ont été recalculés et enregistrés ($n résultats).");
        } catch (Throwable $ex) {
            flash('Indice enregistré, mais le recalcul a échoué : ' . $ex->getMessage(), 'warning');
        }
        redirect('parametres.php#indices');
    }
}
$niveauxSel = explode(',', (string)$i['niveaux']);
while (count($regles) < 3) $regles[] = ['espece_id' => null, 'borne_min' => null, 'borne_max' => null, 'libelle' => '', 'couleur' => 'secondary'];
$especesNoms = array_column($especes, 'nom', 'id');
$lotsNoms = array_column(lots($pdo), 'nom', 'id');
$sel = fn($a, $b) => (string)$a === (string)$b ? 'selected' : '';
require 'includes/header.php';
?>
<nav class="small mb-2"><a href="parametres.php#indices">Paramètres</a> › Indices</nav>
<h1 class="h3 mb-3"><?= e($titre) ?></h1>
<?php if ($erreurs): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach (array_unique($erreurs) as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card p-3 mb-3">
      <div class="row g-2">
        <div class="col-md-2"><label class="form-label small">Code *</label><input name="code" value="<?= e($i['code']) ?>" class="form-control text-uppercase font-monospace" required maxlength="10" placeholder="R11"></div>
        <div class="col-md-6"><label class="form-label small">Indice *</label><input name="nom" value="<?= e($i['nom']) ?>" class="form-control" required></div>
        <div class="col-md-4"><label class="form-label small">Famille</label><select name="famille" class="form-select">
          <?php foreach (FAMILLES_INDICES as $k => [$l, $lettre]): ?><option value="<?= $k ?>" <?= $sel($i['famille'], $k) ?>><?= $lettre ?> · <?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-8"><label class="form-label small">Mode de calcul (texte)</label><input name="description" value="<?= e($i['description']) ?>" class="form-control" placeholder="(Femelles gestantes ÷ femelles saillies) × 100"></div>
        <div class="col-md-2"><label class="form-label small">Unité</label><input name="unite" value="<?= e($i['unite']) ?>" class="form-control" placeholder="%, kg…"></div>
        <div class="col-md-2"><label class="form-label small">Décimales</label><input type="number" min="0" max="4" name="decimales" value="<?= (int)$i['decimales'] ?>" class="form-control"></div>
        <div class="col-md-4"><label class="form-label small">Période de calcul</label><select name="periode" id="periode" class="form-select">
          <?php foreach (PERIODES_INDICES as $k => $l): ?><option value="<?= $k ?>" <?= $sel($i['periode'], $k) ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="col-12">
          <label class="form-label small">Formule *</label>
          <textarea name="formule" id="formule" rows="3" class="form-control font-monospace" required placeholder="gestante / saillie_diag * 100"><?= e($i['formule']) ?></textarea>
          <div class="form-text">Variables de base et <strong>codes d'autres indices</strong> de même période (ex. <code>L02 / L03</code>).
            Opérateurs <code>+ - * / ÷ × % ^ ( )</code>, comparaisons <code>&lt; &gt; &lt;= &gt;= == !=</code>, <code>et ou non</code>.
            Fonctions <code>si(condition, alors[, sinon])</code> <code>sinon(x, défaut)</code> <code>min</code> <code>max</code> <code>moyenne</code>
            <code>moyenne_ponderee(v1, p1, v2, p2…)</code> <code>note(x, valeur_0, valeur_100)</code> <code>arrondi(x, n)</code> <code>abs</code> <code>racine</code> <code>log</code>.
            Une donnée absente ou une division par zéro rend l'indice non calculé pour l'animal (aucune valeur enregistrée).</div>
        </div>
      </div>
    </div>

    <div class="card p-3 mb-3">
      <h2 class="h6">Interprétation</h2>
      <div class="row g-2 mb-3">
        <div class="col-md-4"><label class="form-label small">Valeur favorable</label><select name="sens" class="form-select form-select-sm">
          <?php foreach (SENS_INDICES as $k => $l): ?><option value="<?= $k ?>" <?= $sel($i['sens'], $k) ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="col-md-8"><label class="form-label small">Interprétation (texte)</label><input name="interpretation" value="<?= e($i['interpretation']) ?>" class="form-control form-control-sm" placeholder="Élevé = bonne aptitude à concevoir"></div>
      </div>
      <p class="small text-muted mb-2">Seuils : minimum inclus, maximum exclu, vide = sans limite. Les seuils d'une espèce sont prioritaires sur les seuils généraux.</p>
      <table class="table table-sm mb-2"><thead><tr><th>Espèce</th><th>Min (≥)</th><th>Max (&lt;)</th><th>Libellé</th><th>Couleur</th><th></th></tr></thead>
        <tbody id="regles"><?php foreach ($regles as $r): ?>
          <tr><td><select name="r_espece[]" class="form-select form-select-sm"><option value="">Toutes</option>
              <?php foreach ($especes as $x): ?><option value="<?= $x['id'] ?>" <?= $sel($r['espece_id'], $x['id']) ?>><?= e($x['nom']) ?></option><?php endforeach; ?></select></td>
            <td><input name="r_min[]" value="<?= $r['borne_min'] === null ? '' : e(fmt_borne($r['borne_min'])) ?>" class="form-control form-control-sm" inputmode="decimal"></td>
            <td><input name="r_max[]" value="<?= $r['borne_max'] === null ? '' : e(fmt_borne($r['borne_max'])) ?>" class="form-control form-control-sm" inputmode="decimal"></td>
            <td><input name="r_libelle[]" value="<?= e($r['libelle']) ?>" class="form-control form-control-sm" placeholder="Bon, à surveiller…"></td>
            <td><select name="r_couleur[]" class="form-select form-select-sm"><?php foreach (COULEURS_INDICES as $k => $l): ?><option value="<?= $k ?>" <?= $sel($r['couleur'], $k) ?>><?= $l ?></option><?php endforeach; ?></select></td>
            <td><button type="button" class="btn btn-sm btn-link text-danger p-0 suppr" title="Retirer"><i class="bi bi-x-circle"></i></button></td></tr>
        <?php endforeach; ?></tbody></table>
      <button type="button" id="ajoutRegle" class="btn btn-sm btn-outline-secondary align-self-start"><i class="bi bi-plus"></i> Ajouter un seuil</button>
    </div>

    <div class="card p-3 mb-3">
      <h2 class="h6">Champ d'application et enregistrement</h2>
      <div class="row g-2">
        <div class="col-md-3"><label class="form-label small">Espèce</label><select name="espece_id" class="form-select form-select-sm"><option value="">Toutes</option>
          <?php foreach ($especes as $x): ?><option value="<?= $x['id'] ?>" <?= $sel($i['espece_id'], $x['id']) ?>><?= e($x['nom']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label small">Sexe</label><select name="sexe" class="form-select form-select-sm">
          <option value="">Les deux</option><option value="F" <?= $sel($i['sexe'], 'F') ?>>Femelles</option><option value="M" <?= $sel($i['sexe'], 'M') ?>>Mâles</option></select></div>
        <div class="col-md-4"><label class="form-label small">Calcul des groupes</label><select name="agregation" class="form-select form-select-sm">
          <?php foreach (AGREGATIONS as $k => $l): ?><option value="<?= $k ?>" <?= $sel($i['agregation'], $k) ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label small">Ordre</label><input type="number" name="ordre" value="<?= (int)$i['ordre'] ?>" class="form-control form-control-sm"></div>
        <div class="col-md-8"><label class="form-label small d-block">Enregistrer par</label>
          <?php foreach (NIVEAUX_INDICES as $k => $l): ?><div class="form-check form-check-inline">
            <input class="form-check-input" type="checkbox" name="niveaux[]" value="<?= $k ?>" id="n_<?= $k ?>" <?= in_array($k, $niveauxSel, true) ? 'checked' : '' ?>>
            <label class="form-check-label small" for="n_<?= $k ?>"><?= $l ?></label></div><?php endforeach; ?></div>
        <div class="col-md-4"><div class="form-check form-switch mt-4"><input class="form-check-input" type="checkbox" name="actif" id="actif" <?= $i['actif'] ? 'checked' : '' ?>><label class="form-check-label" for="actif">Indice actif</label></div></div>
        <div class="col-12 form-text">« Ratio » totalise les variables du groupe avant d'appliquer la formule : un taux de troupeau = Σ numérateurs ÷ Σ dénominateurs, et non la moyenne des taux individuels.</div>
      </div>
    </div>

    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
      <button name="op" value="enregistrer" class="btn btn-primary"><i class="bi bi-check-lg"></i> Enregistrer et recalculer</button>
      <span class="ms-2 small text-muted">Tester sur la campagne</span>
      <select name="campagne_test" class="form-select form-select-sm w-auto"><?php foreach (campagnes_indices($pdo) as $c): ?><option value="<?= $c ?>" <?= (int)($apercu['c'] ?? 0) === $c ? 'selected' : '' ?>><?= e(libelle_campagne($c)) ?></option><?php endforeach; ?></select>
      <button name="op" value="tester" class="btn btn-outline-primary" formnovalidate><i class="bi bi-play"></i> Tester la formule</button>
      <a href="parametres.php#indices" class="btn btn-link">Annuler</a>
    </div>

    <?php if ($apercu): $groupes = array_filter($apercu['res'], fn($l) => $l['niveau'] !== 'animal'); $parUnite = [];
      foreach ($apercu['res'] as $l) if ($l['niveau'] === 'animal') $parUnite[$l['cible'] . '|' . $l['cle']] = $l; ?>
    <div class="card mb-3"><div class="card-header bg-white fw-semibold">Aperçu campagne <?= e(libelle_campagne($apercu['c'])) ?> (non enregistré) ·
      <?= count($parUnite) ?> / <?= count($apercu['unites']) ?> <?= $i['periode'] === 'campagne' ? 'animaux' : ($i['periode'] === 'lactation' ? 'lactations' : 'contrôles') ?> calculés
      <?= in_array('animal', $niveauxSel, true) ? '' : '<span class="fw-normal text-muted">(indice de groupe : pas de valeur par animal)</span>' ?></div>
      <?php if ($groupes): ?><div class="p-2 border-bottom small"><?php foreach ($groupes as $g): ?>
        <span class="me-3"><strong><?= NIVEAUX_INDICES[$g['niveau']] ?><?= $g['niveau'] === 'espece' ? ' ' . e($especesNoms[$g['cible']] ?? '') : ($g['niveau'] === 'lot' ? ' ' . e($lotsNoms[$g['cible']] ?? '') : '') ?><?= $i['periode'] === 'jour' ? ' ' . dfr($g['cle']) : '' ?></strong> :
          <?= nfr($g['valeur'], (int)$i['decimales']) ?> <?= e($i['unite']) ?><?= $g['interpretation'] ? ' <span class="badge text-bg-' . e($g['couleur']) . '">' . e($g['interpretation']) . '</span>' : '' ?></span>
      <?php endforeach; ?></div><?php endif; ?>
      <div class="table-responsive" style="max-height:380px"><table class="table table-sm mb-0 small">
        <thead><tr><th>Animal</th><th>Période</th><th>Variables utilisées</th><th class="text-end">Résultat</th><th>Interprétation</th></tr></thead>
        <tbody><?php foreach ($apercu['unites'] as $u): $l = $parUnite[$u['animal']['id'] . '|' . $u['cle']] ?? null; ?><tr>
          <td><a href="animal.php?id=<?= $u['animal']['id'] ?>&tab=indices"><?= e($u['animal']['numero_identification'] . ' ' . $u['animal']['nom']) ?></a></td>
          <td class="text-nowrap"><?= $i['periode'] === 'campagne' ? e(libelle_campagne($u['cle'])) : dfr($u['debut']) ?></td>
          <td class="text-muted"><?= e(implode(' · ', array_map(fn($k) => "$k = " . (($u['vars'][$k] ?? null) === null ? 'absent' : fmt_borne($u['vars'][$k])), $f->variables))) ?></td>
          <td class="text-end fw-semibold"><?= $l ? nfr($l['valeur'], (int)$i['decimales']) : '<span class="text-muted fw-normal">non calculé</span>' ?></td>
          <td><?= $l && $l['interpretation'] ? '<span class="badge text-bg-' . e($l['couleur']) . '">' . e($l['interpretation']) . '</span>' : '' ?></td></tr><?php endforeach; ?>
        <?php if (!$apercu['unites']): ?><tr><td colspan="5" class="text-muted text-center">Aucun animal concerné.</td></tr><?php endif; ?></tbody>
      </table></div></div>
    <?php endif; ?>
  </div>

  <div class="col-xl-4">
    <div class="card p-3 position-sticky" style="top:1rem">
      <h2 class="h6">Variables disponibles <small class="text-muted fw-normal">(cliquer pour insérer)</small></h2>
      <div style="max-height:75vh;overflow:auto">
      <?php foreach (PERIODES_INDICES as $per => $libPer): ?>
        <div class="vars" data-periode="<?= $per ?>">
          <?php $codes = array_filter($tous, fn($x) => $x['periode'] === $per && $x['code'] !== $ancienCode); if ($codes): ?>
          <div class="small fw-semibold text-muted mt-2">Indices (période <?= mb_strtolower($libPer) ?>)</div>
          <div class="d-flex flex-wrap gap-1 py-1"><?php foreach ($codes as $x): ?><button type="button" class="btn btn-sm btn-outline-success py-0 font-monospace ins" data-v="<?= e($x['code']) ?>" title="<?= e($x['nom']) ?>"><?= e($x['code']) ?></button><?php endforeach; ?></div>
          <?php endif; ?>
          <?php foreach (VARIABLES_INDICES[$per] as $groupe => $vars): ?>
            <div class="small fw-semibold text-muted mt-2"><?= e($groupe) ?></div>
            <?php foreach ($vars as $k => $desc): ?>
              <div class="d-flex gap-2 align-items-start py-1 border-bottom"><button type="button" class="btn btn-sm btn-outline-primary py-0 font-monospace ins" data-v="<?= $k ?>"><?= $k ?></button><span class="small text-muted"><?= e($desc) ?></span></div>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </div>
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
const per = document.getElementById('periode');
const montre = () => document.querySelectorAll('.vars').forEach(d => d.hidden = d.dataset.periode !== per.value);
per.onchange = montre; montre();
const tb = document.getElementById('regles');
document.getElementById('ajoutRegle').onclick = () => {
  const tr = tb.rows[tb.rows.length - 1].cloneNode(true);
  tr.querySelectorAll('input').forEach(i => i.value = ''); tb.appendChild(tr);
};
tb.addEventListener('click', ev => { const b = ev.target.closest('.suppr'); if (!b) return;
  const tr = b.closest('tr'); if (tb.rows.length > 1) tr.remove(); else tr.querySelectorAll('input').forEach(i => i.value = ''); });
</script>
JS;
require 'includes/footer.php';
