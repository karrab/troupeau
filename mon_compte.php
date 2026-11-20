<?php
require 'includes/functions.php';
$titre = 'Mon profil';
$moi = utilisateur();
$force = (bool)$moi['doit_changer_mdp'];
$erreurs = ['profil' => null, 'mdp' => null];
$st = $pdo->prepare('SELECT u.*, p.nom profil_nom, p.couleur profil_couleur, p.description profil_description, p.systeme
    FROM utilisateurs u LEFT JOIN profils p ON p.id=u.profil_id WHERE u.id=?');
$st->execute([$moi['id']]); $u = $st->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($force && $action !== 'mot_de_passe') throw new InvalidArgumentException('Choisissez d\'abord votre nouveau mot de passe.');
        if ($action === 'profil') {
            $nom = trim((string)post('nom')); $email = mb_strtolower(trim((string)post('email')));
            if ($nom === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Indiquez un nom et une adresse e-mail valide.');
            // Changer d'identifiant exige le mot de passe actuel
            if ($email !== $u['email'] && !password_verify((string)($_POST['mdp_email'] ?? ''), $u['mot_de_passe']))
                throw new InvalidArgumentException('Pour changer votre adresse e-mail, saisissez votre mot de passe actuel.');
            $photo = $u['photo'];
            if (!empty($_FILES['photo']['name'])) $photo = enregistrer_photo_utilisateur($_FILES['photo'], $u['photo']);
            if (isset($_POST['retirer_photo']) && $photo) {
                if (is_file(UPLOAD_DIR . 'utilisateurs/' . basename($photo))) unlink(UPLOAD_DIR . 'utilisateurs/' . basename($photo));
                $photo = null;
            }
            $pdo->prepare('UPDATE utilisateurs SET nom=?, email=?, telephone=?, fonction=?, photo=? WHERE id=?')
                ->execute([$nom, $email, post('telephone'), post('fonction'), $photo, $moi['id']]);
            flash('Profil mis à jour.');
            redirect('mon_compte.php');
        }
        if ($action === 'mot_de_passe') {
            $nouveau = (string)($_POST['nouveau'] ?? '');
            if (!password_verify((string)($_POST['actuel'] ?? ''), $u['mot_de_passe'])) throw new InvalidArgumentException('Mot de passe actuel incorrect.');
            if (password_verify($nouveau, $u['mot_de_passe'])) throw new InvalidArgumentException('Le nouveau mot de passe doit être différent de l\'actuel.');
            if ($m = erreur_mot_de_passe($nouveau, (string)($_POST['confirmation'] ?? ''))) throw new InvalidArgumentException($m);
            $pdo->prepare('UPDATE utilisateurs SET mot_de_passe=?, doit_changer_mdp=0 WHERE id=?')->execute([password_hash($nouveau, PASSWORD_DEFAULT), $moi['id']]);
            $_SESSION['utilisateur']['doit_changer_mdp'] = 0;
            session_regenerate_id(true);
            flash('Mot de passe modifié.');
            redirect($force ? (peut('tableau_bord.voir') ? 'index.php' : 'mon_compte.php') : 'mon_compte.php');
        }
    } catch (InvalidArgumentException $e) {
        $erreurs[$action === 'profil' ? 'profil' : 'mdp'] = $e->getMessage();
    } catch (PDOException $e) {
        $erreurs['profil'] = $e->getCode() == 23000 ? 'Cette adresse e-mail est déjà utilisée par un autre compte.' : 'Erreur : ' . $e->getMessage();
    }
}
$st = $pdo->prepare('SELECT * FROM journal_connexions WHERE utilisateur_id=? ORDER BY id DESC LIMIT 10'); $st->execute([$moi['id']]); $journal = $st->fetchAll();
require 'includes/header.php';
?>
<div class="d-flex align-items-center gap-3 mb-4">
  <?= avatar($u, 64) ?>
  <div><h1 class="h3 mb-0"><?= e($u['nom']) ?></h1>
    <div><?= badge_profil($u) ?> <span class="text-muted small"><?= e($u['fonction']) ?></span></div></div>
</div>
<?php if ($force): ?><div class="alert alert-warning"><i class="bi bi-key"></i> Votre mot de passe est provisoire : choisissez-en un nouveau pour accéder à l'application.</div><?php endif; ?>

