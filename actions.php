<?php
// Traitement centralisé des ajouts / suppressions de l'historique d'un animal
require 'includes/functions.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
check_csrf();

$animalId = (int)($_POST['animal_id'] ?? 0);
$type     = $_POST['type'] ?? '';
$op       = $_POST['op'] ?? '';
$tables   = ['pesee' => ['pesees', 'poids'], 'lait' => ['controles_laitiers', 'lait'], 'repro' => ['reproductions', 'repro'],
             'sante' => ['sante', 'sante'], 'mvt' => ['mouvements', 'mvt'], 'alim' => ['alimentation', 'alim'],
             'mens' => ['mensurations', 'mens']];
if (!isset($tables[$type])) redirect('index.php');
if (!(($_POST['op'] ?? '') === 'del' ? peut_supprimer($type) : peut_saisir($type))) {
    flash('Votre profil ne permet pas cette ' . (($_POST['op'] ?? '') === 'del' ? 'suppression' : 'saisie') . '.', 'danger');
    redirect('animal.php?id=' . (int)($_POST['animal_id'] ?? 0));
}
[$table, $tab] = $tables[$type];
$retour = "animal.php?id=$animalId&tab=$tab";

$st = $pdo->prepare('SELECT a.*, e.gestation_jours FROM animaux a JOIN especes e ON e.id=a.espece_id WHERE a.id=?');
$st->execute([$animalId]);
$animal = $st->fetch() ?: redirect('animaux.php');

// ---------- Suppression ----------
$colDate = ['pesee' => 'date_pesee', 'lait' => 'date_controle', 'repro' => 'date_evenement', 'sante' => 'date_intervention', 'mvt' => 'date_mouvement', 'alim' => 'date_distribution', 'mens' => 'date_mesure'][$type];
if ($op === 'del' && $type === 'mens') {
    maj_indices_auto($pdo, supprimer_mensuration($pdo, (int)$_POST['id'], $animalId));
    flash('Mensuration supprimée (et sa pesée).', 'warning');
    redirect($retour);
}
if ($op === 'del') {
    $d = $pdo->prepare("SELECT $colDate FROM $table WHERE id=? AND animal_id=?");
    $d->execute([(int)$_POST['id'], $animalId]);
    $dateLigne = $d->fetchColumn() ?: null;
    $pdo->prepare("DELETE FROM $table WHERE id=? AND animal_id=?")->execute([(int)$_POST['id'], $animalId]);
    maj_indices_auto($pdo, $dateLigne);
    flash('Ligne supprimée.', 'warning');
    redirect($retour);
}

