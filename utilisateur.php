<?php
require 'includes/functions.php';
exiger('utilisateurs.gerer');
$id = (int)($_GET['id'] ?? 0);
$charger = function () use ($pdo, $id) {
    $st = $pdo->prepare('SELECT u.*, p.nom profil_nom, p.couleur profil_couleur, p.systeme FROM utilisateurs u LEFT JOIN profils p ON p.id=u.profil_id WHERE u.id=?');
    $st->execute([$id]); return $st->fetch();
};
$u = $charger() ?: redirect('utilisateurs.php');
$moi = utilisateur(); $soi = $id === $moi['id'];
$gerable = peut_gerer_compte($pdo, $u);
$titre = $u['nom'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (!$gerable) { flash('Seul un administrateur peut modifier un compte administrateur.', 'danger'); redirect("utilisateur.php?id=$id"); }
    try {
        switch ($_POST['action'] ?? '') {
            case 'enregistrer':
                $nom = trim((string)post('nom')); $email = mb_strtolower(trim((string)post('email'))); $profil = (int)post('profil_id');
                $actif = $soi ? 1 : (isset($_POST['actif']) ? 1 : 0);
                if ($nom === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Indiquez un nom et une adresse e-mail valide.');
                if (!isset(profils_attribuables($pdo)[$profil])) throw new InvalidArgumentException('Profil non autorisé.');
                if ($soi && !est_admin() && $profil !== (int)$u['profil_id']) throw new InvalidArgumentException('Vous ne pouvez pas changer votre propre profil.');
                if (dernier_admin($pdo, $u) && ($profil !== (int)$u['profil_id'] || !$actif)) throw new InvalidArgumentException('Il doit rester au moins un administrateur actif.');
                $photo = $u['photo'];
                if (!empty($_FILES['photo']['name'])) $photo = enregistrer_photo_utilisateur($_FILES['photo'], $u['photo']);
                $pdo->prepare('UPDATE utilisateurs SET nom=?, email=?, telephone=?, fonction=?, profil_id=?, notes=?, actif=?, photo=? WHERE id=?')
                    ->execute([$nom, $email, post('telephone'), post('fonction'), $profil, post('notes'), $actif, $photo, $id]);
                flash('Compte enregistré.');
                break;
            case 'reinitialiser':
                $provisoire = mdp_provisoire();
                $pdo->prepare('UPDATE utilisateurs SET mot_de_passe=?, doit_changer_mdp=1, echecs=0, bloque_jusqua=NULL WHERE id=?')->execute([password_hash($provisoire, PASSWORD_DEFAULT), $id]);
                $_SESSION['provisoire'] = [$u['email'], $provisoire];
                flash('Mot de passe réinitialisé' . ($u['bloque_jusqua'] ? ' et compte débloqué' : '') . '.');
                break;
            case 'debloquer':
                $pdo->prepare('UPDATE utilisateurs SET echecs=0, bloque_jusqua=NULL WHERE id=?')->execute([$id]);
                flash('Compte débloqué.');
                break;
            case 'supprimer_photo':
                if ($u['photo'] && is_file(UPLOAD_DIR . 'utilisateurs/' . basename($u['photo']))) unlink(UPLOAD_DIR . 'utilisateurs/' . basename($u['photo']));
                $pdo->prepare('UPDATE utilisateurs SET photo=NULL WHERE id=?')->execute([$id]);
                flash('Photo supprimée.');
                break;
            case 'supprimer':
                if ($soi) throw new InvalidArgumentException('Vous ne pouvez pas supprimer votre propre compte.');
                if (dernier_admin($pdo, $u)) throw new InvalidArgumentException('Il doit rester au moins un administrateur actif.');
                if ($u['photo'] && is_file(UPLOAD_DIR . 'utilisateurs/' . basename($u['photo']))) unlink(UPLOAD_DIR . 'utilisateurs/' . basename($u['photo']));
                $pdo->prepare('DELETE FROM utilisateurs WHERE id=?')->execute([$id]);
                flash('Compte de ' . $u['nom'] . ' supprimé.', 'warning');
                redirect('utilisateurs.php');
        }
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'danger');
    } catch (PDOException $e) {
        flash($e->getCode() == 23000 ? 'Cette adresse e-mail est déjà utilisée.' : 'Erreur : ' . $e->getMessage(), 'danger');
    }
    redirect("utilisateur.php?id=$id");
}

