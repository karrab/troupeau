<?php
// =====================================================================
//  Moteur des indices : variables de base, formules paramétrables,
//  calcul par année et par niveau (animal, lot, espèce, troupeau),
//  interprétation par seuils et enregistrement des résultats.
// =====================================================================

const NIVEAUX_INDICES = ['animal' => 'Animal', 'lot' => 'Lot', 'espece' => 'Espèce', 'troupeau' => 'Troupeau'];
const AGREGATIONS = [
    'moyenne' => 'Moyenne des valeurs des animaux',
    'somme'   => 'Somme des valeurs des animaux',
    'min'     => 'Minimum des animaux',
    'max'     => 'Maximum des animaux',
    'ratio'   => 'Formule appliquée aux totaux du groupe (taux, ratios)',
];
const COULEURS_INDICES = ['success' => 'Vert – bon', 'warning' => 'Orange – à surveiller', 'danger' => 'Rouge – mauvais', 'info' => 'Bleu – information', 'secondary' => 'Gris – neutre'];

/** Variables utilisables dans les formules, calculées pour chaque animal et chaque année */
const VARIABLES_INDICES = [
    'Général' => [
        'nb_animaux'      => 'Nombre d\'animaux (1 par animal ; effectif du groupe avec l\'agrégation « ratio »)',
        'age_jours'       => 'Âge en jours au 31/12 (ou à la date de sortie / aujourd\'hui)',
        'age_mois'        => 'Âge en mois au 31/12',
        'jours_presence'  => 'Nombre de jours de présence dans l\'élevage pendant l\'année',
        'femelle'         => '1 si femelle, 0 sinon',
        'mort'            => '1 si l\'animal est mort dans l\'année, 0 sinon',
        'vendu'           => '1 si l\'animal a été vendu dans l\'année, 0 sinon',
        'reforme'         => '1 si l\'animal a été réformé dans l\'année, 0 sinon',
        'ne_annee'        => '1 si l\'animal est né dans l\'année, 0 sinon',
    ],
    'Poids et croissance' => [
        'poids_naissance' => 'Poids de naissance (kg)',
        'nb_pesees'       => 'Nombre de pesées dans l\'année',
        'poids_debut'     => 'Première pesée de l\'année (kg)',
        'poids_fin'       => 'Dernière pesée de l\'année (kg)',
        'poids_max'       => 'Poids maximal de l\'année (kg)',
        'gain_poids'      => 'Gain de poids entre la première et la dernière pesée de l\'année (kg)',
        'jours_pesees'    => 'Jours entre la première et la dernière pesée de l\'année',
        'gmq'             => 'Gain moyen quotidien sur l\'année (g/jour)',
        'nec_moyen'       => 'Note d\'état corporel moyenne (1–5)',
    ],
    'Lait' => [
        'nb_controles'     => 'Nombre de contrôles laitiers',
        'lait_moyen'       => 'Production moyenne par contrôle (L/jour)',
        'lait_pic'         => 'Production maximale (L/jour)',
        'lait_cumul'       => 'Lait produit entre le premier et le dernier contrôle (L, méthode des trapèzes)',
        'jours_controle'   => 'Jours entre le premier et le dernier contrôle',
        'tb_moyen'         => 'Taux butyreux moyen (g/L)',
        'tp_moyen'         => 'Taux protéique moyen (g/L)',
        'cellules_moyen'   => 'Comptage cellulaire moyen (×1000/mL)',
        'cellules_max'     => 'Comptage cellulaire maximal (×1000/mL)',
        'nb_cellules_hautes' => 'Nombre de contrôles au-dessus du seuil cellules (' . SEUIL_CELLULES . ')',
    ],
    'Reproduction' => [
        'nb_chaleurs'      => 'Chaleurs observées',
        'nb_ia'            => 'Mises à la reproduction (saillies + inséminations)',
        'nb_diag_pos'      => 'Diagnostics de gestation positifs',
        'nb_diag_neg'      => 'Diagnostics de gestation négatifs',
        'nb_mises_bas'     => 'Mises bas',
        'nb_petits'        => 'Petits nés (somme des portées)',
        'nb_avortements'   => 'Avortements',
        'ivv'              => 'Intervalle moyen entre mises bas (jours), pour les mises bas de l\'année',
        'age_1ere_mb_mois' => 'Âge à la première mise bas (mois)',
        'nb_descendants'   => 'Descendants nés dans l\'année (comme mère ou père)',
    ],
    'Santé' => [
        'nb_interventions' => 'Interventions sanitaires (tous types)',
        'nb_traitements'   => 'Traitements curatifs',
        'nb_vaccinations'  => 'Vaccinations',
        'cout_sante'       => 'Coût sanitaire de l\'année',
    ],
];

