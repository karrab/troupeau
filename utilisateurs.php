<?php
require 'includes/functions.php';
exiger_role('admin');
$titre = 'Utilisateurs';
$moi = utilisateur();

/** Mot de passe provisoire lisible (sans caractères ambigus) */
function mdp_provisoire(): string
{
    $l = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ'; $c = '23456789'; $m = '';
    for ($i = 0; $i < 8; $i++) $m .= $l[random_int(0, strlen($l) - 1)];
    return $m . $c[random_int(0, 7)] . $c[random_int(0, 7)];
}
$nbAdmins = fn() => (int)$pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE role='admin' AND actif=1")->fetchColumn();
$cible = function (int $id) use ($pdo) { $st = $pdo->prepare('SELECT * FROM utilisateurs WHERE id=?'); $st->execute([$id]); return $st->fetch(); };
$provisoire = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $u = $id ? $cible($id) : null;
    try {
        switch ($_POST['action'] ?? '') {
            case 'creer':
                $nom = trim((string)post('nom')); $email = mb_strtolower(trim((string)post('email'))); $role = post('role');
                if ($nom === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Indiquez un nom et une adresse e-mail valide.');
                if (!isset(ROLES[$role])) throw new InvalidArgumentException('Rôle invalide.');
                $provisoire = mdp_provisoire();
                $pdo->prepare('INSERT INTO utilisateurs (nom, email, mot_de_passe, role, doit_changer_mdp) VALUES (?,?,?,?,1)')
                    ->execute([$nom, $email, password_hash($provisoire, PASSWORD_DEFAULT), $role]);
                $_SESSION['provisoire'] = [$email, $provisoire];
                flash("Compte créé pour $nom.");
                break;
            case 'role':
                if (!$u || !isset(ROLES[post('role')])) break;
                if ($u['role'] === 'admin' && post('role') !== 'admin' && $nbAdmins() <= 1) throw new InvalidArgumentException('Il doit rester au moins un administrateur actif.');
                $pdo->prepare('UPDATE utilisateurs SET role=? WHERE id=?')->execute([post('role'), $id]);
                flash('Rôle de ' . $u['nom'] . ' : ' . ROLES[post('role')][0] . '.');
                break;
            case 'actif':
                if (!$u) break;
                if ($id === $moi['id']) throw new InvalidArgumentException('Vous ne pouvez pas désactiver votre propre compte.');
                if ($u['actif'] && $u['role'] === 'admin' && $nbAdmins() <= 1) throw new InvalidArgumentException('Il doit rester au moins un administrateur actif.');
                $pdo->prepare('UPDATE utilisateurs SET actif=1-actif WHERE id=?')->execute([$id]);
                flash('Compte de ' . $u['nom'] . ($u['actif'] ? ' désactivé.' : ' réactivé.'), $u['actif'] ? 'warning' : 'success');
                break;
            case 'reinitialiser':
                if (!$u) break;
                $provisoire = mdp_provisoire();
                $pdo->prepare('UPDATE utilisateurs SET mot_de_passe=?, doit_changer_mdp=1, echecs=0, bloque_jusqua=NULL WHERE id=?')
                    ->execute([password_hash($provisoire, PASSWORD_DEFAULT), $id]);
                $_SESSION['provisoire'] = [$u['email'], $provisoire];
                flash('Mot de passe de ' . $u['nom'] . ' réinitialisé.');
                break;
            case 'supprimer':
                if (!$u) break;
                if ($id === $moi['id']) throw new InvalidArgumentException('Vous ne pouvez pas supprimer votre propre compte.');
                if ($u['role'] === 'admin' && $u['actif'] && $nbAdmins() <= 1) throw new InvalidArgumentException('Il doit rester au moins un administrateur actif.');
                $pdo->prepare('DELETE FROM utilisateurs WHERE id=?')->execute([$id]);
                flash('Compte de ' . $u['nom'] . ' supprimé.', 'warning');
                break;
        }
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'danger');
    } catch (PDOException $e) {
        flash($e->getCode() == 23000 ? 'Cette adresse e-mail est déjà utilisée.' : 'Erreur : ' . $e->getMessage(), 'danger');
    }
    redirect('utilisateurs.php');
}

// Mot de passe provisoire : affiché une seule fois
$provisoire = $_SESSION['provisoire'] ?? null; unset($_SESSION['provisoire']);
$liste = $pdo->query('SELECT * FROM utilisateurs ORDER BY actif DESC, role, nom')->fetchAll();
$bouton = fn(string $action, int $id, string $html, string $classe, string $confirm = '') =>
    '<form method="post" class="d-inline"' . ($confirm ? ' onsubmit="return confirm(\'' . e($confirm) . '\')"' : '') . '>' . csrf_field()
    . '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="id" value="' . $id . '">'
    . '<button class="btn btn-sm ' . $classe . '">' . $html . '</button></form>';
