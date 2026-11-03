<?php
require 'includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
$a  = ['espece_id' => 1, 'sexe' => 'F', 'origine' => 'naissance', 'statut' => 'actif', 'date_entree' => date('Y-m-d')];
if ($id) {
    $st = $pdo->prepare('SELECT * FROM animaux WHERE id=?'); $st->execute([$id]);
    $a = $st->fetch() ?: redirect('animaux.php');
} elseif (!empty($_GET['mere'])) {
    // Pré-remplissage après une mise bas : espèce, race, lot et dernier père connu
    $st = $pdo->prepare("SELECT a.id, a.espece_id, a.race_id, a.lot_id,
        (SELECT male_id FROM reproductions WHERE animal_id=a.id AND male_id IS NOT NULL ORDER BY date_evenement DESC LIMIT 1) pere
        FROM animaux a WHERE a.id=? AND a.sexe='F'");
    $st->execute([(int)$_GET['mere']]);
    if ($m = $st->fetch()) {
        $a += ['mere_id' => $m['id'], 'pere_id' => $m['pere'], 'race_id' => $m['race_id'], 'lot_id' => $m['lot_id'], 'date_naissance' => date('Y-m-d')];
        $a['espece_id'] = $m['espece_id'];
    }
}
$erreurs = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    // Suppression
    if (isset($_POST['supprimer']) && $id) {
        $pdo->prepare('DELETE FROM animaux WHERE id=?')->execute([$id]);
        if (!empty($a['photo']) && is_file(UPLOAD_DIR . $a['photo'])) unlink(UPLOAD_DIR . $a['photo']);
        flash('Animal supprimé avec tout son historique.', 'warning');
        redirect('animaux.php');
    }

    $champs = ['numero_identification','nom','espece_id','race_id','sexe','categorie','robe','date_naissance','poids_naissance',
               'mere_id','pere_id','origine','date_entree','prix_achat','lot_id','statut','date_sortie','motif_sortie','observations'];
    $d = [];
    foreach ($champs as $c) $d[$c] = post($c);

    if (!$d['numero_identification']) $erreurs[] = "Le numéro d'identification est obligatoire.";
    if (!$d['espece_id'])             $erreurs[] = "L'espèce est obligatoire.";
    if (!in_array($d['sexe'], ['M','F'], true)) $erreurs[] = 'Le sexe est obligatoire.';
    if ($d['statut'] !== 'actif' && !$d['date_sortie']) $erreurs[] = 'Indiquez la date de sortie pour un animal vendu, mort ou réformé.';
    if ($id && ($d['mere_id'] == $id || $d['pere_id'] == $id)) $erreurs[] = 'Un animal ne peut pas être son propre parent.';

    $dup = $pdo->prepare('SELECT id FROM animaux WHERE numero_identification=? AND id<>?');
    $dup->execute([$d['numero_identification'], $id]);
    if ($dup->fetch()) $erreurs[] = 'Ce numéro d\'identification existe déjà.';

    // Photo
    $d['photo'] = $a['photo'] ?? null;
    if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['photo']['tmp_name']);
        if (!in_array($ext, ['jpg','jpeg','png','webp'], true) || !str_starts_with($mime, 'image/')) $erreurs[] = 'Photo : format JPG, PNG ou WEBP uniquement.';
        elseif ($_FILES['photo']['size'] > 3 * 1024 * 1024) $erreurs[] = 'Photo : 3 Mo maximum.';
        else {
            if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0775, true);
            $nom = uniqid('animal_') . '.' . $ext;
            move_uploaded_file($_FILES['photo']['tmp_name'], UPLOAD_DIR . $nom);
            if ($d['photo'] && is_file(UPLOAD_DIR . $d['photo'])) unlink(UPLOAD_DIR . $d['photo']);
            $d['photo'] = $nom;
        }
    }

    if (!$erreurs) {
        $cols = array_keys($d);
        if ($id) {
            $sql = 'UPDATE animaux SET ' . implode(',', array_map(fn($c) => "$c=?", $cols)) . ' WHERE id=?';
            $pdo->prepare($sql)->execute([...array_values($d), $id]);
            // Traçabilité du changement de lot
            if (($a['lot_id'] ?? null) != $d['lot_id']) {
                $pdo->prepare("INSERT INTO mouvements (animal_id,date_mouvement,type,lot_origine,lot_destination,motif) VALUES (?,CURDATE(),'transfert',?,?,'Changement de lot')")
                    ->execute([$id, $a['lot_id'], $d['lot_id']]);
            }
            // Sortie enregistrée automatiquement
            if ($a['statut'] === 'actif' && $d['statut'] !== 'actif') {
                $pdo->prepare("INSERT INTO mouvements (animal_id,date_mouvement,type,lot_origine,motif) VALUES (?,?,'sortie',?,?)")
                    ->execute([$id, $d['date_sortie'], $a['lot_id'], STATUTS[$d['statut']][0] . ($d['motif_sortie'] ? ' – ' . $d['motif_sortie'] : '')]);
            }
            flash('Fiche mise à jour.');
        } else {
            $sql = 'INSERT INTO animaux (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
            $pdo->prepare($sql)->execute(array_values($d));
            $id = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO mouvements (animal_id,date_mouvement,type,lot_destination,motif,montant) VALUES (?,?,'entree',?,?,?)")
                ->execute([$id, $d['date_entree'] ?? date('Y-m-d'), $d['lot_id'], $d['origine'] === 'achat' ? 'Achat' : 'Naissance', $d['prix_achat']]);
            if ($d['poids_naissance'] && $d['date_naissance'] && $d['origine'] === 'naissance') {
                $pdo->prepare("INSERT INTO pesees (animal_id,date_pesee,poids,methode,observations) VALUES (?,?,?,'balance','Poids de naissance')")
                    ->execute([$id, $d['date_naissance'], $d['poids_naissance']]);
            }
            flash('Animal enregistré.');
        }
        redirect('animal.php?id=' . $id);
    }
    $a = array_merge($a, $d);
}

