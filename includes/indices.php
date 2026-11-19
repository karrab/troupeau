<?php
// =====================================================================
//  Moteur des indices de productivité
//  -------------------------------------------------------------------
//  Les indices ne sont jamais saisis : ils sont calculés à partir des
//  événements bruts (reproduction, contrôles laitiers, pesées, santé,
//  alimentation, mouvements), interprétés, puis enregistrés.
//
//  Période de calcul (granularité) :
//    campagne  – une valeur par animal et par campagne
//    lactation – une valeur par lactation (mise bas → tarissement)
//    jour      – une valeur par contrôle laitier
//  Niveaux d'enregistrement : animal, lot, espèce, troupeau.
//  Les formules sont modifiables dans Paramètres › Indices ; elles
//  peuvent utiliser des variables de base et d'autres indices (code).
// =====================================================================

const FAMILLES_INDICES = [
    'reproduction' => ['Reproduction et fertilité', 'R', 'heart'],
    'lait'         => ['Production laitière', 'L', 'droplet'],
    'croissance'   => ['Croissance et alimentation', 'C', 'graph-up-arrow'],
    'sante'        => ['Survie et santé', 'S', 'shield-plus'],
    'globale'      => ['Productivité globale', 'P', 'trophy'],
];
const PERIODES_INDICES = ['campagne' => 'Campagne', 'lactation' => 'Lactation', 'jour' => 'Contrôle (jour)'];
const NIVEAUX_INDICES = ['animal' => 'Animal', 'lot' => 'Lot', 'espece' => 'Espèce', 'troupeau' => 'Troupeau'];
const SENS_INDICES = ['haut' => 'Élevé = favorable', 'bas' => 'Bas = favorable', 'cible' => 'Plage cible', 'neutre' => 'Indicatif'];
const AGREGATIONS = [
    'ratio'   => 'Ratio : formule appliquée aux totaux du groupe (taux, %)',
    'moyenne' => 'Moyenne des valeurs individuelles',
    'somme'   => 'Somme des valeurs individuelles',
    'min'     => 'Minimum des valeurs individuelles',
    'max'     => 'Maximum des valeurs individuelles',
];
const COULEURS_INDICES = ['success' => 'Vert – favorable', 'info' => 'Bleu – correct', 'warning' => 'Orange – à surveiller', 'danger' => 'Rouge – défavorable', 'secondary' => 'Gris – neutre'];
const MOIS_FR = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

/** Variables de base disponibles dans les formules, par période de calcul */
const VARIABLES_INDICES = [
    'campagne' => [
        'Général' => [
            'nb_animaux'     => 'Nombre d\'animaux (1 par animal ; effectif du groupe en mode ratio)',
            'femelle'        => '1 si femelle, sinon 0',
            'reproductrice'  => '1 si femelle reproductrice (mise à la reproduction, ayant déjà mis bas ou âgée d\'au moins 12 mois)',
            'age_jours'      => 'Âge en jours en fin de campagne (ou à la sortie / aujourd\'hui)',
            'age_mois'       => 'Âge en mois en fin de campagne',
            'jours_presence' => 'Jours de présence dans l\'élevage pendant la campagne',
            'jours_parcours' => 'Jours passés sur un lot de type pâturage / parcours (historique des mouvements)',
            'mort'           => '1 si mort pendant la campagne',
            'vendu'          => '1 si vendu pendant la campagne',
            'reforme'        => '1 si réformé pendant la campagne',
            'ne_campagne'    => '1 si né pendant la campagne',
        ],
        'Reproduction' => [
            'saillie'          => '1 si la femelle a été saillie ou inséminée pendant la campagne',
            'saillie_diag'     => '1 si saillie dont le résultat de gestation est connu (gestante, échec constaté ou plus de 60 j)',
            'saillie_bilan'    => '1 si saillie dont l\'issue est connue (mise bas, avortement, échec, ou terme dépassé)',
            'nb_saillies'      => 'Saillies + inséminations de la femelle',
            'nb_saillies_bilan'=> 'Saillies + inséminations dont le résultat est connu',
            'nb_gestations'    => 'Gestations obtenues suite à ces saillies (diagnostic positif, mise bas ou avortement)',
            'gestante'         => '1 si au moins une gestation obtenue',
            'mb_suite_saillie' => '1 si la femelle a mis bas suite aux saillies de la campagne',
            'nb_mb_issues'     => 'Mises bas issues des saillies de la campagne',
            'nb_avortements_gest' => 'Avortements survenus sur les gestations de la campagne',
            'saillies_male'    => 'Mâle : saillies réalisées dont le résultat est connu',
            'gestations_male'  => 'Mâle : gestations obtenues par ses saillies',
            'retour_chaleur'   => '1 si retour en chaleur (chaleur ou nouvelle saillie 3 à 60 j après la 1re saillie)',
            'nb_chaleurs'      => 'Chaleurs observées pendant la campagne',
            'nb_diag_pos'      => 'Diagnostics de gestation positifs',
            'nb_diag_neg'      => 'Diagnostics de gestation négatifs',
            'nb_mises_bas'     => 'Mises bas pendant la campagne',
            'nb_mb_multiples'  => 'Mises bas multiples (2 petits ou plus)',
            'nb_petits'        => 'Petits nés (somme des portées déclarées)',
            'nb_morts_nes'     => 'Petits mort-nés déclarés à la mise bas',
            'nb_avortements'   => 'Avortements pendant la campagne',
            'imb'              => 'Intervalle moyen (jours) entre les mises bas de la campagne et la mise bas précédente',
            'age_1ere_mb_mois' => 'Âge (mois) à la 1re mise bas, si elle a eu lieu pendant la campagne (animaux nés à la ferme)',
        ],
        'Lait (campagne)' => [
            'nb_controles'   => 'Contrôles laitiers de la campagne',
            'lait_total'     => 'Lait produit entre le 1er et le dernier contrôle de la campagne (kg, trapèzes)',
            'lait_moyen'     => 'Production moyenne par contrôle (kg/jour)',
            'lait_pic'       => 'Production maximale (kg/jour)',
            'tb_moyen'       => 'Taux butyreux moyen (g/kg)',
            'tp_moyen'       => 'Taux protéique moyen (g/kg)',
            'cellules_moyen' => 'Cellules moyennes (×1000/mL)',
            'cellules_max'   => 'Cellules maximales (×1000/mL)',
        ],
        'Croissance et alimentation' => [
            'poids_naissance'    => 'Poids de naissance (kg)',
            'nb_pesees'          => 'Pesées de la campagne',
            'poids_debut'        => 'Poids initial : 1re pesée de la campagne (kg)',
            'poids_fin'          => 'Poids final : dernière pesée de la campagne (kg)',
            'gain_poids'         => 'Poids final − poids initial (kg)',
            'jours_pesees'       => 'Jours entre la 1re et la dernière pesée',
            'gmq'                => 'Gain moyen quotidien (g/jour)',
            'nec_moyen'          => 'Note d\'état corporel moyenne',
            'sevre'              => '1 si sevré pendant la campagne',
            'age_sevrage_jours'  => 'Âge au sevrage (jours)',
            'poids_sevrage'      => 'Poids au sevrage (kg) : saisi, sinon pesée la plus proche (± 15 j)',
            'aliment_kg'         => 'Aliment distribué pendant la campagne (kg)',
            'cout_aliment'       => 'Coût de l\'aliment distribué',
        ],
        'Descendance (mère)' => [
            'petits_nes'          => 'Petits nés de la femelle pendant la campagne',
            'petits_morts'        => 'Petits mort-nés + petits morts avant sevrage',
            'petits_sevres'       => 'Petits sevrés',
            'poids_petits_sevres' => 'Poids total des petits sevrés (kg)',
            'gmq_petits'          => 'GMQ moyen des petits, naissance → sevrage (g/jour)',
            'mort_avant_sevrage'  => '1 si l\'animal lui-même est mort avant sevrage pendant la campagne',
        ],
        'Santé' => [
            'nb_interventions' => 'Interventions sanitaires',
            'nb_traitements'   => 'Traitements curatifs',
            'nb_vaccinations'  => 'Vaccinations',
            'cout_sante'       => 'Coût sanitaire',
        ],
    ],
    'lactation' => [
        'Lactation' => [
            'nb_animaux'         => '1 par lactation',
            'numero_lactation'   => 'Rang de lactation (1 = primipare)',
            'lait_lactation'     => 'Production totale de la lactation (kg) : Σ des pesées de lait sur le cycle (méthode Fleischmann)',
            'duree_lactation'    => 'Date de tarissement − date de mise bas (jours) ; vide si lactation en cours',
            'jours_en_lait'      => 'Jours entre la mise bas et le dernier contrôle (ou le tarissement)',
            'lactation_terminee' => '1 si le tarissement est enregistré',
            'nb_controles'       => 'Contrôles de la lactation',
            'lait_moyen'         => 'Moyenne des contrôles (kg/jour)',
            'lait_pic'           => 'Production au pic (kg/jour)',
            'jours_au_pic'       => 'Jours entre la mise bas et le pic',
            'lait_fin'           => 'Production au dernier contrôle, fin de cycle (kg/jour)',
            'tb_moyen'           => 'Taux butyreux moyen (g/kg)',
            'tp_moyen'           => 'Taux protéique moyen (g/kg)',
            'cellules_moyen'     => 'Cellules moyennes (×1000/mL)',
        ],
    ],
    'jour' => [
        'Contrôle' => [
            'nb_animaux'       => '1 par contrôle',
            'lait_jour'        => 'Lait matin + lait soir (kg/jour)',
            'traite_matin'     => 'Traite du matin (kg)',
            'traite_soir'      => 'Traite du soir (kg)',
            'tb'               => 'Taux butyreux (g/kg)',
            'tp'               => 'Taux protéique (g/kg)',
            'cellules'         => 'Cellules (×1000/mL)',
            'jours_en_lait'    => 'Jours depuis la dernière mise bas',
            'numero_lactation' => 'Rang de lactation',
        ],
    ],
];