// ---------- Ajout ----------
try {
    switch ($type) {
        case 'pesee':
            if (!post('date_pesee') || (float)post('poids') <= 0) throw new InvalidArgumentException('Date et poids obligatoires.');
            $pdo->prepare('INSERT INTO pesees (animal_id,date_pesee,poids,note_etat_corporel,methode,observations) VALUES (?,?,?,?,?,?)')
                ->execute([$animalId, post('date_pesee'), post('poids'), post('note_etat_corporel'), post('methode') ?? 'balance', post('observations')]);
            flash('Pesée enregistrée.');
            break;

        case 'lait':
            if (post('traite_matin') === null && post('traite_soir') === null) throw new InvalidArgumentException('Saisissez au moins une traite.');
            $pdo->prepare('INSERT INTO controles_laitiers (animal_id,date_controle,traite_matin,traite_soir,taux_butyreux,taux_proteique,cellules,observations) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$animalId, post('date_controle'), post('traite_matin'), post('traite_soir'), post('taux_butyreux'), post('taux_proteique'), post('cellules'), post('observations')]);
            $msg = 'Contrôle laitier enregistré.';
            if ((int)post('cellules') >= SEUIL_CELLULES) { flash($msg . ' Attention : comptage cellulaire élevé, risque de mammite.', 'warning'); break; }
            flash($msg);
            break;

        case 'repro':
            $t = post('type_evenement');
            if (!isset(TYPES_REPRO[$t])) throw new InvalidArgumentException('Type d\'événement invalide.');
            $date = post('date_evenement');
            $prevue = in_array($t, ['saillie','insemination'], true)
                ? date('Y-m-d', strtotime("$date +{$animal['gestation_jours']} days")) : null;
            if ($t === 'mise_bas') mesures_post('mb_'); // valide les mensurations avant d'enregistrer
            if ($t === 'mise_bas' && (int)post('nb_morts_nes') > (int)post('nb_petits')) throw new InvalidArgumentException('Le nombre de mort-nés dépasse le nombre de petits.');
            $pdo->prepare('INSERT INTO reproductions (animal_id,type_evenement,date_evenement,male_id,semence,resultat,nb_petits,nb_morts_nes,date_mise_bas_prevue,intervenant,observations) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$animalId, $t, $date,
                    $t === 'saillie' ? post('male_id') : null,
                    $t === 'insemination' ? post('semence') : null,
                    $t === 'diagnostic' ? post('resultat') : null,
                    $t === 'mise_bas' ? post('nb_petits') : null,
                    $t === 'mise_bas' ? (int)post('nb_morts_nes') : null,
                    $prevue, post('intervenant'), post('observations')]);
            flash(TYPES_REPRO[$t] . ' enregistrée.' . ($prevue ? ' Mise bas prévue le ' . dfr($prevue) . '.' : ''));
            if ($t === 'mise_bas') {
                $mb = mesures_post('mb_');
                if (array_filter($mb, fn($v) => $v !== null) && peut('mensurations.saisir')) {
                    enregistrer_mensuration($pdo, ['animal_id' => $animalId, 'type' => 'mise_bas', 'stade' => null, 'reproduction_id' => (int)$pdo->lastInsertId(),
                                                   'date_mesure' => $date] + $mb);
                }
                maj_indices_auto($pdo, $date); if (!peut('animaux.creer')) { flash('Mise bas enregistrée.'); break; }
                flash('Mise bas enregistrée. Créez maintenant la fiche du petit.'); redirect("animal_form.php?mere=$animalId"); }
            break;

        case 'sante':
            $t = post('type_sante');
            if (!isset(TYPES_SANTE[$t])) throw new InvalidArgumentException('Type d\'intervention invalide.');
            $pdo->prepare('INSERT INTO sante (animal_id,date_intervention,type,diagnostic,produit,dose,voie,veterinaire,delai_attente_lait,delai_attente_viande,cout,date_rappel) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$animalId, post('date_intervention'), $t, post('diagnostic'), post('produit'), post('dose'), post('voie'), post('veterinaire'),
                    (int)post('delai_attente_lait'), (int)post('delai_attente_viande'), post('cout'), post('date_rappel')]);
            flash('Intervention enregistrée.');
            break;

        case 'alim':
            if (!post('date_distribution') || !post('aliment') || (float)post('quantite_kg') <= 0) throw new InvalidArgumentException('Date, aliment et quantité obligatoires.');
            $pdo->prepare('INSERT INTO alimentation (animal_id,date_distribution,aliment,quantite_kg,cout,observations) VALUES (?,?,?,?,?,?)')
                ->execute([$animalId, post('date_distribution'), post('aliment'), post('quantite_kg'), post('cout'), post('observations')]);
            flash('Distribution enregistrée.');
            break;

        case 'mens':
            $m = ['animal_id' => $animalId, 'type' => post('type_mesure') === 'mise_bas' ? 'mise_bas' : 'croissance',
                  'stade' => post('stade') === null ? null : (int)post('stade'), 'reproduction_id' => post('reproduction_id') ? (int)post('reproduction_id') : null,
                  'date_mesure' => post('date_mesure'), 'observations' => post('observations')] + mesures_post();
            if ($m['type'] === 'mise_bas' && $m['reproduction_id']) {
                $st = $pdo->prepare("SELECT date_evenement FROM reproductions WHERE id=? AND animal_id=? AND type_evenement='mise_bas'");
                $st->execute([$m['reproduction_id'], $animalId]);
                $m['date_mesure'] = $st->fetchColumn() ?: throw new InvalidArgumentException('Mise bas introuvable.');
                $dup = $pdo->prepare('SELECT 1 FROM mensurations WHERE reproduction_id=?'); $dup->execute([$m['reproduction_id']]);
                if ($dup->fetch()) throw new InvalidArgumentException('Cette mise bas a déjà ses mensurations : supprimez-les pour les ressaisir.');
            }
            $pdo->beginTransaction();
            enregistrer_mensuration($pdo, $m);
            $pdo->commit();
            flash('Mensuration ' . ($m['type'] === 'mise_bas' ? 'de mise bas' : libelle_stade($m['stade'])) . ' enregistrée.');
            $colDate = 'date_mesure'; $_POST['date_mesure'] = $m['date_mesure'];
            break;

        case 'mvt':
            $dest = (int)post('lot_destination');
            if (!$dest) throw new InvalidArgumentException('Choisissez le lot de destination.');
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO mouvements (animal_id,date_mouvement,type,lot_origine,lot_destination,motif) VALUES (?,?,'transfert',?,?,?)")
                ->execute([$animalId, post('date_mouvement'), $animal['lot_id'], $dest, post('motif')]);
            $pdo->prepare('UPDATE animaux SET lot_id=? WHERE id=?')->execute([$dest, $animalId]);
            $pdo->commit();
            flash('Transfert enregistré.');
            break;
    }
    // Indices de l'année concernée recalculés et enregistrés automatiquement
    maj_indices_auto($pdo, post($colDate), $type === 'mvt' ? date('Y-m-d') : null);
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash($e->getMessage(), 'danger');
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash($e->getCode() == 23000 ? ($type === 'mens' ? 'Ce stade est déjà mesuré pour cet animal : supprimez-le pour le ressaisir.' : 'Un enregistrement existe déjà pour cette date.') : 'Erreur base de données : ' . $e->getMessage(), 'danger');
}
redirect($retour);
