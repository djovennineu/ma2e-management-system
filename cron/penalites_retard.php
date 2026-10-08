<?php
// =============================================================================
// CRON/PENALITES_RETARD.PHP — MA2E (VERSION CORRIGÉE)
// Fréquence : tous les jours à 08:00
// Crontab   : 0 8 * * * php /chemin/vers/SOUTENANCE_pro/cron/penalites_retard.php
//
// RÈGLES MA2E CLARIFIÉES :
//   - Délai de grâce     : 5 jours après date d'échéance avant mise en retard
//   - Base pénalité      : capital restant dû total du crédit
//   - Fréquence          : journalière (chaque jour de retard)
//   - Effet pénalité     : s'ajoute à l'échéance en retard (intérêt dû / mensualité due)
//   - Traitement         : mise en retard + pénalité + notification
//   - Notifications      : une seule par crédit et par jour
//   - Anti-doublon       : vérifie si pénalité déjà appliquée aujourd'hui
//   - Régularisation     : repasse en_cours si toutes échéances payées
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
$log_file = $log_dir . '/penalites_' . date('Y-m-d') . '.log';

function logMsg(string $msg, string $niveau = 'INFO'): void {
    global $log_file;
    $ligne = '[' . date('Y-m-d H:i:s') . '] [' . $niveau . '] ' . $msg . PHP_EOL;
    file_put_contents($log_file, $ligne, FILE_APPEND);
    echo $ligne;
}

// ── Paramètres ──
function getParam(PDO $pdo, string $cle, float $defaut): float {
    $s = $pdo->prepare("SELECT valeur FROM parametres_systeme WHERE cle = ? LIMIT 1");
    $s->execute([$cle]);
    $val = $s->fetchColumn();
    return $val !== false ? (float)$val : $defaut;
}

function penaltyReference(string $today, int $creditId): string {
    return 'PEN-' . str_replace('-', '', $today) . '-' . str_pad((string)$creditId, 6, '0', STR_PAD_LEFT);
}

function penaliteDejaAppliquee(PDO $pdo, string $reference): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM mouvements
        WHERE type = 'penalite'
          AND reference = ?
    ");
    $stmt->execute([$reference]);
    return (int)$stmt->fetchColumn() > 0;
}

$taux_penalite_annuel = getParam($pdo, 'taux_penalite_retard', 5.0);
$taux_penalite_jour   = $taux_penalite_annuel / 100 / 365;

// DÉLAI DE GRÂCE : 5 jours (règle MA2E clarifiée)
$delai_grace = 5;

logMsg("========== DÉBUT TRAITEMENT PÉNALITÉS RETARD ==========");
logMsg("Date : " . date('d/m/Y'));
logMsg("Taux pénalité : $taux_penalite_annuel%/an | Délai de grâce : $delai_grace jours");

$today      = date('Y-m-d');
$date_limite = date('Y-m-d', strtotime("-{$delai_grace} days")); // Échéances avant cette date = en retard