require 'includes/header.php';
?>
<h1 class="h3 mb-4">Utilisateurs</h1>
<?php if ($provisoire): ?>
<div class="alert alert-warning">
  <strong><i class="bi bi-key"></i> Mot de passe provisoire</strong> pour <strong><?= e($provisoire[0]) ?></strong> :
  <code class="fs-5 user-select-all ms-1"><?= e($provisoire[1]) ?></code>
  <div class="small mt-1">Communiquez-le à la personne : il ne sera plus affiché. Elle devra choisir son propre mot de passe à la première connexion.</div>
</div>
<?php endif; ?>
<div class="row g-3">
  <div class="col-xl-8"><div class="card">
    <div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
      <thead><tr><th>Nom</th><th>Identifiant</th><th>Rôle</th><th>Dernière connexion</th><th>État</th><th></th></tr></thead>
      <tbody><?php foreach ($liste as $x): $soi = (int)$x['id'] === $moi['id']; ?>
        <tr class="<?= $x['actif'] ? '' : 'text-muted' ?>">
          <td class="fw-semibold"><?= e($x['nom']) ?><?= $soi ? ' <span class="badge text-bg-light">vous</span>' : '' ?></td>
          <td><?= e($x['email']) ?></td>
          <td><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="id" value="<?= $x['id'] ?>">
            <select name="role" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
              <?php foreach (ROLES as $k => [$l]): ?><option value="<?= $k ?>" <?= $x['role'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></form></td>
          <td class="small"><?= $x['derniere_connexion'] ? date('d/m/Y H:i', strtotime($x['derniere_connexion'])) : 'jamais' ?></td>
          <td><?= $x['actif'] ? '<span class="badge text-bg-success">Actif</span>' : '<span class="badge text-bg-secondary">Désactivé</span>' ?>
            <?= $x['doit_changer_mdp'] ? '<span class="badge text-bg-warning">Mot de passe provisoire</span>' : '' ?>
            <?= $x['bloque_jusqua'] && $x['bloque_jusqua'] > date('Y-m-d H:i:s') ? '<span class="badge text-bg-danger">Bloqué</span>' : '' ?></td>
          <td class="text-end text-nowrap">
            <?= $bouton('reinitialiser', (int)$x['id'], '<i class="bi bi-key"></i>', 'btn-link p-0 me-2', 'Générer un nouveau mot de passe provisoire pour ' . $x['nom'] . ' ?') ?>
            <?php if (!$soi): ?>
              <?= $bouton('actif', (int)$x['id'], '<i class="bi bi-toggle-' . ($x['actif'] ? 'on text-success' : 'off text-muted') . ' fs-5"></i>', 'btn-link p-0 me-2') ?>
              <?= $bouton('supprimer', (int)$x['id'], '<i class="bi bi-trash"></i>', 'btn-link text-danger p-0', 'Supprimer définitivement le compte de ' . $x['nom'] . ' ?') ?>
            <?php endif; ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div></div>
    <div class="card p-3 mt-3 small">
      <h2 class="h6">Droits par rôle</h2>
      <?php foreach (ROLES as $k => [$l, $c, $d]): ?><div class="mb-1"><span class="badge text-bg-<?= $c ?>"><?= $l ?></span> <?= e($d) ?></div><?php endforeach; ?>
    </div>
  </div>
  <div class="col-xl-4"><div class="card p-3">
    <h2 class="h6">Nouvel utilisateur</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="creer">
      <div class="mb-2"><label class="form-label small" for="nom">Nom</label><input id="nom" name="nom" class="form-control form-control-sm" required></div>
      <div class="mb-2"><label class="form-label small" for="email">Adresse e-mail (identifiant)</label><input id="email" type="email" name="email" class="form-control form-control-sm" required></div>
      <div class="mb-3"><label class="form-label small" for="role">Rôle</label><select id="role" name="role" class="form-select form-select-sm">
        <?php foreach (ROLES as $k => [$l]): ?><option value="<?= $k ?>" <?= $k === 'eleveur' ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <button class="btn btn-primary btn-sm w-100"><i class="bi bi-person-plus"></i> Créer le compte</button>
      <div class="form-text">Un mot de passe provisoire est généré ; il devra être changé à la première connexion.</div>
    </form>
  </div></div>
</div>
<?php require 'includes/footer.php';
