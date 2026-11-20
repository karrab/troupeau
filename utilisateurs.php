<?php
require 'includes/functions.php';
exiger('utilisateurs.gerer', 'profils.gerer');
$titre = 'Utilisateurs';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exiger('utilisateurs.gerer');
    check_csrf();
    try {
        $nom = trim((string)post('nom')); $email = mb_strtolower(trim((string)post('email'))); $profil = (int)post('profil_id');
        if ($nom === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Indiquez un nom et une adresse e-mail valide.');
        if (!isset(profils_attribuables($pdo)[$profil])) throw new InvalidArgumentException('Profil non autorisé.');
        $provisoire = mdp_provisoire();
        $pdo->prepare('INSERT INTO utilisateurs (nom, email, mot_de_passe, profil_id, telephone, fonction, doit_changer_mdp) VALUES (?,?,?,?,?,?,1)')
            ->execute([$nom, $email, password_hash($provisoire, PASSWORD_DEFAULT), $profil, post('telephone'), post('fonction')]);
        $_SESSION['provisoire'] = [$email, $provisoire];
        flash("Compte créé pour $nom.");
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'danger');
    } catch (PDOException $e) {
        flash($e->getCode() == 23000 ? 'Cette adresse e-mail est déjà utilisée.' : 'Erreur : ' . $e->getMessage(), 'danger');
    }
    redirect('utilisateurs.php');
}

