<?php
require 'includes/functions.php';
exiger('profils.gerer');
$titre = 'Profils et permissions';
$catalogue = liste_permissions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        switch ($_POST['action'] ?? '') {
            case 'matrice': // enregistre toute la matrice
                $pdo->beginTransaction();
                $ins = $pdo->prepare('INSERT INTO profil_permissions (profil_id, permission) VALUES (?,?)');
                foreach (profils($pdo) as $p) {
                    if ($p['systeme']) continue; // Administrateur : tous les droits, non modifiable
                    $pdo->prepare('DELETE FROM profil_permissions WHERE profil_id=?')->execute([$p['id']]);
                    $choix = array_values(array_intersect((array)($_POST['perm'][$p['id']] ?? []), array_keys($catalogue)));
                    // Saisir / supprimer / créer… implique de pouvoir consulter le module
                    foreach ($choix as $perm) { $voir = explode('.', $perm)[0] . '.voir'; if (isset($catalogue[$voir])) $choix[] = $voir; }
                    foreach (array_unique($choix) as $perm) $ins->execute([$p['id'], $perm]);
                }
                $pdo->commit();
                flash('Permissions enregistrées. Elles s\'appliquent immédiatement aux utilisateurs connectés.');
                break;
            case 'creer':
            case 'modifier':
                $nom = trim((string)post('nom'));
                if ($nom === '') throw new InvalidArgumentException('Le nom du profil est obligatoire.');
                $couleur = isset(COULEURS_PROFILS[post('couleur')]) ? post('couleur') : 'secondary';
                if ($_POST['action'] === 'creer') {
                    $code = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $nom))), '_') ?: 'profil';
                    $base = $code; $n = 1;
                    while ($pdo->query('SELECT 1 FROM profils WHERE code=' . $pdo->quote($code))->fetchColumn()) $code = $base . '_' . (++$n);
                    $pdo->beginTransaction();
                    $pdo->prepare('INSERT INTO profils (code, nom, description, couleur) VALUES (?,?,?,?)')->execute([$code, $nom, post('description'), $couleur]);
                    $id = (int)$pdo->lastInsertId();
                    if ($modele = (int)post('modele')) // copie des permissions d'un profil existant
                        $pdo->prepare('INSERT INTO profil_permissions (profil_id, permission) SELECT ?, permission FROM profil_permissions WHERE profil_id=?')->execute([$id, $modele]);
                    $pdo->commit();
                    flash("Profil « $nom » créé. Cochez ses permissions dans la matrice.");
                } else {
                    $st = $pdo->prepare('UPDATE profils SET nom=?, description=?, couleur=? WHERE id=? AND systeme=0');
                    $st->execute([$nom, post('description'), $couleur, (int)post('id')]);
                    flash("Profil « $nom » modifié.");
                }
                break;
            case 'supprimer':
                $st = $pdo->prepare('SELECT p.*, (SELECT COUNT(*) FROM utilisateurs u WHERE u.profil_id=p.id) nb FROM profils p WHERE id=?');
                $st->execute([(int)post('id')]); $p = $st->fetch();
                if (!$p || $p['systeme']) throw new InvalidArgumentException('Ce profil ne peut pas être supprimé.');
                if ($p['nb']) throw new InvalidArgumentException("Le profil « {$p['nom']} » est attribué à {$p['nb']} utilisateur(s) : changez d'abord leur profil.");
                $pdo->prepare('DELETE FROM profils WHERE id=?')->execute([$p['id']]);
                flash("Profil « {$p['nom']} » supprimé.", 'warning');
                break;
        }
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($e->getMessage(), 'danger');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($e->getCode() == 23000 ? 'Un profil porte déjà ce nom.' : 'Erreur : ' . $e->getMessage(), 'danger');
    }
    redirect('profils.php');
}

$profils = profils($pdo);
require 'includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div><h1 class="h3 mb-0">Profils et permissions</h1>
    <div class="small text-muted">Chaque utilisateur reçoit un profil ; le profil donne ses permissions. Les changements s'appliquent dès la page suivante.</div></div>
  <a href="utilisateurs.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-people"></i> Utilisateurs</a>
</div>

<form method="post" class="card mb-3"><?= csrf_field() ?><input type="hidden" name="action" value="matrice">
  <div class="card-header bg-white d-flex flex-wrap gap-2 align-items-center">
    <strong class="me-auto">Matrice des permissions</strong>
    <span class="small text-muted"><i class="bi bi-exclamation-triangle text-warning"></i> = permission sensible</span>
    <button class="btn btn-primary btn-sm"><i class="bi bi-save"></i> Enregistrer</button>
  </div>
  <div class="table-responsive" style="max-height:72vh"><table class="table table-sm table-hover mb-0 align-middle matrice">
    <thead class="sticky-top bg-white"><tr><th>Permission</th>
      <?php foreach ($profils as $p): ?><th class="text-center rot" style="min-width:110px">
        <span class="badge text-bg-<?= e($p['couleur']) ?>"><?= e($p['nom']) ?></span><br>
        <small class="text-muted fw-normal"><?= $p['nb_utilisateurs'] ?> utilisateur<?= $p['nb_utilisateurs'] > 1 ? 's' : '' ?></small>
        <?php if (!$p['systeme']): ?><br><button type="button" class="btn btn-link btn-sm p-0 tout" data-p="<?= $p['id'] ?>">tout</button> ·
          <button type="button" class="btn btn-link btn-sm p-0 rien" data-p="<?= $p['id'] ?>">rien</button><?php endif; ?></th>
      <?php endforeach; ?></tr></thead>
    <tbody><?php foreach (PERMISSIONS as $groupe => $perms): ?>
      <tr class="table-light"><td colspan="<?= count($profils) + 1 ?>" class="fw-semibold small"><?= e($groupe) ?></td></tr>
      <?php foreach ($perms as $code => [$lib, $sensible]): ?>
      <tr><td class="perm small"><?= e($lib) ?> <?= $sensible ? '<i class="bi bi-exclamation-triangle text-warning" title="Permission sensible"></i>' : '' ?><br><code class="text-muted"><?= e($code) ?></code></td>
        <?php foreach ($profils as $p): ?><td class="text-center">
          <?php if ($p['systeme']): ?><i class="bi bi-check-lg text-success" title="Administrateur : tous les droits"></i>
          <?php else: ?><input type="checkbox" class="form-check-input p<?= $p['id'] ?>" name="perm[<?= $p['id'] ?>][]" value="<?= e($code) ?>" <?= in_array($code, $p['permissions'], true) ? 'checked' : '' ?> aria-label="<?= e($p['nom'] . ' : ' . $lib) ?>"><?php endif; ?>
        </td><?php endforeach; ?></tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
      <tr class="table-light"><td class="small fw-semibold">Gérer les profils et permissions</td>
        <?php foreach ($profils as $p): ?><td class="text-center"><?= $p['systeme'] ? '<i class="bi bi-check-lg text-success"></i>' : '<span class="small text-muted">réservé admin</span>' ?></td><?php endforeach; ?></tr>
    </tbody>
  </table></div>