function variables_periode(string $periode): array
{
    $l = [];
    foreach (VARIABLES_INDICES[$periode] ?? [] as $g) $l += $g;
    return $l;
}

// ---------------------------------------------------------------------
//  Paramètres généraux et campagnes
// ---------------------------------------------------------------------
function parametre_app(PDO $pdo, string $cle, ?string $defaut = null): ?string
{
    static $cache = [];
    if (!array_key_exists($cle, $cache)) {
        try {
            $st = $pdo->prepare('SELECT valeur FROM parametres_app WHERE cle=?'); $st->execute([$cle]);
            $v = $st->fetchColumn();
            $cache[$cle] = $v === false ? $defaut : $v;
        } catch (PDOException) { return $defaut; }
    }
    return $cache[$cle];
}

/** Mois (1–12) de début de la campagne */
function mois_campagne(): int
{
    global $pdo;
    return max(1, min(12, (int)parametre_app($pdo, 'debut_campagne_mois', '1')));
}
/** Campagne (année de début) à laquelle appartient une date */
function campagne_de(string $date): int
{
    $y = (int)substr($date, 0, 4); $m = (int)substr($date, 5, 2);
    return $m >= mois_campagne() ? $y : $y - 1;
}
/** @return array{0: string, 1: string} premier et dernier jour */
function bornes_campagne(int $c): array
{
    $m = mois_campagne();
    $debut = sprintf('%04d-%02d-01', $c, $m);
    return [$debut, date('Y-m-d', strtotime("$debut +1 year -1 day"))];
}
function libelle_campagne(int|string $c): string { return mois_campagne() === 1 ? (string)$c : $c . '-' . ((int)$c + 1); }

/** Campagnes pour lesquelles des données existent (+ campagne en cours), de la plus récente à la plus ancienne */
function campagnes_indices(PDO $pdo): array
{
    $dates = $pdo->query("SELECT MIN(d), MAX(d) FROM (SELECT date_controle d FROM controles_laitiers UNION ALL SELECT date_pesee FROM pesees
        UNION ALL SELECT date_evenement FROM reproductions UNION ALL SELECT date_intervention FROM sante) x")->fetch(PDO::FETCH_NUM);
    $fin = campagne_de(date('Y-m-d'));
    $debut = $dates[0] ? campagne_de($dates[0]) : $fin;
    return range($fin, min($debut, $fin));
}

// ---------------------------------------------------------------------
//  Analyseur de formules (sans eval)
// ---------------------------------------------------------------------
final class Formule
{
    /** nom => [nb d'arguments min, max] */
    public const FONCTIONS = [
        'min' => [1, 99], 'max' => [1, 99], 'abs' => [1, 1], 'arrondi' => [1, 2], 'racine' => [1, 1], 'log' => [1, 1],
        'si' => [2, 3], 'sinon' => [2, 2], 'moyenne' => [1, 99], 'moyenne_ponderee' => [2, 98], 'note' => [3, 3],
    ];
    private const MOTS = ['et', 'ou', 'non'];