$titre = $id ? 'Modifier ' . $a['numero_identification'] : 'Nouvel animal';
$parents = $pdo->query('SELECT id, numero_identification, nom, sexe, espece_id FROM animaux ORDER BY numero_identification')->fetchAll();
$sel = fn($v, $x) => (string)$v === (string)$x ? 'selected' : '';
require 'includes/header.php';
?>
<h1 class="h3 mb-4"><?= e($titre) ?></h1>
<?php if ($erreurs): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erreurs as $er) echo '<li>' . e($er) . '</li>'; ?></ul></div><?php endif; ?>

<form method="post" enctype="multipart/form-data">
<?= csrf_field() ?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card p-3 mb-3">
      <h2 class="h6 text-muted mb-3">Identification</h2>
      <div class="row g-3">
        <div class="col-md-4"><label class="form-label">N° identification *</label><input name="numero_identification" class="form-control" required value="<?= e($a['numero_identification'] ?? '') ?>" placeholder="Boucle, puce…"></div>
        <div class="col-md-4"><label class="form-label">Nom</label><input name="nom" class="form-control" value="<?= e($a['nom'] ?? '') ?>"></div>
        <div class="col-md-4"><label class="form-label">Sexe *</label><select name="sexe" class="form-select"><option value="F" <?= $sel($a['sexe'],'F') ?>>Femelle</option><option value="M" <?= $sel($a['sexe'],'M') ?>>Mâle</option></select></div>
        <div class="col-md-4"><label class="form-label">Espèce *</label><select name="espece_id" id="espece" class="form-select">
          <?php foreach (especes($pdo) as $x): ?><option value="<?= $x['id'] ?>" <?= $sel($a['espece_id'],$x['id']) ?>><?= e($x['nom']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">Race</label><select name="race_id" id="race" class="form-select"><option value="">—</option>
          <?php foreach (races($pdo) as $x): ?><option value="<?= $x['id'] ?>" data-espece="<?= $x['espece_id'] ?>" <?= $sel($a['race_id'] ?? '',$x['id']) ?>><?= e($x['nom']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">Catégorie</label><input name="categorie" class="form-control" list="cats" value="<?= e($a['categorie'] ?? '') ?>">
          <datalist id="cats"><?php foreach (['veau','velle','génisse','vache','taurillon','taureau','agneau','agnelle','brebis','bélier','chevreau','chèvre','bouc'] as $c) echo "<option value=\"$c\">"; ?></datalist></div>
        <div class="col-md-4"><label class="form-label">Robe</label><input name="robe" class="form-control" value="<?= e($a['robe'] ?? '') ?>"></div>
        <div class="col-md-4"><label class="form-label">Date de naissance</label><input type="date" name="date_naissance" class="form-control" value="<?= e($a['date_naissance'] ?? '') ?>"></div>
        <div class="col-md-4"><label class="form-label">Poids de naissance (kg)</label><input type="number" step="0.1" min="0" name="poids_naissance" class="form-control" value="<?= e($a['poids_naissance'] ?? '') ?>"></div>
      </div>
    </div>

    <div class="card p-3 mb-3">
      <h2 class="h6 text-muted mb-3">Généalogie</h2>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Mère</label><select name="mere_id" class="form-select parent" data-sexe="F"><option value="">Inconnue / hors troupeau</option>
          <?php foreach ($parents as $p) if ($p['sexe']==='F' && $p['id'] != $id): ?><option value="<?= $p['id'] ?>" data-espece="<?= $p['espece_id'] ?>" <?= $sel($a['mere_id'] ?? '',$p['id']) ?>><?= e($p['numero_identification'].' '.$p['nom']) ?></option><?php endif; ?></select></div>
        <div class="col-md-6"><label class="form-label">Père</label><select name="pere_id" class="form-select parent"><option value="">Inconnu / IA</option>
          <?php foreach ($parents as $p) if ($p['sexe']==='M' && $p['id'] != $id): ?><option value="<?= $p['id'] ?>" data-espece="<?= $p['espece_id'] ?>" <?= $sel($a['pere_id'] ?? '',$p['id']) ?>><?= e($p['numero_identification'].' '.$p['nom']) ?></option><?php endif; ?></select></div>
      </div>
    </div>

    <div class="card p-3 mb-3">
      <h2 class="h6 text-muted mb-3">Entrée, localisation et statut</h2>
      <div class="row g-3">
        <div class="col-md-3"><label class="form-label">Origine</label><select name="origine" class="form-select"><option value="naissance" <?= $sel($a['origine'],'naissance') ?>>Né à la ferme</option><option value="achat" <?= $sel($a['origine'],'achat') ?>>Acheté</option></select></div>
        <div class="col-md-3"><label class="form-label">Date d'entrée</label><input type="date" name="date_entree" class="form-control" value="<?= e($a['date_entree'] ?? '') ?>"></div>
        <div class="col-md-3"><label class="form-label">Prix d'achat</label><input type="number" step="0.01" min="0" name="prix_achat" class="form-control" value="<?= e($a['prix_achat'] ?? '') ?>"></div>
        <div class="col-md-3"><label class="form-label">Lot / bâtiment</label><select name="lot_id" class="form-select"><option value="">—</option>
          <?php foreach (lots($pdo) as $x): ?><option value="<?= $x['id'] ?>" <?= $sel($a['lot_id'] ?? '',$x['id']) ?>><?= e($x['nom']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">Statut</label><select name="statut" class="form-select">
          <?php foreach (STATUTS as $k => [$l]): ?><option value="<?= $k ?>" <?= $sel($a['statut'],$k) ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">Date de sortie</label><input type="date" name="date_sortie" class="form-control" value="<?= e($a['date_sortie'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label">Motif de sortie</label><input name="motif_sortie" class="form-control" value="<?= e($a['motif_sortie'] ?? '') ?>"></div>
        <div class="col-12"><label class="form-label">Observations</label><textarea name="observations" rows="3" class="form-control"><?= e($a['observations'] ?? '') ?></textarea></div>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card p-3 mb-3 text-center">
      <h2 class="h6 text-muted mb-3 text-start">Photo</h2>
      <?php if (!empty($a['photo'])): ?><img src="uploads/<?= e($a['photo']) ?>" class="photo-animal mx-auto mb-2" alt=""><?php endif; ?>
      <input type="file" name="photo" accept="image/*" class="form-control">
    </div>
    <div class="d-grid gap-2">
      <button class="btn btn-primary"><i class="bi bi-check-lg"></i> Enregistrer</button>
      <a href="<?= $id ? 'animal.php?id=' . $id : 'animaux.php' ?>" class="btn btn-outline-secondary">Annuler</a>
      <?php if ($id): ?><button name="supprimer" value="1" class="btn btn-outline-danger mt-4" formnovalidate onclick="return confirm('Supprimer définitivement cet animal et tout son historique ? Préférez le statut « Vendu » ou « Mort » pour garder la traçabilité.')"><i class="bi bi-trash"></i> Supprimer</button><?php endif; ?>
    </div>
  </div>
</div>
</form>
<?php
$scripts = <<<JS
<script>
// Filtre les races et les parents selon l'espèce choisie
const esp = document.getElementById('espece');
function filtre() {
  document.querySelectorAll('#race option[data-espece], .parent option[data-espece]').forEach(o => {
    o.hidden = o.dataset.espece !== esp.value;
    if (o.hidden && o.selected) o.parentElement.value = '';
  });
}
esp.addEventListener('change', filtre); filtre();
</script>
JS;
require 'includes/footer.php';