function variables_indices_liste(): array {
    $l = [];
    foreach (VARIABLES_INDICES as $groupe) $l += $groupe;
    return $l;
}

// ---------------------------------------------------------------------
//  Analyseur de formules (sans eval) : + - * / % ^ ( ) comparaisons,
//  et / ou / non, fonctions min max abs arrondi racine si sinon
// ---------------------------------------------------------------------
final class Formule
{
    /** nom => [nb args min, nb args max] */
    public const FONCTIONS = ['min' => [1, 99], 'max' => [1, 99], 'abs' => [1, 1], 'arrondi' => [1, 2], 'racine' => [1, 1],
                              'si' => [2, 3], 'sinon' => [2, 2], 'log' => [1, 1]];
    private const MOTS = ['et', 'ou', 'non'];

    private array $t = [];
    private int $p = 0;
    private array $ast;
    public array $variables = [];

    public function __construct(public readonly string $source)
    {
        $this->t = self::decouper($source);
        if (!$this->t) throw new InvalidArgumentException('La formule est vide.');
        $this->ast = $this->ou();
        if ($this->p < count($this->t)) throw new InvalidArgumentException('Symbole inattendu « ' . $this->t[$this->p][1] . ' ».');
        $this->variables = array_values(array_unique($this->variables));
    }

    private static function decouper(string $s): array
    {
        $tokens = []; $i = 0; $n = strlen($s);
        while ($i < $n) {
            if (ctype_space($s[$i])) { $i++; continue; }
            if (preg_match('/\G(\d+(?:\.\d+)?|\.\d+)/', $s, $m, 0, $i)) { $tokens[] = ['n', (float)$m[1]]; }
            elseif (preg_match('/\G([A-Za-z_][A-Za-z0-9_]*)/', $s, $m, 0, $i)) { $tokens[] = ['id', strtolower($m[1])]; }
            elseif (preg_match('/\G(<=|>=|==|!=|<>|&&|\|\||[-+*\/%^(),<>=!])/', $s, $m, 0, $i)) {
                $op = ['&&' => 'et', '||' => 'ou', '!' => 'non', '<>' => '!=', '=' => '=='][$m[1]] ?? $m[1];
                $tokens[] = [in_array($op, self::MOTS, true) ? 'id' : 'op', $op];
            }
            else throw new InvalidArgumentException('Caractère non autorisé « ' . mb_substr(substr($s, $i), 0, 1) . ' » (position ' . ($i + 1) . '). Utilisez le point comme séparateur décimal.');
            $i += strlen($m[1]);
        }
        return $tokens;
    }

    private function voir(): ?array { return $this->t[$this->p] ?? null; }
    private function est(string $type, string ...$vals): bool
    {
        $x = $this->voir();
        return $x !== null && $x[0] === $type && (!$vals || in_array($x[1], $vals, true));
    }
    private function attendre(string $op): void
    {
        if (!$this->est('op', $op)) throw new InvalidArgumentException('« ' . $op . ' » attendu' . ($this->voir() ? ' avant « ' . $this->voir()[1] . ' »' : ' en fin de formule') . '.');
        $this->p++;
    }

