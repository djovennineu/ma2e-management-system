<?php
// =============================================================================
// CRON/INTERETS_MENSUELS.PHP — MA2E (VERSION CORRIGÉE)
// Fréquence : 1er de chaque mois à 00:01
// Crontab   : 1 0 1 * * php /chemin/vers/SOUTENANCE_pro/cron/interets_mensuels.php
//
// LOGIQUE PAR TYPE DE COMPTE (règles MA2E clarifiées) :
//
// ┌──────────────────────────────────────────────────────────────────────────┐
// │ Expresse  │ Solde du compte   │ 1er janvier (fin exercice) │ 3,5% fixe  │
// │ Ordinaire │ Dates de valeur   │ 1er janvier (fin exercice) │ 3,5% fixe  │
// │ DATS      │ Capital souscrit  │ À l'échéance du contrat    │ 3,5% → 4% │
// │ DATAVP    │ Capital constitué │ À la fin du contrat        │ 3,5% → 4% │
// │ Garantie  │ Encours moyen 12m │ À l'échéance du crédit     │ 3,5% fixe  │
// │ Logement  │ ❌ Pas d'intérêt │ —                          │ —          │
// └──────────────────────────────────────────────────────────────────────────┘
// =============================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Accès interdit. Ce script ne peut être exécuté qu'en ligne de commande.\n");
}

define('ROOT', dirname(__DIR__));
require_once ROOT . '/config/database.php';

// ── Logger ──
$log_dir  = ROOT . '/cron/logs';
if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);
$log_file = $log_dir . '/interets_' . date('Y-m') . '.log';

function logMsg(string $msg, string $niveau = 'INFO'): void {
    global $log_file;
    $ligne = '[' . date('Y-m-d H:i:s') . '] [' . $niveau . '] ' . $msg . PHP_EOL;
    file_put_contents($log_file, $ligne, FILE_APPEND);
    echo $ligne;
}

// ── Paramètres système ──
function getParam(PDO $pdo, string $cle, float $defaut): float {
    $s = $pdo->prepare("SELECT valeur FROM parametres_systeme WHERE cle = ? LIMIT 1");
    $s->execute([$cle]);
    $val = $s->fetchColumn();
    return $val !== false ? (float)$val : $defaut;
}

function interetExiste(PDO $pdo, int $compte_id, string $pattern, ?int $annee = null, ?string $mois = null): bool {
    $sql = "
        SELECT COUNT(*)
        FROM mouvements
        WHERE compte_id = ?
          AND type = 'interet'
          AND commentaire LIKE ?
    ";

    $params = [$compte_id, $pattern];

    if ($annee !== null) {
        $sql .= " AND YEAR(date_operation) = ?";
        $params[] = $annee;
    }

    if ($mois !== null) {
        $sql .= " AND DATE_FORMAT(date_operation, '%Y-%m') = ?";
        $params[] = $mois;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int)$stmt->fetchColumn() > 0;
}

$taux_expresse   = getParam($pdo, 'taux_interet_epargne',    3.5);
$taux_ordinaire  = getParam($pdo, 'taux_interet_epargne',    3.5);
$taux_garantie   = getParam($pdo, 'taux_interet_epargne',    3.5);
$taux_dats_min   = getParam($pdo, 'taux_interet_dats_min',   3.5);
$taux_dats_max   = getParam($pdo, 'taux_interet_dats_max',   4.0);
$taux_datavp_min = getParam($pdo, 'taux_interet_datavp_min', 3.5);
$taux_datavp_max = getParam($pdo, 'taux_interet_datavp_max', 3.9);