$provisoire = $_SESSION['provisoire'] ?? null; unset($_SESSION['provisoire']);
$filtre = (int)($_GET['profil'] ?? 0);
$liste = $pdo->query('SELECT u.*, p.nom profil_nom, p.couleur profil_couleur FROM utilisateurs u LEFT JOIN profils p ON p.id=u.profil_id
    ORDER BY u.actif DESC, p.systeme DESC, u.nom')->fetchAll();
if ($filtre) $liste = array_filter($liste, fn($u) => (int)$u['profil_id'] === $filtre);
$profils = profils($pdo);
$journal = $pdo->query('SELECT j.*, u.nom FROM journal_connexions j LEFT JOIN utilisateurs u ON u.id=j.utilisateur_id ORDER BY j.id DESC LIMIT 25')->fetchAll();
$moi = utilisateur();
require 'includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h3 mb-0">Utilisateurs</h1>
  <div class="d-flex gap-2">
    <form><select name="profil" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Filtrer par profil"><option value="">Tous les profils</option>
      <?php foreach ($profils as $p): ?><option value="<?= $p['id'] ?>" <?= $filtre === $p['id'] ? 'selected' : '' ?>><?= e($p['nom']) ?> (<?= $p['nb_utilisateurs'] ?>)</option><?php endforeach; ?></select></form>
    <?php if (peut('profils.gerer')): ?><a href="profils.php" class="btn btn-outline-primary btn-sm text-nowrap"><i class="bi bi-shield-lock"></i> Profils et permissions</a><?php endif; ?>
  </div>
</div>
<?php if ($provisoire): ?>
<div class="alert alert-warning">
  <strong><i class="bi bi-key"></i> Mot de passe provisoire</strong> pour <strong><?= e($provisoire[0]) ?></strong> :
  <code class="fs-5 user-select-all ms-1"><?= e($provisoire[1]) ?></code>
  <div class="small mt-1">Communiquez-le à la personne : il ne sera plus affiché. Elle devra choisir son propre mot de passe à la première connexion.</div>
</div>
<?php endif; ?>
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
      <thead><tr><th>Utilisateur</th><th>Fonction</th><th>Profil</th><th>Dernière connexion</th><th>État</th></tr></thead>
      <tbody><?php foreach ($liste as $x): ?>
        <tr class="<?= $x['actif'] ? '' : 'text-muted' ?>">
          <td><div class="d-flex align-items-center gap-2"><?= avatar($x, 32) ?><div>
            <a href="utilisateur.php?id=<?= $x['id'] ?>" class="fw-semibold"><?= e($x['nom']) ?></a><?= (int)$x['id'] === $moi['id'] ? ' <span class="badge text-bg-light">vous</span>' : '' ?>
            <div class="small text-muted"><?= e($x['email']) ?><?= $x['telephone'] ? ' · ' . e($x['telephone']) : '' ?></div></div></div></td>
          <td class="small"><?= e($x['fonction'] ?: '—') ?></td>
          <td><?= badge_profil($x) ?></td>
          <td class="small"><?= $x['derniere_connexion'] ? date('d/m/Y H:i', strtotime($x['derniere_connexion'])) : 'jamais' ?></td>
          <td><?= $x['actif'] ? '<span class="badge text-bg-success">Actif</span>' : '<span class="badge text-bg-secondary">Désactivé</span>' ?>
            <?= $x['doit_changer_mdp'] ? '<span class="badge text-bg-warning">Mot de passe provisoire</span>' : '' ?>
            <?= $x['bloque_jusqua'] && $x['bloque_jusqua'] > date('Y-m-d H:i:s') ? '<span class="badge text-bg-danger">Bloqué</span>' : '' ?></td>
        </tr>
      <?php endforeach; if (!$liste): ?><tr><td colspan="5" class="text-center text-muted">Aucun utilisateur.</td></tr><?php endif; ?></tbody>
    </table></div></div>

    <div class="card mt-3"><div class="card-header bg-white fw-semibold">Dernières connexions</div>
      <div class="table-responsive" style="max-height:320px"><table class="table table-sm mb-0 small align-middle">
        <thead><tr><th>Date</th><th>Compte</th><th>Résultat</th><th>Adresse IP</th></tr></thead>
        <tbody><?php foreach ($journal as $j): ?><tr>
          <td class="text-nowrap"><?= date('d/m/Y H:i', strtotime($j['date_connexion'])) ?></td>
          <td><?= e($j['nom'] ?? $j['email']) ?></td>
          <td><?= $j['succes'] ? '<span class="text-success"><i class="bi bi-check-circle"></i> réussie</span>' : '<span class="text-danger"><i class="bi bi-x-circle"></i> ' . e($j['motif']) . '</span>' ?></td>
          <td class="text-muted"><?= e($j['ip']) ?></td></tr><?php endforeach; ?>
        <?php if (!$journal): ?><tr><td colspan="4" class="text-muted text-center">Aucune connexion enregistrée.</td></tr><?php endif; ?></tbody>
      </table></div></div>
  </div>
  <div class="col-xl-4">
    <?php if (peut('utilisateurs.gerer')): ?>
    <div class="card p-3 mb-3">
      <h2 class="h6">Nouvel utilisateur</h2>
      <form method="post"><?= csrf_field() ?>
        <div class="mb-2"><label class="form-label small" for="nom">Nom</label><input id="nom" name="nom" class="form-control form-control-sm" required></div>
        <div class="mb-2"><label class="form-label small" for="email">Adresse e-mail (identifiant)</label><input id="email" type="email" name="email" class="form-control form-control-sm" required></div>
        <div class="row g-2 mb-2">
          <div class="col-6"><label class="form-label small" for="tel">Téléphone</label><input id="tel" name="telephone" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small" for="fonction">Fonction</label><input id="fonction" name="fonction" class="form-control form-control-sm" placeholder="Berger, vétérinaire…"></div>
        </div>
        <div class="mb-3"><label class="form-label small" for="profil_id">Profil</label><select id="profil_id" name="profil_id" class="form-select form-select-sm">
          <?php foreach (profils_attribuables($pdo) as $p): ?><option value="<?= $p['id'] ?>" <?= $p['code'] === 'eleveur' ? 'selected' : '' ?>><?= e($p['nom']) ?></option><?php endforeach; ?></select></div>
        <button class="btn btn-primary btn-sm w-100"><i class="bi bi-person-plus"></i> Créer le compte</button>
        <div class="form-text">Un mot de passe provisoire est généré ; il devra être changé à la première connexion.</div>
      </form>
    </div>
    <?php endif; ?>
    <div class="card p-3 small">
      <h2 class="h6">Profils</h2>
      <?php foreach ($profils as $p): ?><div class="mb-2"><?= badge_profil(['profil_nom' => $p['nom'], 'profil_couleur' => $p['couleur']]) ?>
        <span class="text-muted"><?= $p['systeme'] ? 'toutes les permissions' : count($p['permissions']) . ' permission(s)' ?></span><br><?= e($p['description']) ?></div><?php endforeach; ?>
    </div>
  </div>
</div>
<?php require 'includes/footer.php';