    private function ou(): array
    {
        $a = $this->et();
        while ($this->est('id', 'ou')) { $this->p++; $a = ['op', 'ou', $a, $this->et()]; }
        return $a;
    }
    private function et(): array
    {
        $a = $this->comparaison();
        while ($this->est('id', 'et')) { $this->p++; $a = ['op', 'et', $a, $this->comparaison()]; }
        return $a;
    }
    private function comparaison(): array
    {
        $a = $this->addition();
        if ($this->est('op', '<', '>', '<=', '>=', '==', '!=')) { $op = $this->t[$this->p++][1]; $a = ['op', $op, $a, $this->addition()]; }
        return $a;
    }
    private function addition(): array
    {
        $a = $this->produit();
        while ($this->est('op', '+', '-')) { $op = $this->t[$this->p++][1]; $a = ['op', $op, $a, $this->produit()]; }
        return $a;
    }
    private function produit(): array
    {
        $a = $this->unaire();
        while ($this->est('op', '*', '/', '%')) { $op = $this->t[$this->p++][1]; $a = ['op', $op, $a, $this->unaire()]; }
        return $a;
    }
    private function unaire(): array
    {
        if ($this->est('op', '-')) { $this->p++; return ['neg', $this->unaire()]; }
        if ($this->est('op', '+')) { $this->p++; return $this->unaire(); }
        if ($this->est('id', 'non')) { $this->p++; return ['non', $this->unaire()]; }
        return $this->puissance();
    }
    private function puissance(): array
    {
        $a = $this->primaire();
        if ($this->est('op', '^')) { $this->p++; $a = ['op', '^', $a, $this->unaire()]; }
        return $a;
    }
    private function primaire(): array
    {
        $x = $this->voir();
        if ($x === null) throw new InvalidArgumentException('Formule incomplète.');
        if ($x[0] === 'n') { $this->p++; return ['n', $x[1]]; }
        if ($this->est('op', '(')) { $this->p++; $a = $this->ou(); $this->attendre(')'); return $a; }
        if ($x[0] === 'id' && !in_array($x[1], self::MOTS, true)) {
            $this->p++;
            if ($this->est('op', '(')) {
                if (!isset(self::FONCTIONS[$x[1]])) throw new InvalidArgumentException('Fonction inconnue « ' . $x[1] . ' ».');
                $this->p++; $args = [];
                if (!$this->est('op', ')')) {
                    $args[] = $this->ou();
                    while ($this->est('op', ',')) { $this->p++; $args[] = $this->ou(); }
                }
                $this->attendre(')');
                [$mn, $mx] = self::FONCTIONS[$x[1]];
                if (count($args) < $mn || count($args) > $mx) throw new InvalidArgumentException('Nombre d\'arguments incorrect pour ' . $x[1] . '().');
                return ['f', $x[1], $args];
            }
            if (!array_key_exists($x[1], variables_indices_liste())) throw new InvalidArgumentException('Variable inconnue « ' . $x[1] . ' ».');
            $this->variables[] = $x[1];
            return ['v', $x[1]];
        }
        throw new InvalidArgumentException('Symbole inattendu « ' . $x[1] . ' ».');
    }

    /** Évalue la formule ; renvoie null si une donnée manque ou si le calcul est impossible (division par zéro…) */
    public function evaluer(array $vars): ?float
    {
        $r = $this->ev($this->ast, $vars);
        return $r === null || !is_finite($r) ? null : $r;
    }

    private function ev(array $n, array $v): ?float
    {
        switch ($n[0]) {
            case 'n': return $n[1];
            case 'v': return isset($v[$n[1]]) ? (float)$v[$n[1]] : null;
            case 'neg': $a = $this->ev($n[1], $v); return $a === null ? null : -$a;
            case 'non': $a = $this->ev($n[1], $v); return $a === null ? null : (float)($a == 0);
            case 'f': return $this->fonction($n[1], $n[2], $v);
        }
        [, $op, $na, $nb] = $n;
        $a = $this->ev($na, $v);
        if ($op === 'et' && $a !== null && $a == 0) return 0.0;
        if ($op === 'ou' && $a !== null && $a != 0) return 1.0;
        $b = $this->ev($nb, $v);
        if ($a === null || $b === null) return null;
        return match ($op) {
            '+' => $a + $b, '-' => $a - $b, '*' => $a * $b,
            '/' => $b == 0 ? null : $a / $b,
            '%' => $b == 0 ? null : fmod($a, $b),
            '^' => ($r = @pow($a, $b)) === null || is_nan((float)$r) ? null : (float)$r,
            '<' => (float)($a < $b), '>' => (float)($a > $b), '<=' => (float)($a <= $b), '>=' => (float)($a >= $b),
            '==' => (float)(abs($a - $b) < 1e-9), '!=' => (float)(abs($a - $b) >= 1e-9),
            'et' => (float)($a != 0 && $b != 0), 'ou' => (float)($a != 0 || $b != 0),
        };
    }

