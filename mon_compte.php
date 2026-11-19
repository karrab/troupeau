<?php
require 'includes/functions.php';
$titre = 'Mon compte';
$moi = utilisateur();
$force = (bool)$moi['doit_changer_mdp'];
$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $st = $pdo->prepare('SELECT mot_de_passe FROM utilisateurs WHERE id=?'); $st->execute([$moi['id']]);
    $hash = (string)$st->fetchColumn();
    $nouveau = (string)($_POST['nouveau'] ?? '');
    if (!password_verify((string)($_POST['actuel'] ?? ''), $hash)) $erreur = 'Mot de passe actuel incorrect.';
    elseif (password_verify($nouveau, $hash)) $erreur = 'Le nouveau mot de passe doit être différent de l\'actuel.';
    else $erreur = erreur_mot_de_passe($nouveau, (string)($_POST['confirmation'] ?? ''));
    if (!$erreur) {
        $pdo->prepare('UPDATE utilisateurs SET mot_de_passe=?, doit_changer_mdp=0 WHERE id=?')->execute([password_hash($nouveau, PASSWORD_DEFAULT), $moi['id']]);
        $_SESSION['utilisateur']['doit_changer_mdp'] = 0;
        session_regenerate_id(true);
        flash('Mot de passe modifié.');
        redirect($force ? 'index.php' : 'mon_compte.php');
    }
}
$st = $pdo->prepare('SELECT * FROM utilisateurs WHERE id=?'); $st->execute([$moi['id']]); $u = $st->fetch();
require 'includes/header.php';
?>
<h1 class="h3 mb-4">Mon compte</h1>
<div class="row g-3">
  <div class="col-lg-5"><div class="card p-3 h-100">
    <h2 class="h6 text-muted">Profil</h2>
    <dl class="fiche mb-0">
      <dt>Nom</dt><dd><?= e($u['nom']) ?></dd>
      <dt>Identifiant</dt><dd><?= e($u['email']) ?></dd>
      <dt>Rôle</dt><dd><span class="badge text-bg-<?= ROLES[$u['role']][1] ?>"><?= ROLES[$u['role']][0] ?></span> <span class="small text-muted"><?= e(ROLES[$u['role']][2]) ?></span></dd>
      <dt>Dernière connexion</dt><dd><?= $u['derniere_connexion'] ? date('d/m/Y à H:i', strtotime($u['derniere_connexion'])) : '—' ?></dd>
    </dl>
  </div></div>
  <div class="col-lg-5"><div class="card p-3 h-100">
    <h2 class="h6 text-muted">Changer de mot de passe</h2>
    <?php if ($force): ?><div class="alert alert-warning small py-2">Votre mot de passe est provisoire : choisissez-en un nouveau pour accéder à l'application.</div><?php endif; ?>
    <?php if ($erreur): ?><div class="alert alert-danger small py-2"><?= e($erreur) ?></div><?php endif; ?>
    <form method="post"><?= csrf_field() ?>
      <div class="mb-2"><label class="form-label small" for="actuel">Mot de passe actuel</label><input id="actuel" type="password" name="actuel" class="form-control" required autocomplete="current-password"></div>
      <div class="mb-2"><label class="form-label small" for="nouveau">Nouveau mot de passe</label><input id="nouveau" type="password" name="nouveau" class="form-control" required minlength="<?= MDP_LONGUEUR_MIN ?>" autocomplete="new-password">
        <div class="form-text"><?= MDP_LONGUEUR_MIN ?> caractères minimum, avec au moins une lettre et un chiffre.</div></div>
      <div class="mb-3"><label class="form-label small" for="confirmation">Confirmation</label><input id="confirmation" type="password" name="confirmation" class="form-control" required autocomplete="new-password"></div>
      <button class="btn btn-primary"><i class="bi bi-key"></i> Enregistrer</button>
    </form>
  </div></div>
</div>
<?php require 'includes/footer.php';