$provisoire = $_SESSION['provisoire'] ?? null; unset($_SESSION['provisoire']);
$profil = profils($pdo)[(int)$u['profil_id']] ?? null;
$st = $pdo->prepare('SELECT * FROM journal_connexions WHERE utilisateur_id=? ORDER BY id DESC LIMIT 20'); $st->execute([$id]); $journal = $st->fetchAll();
$dis = $gerable ? '' : 'disabled';
require 'includes/header.php';
?>
<nav class="small mb-2"><a href="utilisateurs.php">Utilisateurs</a> › <?= e($u['nom']) ?></nav>
<div class="d-flex align-items-center gap-3 mb-3">
  <?= avatar($u, 56) ?>
  <div><h1 class="h3 mb-0"><?= e($u['nom']) ?> <?= $soi ? '<span class="badge text-bg-light fs-6">vous</span>' : '' ?></h1>
    <div><?= badge_profil($u) ?> <?= $u['actif'] ? '<span class="badge text-bg-success">Actif</span>' : '<span class="badge text-bg-secondary">Désactivé</span>' ?>
      <?= $u['bloque_jusqua'] && $u['bloque_jusqua'] > date('Y-m-d H:i:s') ? '<span class="badge text-bg-danger">Bloqué jusqu\'à ' . date('H:i', strtotime($u['bloque_jusqua'])) . '</span>' : '' ?></div></div>
</div>
<?php if (!$gerable): ?><div class="alert alert-info small">Compte administrateur : seul un administrateur peut le modifier.</div><?php endif; ?>
<?php if ($provisoire): ?><div class="alert alert-warning"><strong><i class="bi bi-key"></i> Mot de passe provisoire</strong> : <code class="fs-5 user-select-all"><?= e($provisoire[1]) ?></code>
  <div class="small">À communiquer à <?= e($u['nom']) ?> ; il ne sera plus affiché.</div></div><?php endif; ?>