    private function fonction(string $f, array $args, array $v): ?float
    {
        if ($f === 'si') {
            $c = $this->ev($args[0], $v);
            if ($c === null) return null;
            if ($c != 0) return $this->ev($args[1], $v);
            return isset($args[2]) ? $this->ev($args[2], $v) : null; // si(condition, valeur) : non calculé si faux
        }
        if ($f === 'sinon') return $this->ev($args[0], $v) ?? $this->ev($args[1], $v);
        $x = [];
        foreach ($args as $a) { $r = $this->ev($a, $v); if ($r === null) return null; $x[] = $r; }
        return match ($f) {
            'min' => min($x), 'max' => max($x), 'abs' => abs($x[0]),
            'arrondi' => round($x[0], (int)($x[1] ?? 0)),
            'racine' => $x[0] < 0 ? null : sqrt($x[0]),
            'log' => $x[0] <= 0 ? null : log($x[0]),
        };
    }
}

// ---------------------------------------------------------------------
//  Calcul des variables de base pour tous les animaux présents une année
// ---------------------------------------------------------------------
function moyenne(array $x): ?float { $x = array_filter($x, fn($v) => $v !== null && $v !== ''); return $x ? array_sum($x) / count($x) : null; }
function jours_entre(string $a, string $b): int { return (int)round((strtotime($b) - strtotime($a)) / 86400); }

