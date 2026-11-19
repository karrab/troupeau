<?php
// =====================================================================
//  Mensurations
//  - à la mise bas (mère) : PMB (poids à la mise bas) + L1 … L6
//  - croissance (chevreau) : stades W0, W1, W2 … chacun avec poids + L1 … L6
//  Le poids saisi est aussi enregistré comme pesée : il alimente le GMQ,
//  le poids au sevrage et les indices.
// =====================================================================

const MESURES = ['l1', 'l2', 'l3', 'l4', 'l5', 'l6'];

/** Libellés des mesures L1…L6 (modifiables dans Paramètres) : ['l1' => 'L1 – Longueur du corps', …] */
function libelles_mesures(PDO $pdo): array
{
    $l = [];
    foreach (MESURES as $k) {
        $nom = trim((string)parametre_app($pdo, 'mesure_' . $k, ''));
        $l[$k] = strtoupper($k) . ($nom !== '' ? ' – ' . $nom : '');
    }
    return $l;
}
function unite_mesures(PDO $pdo): string { return (string)parametre_app($pdo, 'mesure_unite', 'cm'); }

/** Libellé court d'un stade de croissance : W0, W1… */
function libelle_stade(?int $stade): string { return $stade === null ? 'Mise bas' : 'W' . $stade; }

/**
 * Enregistre une mensuration et, si un poids est saisi, la pesée correspondante.
 * $m : animal_id, type ('mise_bas'|'croissance'), stade, reproduction_id, date_mesure, poids, l1…l6, observations
 */
function enregistrer_mensuration(PDO $pdo, array $m): int
{
    $vides = array_filter([$m['poids'] ?? null, ...array_map(fn($k) => $m[$k] ?? null, MESURES)], fn($v) => $v !== null && $v !== '');
    if (!$vides) throw new InvalidArgumentException('Saisissez au moins le poids ou une mesure.');
    if (!$m['date_mesure']) throw new InvalidArgumentException('La date est obligatoire.');
    foreach (['poids', ...MESURES] as $k) if (($m[$k] ?? null) !== null && (float)$m[$k] < 0) throw new InvalidArgumentException('Les valeurs doivent être positives.');
    if ($m['type'] === 'croissance' && ($m['stade'] === null || $m['stade'] < 0)) throw new InvalidArgumentException('Indiquez le stade (W0, W1…).');

    // Pesée associée : réutilise celle du même jour si elle existe
    $peseeId = null;
    if (($m['poids'] ?? null) !== null && $m['poids'] !== '') {
        $obs = $m['type'] === 'mise_bas' ? 'Poids à la mise bas (PMB)' : 'Mensuration ' . libelle_stade($m['stade']);
        $st = $pdo->prepare('SELECT id FROM pesees WHERE animal_id=? AND date_pesee=? LIMIT 1');
        $st->execute([$m['animal_id'], $m['date_mesure']]);
        if ($peseeId = $st->fetchColumn()) {
            $pdo->prepare('UPDATE pesees SET poids=? WHERE id=?')->execute([$m['poids'], $peseeId]);
        } else {
            $pdo->prepare("INSERT INTO pesees (animal_id, date_pesee, poids, methode, observations) VALUES (?,?,?,'balance',?)")
                ->execute([$m['animal_id'], $m['date_mesure'], $m['poids'], $obs]);
            $peseeId = (int)$pdo->lastInsertId();
        }
    }
    $pdo->prepare('INSERT INTO mensurations (animal_id, type, stade, reproduction_id, date_mesure, poids, l1, l2, l3, l4, l5, l6, pesee_id, observations)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$m['animal_id'], $m['type'], $m['type'] === 'croissance' ? $m['stade'] : null, $m['reproduction_id'] ?? null, $m['date_mesure'],
                   $m['poids'] ?? null, ...array_map(fn($k) => $m[$k] ?? null, MESURES), $peseeId, $m['observations'] ?? null]);
    return (int)$pdo->lastInsertId();
}

/** Supprime une mensuration et la pesée qu'elle avait créée ; renvoie la date (pour le recalcul des indices) */
function supprimer_mensuration(PDO $pdo, int $id, int $animalId): ?string
{
    $st = $pdo->prepare('SELECT * FROM mensurations WHERE id=? AND animal_id=?'); $st->execute([$id, $animalId]);
    if (!$m = $st->fetch()) return null;
    $pdo->prepare('DELETE FROM mensurations WHERE id=?')->execute([$id]);
    if ($m['pesee_id']) {
        $autre = $pdo->prepare('SELECT COUNT(*) FROM mensurations WHERE pesee_id=?'); $autre->execute([$m['pesee_id']]);
        if (!$autre->fetchColumn()) $pdo->prepare('DELETE FROM pesees WHERE id=?')->execute([$m['pesee_id']]);
    }
    return $m['date_mesure'];
}

/** Valeurs L1…L6 lues dans $_POST (préfixe optionnel, ex. 'mb_') */
function mesures_post(string $prefixe = ''): array
{
    $r = [];
    foreach (['poids', ...MESURES] as $k) {
        $v = trim(str_replace(',', '.', (string)($_POST[$prefixe . $k] ?? '')));
        $r[$k] = $v === '' ? null : (is_numeric($v) ? $v : throw new InvalidArgumentException(strtoupper($k) . ' : valeur numérique attendue.'));
    }
    return $r;
}