// ── Identifiant système ──
$system_user_id = $pdo->query("
    SELECT id FROM utilisateurs WHERE role = 'super_admin' LIMIT 1
")->fetchColumn() ?: 1;

$today       = date('Y-m-d');
$mois_actuel = (int)date('n');
$annee       = (int)date('Y');
$est_janvier = ($mois_actuel === 1);

$nb_total_traites = 0;
$nb_total_erreurs = 0;
$total_interets   = 0.0;

logMsg("========== DÉBUT CALCUL INTÉRÊTS — " . date('F Y') . " ==========");
logMsg("Taux Expresse/Ordinaire : $taux_expresse% | DATS : $taux_dats_min%→$taux_dats_max% | DATAVP : $taux_datavp_min%→$taux_datavp_max%");

// ============================================================
// FONCTION : Créditer les intérêts sur un compte
// ============================================================
function crediterInteret(
    PDO $pdo,
    int $compte_id,
    float $montant_interet,
    float $solde_avant,
    int $membre_id,
    string $type_libelle,
    string $commentaire,
    int $system_user_id,
    string $today
): void {
    global $total_interets, $nb_total_traites;

    if ($montant_interet <= 0) return;

    $solde_apres = $solde_avant + $montant_interet;
    $ref = 'INT-' . date('Ymd') . '-' . str_pad($compte_id, 6, '0', STR_PAD_LEFT);

    $pdo->prepare("
        UPDATE comptes_epargne
        SET solde = solde + ?, solde_disponible = solde_disponible + ?
        WHERE id = ?
    ")->execute([$montant_interet, $montant_interet, $compte_id]);

    $pdo->prepare("
        INSERT INTO mouvements
            (compte_id, reference, type, montant, solde_avant, solde_apres,
             date_valeur, date_operation, canal, utilisateur_id, commentaire)
        VALUES (?, ?, 'interet', ?, ?, ?, ?, ?, 'systeme', ?, ?)
    ")->execute([
        $compte_id, $ref,
        $montant_interet, $solde_avant, $solde_apres,
        $today, $today, $system_user_id, $commentaire
    ]);

    $pdo->prepare("
        INSERT INTO notifications
            (membre_id, titre, message, type, lien, date_creation)
        VALUES (?, '💰 Intérêts crédités', ?, 'info', 'fiche_epargne.php', NOW())
    ")->execute([
        $membre_id,
        "Intérêts crédités sur votre compte $type_libelle : +"
        . number_format($montant_interet, 0, ',', ' ') . " F CFA. $commentaire"
    ]);

    $total_interets   += $montant_interet;
    $nb_total_traites++;
}

// ============================================================
// FONCTIONS TAUX DATS / DATAVP
// ============================================================
function tauxDats(int $duree_mois, float $tmin, float $tmax): float {
    if ($duree_mois <= 12) return $tmin;
    if ($duree_mois <= 24) return round(($tmin + $tmax) / 2, 2);
    return $tmax;
}

function tauxDatavp(int $duree_mois, float $tmin, float $tmax): float {
    if ($duree_mois <= 24) return $tmin;
    if ($duree_mois <= 48) return round(($tmin + $tmax) / 2, 2);
    return $tmax;
}