/** Années pour lesquelles des données existent (+ année en cours) */
function annees_indices(PDO $pdo): array
{
    $y = $pdo->query("SELECT YEAR(date_controle) FROM controles_laitiers UNION SELECT YEAR(date_pesee) FROM pesees
        UNION SELECT YEAR(date_evenement) FROM reproductions UNION SELECT YEAR(date_intervention) FROM sante")->fetchAll(PDO::FETCH_COLUMN);
    $y[] = (int)date('Y');
    $y = array_unique(array_map('intval', $y));
    rsort($y);
    return $y;
}

/** @return array<int, array{animal: array, vars: array}> */
function variables_annee(PDO $pdo, int $annee): array
{
    $debut = "$annee-01-01"; $fin = "$annee-12-31";
    $ref = min($fin, date('Y-m-d'));

    $st = $pdo->prepare("SELECT a.* FROM animaux a
        WHERE COALESCE(a.date_entree, a.date_naissance, DATE(a.created_at)) <= ? AND (a.date_sortie IS NULL OR a.date_sortie >= ?)");
    $st->execute([$fin, $debut]);
    $animaux = $st->fetchAll();
    if (!$animaux) return [];

    $parAnimal = function (string $sql, array $params = []) use ($pdo): array {
        $s = $pdo->prepare($sql); $s->execute($params); $g = [];
        foreach ($s->fetchAll() as $r) $g[$r['animal_id']][] = $r;
        return $g;
    };
    $laits   = $parAnimal('SELECT * FROM controles_laitiers WHERE date_controle BETWEEN ? AND ? ORDER BY date_controle', [$debut, $fin]);
    $pesees  = $parAnimal('SELECT * FROM pesees WHERE date_pesee BETWEEN ? AND ? ORDER BY date_pesee', [$debut, $fin]);
    $repros  = $parAnimal('SELECT * FROM reproductions ORDER BY date_evenement, id');
    $soins   = $parAnimal('SELECT * FROM sante WHERE date_intervention BETWEEN ? AND ?', [$debut, $fin]);
    $st = $pdo->prepare('SELECT mere_id, pere_id FROM animaux WHERE date_naissance BETWEEN ? AND ?');
    $st->execute([$debut, $fin]);
    $desc = [];
    foreach ($st->fetchAll() as $r) foreach ([$r['mere_id'], $r['pere_id']] as $p) if ($p) $desc[$p] = ($desc[$p] ?? 0) + 1;

    $res = [];
    foreach ($animaux as $a) {
        $id = (int)$a['id'];
        $v = array_fill_keys(array_keys(variables_indices_liste()), null);

        // --- Général
        $finAnimal = $a['date_sortie'] && $a['date_sortie'] < $ref ? $a['date_sortie'] : $ref;
        $entree = max($debut, $a['date_entree'] ?? $a['date_naissance'] ?? $debut);
        $v['nb_animaux'] = 1;
        $v['femelle'] = $a['sexe'] === 'F' ? 1 : 0;
        $v['jours_presence'] = max(0, jours_entre($entree, $finAnimal) + 1);
        if ($a['date_naissance'] && $a['date_naissance'] <= $finAnimal) {
            $v['age_jours'] = jours_entre($a['date_naissance'], $finAnimal);
            $v['age_mois'] = round($v['age_jours'] / 30.4375, 1);
        }
        $sortieAnnee = $a['date_sortie'] && $a['date_sortie'] >= $debut && $a['date_sortie'] <= $fin;
        foreach (['mort', 'vendu', 'reforme'] as $s) $v[$s] = $sortieAnnee && $a['statut'] === $s ? 1 : 0;
        $v['ne_annee'] = $a['date_naissance'] && substr($a['date_naissance'], 0, 4) == $annee ? 1 : 0;

        // --- Poids
        $v['poids_naissance'] = $a['poids_naissance'];
        $p = $pesees[$id] ?? [];
        $v['nb_pesees'] = count($p);
        if ($p) {
            $prem = $p[0]; $der = end($p);
            $v['poids_debut'] = (float)$prem['poids'];
            $v['poids_fin'] = (float)$der['poids'];
            $v['poids_max'] = max(array_map('floatval', array_column($p, 'poids')));
            $v['nec_moyen'] = moyenne(array_column($p, 'note_etat_corporel'));
            if (count($p) >= 2) {
                $v['gain_poids'] = $v['poids_fin'] - $v['poids_debut'];
                $v['jours_pesees'] = jours_entre($prem['date_pesee'], $der['date_pesee']);
                $v['gmq'] = $v['jours_pesees'] > 0 ? $v['gain_poids'] / $v['jours_pesees'] * 1000 : null;
            }
        }

        // --- Lait
        $l = $laits[$id] ?? [];
        $v['nb_controles'] = count($l);
        if ($l) {
            $q = array_map('floatval', array_column($l, 'quantite_totale'));
            $v['lait_moyen'] = array_sum($q) / count($q);
            $v['lait_pic'] = max($q);
            $v['tb_moyen'] = moyenne(array_column($l, 'taux_butyreux'));
            $v['tp_moyen'] = moyenne(array_column($l, 'taux_proteique'));
            $v['cellules_moyen'] = moyenne(array_column($l, 'cellules'));
            $cel = array_filter(array_column($l, 'cellules'), fn($c) => $c !== null);
            $v['cellules_max'] = $cel ? max($cel) : null;
            $v['nb_cellules_hautes'] = count(array_filter($cel, fn($c) => $c >= SEUIL_CELLULES));
            if (count($l) >= 2) {
                $cumul = 0;
                for ($i = 1; $i < count($l); $i++) $cumul += jours_entre($l[$i-1]['date_controle'], $l[$i]['date_controle']) * ($q[$i] + $q[$i-1]) / 2;
                $v['lait_cumul'] = $cumul;
                $v['jours_controle'] = jours_entre($l[0]['date_controle'], end($l)['date_controle']);
            }
        }

        // --- Reproduction
        $r = $repros[$id] ?? [];
        $dansAnnee = array_filter($r, fn($x) => $x['date_evenement'] >= $debut && $x['date_evenement'] <= $fin);
        $compte = fn(callable $f) => count(array_filter($dansAnnee, $f));
        $v['nb_chaleurs']    = $compte(fn($x) => $x['type_evenement'] === 'chaleur');
        $v['nb_ia']          = $compte(fn($x) => in_array($x['type_evenement'], ['saillie', 'insemination'], true));
        $v['nb_diag_pos']    = $compte(fn($x) => $x['type_evenement'] === 'diagnostic' && $x['resultat'] === 'positif');
        $v['nb_diag_neg']    = $compte(fn($x) => $x['type_evenement'] === 'diagnostic' && $x['resultat'] === 'negatif');
        $v['nb_mises_bas']   = $compte(fn($x) => $x['type_evenement'] === 'mise_bas');
        $v['nb_avortements'] = $compte(fn($x) => $x['type_evenement'] === 'avortement');
        $v['nb_petits']      = array_sum(array_map(fn($x) => $x['type_evenement'] === 'mise_bas' ? (int)($x['nb_petits'] ?? 1) : 0, $dansAnnee));
        $mb = array_values(array_column(array_filter($r, fn($x) => $x['type_evenement'] === 'mise_bas'), 'date_evenement'));
        $intervalles = [];
        for ($i = 1; $i < count($mb); $i++) if ($mb[$i] >= $debut && $mb[$i] <= $fin) $intervalles[] = jours_entre($mb[$i-1], $mb[$i]);
        $v['ivv'] = moyenne($intervalles);
        if ($mb && $a['date_naissance']) $v['age_1ere_mb_mois'] = round(jours_entre($a['date_naissance'], $mb[0]) / 30.4375, 1);
        $v['nb_descendants'] = $desc[$id] ?? 0;

        // --- Santé
        $s = $soins[$id] ?? [];
        $v['nb_interventions'] = count($s);
        $v['nb_traitements']   = count(array_filter($s, fn($x) => $x['type'] === 'traitement'));
        $v['nb_vaccinations']  = count(array_filter($s, fn($x) => $x['type'] === 'vaccination'));
        $v['cout_sante']       = array_sum(array_map('floatval', array_column($s, 'cout')));

        $res[$id] = ['animal' => $a, 'vars' => $v];
    }
    return $res;
}

// ---------------------------------------------------------------------
//  Interprétation, agrégation, calcul et enregistrement
// ---------------------------------------------------------------------
function interpretations_indices(PDO $pdo): array
{
    $g = [];
    foreach ($pdo->query('SELECT * FROM indices_interpretations ORDER BY indice_id, ordre, id') as $r) $g[$r['indice_id']][] = $r;
    return $g;
}

/** @return array{0: ?string, 1: ?string} [libellé, couleur] */
function interpreter(array $regles, ?float $val): array
{
    if ($val === null) return [null, null];
    foreach ($regles as $r) {
        if (($r['borne_min'] === null || $val >= (float)$r['borne_min']) && ($r['borne_max'] === null || $val < (float)$r['borne_max'])) return [$r['libelle'], $r['couleur']];
    }
    return [null, null];
}

/** @return array{0: ?float, 1: int} [valeur du groupe, nombre d'animaux pris en compte] */
function agreger(array $indice, Formule $f, array $ids, array $valeurs, array $data): array
{
    if ($indice['agregation'] === 'ratio') {
        $tot = [];
        foreach ($f->variables as $var) {
            $x = array_filter(array_map(fn($id) => $data[$id]['vars'][$var], $ids), fn($v) => $v !== null);
            $tot[$var] = $x ? array_sum($x) : null;
        }
        $tot['nb_animaux'] = count($ids);
        return [$f->evaluer($tot), count($ids)];
    }
    $x = array_values(array_filter(array_map(fn($id) => $valeurs[$id], $ids), fn($v) => $v !== null));
    if (!$x) return [null, 0];
    return [match ($indice['agregation']) {
        'somme' => array_sum($x), 'min' => min($x), 'max' => max($x),
        default => array_sum($x) / count($x),
    }, count($x)];
}

/**
 * Calcule et enregistre les indices d'une année.
 * @return int nombre de résultats enregistrés
 */
function calculer_indices(PDO $pdo, int $annee, ?int $indiceId = null): int
{
    $sql = 'SELECT * FROM indices WHERE actif=1' . ($indiceId ? ' AND id=' . (int)$indiceId : '');
    $indices = $pdo->query($sql)->fetchAll();
    if (!$indices) return 0;
    $data = variables_annee($pdo, $annee);
    $regles = interpretations_indices($pdo);
    $ins = $pdo->prepare('INSERT INTO indices_resultats (indice_id, annee, niveau, cible_id, valeur, nb_animaux, interpretation, couleur, formule) VALUES (?,?,?,?,?,?,?,?,?)');
    $del = $pdo->prepare('DELETE FROM indices_resultats WHERE indice_id=? AND annee=?');
    $total = 0;

    $pdo->beginTransaction();
    try {
        foreach ($indices as $ind) {
            $del->execute([$ind['id'], $annee]);
            try { $f = new Formule($ind['formule']); } catch (InvalidArgumentException) { continue; } // formule invalide : ignorée
            $niveaux = explode(',', $ind['niveaux']);
            $cibles = array_filter($data, fn($d) => (!$ind['espece_id'] || $d['animal']['espece_id'] == $ind['espece_id'])
                                                 && (!$ind['sexe'] || $d['animal']['sexe'] === $ind['sexe']));
            $valeurs = array_map(fn($d) => $f->evaluer($d['vars']), $cibles);
            $lignes = [];
            if (in_array('animal', $niveaux, true)) foreach ($valeurs as $id => $val) if ($val !== null) $lignes[] = ['animal', $id, $val, 1];
            $groupes = ['lot' => 'lot_id', 'espece' => 'espece_id', 'troupeau' => null];
            foreach ($groupes as $niv => $col) {
                if (!in_array($niv, $niveaux, true)) continue;
                $g = [];
                foreach ($cibles as $id => $d) {
                    $cle = $col ? $d['animal'][$col] : 0;
                    if ($cle !== null) $g[(int)$cle][] = $id;
                }
                foreach ($g as $cle => $ids) {
                    [$val, $nb] = agreger($ind, $f, $ids, $valeurs, $data);
                    if ($val !== null) $lignes[] = [$niv, $cle, $val, $nb];
                }
            }
            foreach ($lignes as [$niv, $cible, $val, $nb]) {
                [$lib, $coul] = interpreter($regles[$ind['id']] ?? [], $val);
                $ins->execute([$ind['id'], $annee, $niv, $cible, round($val, 4), $nb, $lib, $coul, $ind['formule']]);
                $total++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $total;
}

/** Recalcule toutes les années (option : un seul indice) */
function recalculer_indices(PDO $pdo, ?int $indiceId = null): int
{
    $n = 0;
    foreach (annees_indices($pdo) as $y) $n += calculer_indices($pdo, $y, $indiceId);
    return $n;
}

/** Mise à jour automatique après une saisie : ne bloque jamais l'enregistrement principal */
function maj_indices_auto(PDO $pdo, ?string ...$dates): void
{
    try {
        $annees = array_unique(array_filter(array_map(fn($d) => $d ? (int)substr($d, 0, 4) : null, $dates)));
        foreach ($annees as $y) calculer_indices($pdo, $y);
    } catch (Throwable $e) {
        error_log('Indices : ' . $e->getMessage());
    }
}

/** Affichage d'une valeur d'indice avec son interprétation */
function cellule_indice(array $ind, ?array $r): string
{
    if (!$r) return '<span class="text-muted">—</span>';
    $val = nfr($r['valeur'], (int)$ind['decimales']) . ($ind['unite'] ? ' <small class="text-muted">' . e($ind['unite']) . '</small>' : '');
    $html = '<span class="fw-semibold">' . $val . '</span>';
    if ($r['interpretation']) $html .= '<br><span class="badge text-bg-' . e($r['couleur'] ?: 'secondary') . '">' . e($r['interpretation']) . '</span>';
    return $html;
}

/** Borne d'interprétation lisible (sans zéros inutiles) */
function fmt_borne($v): string { return rtrim(rtrim(number_format((float)$v, 4, ',', ' '), '0'), ','); }

/** Texte « < 15 », « 15 – 25 », « ≥ 25 » */
function texte_regle(array $r): string
{
    if ($r['borne_min'] === null && $r['borne_max'] === null) return 'toujours';
    if ($r['borne_min'] === null) return '< ' . fmt_borne($r['borne_max']);
    if ($r['borne_max'] === null) return '≥ ' . fmt_borne($r['borne_min']);
    return fmt_borne($r['borne_min']) . ' – ' . fmt_borne($r['borne_max']);
}