    private array $t = [];
    private int $p = 0;
    private array $ast;
    /** @var string[] variables de base utilisées */
    public array $variables = [];
    /** @var string[] codes d'indices utilisés */
    public array $references = [];

    /**
     * @param string[] $variables noms de variables autorisés
     * @param string[] $codes     codes d'indices autorisés
     */
    public function __construct(public readonly string $source, private array $autorisees = [], private array $codes = [])
    {
        $this->t = self::decouper($source);
        if (!$this->t) throw new InvalidArgumentException('La formule est vide.');
        $this->ast = $this->ou();
        if ($this->p < count($this->t)) throw new InvalidArgumentException('Symbole inattendu « ' . $this->t[$this->p][1] . ' ».');
        $this->variables = array_values(array_unique($this->variables));
        $this->references = array_values(array_unique($this->references));
    }

    private static function decouper(string $s): array
    {
        $tokens = []; $i = 0; $n = strlen($s);
        while ($i < $n) {
            if (ctype_space($s[$i])) { $i++; continue; }
            if (preg_match('/\G(\d+(?:\.\d+)?|\.\d+)/', $s, $m, 0, $i)) $tokens[] = ['n', (float)$m[1]];
            elseif (preg_match('/\G([A-Za-z_][A-Za-z0-9_]*)/', $s, $m, 0, $i)) {
                $low = strtolower($m[1]);
                $tokens[] = ['id', in_array($low, self::MOTS, true) ? $low : $m[1]];
            }
            elseif (preg_match('/\G(<=|>=|==|!=|<>|&&|\|\||[-+*\/%^(),<>=!÷×])/u', $s, $m, 0, $i)) {
                $op = ['&&' => 'et', '||' => 'ou', '!' => 'non', '<>' => '!=', '=' => '==', '÷' => '/', '×' => '*'][$m[1]] ?? $m[1];
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
            $nom = $x[1]; $low = strtolower($nom);
            if ($this->est('op', '(')) {
                if (!isset(self::FONCTIONS[$low])) throw new InvalidArgumentException('Fonction inconnue « ' . $nom . ' ».');
                $this->p++; $args = [];
                if (!$this->est('op', ')')) {
                    $args[] = $this->ou();
                    while ($this->est('op', ',')) { $this->p++; $args[] = $this->ou(); }
                }
                $this->attendre(')');
                [$mn, $mx] = self::FONCTIONS[$low];
                if (count($args) < $mn || count($args) > $mx) throw new InvalidArgumentException('Nombre d\'arguments incorrect pour ' . $low . '().');
                if ($low === 'moyenne_ponderee' && count($args) % 2) throw new InvalidArgumentException('moyenne_ponderee() attend des paires valeur, poids.');
                return ['f', $low, $args];
            }
            if (in_array(strtoupper($nom), $this->codes, true)) { $c = strtoupper($nom); $this->references[] = $c; return ['r', $c]; }
            if (in_array($low, $this->autorisees, true)) { $this->variables[] = $low; return ['v', $low]; }
            throw new InvalidArgumentException('Variable ou indice inconnu « ' . $nom . ' » pour cette période de calcul.');
        }
        throw new InvalidArgumentException('Symbole inattendu « ' . $x[1] . ' ».');
    }

    /**
     * @param array $vars variables de base
     * @param array $refs valeurs des indices référencés (code => valeur)
     */
    public function evaluer(array $vars, array $refs = []): ?float
    {
        $r = $this->ev($this->ast, $vars, $refs);
        return $r === null || !is_finite($r) ? null : $r;
    }

    private function ev(array $n, array $v, array $r): ?float
    {
        switch ($n[0]) {
            case 'n': return $n[1];
            case 'v': return isset($v[$n[1]]) ? (float)$v[$n[1]] : null;
            case 'r': return isset($r[$n[1]]) ? (float)$r[$n[1]] : null;
            case 'neg': $a = $this->ev($n[1], $v, $r); return $a === null ? null : -$a;
            case 'non': $a = $this->ev($n[1], $v, $r); return $a === null ? null : (float)($a == 0);
            case 'f': return $this->fonction($n[1], $n[2], $v, $r);
        }
        [, $op, $na, $nb] = $n;
        $a = $this->ev($na, $v, $r);
        if ($op === 'et' && $a !== null && $a == 0) return 0.0;
        if ($op === 'ou' && $a !== null && $a != 0) return 1.0;
        $b = $this->ev($nb, $v, $r);
        if ($a === null || $b === null) return null;
        return match ($op) {
            '+' => $a + $b, '-' => $a - $b, '*' => $a * $b,
            '/' => $b == 0 ? null : $a / $b,
            '%' => $b == 0 ? null : fmod($a, $b),
            '^' => is_nan($p = (float)@pow($a, $b)) ? null : $p,
            '<' => (float)($a < $b), '>' => (float)($a > $b), '<=' => (float)($a <= $b), '>=' => (float)($a >= $b),
            '==' => (float)(abs($a - $b) < 1e-9), '!=' => (float)(abs($a - $b) >= 1e-9),
            'et' => (float)($a != 0 && $b != 0), 'ou' => (float)($a != 0 || $b != 0),
        };
    }

    private function fonction(string $f, array $args, array $v, array $r): ?float
    {
        switch ($f) {
            case 'si':
                $c = $this->ev($args[0], $v, $r);
                if ($c === null) return null;
                if ($c != 0) return $this->ev($args[1], $v, $r);
                return isset($args[2]) ? $this->ev($args[2], $v, $r) : null;
            case 'sinon':
                return $this->ev($args[0], $v, $r) ?? $this->ev($args[1], $v, $r);
            case 'moyenne': // ignore les valeurs absentes
                $x = array_filter(array_map(fn($a) => $this->ev($a, $v, $r), $args), fn($y) => $y !== null);
                return $x ? array_sum($x) / count($x) : null;
            case 'moyenne_ponderee': // paires valeur, poids ; les valeurs absentes sont ignorées
                $s = 0; $w = 0;
                for ($i = 0; $i < count($args); $i += 2) {
                    $x = $this->ev($args[$i], $v, $r); $p = $this->ev($args[$i + 1], $v, $r);
                    if ($x !== null && $p !== null) { $s += $x * $p; $w += $p; }
                }
                return $w ? $s / $w : null;
        }
        $x = [];
        foreach ($args as $a) { $y = $this->ev($a, $v, $r); if ($y === null) return null; $x[] = $y; }
        return match ($f) {
            'min' => min($x), 'max' => max($x), 'abs' => abs($x[0]),
            'arrondi' => round($x[0], (int)($x[1] ?? 0)),
            'racine' => $x[0] < 0 ? null : sqrt($x[0]),
            'log' => $x[0] <= 0 ? null : log($x[0]),
            // note(valeur, valeur à 0 point, valeur à 100 points) : échelle 0–100 bornée, sens inversé si bon < mauvais
            'note' => $x[2] == $x[1] ? null : max(0, min(100, ($x[0] - $x[1]) / ($x[2] - $x[1]) * 100)),
        };
    }
}

/** Analyse la formule d'un indice dans son contexte (variables de sa période + codes des indices de même période) */
function formule_indice(array $ind, array $tous): Formule
{
    $codes = array_values(array_map(fn($i) => $i['code'], array_filter($tous, fn($i) => $i['periode'] === $ind['periode'] && $i['code'] !== $ind['code'])));
    return new Formule($ind['formule'], array_keys(variables_periode($ind['periode'])), $codes);
}

/**
 * Ordonne les indices pour que chaque indice soit calculé après ceux qu'il référence.
 * @return array{0: array, 1: array} [indices ordonnés avec leur Formule, erreurs code => message]
 */
function ordonner_indices(array $indices): array
{
    $f = []; $err = []; $parCode = [];
    foreach ($indices as $i) $parCode[$i['code']] = $i;
    foreach ($indices as $i) {
        try { $f[$i['code']] = formule_indice($i, $indices); }
        catch (InvalidArgumentException $e) { $err[$i['code']] = $e->getMessage(); }
    }
    $ordre = []; $etat = [];
    $visite = function (string $c, array $pile) use (&$visite, &$ordre, &$etat, &$err, $f): bool {
        if (($etat[$c] ?? 0) === 2) return true;
        if (($etat[$c] ?? 0) === 1) { // cycle : tous les indices concernés sont signalés
            $cycle = [...array_slice($pile, (int)array_search($c, $pile, true)), $c];
            foreach (array_slice($cycle, 0, -1) as $x) $err[$x] = 'Référence circulaire : ' . implode(' → ', $cycle) . '.';
            return false;
        }
        if (!isset($f[$c])) return false;
        $etat[$c] = 1;
        foreach ($f[$c]->references as $r) if (!$visite($r, [...$pile, $c])) { $err[$c] ??= 'Dépend de l\'indice ' . $r . ' qui ne peut pas être calculé.'; $etat[$c] = 2; return false; }
        $etat[$c] = 2; $ordre[] = $c;
        return true;
    };
    foreach (array_keys($f) as $c) $visite($c, []);
    return [array_map(fn($c) => $parCode[$c] + ['_f' => $f[$c]], $ordre), $err];
}

// ---------------------------------------------------------------------
//  Données de base d'une campagne
// ---------------------------------------------------------------------
function moyenne_val(array $x): ?float { $x = array_filter($x, fn($v) => $v !== null && $v !== ''); return $x ? array_sum($x) / count($x) : null; }
function jours_entre(string $a, string $b): int { return (int)round((strtotime($b) - strtotime($a)) / 86400); }

/** Historique des lots d'un animal reconstitué à partir des mouvements : [[lot_id, du, au|null], …] */
function historique_lots(array $animal, array $mvts): array
{
    $tr = array_values(array_filter($mvts, fn($m) => $m['type'] === 'transfert' || ($m['type'] === 'entree' && $m['lot_destination'])));
    $debut = $animal['date_entree'] ?? $animal['date_naissance'] ?? '1900-01-01';
    if (!$tr) return [[$animal['lot_id'], $debut, null]];
    $h = []; $lot = $tr[0]['type'] === 'transfert' ? $tr[0]['lot_origine'] : null; $du = $debut;
    foreach ($tr as $m) {
        if ($lot !== null && $m['date_mouvement'] > $du) $h[] = [$lot, $du, $m['date_mouvement']];
        $lot = $m['lot_destination']; $du = $m['date_mouvement'];
    }
    $h[] = [$lot ?? $animal['lot_id'], $du, null];
    return $h;
}
function lot_a_date(array $hist, string $d): ?int
{
    foreach (array_reverse($hist) as [$lot, $du, $au]) if ($du <= $d && ($au === null || $d < $au)) return $lot ? (int)$lot : null;
    return isset($hist[0][0]) ? (int)$hist[0][0] : null;
}

/**
 * Construit les unités de calcul d'une campagne.
 * @return array{campagne: array, lactation: array, jour: array} chaque unité : [animal, cle, debut, fin, groupe_date, vars]
 */
function donnees_campagne(PDO $pdo, int $c): array
{
    [$D, $F] = bornes_campagne($c);
    $auj = date('Y-m-d');
    $ref = min($F, $auj);
    $dans = fn(?string $d) => $d !== null && $d >= $D && $d <= $F;

    $tous = $pdo->query('SELECT a.*, e.gestation_jours FROM animaux a JOIN especes e ON e.id=a.espece_id')->fetchAll(PDO::FETCH_UNIQUE);
    foreach ($tous as $id => &$a) $a['id'] = $id;
    unset($a);
    $grp = function (string $sql) use ($pdo): array {
        $g = [];
        foreach ($pdo->query($sql) as $r) $g[$r['animal_id']][] = $r;
        return $g;
    };
    $laits  = $grp('SELECT * FROM controles_laitiers ORDER BY date_controle');
    $pesees = $grp('SELECT * FROM pesees ORDER BY date_pesee, id');
    $repros = $grp('SELECT * FROM reproductions ORDER BY date_evenement, id');
    $soins  = $grp('SELECT * FROM sante');
    $alim   = $grp('SELECT * FROM alimentation');
    $mvts   = $grp('SELECT * FROM mouvements ORDER BY date_mouvement, id');
    $paturages = $pdo->query("SELECT id FROM lots WHERE type='paturage'")->fetchAll(PDO::FETCH_COLUMN);
    $petitsDe = []; $sailliesMale = [];
    foreach ($tous as $x) if ($x['mere_id']) $petitsDe[$x['mere_id']][] = $x;
    foreach ($repros as $fid => $evts) foreach ($evts as $e) if ($e['male_id'] && $e['type_evenement'] === 'saillie') $sailliesMale[$e['male_id']][] = $e;

    // Poids au sevrage : saisi, sinon pesée la plus proche (± 15 jours)
    $poidsSevrage = function (array $x) use ($pesees): ?float {
        if ($x['poids_sevrage'] !== null) return (float)$x['poids_sevrage'];
        if (!$x['date_sevrage']) return null;
        $best = null;
        foreach ($pesees[$x['id']] ?? [] as $p) {
            $d = abs(jours_entre($p['date_pesee'], $x['date_sevrage']));
            if ($d <= 15 && ($best === null || $d < $best[0])) $best = [$d, (float)$p['poids']];
        }
        return $best[1] ?? null;
    };
    // Une saillie aboutit-elle à une gestation ? Renvoie l'événement preuve (diag+, mise bas, avortement) ou null
    // [gestation obtenue, mise bas, avortement, résultat de gestation connu, issue connue]
    $issue = function (array $evts, array $s, int $gest) use ($auj): array {
        $limite = date('Y-m-d', strtotime($s['date_evenement'] . ' +' . ($gest + 30) . ' days'));
        $preuve = false; $mb = false; $avort = false; $echec = false;
        foreach ($evts as $e) {
            if ($e['date_evenement'] <= $s['date_evenement'] || $e['date_evenement'] > $limite) continue;
            if (in_array($e['type_evenement'], ['saillie', 'insemination'], true)) {
                if (jours_entre($s['date_evenement'], $e['date_evenement']) <= 2) continue; // double saillie / IA sur la même chaleur
                $echec = true; break;                                                         // nouvelle saillie : échec de la précédente
            }
            if ($e['type_evenement'] === 'diagnostic' && $e['resultat'] === 'negatif') { $echec = !$preuve; break; }
            if ($e['type_evenement'] === 'diagnostic' && $e['resultat'] === 'positif') $preuve = true;
            if ($e['type_evenement'] === 'mise_bas') { $preuve = $mb = true; break; }
            if ($e['type_evenement'] === 'avortement') { $preuve = $avort = true; break; }
        }
        $diagConnu = $preuve || $echec || jours_entre($s['date_evenement'], $auj) > 60;
        $issueConnue = $mb || $avort || $echec || $auj > $limite;
        return [$preuve, $mb, $avort, $diagConnu, $issueConnue];
    };

    $u = ['campagne' => [], 'lactation' => [], 'jour' => []];
    foreach ($tous as $id => $a) {
        $entree = $a['date_entree'] ?? $a['date_naissance'] ?? substr($a['created_at'], 0, 10);
        if ($entree > $F || ($a['date_sortie'] && $a['date_sortie'] < $D)) continue; // absent pendant la campagne
        $finA = $a['date_sortie'] && $a['date_sortie'] < $ref ? $a['date_sortie'] : $ref;
        $hist = historique_lots($a, $mvts[$id] ?? []);
        $evts = $repros[$id] ?? [];
        $gest = (int)$a['gestation_jours'];

        // =============== Unité CAMPAGNE ===============
        $v = array_fill_keys(array_keys(variables_periode('campagne')), null);
        $v['nb_animaux'] = 1;
        $v['femelle'] = $a['sexe'] === 'F' ? 1 : 0;
        $v['jours_presence'] = max(0, jours_entre(max($D, $entree), $finA) + 1);
        if ($a['date_naissance'] && $a['date_naissance'] <= $finA) {
            $v['age_jours'] = jours_entre($a['date_naissance'], $finA);
            $v['age_mois'] = round($v['age_jours'] / 30.4375, 1);
        }
        foreach (['mort', 'vendu', 'reforme'] as $s) $v[$s] = $dans($a['date_sortie']) && $a['statut'] === $s ? 1 : 0;
        $v['ne_campagne'] = $dans($a['date_naissance']) ? 1 : 0;
        // Jours sur parcours
        $jp = 0;
        foreach ($hist as [$lot, $du, $au]) {
            if (!in_array($lot, $paturages)) continue;
            $x = max($du, $D, $entree); $y = min($au ? date('Y-m-d', strtotime("$au -1 day")) : $finA, $finA);
            if ($y >= $x) $jp += jours_entre($x, $y) + 1;
        }
        $v['jours_parcours'] = $jp;

        // --- Reproduction
        $evC = array_values(array_filter($evts, fn($e) => $dans($e['date_evenement'])));
        $cnt = fn(callable $f) => count(array_filter($evC, $f));
        if ($a['sexe'] === 'F') {
            $sail = array_values(array_filter($evC, fn($e) => in_array($e['type_evenement'], ['saillie', 'insemination'], true)));
            $v['saillie'] = $sail ? 1 : 0;
            $v['nb_saillies'] = count($sail);
            $g = 0; $mbIss = 0; $av = 0; $connus = 0; $res = [];
            foreach ($sail as $s) {
                $res[] = $r = $issue($evts, $s, $gest);
                $g += $r[0]; $mbIss += $r[1]; $av += $r[2]; $connus += $r[3];
            }
            $v['nb_gestations'] = $g; $v['gestante'] = $g ? 1 : 0;
            $v['nb_mb_issues'] = $mbIss; $v['mb_suite_saillie'] = $mbIss ? 1 : 0;
            $v['nb_avortements_gest'] = $av;
            $v['nb_saillies_bilan'] = $connus;
            $dern = $res ? end($res) : null;
            $v['saillie_diag'] = $sail && ($g || $dern[3]) ? 1 : 0;
            $v['saillie_bilan'] = $sail && ($mbIss || $av || $dern[4]) ? 1 : 0;
            $v['retour_chaleur'] = 0;
            if ($sail) {
                $d0 = $sail[0]['date_evenement'];
                foreach ($evts as $e) {
                    $j = jours_entre($d0, $e['date_evenement']);
                    if ($j >= 3 && $j <= 60 && in_array($e['type_evenement'], ['chaleur', 'saillie', 'insemination'], true)) { $v['retour_chaleur'] = 1; break; }
                }
            }
        } else { // mâle : saillies réalisées et gestations obtenues
            $sm = array_values(array_filter($sailliesMale[$id] ?? [], fn($e) => $dans($e['date_evenement'])));
            $v['saillies_male'] = 0; $v['gestations_male'] = 0;
            foreach ($sm as $s) {
                $fem = $tous[$s['animal_id']] ?? null;
                if (!$fem) continue;
                $r = $issue($repros[$s['animal_id']] ?? [], $s, (int)$fem['gestation_jours']);
                $v['saillies_male'] += $r[3]; $v['gestations_male'] += $r[0];
            }
        }
        $v['nb_chaleurs']    = $cnt(fn($e) => $e['type_evenement'] === 'chaleur');
        $v['nb_diag_pos']    = $cnt(fn($e) => $e['type_evenement'] === 'diagnostic' && $e['resultat'] === 'positif');
        $v['nb_diag_neg']    = $cnt(fn($e) => $e['type_evenement'] === 'diagnostic' && $e['resultat'] === 'negatif');
        $v['nb_avortements'] = $cnt(fn($e) => $e['type_evenement'] === 'avortement');
        $mbC = array_values(array_filter($evC, fn($e) => $e['type_evenement'] === 'mise_bas'));
        $v['nb_mises_bas'] = count($mbC);
        $v['nb_petits'] = array_sum(array_map(fn($e) => (int)($e['nb_petits'] ?? 1), $mbC));
        $v['nb_mb_multiples'] = count(array_filter($mbC, fn($e) => (int)$e['nb_petits'] >= 2));
        $v['nb_morts_nes'] = array_sum(array_map(fn($e) => (int)($e['nb_morts_nes'] ?? 0), $mbC));
        $mbTous = array_values(array_column(array_filter($evts, fn($e) => $e['type_evenement'] === 'mise_bas'), 'date_evenement'));
        $iv = [];
        for ($i = 1; $i < count($mbTous); $i++) if ($dans($mbTous[$i])) $iv[] = jours_entre($mbTous[$i - 1], $mbTous[$i]);
        $v['imb'] = moyenne_val($iv);
        if ($mbTous && $dans($mbTous[0]) && $a['date_naissance'] && $a['origine'] === 'naissance') $v['age_1ere_mb_mois'] = round(jours_entre($a['date_naissance'], $mbTous[0]) / 30.4375, 1);
        $v['reproductrice'] = $a['sexe'] === 'F' && ($v['saillie'] || ($mbTous && $mbTous[0] <= $F) || ($v['age_mois'] ?? 0) >= 12) ? 1 : 0;

        // --- Lait de la campagne
        $l = array_values(array_filter($laits[$id] ?? [], fn($x) => $dans($x['date_controle'])));
        $v['nb_controles'] = count($l);
        if ($l) {
            $q = array_map('floatval', array_column($l, 'quantite_totale'));
            $v['lait_moyen'] = array_sum($q) / count($q);
            $v['lait_pic'] = max($q);
            $v['tb_moyen'] = moyenne_val(array_column($l, 'taux_butyreux'));
            $v['tp_moyen'] = moyenne_val(array_column($l, 'taux_proteique'));
            $v['cellules_moyen'] = moyenne_val(array_column($l, 'cellules'));
            $cel = array_filter(array_column($l, 'cellules'), fn($x) => $x !== null);
            $v['cellules_max'] = $cel ? max($cel) : null;
            if (count($l) >= 2) {
                $t = 0;
                for ($i = 1; $i < count($l); $i++) $t += jours_entre($l[$i - 1]['date_controle'], $l[$i]['date_controle']) * ($q[$i] + $q[$i - 1]) / 2;
                $v['lait_total'] = $t;
            }
        }

        // --- Croissance et alimentation
        $v['poids_naissance'] = $a['poids_naissance'];
        $p = array_values(array_filter($pesees[$id] ?? [], fn($x) => $dans($x['date_pesee'])));
        $v['nb_pesees'] = count($p);
        if ($p) {
            $v['poids_debut'] = (float)$p[0]['poids'];
            $v['poids_fin'] = (float)end($p)['poids'];
            $v['nec_moyen'] = moyenne_val(array_column($p, 'note_etat_corporel'));
            if (count($p) >= 2) {
                $v['gain_poids'] = $v['poids_fin'] - $v['poids_debut'];
                $v['jours_pesees'] = jours_entre($p[0]['date_pesee'], end($p)['date_pesee']);
                $v['gmq'] = $v['jours_pesees'] > 0 ? $v['gain_poids'] / $v['jours_pesees'] * 1000 : null;
            }
        }
        if ($dans($a['date_sevrage'])) {
            $v['sevre'] = 1;
            $v['poids_sevrage'] = $poidsSevrage($a);
            if ($a['date_naissance']) $v['age_sevrage_jours'] = jours_entre($a['date_naissance'], $a['date_sevrage']);
        } else $v['sevre'] = 0;
        $al = array_filter($alim[$id] ?? [], fn($x) => $dans($x['date_distribution']));
        $v['aliment_kg'] = array_sum(array_map('floatval', array_column($al, 'quantite_kg')));
        $v['cout_aliment'] = array_sum(array_map('floatval', array_column($al, 'cout')));

        // --- Descendance (mère) : petits nés pendant la campagne
        $v['mort_avant_sevrage'] = $a['statut'] === 'mort' && !$a['date_sevrage'] && $dans($a['date_sortie']) ? 1 : 0;
        if ($a['sexe'] === 'F') {
            $fiches = array_values(array_filter($petitsDe[$id] ?? [], fn($x) => $dans($x['date_naissance'])));
            $nes = max($v['nb_petits'], count($fiches));
            $sev = array_values(array_filter($fiches, fn($x) => $x['date_sevrage'] !== null));
            $morts = count(array_filter($fiches, fn($x) => $x['statut'] === 'mort' && !$x['date_sevrage']));
            $v['petits_nes'] = $nes;
            $v['petits_morts'] = min($nes, $v['nb_morts_nes'] + $morts);
            $v['petits_sevres'] = count($sev);
            $v['poids_petits_sevres'] = array_sum(array_map(fn($x) => $poidsSevrage($x) ?? 0, $sev));
            $g = [];
            foreach ($sev as $x) {
                $ps = $poidsSevrage($x);
                if ($ps !== null && $x['poids_naissance'] !== null && $x['date_naissance'] && $x['date_sevrage'] > $x['date_naissance'])
                    $g[] = ($ps - $x['poids_naissance']) / jours_entre($x['date_naissance'], $x['date_sevrage']) * 1000;
            }
            $v['gmq_petits'] = moyenne_val($g);
        }

        // --- Santé
        $s = array_filter($soins[$id] ?? [], fn($x) => $dans($x['date_intervention']));
        $v['nb_interventions'] = count($s);
        $v['nb_traitements'] = count(array_filter($s, fn($x) => $x['type'] === 'traitement'));
        $v['nb_vaccinations'] = count(array_filter($s, fn($x) => $x['type'] === 'vaccination'));
        $v['cout_sante'] = array_sum(array_map('floatval', array_column($s, 'cout')));

        $u['campagne'][$id] = ['animal' => $a, 'cle' => (string)$c, 'debut' => max($D, $entree), 'fin' => $finA, 'lot' => lot_a_date($hist, $finA), 'vars' => $v];

        if ($a['sexe'] !== 'F') continue;

        // =============== Unités LACTATION (mise bas pendant la campagne) ===============
        $tousLait = $laits[$id] ?? [];
        $mbEv = array_values(array_filter($evts, fn($e) => $e['type_evenement'] === 'mise_bas'));
        $starts = array_map(fn($e) => [$e['date_evenement'], true], $mbEv);
        if ($tousLait && (!$mbEv || $tousLait[0]['date_controle'] < $mbEv[0]['date_evenement'])) array_unshift($starts, [$tousLait[0]['date_controle'], false]);
        foreach ($starts as $k => [$deb, $connu]) {
            $suivante = $starts[$k + 1][0] ?? null;
            $tari = null;
            foreach ($evts as $e) if ($e['type_evenement'] === 'tarissement' && $e['date_evenement'] > $deb && ($suivante === null || $e['date_evenement'] < $suivante)) { $tari = $e['date_evenement']; break; }
            $limite = $tari ?? $suivante;
            $lc = array_values(array_filter($tousLait, fn($x) => $x['date_controle'] >= $deb && ($limite === null || $x['date_controle'] < $limite)));
            if (!$dans($deb) || (!$lc && !$tari)) continue;
            $w = array_fill_keys(array_keys(variables_periode('lactation')), null);
            $w['nb_animaux'] = 1;
            $w['numero_lactation'] = $connu ? count(array_filter($mbEv, fn($e) => $e['date_evenement'] <= $deb)) : null;
            $w['lactation_terminee'] = $tari ? 1 : 0;
            $w['duree_lactation'] = $tari ? jours_entre($deb, $tari) : null;
            $w['nb_controles'] = count($lc);
            if ($lc) {
                $q = array_map('floatval', array_column($lc, 'quantite_totale'));
                $w['lait_moyen'] = array_sum($q) / count($q);
                $w['lait_pic'] = max($q);
                $w['jours_au_pic'] = jours_entre($deb, $lc[array_search($w['lait_pic'], $q)]['date_controle']);
                $w['lait_fin'] = end($q);
                $w['tb_moyen'] = moyenne_val(array_column($lc, 'taux_butyreux'));
                $w['tp_moyen'] = moyenne_val(array_column($lc, 'taux_proteique'));
                $w['cellules_moyen'] = moyenne_val(array_column($lc, 'cellules'));
                // Méthode Fleischmann : 1er contrôle × jours depuis la mise bas, trapèzes entre contrôles, dernier contrôle × jours jusqu'au tarissement
                $tot = $connu ? $q[0] * jours_entre($deb, $lc[0]['date_controle']) : 0;
                for ($i = 1; $i < count($lc); $i++) $tot += jours_entre($lc[$i - 1]['date_controle'], $lc[$i]['date_controle']) * ($q[$i] + $q[$i - 1]) / 2;
                if ($tari) $tot += end($q) * jours_entre(end($lc)['date_controle'], $tari);
                $w['lait_lactation'] = $tot;
                $w['jours_en_lait'] = jours_entre($deb, $tari ?? end($lc)['date_controle']);
            }
            $u['lactation'][$id . '|' . $deb] = ['animal' => $a, 'cle' => 'L' . $deb, 'debut' => $deb, 'fin' => $tari ?? ($lc ? end($lc)['date_controle'] : null),
                'lot' => lot_a_date($hist, $deb), 'vars' => $w, 'groupe' => (string)$c];
        }

        // =============== Unités JOUR (contrôles de la campagne) ===============
        foreach ($l as $x) {
            $dmb = null; $n = 0;
            foreach ($mbEv as $e) if ($e['date_evenement'] <= $x['date_controle']) { $dmb = $e['date_evenement']; $n++; }
            $u['jour'][$id . '|' . $x['date_controle']] = ['animal' => $a, 'cle' => $x['date_controle'], 'debut' => $x['date_controle'], 'fin' => $x['date_controle'],
                'lot' => lot_a_date($hist, $x['date_controle']), 'groupe' => $x['date_controle'], 'vars' => [
                'nb_animaux' => 1, 'lait_jour' => (float)$x['quantite_totale'], 'traite_matin' => $x['traite_matin'], 'traite_soir' => $x['traite_soir'],
                'tb' => $x['taux_butyreux'], 'tp' => $x['taux_proteique'], 'cellules' => $x['cellules'],
                'jours_en_lait' => $dmb ? jours_entre($dmb, $x['date_controle']) : null, 'numero_lactation' => $dmb ? $n : null]];
        }
    }
    return $u;
}

// ---------------------------------------------------------------------
//  Interprétation, agrégation et calcul
// ---------------------------------------------------------------------
function interpretations_indices(PDO $pdo): array
{
    $g = [];
    foreach ($pdo->query('SELECT * FROM indices_interpretations ORDER BY indice_id, espece_id IS NULL, espece_id, ordre, id') as $r) $g[$r['indice_id']][] = $r;
    return $g;
}

/**
 * Seuils propres à l'espèce en priorité, puis seuils généraux.
 * @return array{0: ?string, 1: ?string} [libellé, couleur]
 */
function interpreter(array $regles, ?float $val, ?int $espece = null): array
{
    if ($val === null) return [null, null];
    $propres = array_filter($regles, fn($r) => $r['espece_id'] !== null && (int)$r['espece_id'] === $espece);
    $generales = array_filter($regles, fn($r) => $r['espece_id'] === null);
    foreach ($propres ?: $generales as $r) {
        if (($r['borne_min'] === null || $val >= (float)$r['borne_min']) && ($r['borne_max'] === null || $val < (float)$r['borne_max'])) return [$r['libelle'], $r['couleur']];
    }
    return [null, null];
}

/**
 * Calcule (sans enregistrer) tous les indices d'une campagne.
 * @return array lignes de résultats
 */
function calculer_campagne(PDO $pdo, int $c, array $indices, array $regles, ?array $donnees = null): array
{
    [$ordonnes] = ordonner_indices(array_values(array_filter($indices, fn($i) => $i['actif'])));
    $donnees ??= donnees_campagne($pdo, $c);
    $valU = []; // code => cléUnité => valeur
    $valG = []; // code => niveau => cible|groupe => valeur
    $lignes = [];
    foreach ($ordonnes as $ind) {
        $f = $ind['_f']; $code = $ind['code'];
        $niveaux = explode(',', $ind['niveaux']);
        $unites = array_filter($donnees[$ind['periode']], fn($x) => (!$ind['espece_id'] || $x['animal']['espece_id'] == $ind['espece_id'])
                                                               && (!$ind['sexe'] || $x['animal']['sexe'] === $ind['sexe']));
        // Valeur pour chaque unité (animal × période)
        foreach ($unites as $k => $x) {
            $refs = [];
            foreach ($f->references as $r) $refs[$r] = $valU[$r][$k] ?? null;
            $valU[$code][$k] = $f->evaluer($x['vars'], $refs);
            if ($valU[$code][$k] !== null && in_array('animal', $niveaux, true))
                $lignes[] = ['ind' => $ind, 'niveau' => 'animal', 'cible' => (int)$x['animal']['id'], 'cle' => $x['cle'], 'debut' => $x['debut'], 'fin' => $x['fin'],
                             'valeur' => $valU[$code][$k], 'nb' => 1, 'espece' => (int)$x['animal']['espece_id']];
        }
        // Groupes : lot, espèce, troupeau – par campagne (ou par jour de contrôle)
        foreach (['lot', 'espece', 'troupeau'] as $niv) {
            if (!in_array($niv, $niveaux, true)) continue;
            $g = [];
            foreach ($unites as $k => $x) {
                $cible = match ($niv) { 'lot' => $x['lot'], 'espece' => (int)$x['animal']['espece_id'], default => 0 };
                if ($cible === null) continue;
                $g[$cible . '|' . ($x['groupe'] ?? $x['cle'])][] = $k;
            }
            foreach ($g as $gk => $ks) {
                [$cible, $cle] = explode('|', $gk, 2);
                if ($ind['agregation'] === 'ratio') {
                    $tot = [];
                    foreach ($f->variables as $var) {
                        $xs = array_filter(array_map(fn($k) => $unites[$k]['vars'][$var], $ks), fn($y) => $y !== null);
                        $tot[$var] = $xs ? array_sum($xs) : null;
                    }
                    $tot['nb_animaux'] = count($ks);
                    $refs = [];
                    foreach ($f->references as $r) $refs[$r] = $valG[$r][$niv][$gk] ?? null;
                    $val = $f->evaluer($tot, $refs); $nb = count($ks);
                } else {
                    $xs = array_values(array_filter(array_map(fn($k) => $valU[$code][$k], $ks), fn($y) => $y !== null));
                    $nb = count($xs);
                    $val = !$xs ? null : match ($ind['agregation']) { 'somme' => array_sum($xs), 'min' => min($xs), 'max' => max($xs), default => array_sum($xs) / count($xs) };
                }
                $valG[$code][$niv][$gk] = $val;
                if ($val === null) continue;
                $esp = array_unique(array_map(fn($k) => (int)$unites[$k]['animal']['espece_id'], $ks));
                $lignes[] = ['ind' => $ind, 'niveau' => $niv, 'cible' => (int)$cible, 'cle' => $cle,
                             'debut' => min(array_map(fn($k) => $unites[$k]['debut'], $ks)), 'fin' => max(array_map(fn($k) => $unites[$k]['fin'] ?? $unites[$k]['debut'], $ks)),
                             'valeur' => $val, 'nb' => $nb, 'espece' => count($esp) === 1 ? $esp[0] : null];
            }
        }
    }
    foreach ($lignes as &$l) [$l['interpretation'], $l['couleur']] = interpreter($regles[$l['ind']['id']] ?? [], $l['valeur'], $l['espece']);
    return $lignes;
}

/** Calcule, interprète et enregistre tous les indices d'une campagne. @return int nombre de résultats */
function calculer_indices(PDO $pdo, int $c): int
{
    $indices = $pdo->query('SELECT * FROM indices')->fetchAll();
    $lignes = calculer_campagne($pdo, $c, $indices, interpretations_indices($pdo));
    $ins = $pdo->prepare('INSERT INTO indices_resultats (indice_id, campagne, periode, periode_cle, date_debut, date_fin, niveau, cible_id, valeur, nb_animaux, interpretation, couleur, formule)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM indices_resultats WHERE campagne=?')->execute([$c]);
        foreach ($lignes as $l) $ins->execute([$l['ind']['id'], $c, $l['ind']['periode'], $l['cle'], $l['debut'], $l['fin'], $l['niveau'], $l['cible'],
            round($l['valeur'], 4), $l['nb'], $l['interpretation'], $l['couleur'], $l['ind']['formule']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return count($lignes);
}

/** Recalcule toutes les campagnes */
function recalculer_indices(PDO $pdo): int
{
    $pdo->exec('DELETE FROM indices_resultats');
    $n = 0;
    foreach (campagnes_indices($pdo) as $c) $n += calculer_indices($pdo, $c);
    return $n;
}

/**
 * Recalcul automatique après un enregistrement : campagne de chaque date et campagne précédente
 * (une mise bas confirme les saillies de la campagne d'avant). Ne bloque jamais la saisie.
 */
function maj_indices_auto(PDO $pdo, ?string ...$dates): void
{
    try {
        $cs = [];
        foreach (array_filter($dates) as $d) { $c = campagne_de($d); $cs[$c] = $cs[$c - 1] = true; }
        foreach (array_keys($cs) as $c) if ($c <= campagne_de(date('Y-m-d'))) calculer_indices($pdo, $c);
    } catch (Throwable $e) {
        error_log('Indices : ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------
//  Affichage
// ---------------------------------------------------------------------
function fmt_borne($v): string { return rtrim(rtrim(number_format((float)$v, 4, ',', ' '), '0'), ','); }

function texte_regle(array $r): string
{
    if ($r['borne_min'] === null && $r['borne_max'] === null) return 'toujours';
    if ($r['borne_min'] === null) return '< ' . fmt_borne($r['borne_max']);
    if ($r['borne_max'] === null) return '≥ ' . fmt_borne($r['borne_min']);
    return fmt_borne($r['borne_min']) . ' – ' . fmt_borne($r['borne_max']);
}

/** Valeur d'un indice avec son interprétation */
function cellule_indice(array $ind, ?array $r): string
{
    if (!$r) return '<span class="text-muted">—</span>';
    $val = nfr($r['valeur'], (int)$ind['decimales']) . ($ind['unite'] ? ' <small class="text-muted">' . e($ind['unite']) . '</small>' : '');
    $html = '<span class="fw-semibold">' . $val . '</span>';
    if ($r['interpretation']) $html .= '<br><span class="badge text-bg-' . e($r['couleur'] ?: 'secondary') . '">' . e($r['interpretation']) . '</span>';
    return $html;
}

/** En-tête de colonne d'un indice */
function entete_indice(array $i): string
{
    return '<th class="text-center small" title="' . e(($i['description'] ? $i['description'] . ' — ' : '') . $i['formule'] . ($i['interpretation'] ? ' — ' . $i['interpretation'] : '')) . '">'
        . '<span class="badge text-bg-light font-monospace">' . e($i['code']) . '</span><br>' . e($i['nom']) . '</th>';
}