// ============================================================
// BLOC 1 : EXPRESSE + ORDINAIRE — Intérêts annuels
// Crédités uniquement le 1er janvier (fin d'exercice)
// ============================================================
if ($est_janvier) {
    logMsg("── BLOC 1 : Intérêts annuels EXPRESSE + ORDINAIRE (exercice " . ($annee - 1) . ") ──");

    // Anti-doublon : vérifier si déjà crédités cette année
    $deja = $pdo->prepare("
        SELECT COUNT(*) FROM mouvements
        WHERE type = 'interet'
          AND YEAR(date_operation) = ?
          AND commentaire LIKE '%annuels%'
    ");
    $deja->execute([$annee]);

    if ($deja->fetchColumn() > 0) {
        logMsg("  ⚠️  Intérêts annuels déjà crédités pour $annee. SKIP.", 'WARN');
    } else {
        $stmt = $pdo->prepare("
            SELECT ce.id, ce.numero_compte, ce.solde, ce.solde_disponible,
                   tce.code AS type_code, tce.libelle AS type_libelle,
                   m.id AS membre_id, m.nom, m.prenoms
            FROM comptes_epargne ce
            JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
            JOIN membres m ON ce.membre_id = m.id
            WHERE ce.statut = 'actif'
              AND tce.code IN ('expresse','ordinaire')
              AND ce.solde > 0
              AND m.statut = 'actif'
        ");
        $stmt->execute();
        $comptes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        logMsg("  Comptes trouvés : " . count($comptes));

        foreach ($comptes as $c) {
            try {
                if (interetExiste($pdo, (int)$c['id'], '%Intérêts annuels ' . ($annee - 1) . '%', $annee)) {
                    logMsg("  SKIP {$c['numero_compte']} — intérêts annuels déjà crédités", 'DEBUG');
                    continue;
                }

                $pdo->beginTransaction();

                $taux      = $c['type_code'] === 'expresse' ? $taux_expresse : $taux_ordinaire;
                $base      = (float)$c['solde'];
                $interet   = round($base * ($taux / 100), 2);
                $comment   = "Intérêts annuels " . ($annee - 1) . " — {$c['type_libelle']} — taux $taux%/an";

                crediterInteret($pdo, $c['id'], $interet, $base,
                    $c['membre_id'], $c['type_libelle'], $comment,
                    $system_user_id, $today);

                $pdo->commit();
                logMsg(sprintf(
                    "  ✅ %s | %s %s | Solde %.0f F | Taux %.2f%% | +%.2f F",
                    $c['numero_compte'], $c['prenoms'], $c['nom'], $base, $taux, $interet
                ));

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $nb_total_erreurs++;
                logMsg("  ❌ {$c['numero_compte']} : " . $e->getMessage(), 'ERROR');
            }
        }
    }
} else {
    logMsg("── BLOC 1 : SKIP — Expresse/Ordinaire crédités uniquement le 1er janvier ──");
}

// ============================================================
// BLOC 2 : DATS — Intérêts à l'échéance du contrat
// Vérification mensuelle : DATS dont l'échéance tombe ce mois
// ============================================================
logMsg("── BLOC 2 : Intérêts DATS arrivés à échéance ──");

$dats_stmt = $pdo->prepare("
    SELECT ce.id, ce.numero_compte, ce.solde, ce.solde_disponible,
           ce.date_ouverture, ce.date_echeance, ce.duree_mois,
           tce.libelle AS type_libelle,
           m.id AS membre_id, m.nom, m.prenoms
    FROM comptes_epargne ce
    JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
    JOIN membres m ON ce.membre_id = m.id
    WHERE ce.statut = 'actif'
      AND tce.code = 'dats'
      AND ce.solde > 0
      AND m.statut = 'actif'
      AND (
        (ce.date_echeance IS NOT NULL
         AND DATE_FORMAT(ce.date_echeance, '%Y-%m') = DATE_FORMAT(?, '%Y-%m'))
        OR
        (ce.date_echeance IS NULL AND ce.duree_mois IS NOT NULL
         AND DATE_FORMAT(DATE_ADD(ce.date_ouverture, INTERVAL ce.duree_mois MONTH), '%Y-%m')
             = DATE_FORMAT(?, '%Y-%m'))
      )
");
$dats_stmt->execute([$today, $today]);
$dats_list = $dats_stmt->fetchAll(PDO::FETCH_ASSOC);

logMsg("  DATS arrivés à échéance : " . count($dats_list));

foreach ($dats_list as $c) {
    try {
        if (interetExiste($pdo, (int)$c['id'], '%Intérêts DATS échéance%', null, date('Y-m'))) {
            logMsg("  SKIP DATS {$c['numero_compte']} — intérêts d'échéance déjà crédités ce mois", 'DEBUG');
            continue;
        }

        $pdo->beginTransaction();

        $date_ouv   = new DateTime($c['date_ouverture']);
        $date_ech   = $c['date_echeance']
            ? new DateTime($c['date_echeance'])
            : (clone $date_ouv)->modify('+' . (int)$c['duree_mois'] . ' months');
        $duree_mois = max(1, (int)($date_ouv->diff($date_ech)->days / 30));

        $taux    = tauxDats($duree_mois, $taux_dats_min, $taux_dats_max);
        $base    = (float)$c['solde'];
        $interet = round($base * ($taux / 100) * ($duree_mois / 12), 2);
        $comment = "Intérêts DATS échéance — $duree_mois mois — taux $taux%/an";

        crediterInteret($pdo, $c['id'], $interet, $base,
            $c['membre_id'], $c['type_libelle'], $comment,
            $system_user_id, $today);

        // Clôturer le compte DATS
        $pdo->prepare("UPDATE comptes_epargne SET statut = 'cloture' WHERE id = ?")
            ->execute([$c['id']]);

        $pdo->commit();
        logMsg(sprintf(
            "  ✅ %s | %s %s | Capital %.0f F | Taux %.2f%% | %d mois | +%.2f F | CLÔTURÉ",
            $c['numero_compte'], $c['prenoms'], $c['nom'],
            $base, $taux, $duree_mois, $interet
        ));

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $nb_total_erreurs++;
        logMsg("  ❌ DATS {$c['numero_compte']} : " . $e->getMessage(), 'ERROR');
    }
}

// ============================================================
// BLOC 3 : DATAVP — Intérêts à la fin du contrat
// ============================================================
logMsg("── BLOC 3 : Intérêts DATAVP arrivés à terme ──");

$datavp_stmt = $pdo->prepare("
    SELECT ce.id, ce.numero_compte, ce.solde, ce.solde_disponible,
           ce.date_ouverture, ce.date_echeance, ce.duree_mois,
           ce.nb_versements_effectues,
           tce.libelle AS type_libelle,
           m.id AS membre_id, m.nom, m.prenoms
    FROM comptes_epargne ce
    JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
    JOIN membres m ON ce.membre_id = m.id
    WHERE ce.statut = 'actif'
      AND tce.code = 'datavp'
      AND ce.solde > 0
      AND m.statut = 'actif'
      AND (
        (ce.date_echeance IS NOT NULL
         AND DATE_FORMAT(ce.date_echeance, '%Y-%m') = DATE_FORMAT(?, '%Y-%m'))
        OR
        (ce.date_echeance IS NULL AND ce.duree_mois IS NOT NULL
         AND DATE_FORMAT(DATE_ADD(ce.date_ouverture, INTERVAL ce.duree_mois MONTH), '%Y-%m')
             = DATE_FORMAT(?, '%Y-%m'))
      )
");
$datavp_stmt->execute([$today, $today]);
$datavp_list = $datavp_stmt->fetchAll(PDO::FETCH_ASSOC);

logMsg("  DATAVP arrivés à terme : " . count($datavp_list));

foreach ($datavp_list as $c) {
    try {
        if (interetExiste($pdo, (int)$c['id'], '%Intérêts DATAVP terme%', null, date('Y-m'))) {
            logMsg("  SKIP DATAVP {$c['numero_compte']} — intérêts de terme déjà crédités ce mois", 'DEBUG');
            continue;
        }

        $pdo->beginTransaction();

        $date_ouv   = new DateTime($c['date_ouverture']);
        $date_ech   = $c['date_echeance']
            ? new DateTime($c['date_echeance'])
            : (clone $date_ouv)->modify('+' . (int)$c['duree_mois'] . ' months');
        $duree_mois = max(1, (int)($date_ouv->diff($date_ech)->days / 30));

        $taux    = tauxDatavp($duree_mois, $taux_datavp_min, $taux_datavp_max);
        $base    = (float)$c['solde'];
        $nb_vers = (int)$c['nb_versements_effectues'];
        $interet = round($base * ($taux / 100) * ($duree_mois / 12), 2);
        $comment = "Intérêts DATAVP terme — $nb_vers versements — $duree_mois mois — taux $taux%/an";

        crediterInteret($pdo, $c['id'], $interet, $base,
            $c['membre_id'], $c['type_libelle'], $comment,
            $system_user_id, $today);

        // Clôturer le compte DATAVP
        $pdo->prepare("UPDATE comptes_epargne SET statut = 'cloture' WHERE id = ?")
            ->execute([$c['id']]);

        $pdo->commit();
        logMsg(sprintf(
            "  ✅ %s | %s %s | Capital %.0f F | Taux %.2f%% | %d mois | +%.2f F | CLÔTURÉ",
            $c['numero_compte'], $c['prenoms'], $c['nom'],
            $base, $taux, $duree_mois, $interet
        ));

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $nb_total_erreurs++;
        logMsg("  ❌ DATAVP {$c['numero_compte']} : " . $e->getMessage(), 'ERROR');
    }
}

// ============================================================
// BLOC 4 : GARANTIE — Intérêts à l'échéance du crédit associé
// Base = encours moyen 12 mois (approximé par solde actuel)
// ============================================================
logMsg("── BLOC 4 : Intérêts GARANTIE (crédits soldés ce mois) ──");

$garantie_stmt = $pdo->prepare("
    SELECT ce.id, ce.numero_compte, ce.solde, ce.solde_disponible,
           ce.date_ouverture,
           tce.libelle AS type_libelle,
           m.id AS membre_id, m.nom, m.prenoms,
           cr.id AS credit_id, cr.numero_credit
    FROM comptes_epargne ce
    JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
    JOIN membres m ON ce.membre_id = m.id
    JOIN credits cr ON cr.compte_garantie_id = ce.id
    WHERE ce.statut = 'actif'
      AND tce.code = 'garantie'
      AND ce.solde > 0
      AND m.statut = 'actif'
      AND cr.statut = 'solde'
      AND DATE_FORMAT(cr.updated_at, '%Y-%m') = DATE_FORMAT(?, '%Y-%m')
");
$garantie_stmt->execute([$today]);
$garanties_list = $garantie_stmt->fetchAll(PDO::FETCH_ASSOC);

logMsg("  Garanties éligibles : " . count($garanties_list));

foreach ($garanties_list as $g) {
    try {
        if (interetExiste($pdo, (int)$g['id'], '%Intérêts garantie crédit ' . $g['numero_credit'] . '%', null, date('Y-m'))) {
            logMsg("  SKIP Garantie {$g['numero_compte']} — intérêts déjà crédités pour ce crédit ce mois", 'DEBUG');
            continue;
        }

        $pdo->beginTransaction();

        $date_ouv   = new DateTime($g['date_ouverture']);
        $duree_mois = max(1, (int)((new DateTime())->diff($date_ouv)->days / 30));
        $base       = (float)$g['solde'];
        $interet    = round($base * ($taux_garantie / 100) * ($duree_mois / 12), 2);
        $comment    = "Intérêts garantie crédit {$g['numero_credit']} — $duree_mois mois — taux $taux_garantie%/an";

        crediterInteret($pdo, $g['id'], $interet, $base,
            $g['membre_id'], $g['type_libelle'], $comment,
            $system_user_id, $today);

        $pdo->commit();
        logMsg(sprintf(
            "  ✅ Garantie %s | Crédit %s | Base %.0f F | %d mois | +%.2f F",
            $g['numero_compte'], $g['numero_credit'],
            $base, $duree_mois, $interet
        ));

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $nb_total_erreurs++;
        logMsg("  ❌ Garantie {$g['numero_compte']} : " . $e->getMessage(), 'ERROR');
    }
}

// ============================================================
// BILAN DANS LOGS_CRON
// ============================================================
try {
    $pdo->prepare("
        INSERT INTO logs_cron
            (script, date_execution, nb_traites, nb_erreurs, montant_total, commentaire)
        VALUES ('interets_mensuels', NOW(), ?, ?, ?, ?)
    ")->execute([
        $nb_total_traites, $nb_total_erreurs, $total_interets,
        "Intérêts " . date('F Y')
        . " | Expresse/Ordinaire : " . ($est_janvier ? 'OUI' : 'NON')
        . " | DATS : " . count($dats_list)
        . " | DATAVP : " . count($datavp_list)
        . " | Garanties : " . count($garanties_list)
        . " | Total : " . number_format($total_interets, 2) . " F"
    ]);
} catch (Exception $e) {
    logMsg("  ⚠️  logs_cron absent : " . $e->getMessage(), 'WARN');
}

// ============================================================
// RÉSUMÉ FINAL
// ============================================================
logMsg("─────────────────────────────────────────────────────");
logMsg("📋 Expresse/Ordinaire : " . ($est_janvier ? '✅ Traités' : '⏭️  Skip'));
logMsg("📋 DATS à échéance    : " . count($dats_list) . " compte(s)");
logMsg("📋 DATAVP à terme     : " . count($datavp_list) . " compte(s)");
logMsg("📋 Garanties soldées  : " . count($garanties_list) . " compte(s)");
logMsg("✅ Total traités      : $nb_total_traites");
logMsg("❌ Erreurs            : $nb_total_erreurs");
logMsg("💰 Total intérêts     : " . number_format($total_interets, 2, ',', ' ') . " F CFA");
logMsg("========== FIN CALCUL INTÉRÊTS — " . date('F Y') . " ==========");