<div class="row g-3">
  <div class="col-xl-7">
    <form method="post" enctype="multipart/form-data" class="card p-3 mb-3"><?= csrf_field() ?><input type="hidden" name="action" value="enregistrer">
      <h2 class="h6">Compte</h2>
      <div class="row g-2">
        <div class="col-md-6"><label class="form-label small" for="nom">Nom</label><input id="nom" name="nom" class="form-control form-control-sm" value="<?= e($u['nom']) ?>" required <?= $dis ?>></div>
        <div class="col-md-6"><label class="form-label small" for="email">Adresse e-mail (identifiant)</label><input id="email" type="email" name="email" class="form-control form-control-sm" value="<?= e($u['email']) ?>" required <?= $dis ?>></div>
        <div class="col-md-6"><label class="form-label small" for="tel">Téléphone</label><input id="tel" name="telephone" class="form-control form-control-sm" value="<?= e($u['telephone']) ?>" <?= $dis ?>></div>
        <div class="col-md-6"><label class="form-label small" for="fonction">Fonction</label><input id="fonction" name="fonction" class="form-control form-control-sm" value="<?= e($u['fonction']) ?>" <?= $dis ?>></div>
        <div class="col-md-6"><label class="form-label small" for="profil_id">Profil</label><select id="profil_id" name="profil_id" class="form-select form-select-sm" <?= $dis ?>>
          <?php foreach ($gerable ? profils_attribuables($pdo) : profils($pdo) as $p): ?><option value="<?= $p['id'] ?>" <?= (int)$u['profil_id'] === $p['id'] ? 'selected' : '' ?>><?= e($p['nom']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label small" for="photo">Photo</label><input id="photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="form-control form-control-sm" <?= $dis ?>></div>
        <div class="col-12"><label class="form-label small" for="notes">Note interne</label><input id="notes" name="notes" class="form-control form-control-sm" value="<?= e($u['notes']) ?>" maxlength="255" <?= $dis ?>></div>
        <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="actif" id="actif" <?= $u['actif'] ? 'checked' : '' ?> <?= $soi || !$gerable ? 'disabled' : '' ?>>
          <label class="form-check-label small" for="actif">Compte actif<?= $soi ? ' (vous ne pouvez pas désactiver votre propre compte)' : '' ?></label></div></div>
      </div>
      <?php if ($gerable): ?><div class="mt-3"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Enregistrer</button></div><?php endif; ?>
    </form>
    <?php if ($gerable): ?>
    <div class="card p-3 mb-3 d-flex flex-row flex-wrap gap-2">
      <form method="post" onsubmit="return confirm('Générer un mot de passe provisoire ?')"><?= csrf_field() ?><input type="hidden" name="action" value="reinitialiser"><button class="btn btn-outline-secondary btn-sm"><i class="bi bi-key"></i> Réinitialiser le mot de passe</button></form>
      <?php if ($u['bloque_jusqua'] && $u['bloque_jusqua'] > date('Y-m-d H:i:s')): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="debloquer"><button class="btn btn-outline-secondary btn-sm"><i class="bi bi-unlock"></i> Débloquer</button></form><?php endif; ?>
      <?php if ($u['photo']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="supprimer_photo"><button class="btn btn-outline-secondary btn-sm"><i class="bi bi-image"></i> Retirer la photo</button></form><?php endif; ?>
      <?php if (!$soi): ?><form method="post" class="ms-auto" onsubmit="return confirm('Supprimer définitivement ce compte ? Préférez la désactivation pour garder l\'historique.')"><?= csrf_field() ?><input type="hidden" name="action" value="supprimer"><button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i> Supprimer le compte</button></form><?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="card"><div class="card-header bg-white fw-semibold">Connexions</div>
      <div class="table-responsive" style="max-height:300px"><table class="table table-sm mb-0 small">
        <thead><tr><th>Date</th><th>Résultat</th><th>Adresse IP</th></tr></thead>
        <tbody><?php foreach ($journal as $j): ?><tr><td><?= date('d/m/Y H:i', strtotime($j['date_connexion'])) ?></td>
          <td><?= $j['succes'] ? '<span class="text-success">réussie</span>' : '<span class="text-danger">' . e($j['motif']) . '</span>' ?></td><td class="text-muted"><?= e($j['ip']) ?></td></tr><?php endforeach; ?>
        <?php if (!$journal): ?><tr><td colspan="3" class="text-muted text-center">Aucune connexion.</td></tr><?php endif; ?></tbody>
      </table></div></div>
  </div>
  <div class="col-xl-5"><div class="card p-3">
    <h2 class="h6">Permissions du profil <?= badge_profil($u) ?></h2>
    <?php if (!$profil): ?><p class="text-muted small">Aucun profil : ce compte n'a accès à rien.</p>
    <?php else: foreach (PERMISSIONS as $groupe => $perms): ?>
      <div class="small fw-semibold text-muted mt-2"><?= e($groupe) ?></div>
      <?php foreach ($perms as $code => [$lib]): $ok = $profil['systeme'] || in_array($code, $profil['permissions'], true); ?>
        <div class="small <?= $ok ? '' : 'text-muted' ?>"><i class="bi bi-<?= $ok ? 'check-circle-fill text-success' : 'x-circle' ?>"></i> <?= e($lib) ?></div>
      <?php endforeach; endforeach; ?>
      <?php if (peut('profils.gerer')): ?><a href="profils.php" class="small d-block mt-2">Modifier les permissions des profils →</a><?php endif; ?>
    <?php endif; ?>
  </div></div>
</div>
<?php require 'includes/footer.php';