</form>

<div class="row g-3">
  <div class="col-xl-8"><div class="card">
    <div class="card-header bg-white fw-semibold">Profils</div>
    <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
      <thead><tr><th>Profil</th><th>Description</th><th>Couleur</th><th class="text-end">Permissions</th><th class="text-end">Utilisateurs</th><th></th></tr></thead>
      <tbody><?php foreach ($profils as $p): ?>
        <?php if ($p['systeme']): ?>
        <tr><td><span class="badge text-bg-<?= e($p['couleur']) ?>"><?= e($p['nom']) ?></span></td><td class="small"><?= e($p['description']) ?></td><td></td>
          <td class="text-end small">toutes</td><td class="text-end"><?= $p['nb_utilisateurs'] ?></td><td class="small text-muted text-end">non modifiable</td></tr>
        <?php else: ?>
        <tr><td colspan="6" class="p-0"><form method="post" class="d-flex flex-wrap gap-2 align-items-center p-2"><?= csrf_field() ?><input type="hidden" name="action" value="modifier"><input type="hidden" name="id" value="<?= $p['id'] ?>">
          <input name="nom" value="<?= e($p['nom']) ?>" class="form-control form-control-sm" style="max-width:180px" required aria-label="Nom">
          <input name="description" value="<?= e($p['description']) ?>" class="form-control form-control-sm flex-grow-1" style="min-width:200px" aria-label="Description">
          <select name="couleur" class="form-select form-select-sm w-auto" aria-label="Couleur"><?php foreach (COULEURS_PROFILS as $k => $l): ?><option value="<?= $k ?>" <?= $p['couleur'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
          <span class="small text-muted text-nowrap"><?= count($p['permissions']) ?> perm. · <?= $p['nb_utilisateurs'] ?> util.</span>
          <button class="btn btn-sm btn-outline-primary" title="Enregistrer"><i class="bi bi-check"></i></button>
          <button class="btn btn-sm btn-link text-danger p-0" name="action" value="supprimer" title="Supprimer" onclick="return confirm('Supprimer ce profil ?')" <?= $p['nb_utilisateurs'] ? 'disabled' : '' ?>><i class="bi bi-trash"></i></button>
        </form></td></tr>
        <?php endif; ?>
      <?php endforeach; ?></tbody>
    </table></div></div></div>
  <div class="col-xl-4"><div class="card p-3">
    <h2 class="h6">Nouveau profil</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="creer">
      <div class="mb-2"><label class="form-label small" for="pnom">Nom</label><input id="pnom" name="nom" class="form-control form-control-sm" required placeholder="ex. Stagiaire, Contrôleur laitier"></div>
      <div class="mb-2"><label class="form-label small" for="pdesc">Description</label><input id="pdesc" name="description" class="form-control form-control-sm"></div>
      <div class="row g-2 mb-3">
        <div class="col-6"><label class="form-label small" for="pcoul">Couleur</label><select id="pcoul" name="couleur" class="form-select form-select-sm"><?php foreach (COULEURS_PROFILS as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label small" for="pmod">Copier les droits de</label><select id="pmod" name="modele" class="form-select form-select-sm"><option value="">Aucun</option>
          <?php foreach ($profils as $p) if (!$p['systeme']): ?><option value="<?= $p['id'] ?>"><?= e($p['nom']) ?></option><?php endif; ?></select></div>
      </div>
      <button class="btn btn-primary btn-sm w-100"><i class="bi bi-plus-lg"></i> Créer le profil</button>
    </form>
  </div></div>
</div>
<?php
$scripts = <<<'JS'
<script>
document.querySelectorAll(".tout,.rien").forEach(b => b.onclick = () => document.querySelectorAll(".p" + b.dataset.p).forEach(c => c.checked = b.classList.contains("tout")));
// Cohérence : une action sur un module implique de pouvoir le consulter
document.querySelectorAll(".matrice input[type=checkbox]").forEach(c => c.addEventListener("change", () => {
  const [module, action] = c.value.split("."), cls = [...c.classList].find(x => /^p\d+$/.test(x));
  const voir = document.querySelector(`input.${cls}[value="${module}.voir"]`);
  if (action !== "voir" && c.checked && voir) voir.checked = true;
  if (action === "voir" && !c.checked) document.querySelectorAll(`input.${cls}[value^="${module}."]`).forEach(x => x.checked = false);
}));
</script>
JS;
require 'includes/footer.php';
