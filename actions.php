<?php
// Traitement centralisé des ajouts / suppressions de l'historique d'un animal
require 'includes/functions.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
check_csrf();

$animalId = (int)($_POST['animal_id'] ?? 0);
$type     = $_POST['type'] ?? '';
$op       = $_POST['op'] ?? '';
$tables   = ['pesee' => ['pesees', 'poids'], 'lait' => ['controles_laitiers', 'lait'], 'repro' => ['reproductions', 'repro'],
             'sante' => ['sante', 'sante'], 'mvt' => ['mouvements', 'mvt']];
if (!isset($tables[$type])) redirect('index.php');
[$table, $tab] = $tables[$type];
$retour = "animal.php?id=$animalId&tab=$tab";

$st = $pdo->prepare('SELECT a.*, e.gestation_jours FROM animaux a JOIN especes e ON e.id=a.espece_id WHERE a.id=?');
$st->execute([$animalId]);
$animal = $st->fetch() ?: redirect('animaux.php');

// ---------- Suppression ----------
if ($op === 'del') {
    $pdo->prepare("DELETE FROM $table WHERE id=? AND animal_id=?")->execute([(int)$_POST['id'], $animalId]);
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
            $pdo->prepare('INSERT INTO reproductions (animal_id,type_evenement,date_evenement,male_id,semence,resultat,nb_petits,date_mise_bas_prevue,intervenant,observations) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$animalId, $t, $date,
                    $t === 'saillie' ? post('male_id') : null,
                    $t === 'insemination' ? post('semence') : null,
                    $t === 'diagnostic' ? post('resultat') : null,
                    $t === 'mise_bas' ? post('nb_petits') : null,
                    $prevue, post('intervenant'), post('observations')]);
            flash(TYPES_REPRO[$t] . ' enregistrée.' . ($prevue ? ' Mise bas prévue le ' . dfr($prevue) . '.' : ''));
            if ($t === 'mise_bas') { flash('Mise bas enregistrée. Créez maintenant la fiche du petit.'); redirect("animal_form.php?mere=$animalId"); }
            break;

        case 'sante':
            $t = post('type_sante');
            if (!isset(TYPES_SANTE[$t])) throw new InvalidArgumentException('Type d\'intervention invalide.');
            $pdo->prepare('INSERT INTO sante (animal_id,date_intervention,type,diagnostic,produit,dose,voie,veterinaire,delai_attente_lait,delai_attente_viande,cout,date_rappel) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$animalId, post('date_intervention'), $t, post('diagnostic'), post('produit'), post('dose'), post('voie'), post('veterinaire'),
                    (int)post('delai_attente_lait'), (int)post('delai_attente_viande'), post('cout'), post('date_rappel')]);
            flash('Intervention enregistrée.');
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
} catch (InvalidArgumentException $e) {
    flash($e->getMessage(), 'danger');
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash($e->getCode() == 23000 ? 'Un enregistrement existe déjà pour cette date.' : 'Erreur base de données : ' . $e->getMessage(), 'danger');
}
redirect($retour);