<div class="row g-3">
  <div class="col-xl-4 col-lg-6"><div class="card p-3 h-100">
    <h2 class="h6">Mes informations</h2>
    <?php if ($erreurs['profil']): ?><div class="alert alert-danger small py-2"><?= e($erreurs['profil']) ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="profil">
      <fieldset <?= $force ? 'disabled' : '' ?>>
      <div class="mb-2"><label class="form-label small" for="nom">Nom</label><input id="nom" name="nom" class="form-control form-control-sm" value="<?= e($_POST['nom'] ?? $u['nom']) ?>" required></div>
      <div class="mb-2"><label class="form-label small" for="email">Adresse e-mail (identifiant)</label><input id="email" type="email" name="email" class="form-control form-control-sm" value="<?= e($_POST['email'] ?? $u['email']) ?>" required>
        <div id="confEmail" class="mt-2" hidden><label class="form-label small" for="mdp_email">Mot de passe actuel (pour changer d'identifiant)</label><input id="mdp_email" type="password" name="mdp_email" class="form-control form-control-sm" autocomplete="current-password"></div></div>
      <div class="row g-2 mb-2">
        <div class="col-6"><label class="form-label small" for="tel">Téléphone</label><input id="tel" name="telephone" class="form-control form-control-sm" value="<?= e($_POST['telephone'] ?? $u['telephone']) ?>"></div>
        <div class="col-6"><label class="form-label small" for="fonction">Fonction</label><input id="fonction" name="fonction" class="form-control form-control-sm" value="<?= e($_POST['fonction'] ?? $u['fonction']) ?>"></div>
      </div>
      <div class="mb-3"><label class="form-label small" for="photo">Photo</label><input id="photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="form-control form-control-sm">
        <?php if ($u['photo']): ?><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="retirer_photo" id="rp"><label class="form-check-label small" for="rp">Retirer la photo</label></div><?php endif; ?></div>
      <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Enregistrer</button>
      </fieldset>
    </form>
    <dl class="fiche small mt-3 mb-0">
      <dt>Compte créé le</dt><dd><?= date('d/m/Y', strtotime($u['created_at'])) ?></dd>
      <dt>Dernière connexion</dt><dd><?= $u['derniere_connexion'] ? date('d/m/Y à H:i', strtotime($u['derniere_connexion'])) : '—' ?></dd>
    </dl>
  </div></div>

  <div class="col-xl-4 col-lg-6"><div class="card p-3 h-100">
    <h2 class="h6">Changer de mot de passe</h2>
    <?php if ($erreurs['mdp']): ?><div class="alert alert-danger small py-2"><?= e($erreurs['mdp']) ?></div><?php endif; ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="mot_de_passe">
      <div class="mb-2"><label class="form-label small" for="actuel">Mot de passe actuel</label><input id="actuel" type="password" name="actuel" class="form-control form-control-sm" required autocomplete="current-password"></div>
      <div class="mb-2"><label class="form-label small" for="nouveau">Nouveau mot de passe</label><input id="nouveau" type="password" name="nouveau" class="form-control form-control-sm" required minlength="<?= MDP_LONGUEUR_MIN ?>" autocomplete="new-password">
        <div class="form-text"><?= MDP_LONGUEUR_MIN ?> caractères minimum, avec au moins une lettre et un chiffre.</div></div>
      <div class="mb-3"><label class="form-label small" for="confirmation">Confirmation</label><input id="confirmation" type="password" name="confirmation" class="form-control form-control-sm" required autocomplete="new-password"></div>
      <button class="btn btn-primary btn-sm"><i class="bi bi-key"></i> Enregistrer</button>
    </form>
    <h2 class="h6 mt-4">Mes dernières connexions</h2>
    <ul class="list-unstyled small mb-0"><?php foreach ($journal as $j): ?>
      <li><?= $j['succes'] ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-danger"></i>' ?> <?= date('d/m/Y H:i', strtotime($j['date_connexion'])) ?>
        <span class="text-muted"><?= $j['succes'] ? '' : e($j['motif']) . ' · ' ?><?= e($j['ip']) ?></span></li><?php endforeach; ?>
      <?php if (!$journal): ?><li class="text-muted">—</li><?php endif; ?></ul>
    <p class="small text-muted mt-2 mb-0">Une tentative que vous ne reconnaissez pas ? Changez votre mot de passe et prévenez l'administrateur.</p>
  </div></div>

  <div class="col-xl-4"><div class="card p-3 h-100">
    <h2 class="h6">Mes droits <?= badge_profil($u) ?></h2>
    <p class="small text-muted"><?= e($u['profil_description']) ?></p>
    <?php foreach (PERMISSIONS as $groupe => $perms): $ok = array_filter(array_keys($perms), 'peut'); if (!$ok) continue; ?>
      <div class="small fw-semibold text-muted mt-2"><?= e($groupe) ?></div>
      <?php foreach ($ok as $code): ?><div class="small"><i class="bi bi-check-circle-fill text-success"></i> <?= e($perms[$code][0]) ?></div><?php endforeach; ?>
    <?php endforeach; ?>
    <p class="small text-muted mt-3 mb-0">Pour obtenir d'autres droits, adressez-vous à l'administrateur.</p>
  </div></div>
</div>
<?php
$scripts = '<script>const em = document.getElementById("email"), ce = document.getElementById("confEmail");
em.addEventListener("input", () => ce.hidden = em.value.trim().toLowerCase() === ' . json_encode($u['email']) . ');</script>';
require 'includes/footer.php';