// ── Identifiant système ──
$system_user_id = $pdo->query("
    SELECT id FROM utilisateurs WHERE role = 'super_admin' LIMIT 1
")->fetchColumn() ?: 1;

$nb_penalites    = 0;
$nb_erreurs      = 0;
$nb_regularises  = 0;
$total_penalites = 0.0;

// ============================================================
// ÉTAPE 1 : Détecter les crédits en retard (délai de grâce dépassé)
// Une échéance est en retard si date_echeance < (aujourd'hui - 5 jours)
// ============================================================
logMsg("── ÉTAPE 1 : Détection crédits avec échéances impayées (délai > $delai_grace jours) ──");

$credits_retard_stmt = $pdo->prepare("
    SELECT
        c.id              AS credit_id,
        c.numero_credit,
        c.capital_restant_du,
        c.statut          AS credit_statut,
        c.membre_id,
        c.type_credit_id,
        tc.libelle        AS type_libelle,
        m.nom             AS membre_nom,
        m.prenoms         AS membre_prenoms,
        m.email           AS membre_email,
        -- Nombre d'échéances impayées
        COUNT(e.id)       AS nb_echeances_retard,
        -- Montant total impayé
        SUM(e.capital_du + e.interet_du) AS total_du,
        -- Date de la première échéance impayée
        MIN(e.date_echeance) AS premiere_echeance,
        -- Jours depuis la première échéance impayée
        DATEDIFF(?, MIN(e.date_echeance)) AS jours_retard
    FROM credits c
    JOIN types_credit tc ON c.type_credit_id = tc.id
    JOIN membres m ON c.membre_id = m.id
    JOIN echeanciers e ON e.credit_id = c.id
    WHERE c.statut IN ('en_cours', 'en_retard')
      AND m.statut = 'actif'
      AND e.statut IN ('a_venir', 'paye_partiellement', 'en_retard')
      -- Délai de grâce dépassé : échéance avant (aujourd'hui - 5 jours)
      AND e.date_echeance < ?
    GROUP BY c.id
    HAVING jours_retard > ?
    ORDER BY jours_retard DESC
");
$credits_retard_stmt->execute([$today, $date_limite, $delai_grace]);
$credits_retard = $credits_retard_stmt->fetchAll(PDO::FETCH_ASSOC);

logMsg("Crédits en retard détectés : " . count($credits_retard));

// ============================================================
// ÉTAPE 2 : Passer les crédits en statut 'en_retard'
//           si ce n'est pas déjà fait
// ============================================================
logMsg("── ÉTAPE 2 : Mise à jour statuts ──");

$nb_maj_statut = 0;
foreach ($credits_retard as $cr) {
    try {
        $pdo->beginTransaction();

        if ($cr['credit_statut'] !== 'en_retard') {
            $pdo->prepare("UPDATE credits SET statut = 'en_retard' WHERE id = ?")
                ->execute([$cr['credit_id']]);
            $nb_maj_statut++;
            logMsg("  → Crédit {$cr['numero_credit']} passé en 'en_retard'");
        }

        $pdo->prepare("
            UPDATE echeanciers
            SET statut = 'en_retard'
            WHERE credit_id = ?
              AND date_echeance < ?
              AND statut IN ('a_venir', 'paye_partiellement')
        ")->execute([$cr['credit_id'], $date_limite]);

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $nb_erreurs++;
        logMsg("  ❌ Erreur statut {$cr['numero_credit']} : " . $e->getMessage(), 'ERROR');
    }
}
logMsg("Statuts mis à jour : $nb_maj_statut");

// ============================================================
// ÉTAPE 3 : Anti-doublon — crédits déjà pénalisés aujourd'hui
// ============================================================
logMsg("Anti-doublon actif : vérification par référence unique de pénalité");

// ============================================================
// ÉTAPE 4 : Appliquer les pénalités journalières
// Base = capital restant dû total
// La pénalité s'ajoute à l'échéance en retard pour rester compatible
// avec le moteur de remboursement existant
// ============================================================
logMsg("── ÉTAPE 3 : Application des pénalités ──");

foreach ($credits_retard as $cr) {
    $ref = penaltyReference($today, (int)$cr['credit_id']);

    // Anti-doublon : skip si déjà pénalisé aujourd'hui
    if (penaliteDejaAppliquee($pdo, $ref)) {
        logMsg("  SKIP {$cr['numero_credit']} — déjà pénalisé aujourd'hui", 'DEBUG');
        continue;
    }

    $capital = (float)$cr['capital_restant_du'];
    if ($capital <= 0) {
        logMsg("  SKIP {$cr['numero_credit']} — capital nul", 'DEBUG');
        continue;
    }

    // Calcul pénalité journalière sur le CAPITAL RESTANT DÛ TOTAL
    $penalite_jour = round($capital * $taux_penalite_jour, 2);
    if ($penalite_jour < 1) $penalite_jour = 1.0; // Minimum 1 F

    try {
        $pdo->beginTransaction();

        $pdo->prepare("
            UPDATE credits
            SET statut = 'en_retard'
            WHERE id = ?
        ")->execute([$cr['credit_id']]);

        // Rattacher la pénalité à la prochaine échéance non soldée pour qu'elle soit remboursable.
        $echeanceCibleStmt = $pdo->prepare("
            SELECT id
            FROM echeanciers
            WHERE credit_id = ?
              AND statut IN ('en_retard', 'a_venir', 'paye_partiellement')
            ORDER BY date_echeance ASC, numero_echeance ASC
            LIMIT 1
        ");
        $echeanceCibleStmt->execute([$cr['credit_id']]);
        $echeance_id = $echeanceCibleStmt->fetchColumn();

        if (!$echeance_id) {
            throw new Exception("Aucune échéance disponible pour rattacher la pénalité.");
        }

        $pdo->prepare("
            UPDATE echeanciers
            SET interet_du = interet_du + ?,
                mensualite_due = mensualite_due + ?,
                statut = CASE
                    WHEN statut = 'paye_partiellement' THEN 'paye_partiellement'
                    ELSE 'en_retard'
                END
            WHERE id = ?
        ")->execute([$penalite_jour, $penalite_jour, $echeance_id]);

        // Récupérer le compte Crédits du membre pour le mouvement
        $compte_credits = $pdo->prepare("
            SELECT ce.id, ce.solde FROM comptes_epargne ce
            JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
            WHERE ce.membre_id = ? AND tce.code = 'credits' AND ce.statut = 'actif'
            LIMIT 1
        ");
        $compte_credits->execute([$cr['membre_id']]);
        $compte_credits = $compte_credits->fetch(PDO::FETCH_ASSOC);

        $compte_id_mouv = $compte_credits['id'] ?? null;
        $solde_avant    = (float)($compte_credits['solde'] ?? 0);

        // Mouvement pénalité (comptable — trace la charge)
        $pdo->prepare("
            INSERT INTO mouvements
                (compte_id, reference, type, montant, solde_avant, solde_apres,
                 date_valeur, date_operation, canal, utilisateur_id, commentaire)
            VALUES (?, ?, 'penalite', ?, ?, ?, ?, ?, 'systeme', ?, ?)
        ")->execute([
            $compte_id_mouv,
            $ref,
            $penalite_jour,
            $solde_avant,
            $solde_avant, // Solde compte non modifié — la pénalité augmente la dette crédit
            $today, $today,
            $system_user_id,
            "Pénalité retard crédit {$cr['numero_credit']} — {$cr['jours_retard']} jour(s) de retard — capital +$penalite_jour F"
        ]);

        // ── Notification : une seule par crédit et par jour ──
        // Vérifier si une notification a déjà été envoyée aujourd'hui pour ce crédit
        $notif_existe = $pdo->prepare("
            SELECT COUNT(*) FROM notifications
            WHERE membre_id = ?
              AND DATE(date_creation) = ?
              AND lien = 'mes_credits.php'
              AND titre LIKE ?
        ");
        $notif_existe->execute([$cr['membre_id'], $today, '%' . $cr['numero_credit'] . '%']);

        if ($notif_existe->fetchColumn() == 0) {
            $jours    = $cr['jours_retard'];
            $nb_ech   = $cr['nb_echeances_retard'];
            $total_du = number_format($cr['total_du'], 0, ',', ' ');

            $pdo->prepare("
                INSERT INTO notifications
                    (membre_id, titre, message, type, lien, date_creation)
                VALUES (?, ?, ?, 'danger', 'mes_credits.php', NOW())
            ")->execute([
                $cr['membre_id'],
                "⚠️ Crédit en retard — {$cr['numero_credit']}",
                "Votre crédit {$cr['numero_credit']} est en retard de $jours jour(s). "
                . "$nb_ech échéance(s) impayée(s) — Total dû : $total_du F. "
                . "Pénalité du jour : " . number_format($penalite_jour, 2, ',', ' ') . " F ajoutée à votre échéance en retard. "
                . "Capital restant du crédit : " . number_format($capital, 0, ',', ' ') . " F. "
                . "Régularisez votre situation dès que possible."
            ]);
        }

        $pdo->commit();

        $nb_penalites++;
        $total_penalites += $penalite_jour;

        logMsg(sprintf(
            "  ✅ %s | %s %s | Capital %.0f F | %d j. retard | Pénalité +%.2f F sur échéance",
            $cr['numero_credit'],
            $cr['membre_prenoms'], $cr['membre_nom'],
            $capital,
            $cr['jours_retard'],
            $penalite_jour
        ));

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $nb_erreurs++;
        logMsg("  ❌ ERREUR {$cr['numero_credit']} : " . $e->getMessage(), 'ERROR');
    }
}

// ============================================================
// ÉTAPE 5 : Régularisation automatique
// Si un crédit est marqué 'en_retard' mais n'a plus d'échéances
// impayées dépassées → repasser à 'en_cours'
// ============================================================
logMsg("── ÉTAPE 4 : Vérification crédits régularisés ──");

$regularises_stmt = $pdo->prepare("
    SELECT c.id, c.numero_credit, c.membre_id, c.capital_restant_du
    FROM credits c
    WHERE c.statut = 'en_retard'
      AND NOT EXISTS (
          SELECT 1 FROM echeanciers e
          WHERE e.credit_id = c.id
            AND e.date_echeance < ?
            AND e.statut IN ('a_venir', 'paye_partiellement', 'en_retard')
      )
");
$regularises_stmt->execute([$date_limite]);
$regularises = $regularises_stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($regularises as $cr) {
    try {
        $statut_cible = ((float)$cr['capital_restant_du'] <= 0) ? 'solde' : 'en_cours';
        $pdo->prepare("UPDATE credits SET statut = ? WHERE id = ?")
            ->execute([$statut_cible, $cr['id']]);

        // Notification de régularisation (une seule si pas déjà envoyée aujourd'hui)
        $notif_reg = $pdo->prepare("
            SELECT COUNT(*) FROM notifications
            WHERE membre_id = ? AND DATE(date_creation) = ?
              AND titre LIKE '%régularisé%'
        ");
        $notif_reg->execute([$cr['membre_id'], $today]);

        if ($notif_reg->fetchColumn() == 0) {
            $pdo->prepare("
                INSERT INTO notifications
                    (membre_id, titre, message, type, lien, date_creation)
                VALUES (?, '✅ Crédit régularisé', ?, 'success', 'mes_credits.php', NOW())
            ")->execute([
                $cr['membre_id'],
                $statut_cible === 'solde'
                    ? "Félicitations ! Votre crédit {$cr['numero_credit']} est maintenant soldé."
                    : "Félicitations ! Votre crédit {$cr['numero_credit']} est à nouveau en règle. Continuez ainsi !"
            ]);
        }

        $nb_regularises++;
        logMsg("  ✅ Crédit {$cr['numero_credit']} → régularisé, statut '$statut_cible'");

    } catch (Exception $e) {
        $nb_erreurs++;
        logMsg("  ❌ Erreur régularisation {$cr['numero_credit']} : " . $e->getMessage(), 'ERROR');
    }
}
logMsg("Crédits régularisés : $nb_regularises");

// ============================================================
// BILAN DANS LOGS_CRON
// ============================================================
try {
    $pdo->prepare("
        INSERT INTO logs_cron
            (script, date_execution, nb_traites, nb_erreurs, montant_total, commentaire)
        VALUES ('penalites_retard', NOW(), ?, ?, ?, ?)
    ")->execute([
        $nb_penalites,
        $nb_erreurs,
        $total_penalites,
        "Pénalités $today — "
        . count($credits_retard) . " crédits en retard — "
        . "$nb_penalites pénalisés — "
        . "$nb_regularises régularisés — "
        . "Total : " . number_format($total_penalites, 2) . " F"
    ]);
} catch (Exception $e) {
    logMsg("  ⚠️  logs_cron absent : " . $e->getMessage(), 'WARN');
}

// ============================================================
// RÉSUMÉ FINAL
// ============================================================
logMsg("─────────────────────────────────────────────────────");
logMsg("📋 Crédits en retard détectés  : " . count($credits_retard));
logMsg("✅ Pénalités appliquées        : $nb_penalites");
logMsg("✅ Crédits régularisés         : $nb_regularises");
logMsg("❌ Erreurs                     : $nb_erreurs");
logMsg("💸 Total pénalités             : " . number_format($total_penalites, 2, ',', ' ') . " F CFA");
logMsg("📅 Délai de grâce appliqué     : $delai_grace jours");
logMsg("📊 Date limite retard          : $date_limite");
logMsg("========== FIN TRAITEMENT PÉNALITÉS RETARD ==========");
