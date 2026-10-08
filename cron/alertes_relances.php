<?php
// =============================================================================
// CRON/ALERTES_RELANCES.PHP — MA2E
// Fréquence : tous les jours à 09:00
// Crontab   : 0 9 * * * php /chemin/vers/SOUTENANCE_pro/cron/alertes_relances.php
//
// ALERTES GÉRÉES :
//   1. Échéance dans 7 jours (rappel préventif)
//   2. Échéance dans 3 jours (rappel urgent)
//   3. Crédit en retard → relance email membre
//   4. Compte DATS/DATAVP arrivant à échéance dans 30 jours
//   5. Solde épargne Expresse trop bas (< solde minimum réglementaire)
//   6. Cotisation mensuelle non versée (retenue_source=0 et pas de dépôt ce mois)
//
// RÈGLES :
//   - Une seule notification par type d'alerte, par membre et par jour
//   - Email via PHPMailer (config/mailer.php)
//   - Log dans logs/alertes_YYYY-MM-DD.log
// =============================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Accès interdit. Ce script ne peut être exécuté qu'en ligne de commande.\n");
}

define('ROOT', dirname(__DIR__));
require_once ROOT . '/config/database.php';
require_once ROOT . '/config/mailer.php';

// ── Logger ──
$log_dir  = ROOT . '/cron/logs';
if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);
$log_file = $log_dir . '/alertes_' . date('Y-m-d') . '.log';

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

$delai_grace      = (int)getParam($pdo, 'delai_grace_retard', 5);
$solde_mini_mois  = (int)getParam($pdo, 'solde_mini_expresse', 24); // nb mois cotisés

$today      = date('Y-m-d');
$dans_3j    = date('Y-m-d', strtotime('+3 days'));
$dans_7j    = date('Y-m-d', strtotime('+7 days'));
$dans_30j   = date('Y-m-d', strtotime('+30 days'));
$debut_mois = date('Y-m-01');

$nb_alertes_envoyees = 0;
$nb_emails_envoyes   = 0;
$nb_erreurs          = 0;

logMsg("========== DÉBUT ALERTES & RELANCES — " . date('d/m/Y') . " ==========");

// ============================================================
// FONCTION : Vérifier si une notification a déjà été envoyée
//            aujourd'hui pour ce membre + ce type d'alerte
// ============================================================
function notifDejaEnvoyee(PDO $pdo, int $membre_id, string $titre_like): bool {
    global $today;
    $s = $pdo->prepare("
        SELECT COUNT(*) FROM notifications
        WHERE membre_id = ?
          AND DATE(date_creation) = ?
          AND titre LIKE ?
    ");
    $s->execute([$membre_id, $today, $titre_like]);
    return $s->fetchColumn() > 0;
}

function emailValide(?string $email): bool {
    return !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// ============================================================
// FONCTION : Envoyer un email via PHPMailer
// ============================================================
function envoyerAlerte(
    string $email,
    string $nom_complet,
    string $sujet,
    string $corps_html,
    string $corps_texte = ''
): bool {
    try {
        $mail = creerMailer();
        $mail->addAddress($email, $nom_complet);
        $mail->Subject = $sujet;
        $mail->isHTML(true);
        $mail->Body    = wrapEmailHtml($nom_complet, $corps_html);
        $mail->AltBody = $corps_texte ?: strip_tags($corps_html);
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Erreur email ($email) : " . $e->getMessage());
        return false;
    }
}

function envoyerAlerteSiPossible(
    ?string $email,
    string $nom_complet,
    string $sujet,
    string $corps_html,
    string $corps_texte = ''
): bool {
    if (!emailValide($email)) {
        return false;
    }

    return envoyerAlerte($email, $nom_complet, $sujet, $corps_html, $corps_texte);
}

// ============================================================
// FONCTION : Template email HTML MA2E
// ============================================================
function wrapEmailHtml(string $nom, string $contenu): string {
    return '
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:30px 0">
<tr><td align="center">
<table width="520" cellpadding="0" cellspacing="0"
       style="background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.1)">
  <tr><td style="background:linear-gradient(135deg,#1a1a2e,#C9A84C);padding:28px;text-align:center">
    <h1 style="margin:0;color:#fff;font-size:24px;letter-spacing:2px;font-family:Georgia,serif">MA2E</h1>
    <p style="margin:4px 0 0;color:rgba(255,255,255,.75);font-size:11px">Mutuelle des Agents de l\'Eau et de l\'Électricité</p>
  </td></tr>
  <tr><td style="padding:28px">
    <p style="margin:0 0 16px;color:#333;font-size:14px">Bonjour <strong>' . htmlspecialchars($nom) . '</strong>,</p>
    ' . $contenu . '
    <p style="margin:20px 0 0;color:#555;font-size:13px">Cordialement,<br><strong>L\'équipe MA2E</strong></p>
  </td></tr>
  <tr><td style="background:#1a1a1a;padding:16px;text-align:center">
    <p style="margin:0;color:#C9A84C;font-size:11px">© ' . date('Y') . ' MA2E — Ne pas répondre à cet email</p>
  </td></tr>
</table>
</td></tr></table>
</body></html>';
}

// ============================================================
// ALERTE 1 : Échéance dans 7 jours (rappel préventif)
// ============================================================
logMsg("── ALERTE 1 : Échéances dans 7 jours ──");

$ech_7j = $pdo->prepare("
    SELECT e.id, e.date_echeance, e.capital_du, e.interet_du,
           c.numero_credit, c.membre_id,
           tc.libelle AS type_credit,
           m.nom, m.prenoms, m.email
    FROM echeanciers e
    JOIN credits c ON e.credit_id = c.id
    JOIN types_credit tc ON c.type_credit_id = tc.id
    JOIN membres m ON c.membre_id = m.id
    WHERE e.statut = 'a_venir'
      AND DATE(e.date_echeance) = ?
      AND c.statut IN ('en_cours', 'en_retard')
      AND m.statut = 'actif'
");
$ech_7j->execute([$dans_7j]);
$echeances_7j = $ech_7j->fetchAll(PDO::FETCH_ASSOC);

logMsg("  Membres concernés : " . count($echeances_7j));

foreach ($echeances_7j as $e) {
    $mensualite = $e['capital_du'] + $e['interet_du'];
    $titre      = "📅 Rappel échéance — {$e['numero_credit']}";

    if (notifDejaEnvoyee($pdo, $e['membre_id'], "%Rappel échéance%{$e['numero_credit']}%")) {
        logMsg("  SKIP membre {$e['membre_id']} — notif déjà envoyée", 'DEBUG');
        continue;
    }

    try {
        // Notification en base
        $pdo->prepare("
            INSERT INTO notifications (membre_id, titre, message, type, lien, date_creation)
            VALUES (?, ?, ?, 'info', 'mes_credits.php', NOW())
        ")->execute([
            $e['membre_id'],
            $titre,
            "Rappel : votre échéance de " . number_format($mensualite, 0, ',', ' ')
            . " F sur le crédit {$e['numero_credit']} est prévue dans 7 jours, le "
            . date('d/m/Y', strtotime($e['date_echeance'])) . "."
        ]);

        // Email
        $corps = "
            <p style='color:#555;font-size:14px;line-height:1.6'>
                Nous vous rappelons que votre prochaine échéance de crédit arrive dans
                <strong>7 jours</strong>.
            </p>
            <table style='width:100%;border-collapse:collapse;margin:16px 0'>
                <tr style='background:#f8f8f8'><td style='padding:10px;border-bottom:1px solid #eee'><strong>Crédit</strong></td><td style='padding:10px;border-bottom:1px solid #eee'>{$e['numero_credit']} — {$e['type_credit']}</td></tr>
                <tr><td style='padding:10px;border-bottom:1px solid #eee'><strong>Date d'échéance</strong></td><td style='padding:10px;border-bottom:1px solid #eee;color:#C9A84C;font-weight:700'>" . date('d/m/Y', strtotime($e['date_echeance'])) . "</td></tr>
                <tr style='background:#f8f8f8'><td style='padding:10px'><strong>Montant à payer</strong></td><td style='padding:10px;color:#2E7D32;font-weight:700;font-size:16px'>" . number_format($mensualite, 0, ',', ' ') . " F CFA</td></tr>
            </table>
            <p style='color:#555;font-size:13px'>Assurez-vous d'avoir les fonds nécessaires avant cette date.</p>";

        if (!emailValide($e['email'])) {
            logMsg("  INFO {$e['numero_credit']} — email absent/invalide, notification base uniquement", 'DEBUG');
        }
        $envoye = envoyerAlerteSiPossible(
            $e['email'],
            $e['prenoms'] . ' ' . $e['nom'],
            "MA2E — Rappel échéance dans 7 jours — {$e['numero_credit']}",
            $corps
        );

        if ($envoye) $nb_emails_envoyes++;
        $nb_alertes_envoyees++;

        logMsg(sprintf("  ✅ %s %s | Crédit %s | Échéance %s | %.0f F",
            $e['prenoms'], $e['nom'], $e['numero_credit'],
            date('d/m/Y', strtotime($e['date_echeance'])), $mensualite
        ));

    } catch (Exception $ex) {
        $nb_erreurs++;
        logMsg("  ❌ Erreur membre {$e['membre_id']} : " . $ex->getMessage(), 'ERROR');
    }
}

// ============================================================
// ALERTE 2 : Échéance dans 3 jours (rappel urgent)
// ============================================================
logMsg("── ALERTE 2 : Échéances dans 3 jours (urgent) ──");

$ech_3j = $pdo->prepare("
    SELECT e.id, e.date_echeance, e.capital_du, e.interet_du,
           c.numero_credit, c.membre_id,
           tc.libelle AS type_credit,
           m.nom, m.prenoms, m.email
    FROM echeanciers e
    JOIN credits c ON e.credit_id = c.id
    JOIN types_credit tc ON c.type_credit_id = tc.id
    JOIN membres m ON c.membre_id = m.id
    WHERE e.statut = 'a_venir'
      AND DATE(e.date_echeance) = ?
      AND c.statut IN ('en_cours', 'en_retard')
      AND m.statut = 'actif'
");
$ech_3j->execute([$dans_3j]);
$echeances_3j = $ech_3j->fetchAll(PDO::FETCH_ASSOC);

logMsg("  Membres concernés : " . count($echeances_3j));

foreach ($echeances_3j as $e) {
    $mensualite = $e['capital_du'] + $e['interet_du'];
    $titre      = "🔔 Échéance urgente — {$e['numero_credit']}";

    if (notifDejaEnvoyee($pdo, $e['membre_id'], "%Échéance urgente%{$e['numero_credit']}%")) continue;

    try {
        $pdo->prepare("
            INSERT INTO notifications (membre_id, titre, message, type, lien, date_creation)
            VALUES (?, ?, ?, 'warning', 'mes_credits.php', NOW())
        ")->execute([
            $e['membre_id'], $titre,
            "⚠️ Votre échéance de " . number_format($mensualite, 0, ',', ' ')
            . " F sur le crédit {$e['numero_credit']} est dans 3 jours ! "
            . "Date : " . date('d/m/Y', strtotime($e['date_echeance'])) . "."
        ]);

        $corps = "
            <div style='background:#FFF3E0;border-left:4px solid #FF9800;padding:14px;border-radius:0 8px 8px 0;margin-bottom:16px'>
                <strong style='color:#E65100'>⚠️ Rappel urgent — 3 jours restants</strong>
            </div>
            <p style='color:#555;font-size:14px;line-height:1.6'>
                Votre prochaine échéance de crédit est dans <strong style='color:#E65100'>3 jours seulement</strong>.
                Veuillez vous assurer que votre paiement est prêt.
            </p>
            <table style='width:100%;border-collapse:collapse;margin:16px 0'>
                <tr style='background:#FFF3E0'><td style='padding:10px;border-bottom:1px solid #eee'><strong>Crédit</strong></td><td style='padding:10px;border-bottom:1px solid #eee'>{$e['numero_credit']}</td></tr>
                <tr><td style='padding:10px;border-bottom:1px solid #eee'><strong>Date limite</strong></td><td style='padding:10px;border-bottom:1px solid #eee;color:#E65100;font-weight:700'>" . date('d/m/Y', strtotime($e['date_echeance'])) . "</td></tr>
                <tr style='background:#FFF3E0'><td style='padding:10px'><strong>Montant</strong></td><td style='padding:10px;color:#C62828;font-weight:700;font-size:16px'>" . number_format($mensualite, 0, ',', ' ') . " F CFA</td></tr>
            </table>
            <p style='color:#888;font-size:12px'>⚠️ Après le délai de grâce de $delai_grace jours, des pénalités seront appliquées.</p>";

        if (!emailValide($e['email'])) {
            logMsg("  INFO {$e['numero_credit']} — email absent/invalide, notification base uniquement", 'DEBUG');
        }
        $envoye = envoyerAlerteSiPossible(
            $e['email'],
            $e['prenoms'] . ' ' . $e['nom'],
            "⚠️ MA2E — Échéance URGENTE dans 3 jours — {$e['numero_credit']}",
            $corps
        );

        if ($envoye) $nb_emails_envoyes++;
        $nb_alertes_envoyees++;
        logMsg(sprintf("  ✅ URGENT %s %s | Crédit %s | %.0f F",
            $e['prenoms'], $e['nom'], $e['numero_credit'], $mensualite));

    } catch (Exception $ex) {
        $nb_erreurs++;
        logMsg("  ❌ Erreur : " . $ex->getMessage(), 'ERROR');
    }
}

// ============================================================
// ALERTE 3 : Crédits en retard → Relance email
// ============================================================
logMsg("── ALERTE 3 : Relances crédits en retard ──");

$date_limite = date('Y-m-d', strtotime("-{$delai_grace} days"));

$credits_retard = $pdo->prepare("
    SELECT c.id, c.numero_credit, c.capital_restant_du, c.membre_id,
           tc.libelle AS type_credit,
           m.nom, m.prenoms, m.email,
           COUNT(e.id) AS nb_echeances,
           SUM(e.capital_du + e.interet_du) AS total_du,
           MIN(e.date_echeance) AS premiere_echeance,
           DATEDIFF(?, MIN(e.date_echeance)) AS jours_retard
    FROM credits c
    JOIN types_credit tc ON c.type_credit_id = tc.id
    JOIN membres m ON c.membre_id = m.id
    JOIN echeanciers e ON e.credit_id = c.id
    WHERE c.statut = 'en_retard'
      AND m.statut = 'actif'
      AND e.statut IN ('en_retard', 'a_venir', 'paye_partiellement')
      AND e.date_echeance < ?
    GROUP BY c.id
    HAVING jours_retard > ?
");
$credits_retard->execute([$today, $date_limite, $delai_grace]);
$retards = $credits_retard->fetchAll(PDO::FETCH_ASSOC);

logMsg("  Crédits en retard : " . count($retards));

foreach ($retards as $cr) {
    $titre = "🚨 Relance crédit — {$cr['numero_credit']}";

    if (notifDejaEnvoyee($pdo, $cr['membre_id'], "%Relance crédit%{$cr['numero_credit']}%")) {
        logMsg("  SKIP {$cr['numero_credit']} — notif déjà envoyée", 'DEBUG');
        continue;
    }

    $jours    = $cr['jours_retard'];
    $total_du = number_format($cr['total_du'], 0, ',', ' ');
    $capital  = number_format($cr['capital_restant_du'], 0, ',', ' ');

    try {
        $pdo->prepare("
            INSERT INTO notifications (membre_id, titre, message, type, lien, date_creation)
            VALUES (?, ?, ?, 'danger', 'mes_credits.php', NOW())
        ")->execute([
            $cr['membre_id'], $titre,
            "🚨 Votre crédit {$cr['numero_credit']} est en retard de $jours jour(s). "
            . "{$cr['nb_echeances']} échéance(s) impayée(s) — Total : $total_du F. "
            . "Des pénalités s'accumulent chaque jour. Régularisez votre situation."
        ]);

        $corps = "
            <div style='background:#FFEBEE;border-left:4px solid #F44336;padding:14px;border-radius:0 8px 8px 0;margin-bottom:20px'>
                <strong style='color:#B71C1C'>🚨 Votre crédit est en situation de retard</strong>
            </div>
            <p style='color:#555;font-size:14px;line-height:1.6'>
                Votre crédit <strong>{$cr['numero_credit']}</strong> présente un retard de
                <strong style='color:#C62828'>$jours jour(s)</strong>.
                Des pénalités s'accumulent chaque jour sur votre capital restant.
            </p>
            <table style='width:100%;border-collapse:collapse;margin:16px 0'>
                <tr style='background:#FFEBEE'><td style='padding:10px;border-bottom:1px solid #eee'><strong>Crédit</strong></td><td style='padding:10px;border-bottom:1px solid #eee'>{$cr['numero_credit']} — {$cr['type_credit']}</td></tr>
                <tr><td style='padding:10px;border-bottom:1px solid #eee'><strong>Jours de retard</strong></td><td style='padding:10px;border-bottom:1px solid #eee;color:#C62828;font-weight:700'>$jours jours</td></tr>
                <tr style='background:#FFEBEE'><td style='padding:10px;border-bottom:1px solid #eee'><strong>Échéances impayées</strong></td><td style='padding:10px;border-bottom:1px solid #eee'>{$cr['nb_echeances']}</td></tr>
                <tr><td style='padding:10px;border-bottom:1px solid #eee'><strong>Total dû</strong></td><td style='padding:10px;border-bottom:1px solid #eee;color:#C62828;font-weight:700;font-size:16px'>$total_du F CFA</td></tr>
                <tr style='background:#FFEBEE'><td style='padding:10px'><strong>Capital restant dû</strong></td><td style='padding:10px;font-weight:700'>$capital F CFA</td></tr>
            </table>
            <div style='background:#E3F2FD;padding:14px;border-radius:8px;font-size:13px;color:#1565C0'>
                💡 <strong>Comment régulariser ?</strong><br>
                Connectez-vous à votre espace membre et effectuez un remboursement via
                Mobile Money, virement ou espèces auprès de votre agence MA2E.
            </div>";

        if (!emailValide($cr['email'])) {
            logMsg("  INFO {$cr['numero_credit']} — email absent/invalide, notification base uniquement", 'DEBUG');
        }
        $envoye = envoyerAlerteSiPossible(
            $cr['email'],
            $cr['prenoms'] . ' ' . $cr['nom'],
            "🚨 MA2E — Relance crédit en retard — {$cr['numero_credit']}",
            $corps
        );

        if ($envoye) $nb_emails_envoyes++;
        $nb_alertes_envoyees++;
        logMsg(sprintf("  ✅ RELANCE %s %s | Crédit %s | %d j. retard | Total : %s F",
            $cr['prenoms'], $cr['nom'], $cr['numero_credit'], $jours, $total_du));

    } catch (Exception $ex) {
        $nb_erreurs++;
        logMsg("  ❌ Erreur relance {$cr['numero_credit']} : " . $ex->getMessage(), 'ERROR');
    }
}

// ============================================================
// ALERTE 4 : DATS/DATAVP arrivant à échéance dans 30 jours
// ============================================================
logMsg("── ALERTE 4 : DATS/DATAVP échéance dans 30 jours ──");

$dats_proches = $pdo->prepare("
    SELECT ce.id, ce.numero_compte, ce.solde, ce.date_echeance, ce.date_ouverture, ce.duree_mois,
           COALESCE(
               ce.date_echeance,
               DATE_ADD(ce.date_ouverture, INTERVAL ce.duree_mois MONTH)
           ) AS date_echeance_calculee,
           tce.libelle AS type_libelle, tce.code AS type_code,
           m.id AS membre_id, m.nom, m.prenoms, m.email
    FROM comptes_epargne ce
    JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
    JOIN membres m ON ce.membre_id = m.id
    WHERE ce.statut = 'actif'
      AND tce.code IN ('dats', 'datavp')
      AND ce.solde > 0
      AND m.statut = 'actif'
      AND (
          (ce.date_echeance IS NOT NULL AND DATE(ce.date_echeance) = ?)
          OR
          (ce.date_echeance IS NULL AND ce.duree_mois IS NOT NULL
           AND DATE(DATE_ADD(ce.date_ouverture, INTERVAL ce.duree_mois MONTH)) = ?)
      )
");
$dats_proches->execute([$dans_30j, $dans_30j]);
$dats_list = $dats_proches->fetchAll(PDO::FETCH_ASSOC);

logMsg("  Comptes DATS/DATAVP concernés : " . count($dats_list));

foreach ($dats_list as $d) {
    $titre = "📅 Échéance {$d['type_libelle']} — {$d['numero_compte']}";

    if (notifDejaEnvoyee($pdo, $d['membre_id'], "%Échéance {$d['type_libelle']}%")) continue;

    try {
        $pdo->prepare("
            INSERT INTO notifications (membre_id, titre, message, type, lien, date_creation)
            VALUES (?, ?, ?, 'info', 'fiche_epargne.php', NOW())
        ")->execute([
            $d['membre_id'], $titre,
            "Votre compte {$d['type_libelle']} {$d['numero_compte']} arrive à échéance dans 30 jours "
            . "(" . date('d/m/Y', strtotime($d['date_echeance_calculee'])) . "). "
            . "Capital constitué : " . number_format($d['solde'], 0, ',', ' ') . " F. "
            . "Les intérêts vous seront versés à cette date."
        ]);

        $corps = "
            <p style='color:#555;font-size:14px;line-height:1.6'>
                Votre compte <strong>{$d['type_libelle']}</strong> arrive à échéance dans
                <strong>30 jours</strong>.
            </p>
            <table style='width:100%;border-collapse:collapse;margin:16px 0'>
                <tr style='background:#E8F5E9'><td style='padding:10px;border-bottom:1px solid #eee'><strong>Compte</strong></td><td style='padding:10px;border-bottom:1px solid #eee'>{$d['numero_compte']}</td></tr>
                <tr><td style='padding:10px;border-bottom:1px solid #eee'><strong>Date d'échéance</strong></td><td style='padding:10px;border-bottom:1px solid #eee;color:#2E7D32;font-weight:700'>" . date('d/m/Y', strtotime($d['date_echeance_calculee'])) . "</td></tr>
                <tr style='background:#E8F5E9'><td style='padding:10px'><strong>Capital constitué</strong></td><td style='padding:10px;color:#C9A84C;font-weight:700;font-size:16px'>" . number_format($d['solde'], 0, ',', ' ') . " F CFA</td></tr>
            </table>
            <p style='color:#555;font-size:13px'>
                💰 Vos intérêts seront calculés et versés automatiquement à l'échéance.
                Si vous souhaitez renouveler ou clôturer ce compte, contactez votre agence MA2E.
            </p>";

        if (!emailValide($d['email'])) {
            logMsg("  INFO {$d['numero_compte']} — email absent/invalide, notification base uniquement", 'DEBUG');
        }
        $envoye = envoyerAlerteSiPossible(
            $d['email'],
            $d['prenoms'] . ' ' . $d['nom'],
            "MA2E — Échéance {$d['type_libelle']} dans 30 jours",
            $corps
        );

        if ($envoye) $nb_emails_envoyes++;
        $nb_alertes_envoyees++;
        logMsg(sprintf("  ✅ %s %s | Compte %s | Échéance %s",
            $d['prenoms'], $d['nom'], $d['numero_compte'],
            date('d/m/Y', strtotime($d['date_echeance_calculee']))));

    } catch (Exception $ex) {
        $nb_erreurs++;
        logMsg("  ❌ Erreur DATS {$d['numero_compte']} : " . $ex->getMessage(), 'ERROR');
    }
}

// ============================================================
// ALERTE 5 : Solde Expresse sous le minimum réglementaire
// Règle observée dans le système : solde minimum = cotisation mensuelle × 24
// ============================================================
logMsg("── ALERTE 5 : Solde Expresse sous le minimum réglementaire ──");

$expresse_bas = $pdo->prepare("
    SELECT ce.id AS compte_id, ce.numero_compte, ce.solde,
           m.id AS membre_id, m.nom, m.prenoms, m.email, m.cotisation_mensuelle,
           pep.mois_cotises
    FROM comptes_epargne ce
    JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
    JOIN membres m ON ce.membre_id = m.id
    LEFT JOIN plans_epargne_expresse pep ON pep.compte_id = ce.id AND pep.statut = 'en_cours'
    WHERE ce.statut = 'actif'
      AND tce.code = 'expresse'
      AND m.statut = 'actif'
      AND m.cotisation_mensuelle > 0
      AND ce.solde < (m.cotisation_mensuelle * 24)
");
$expresse_bas->execute();
$expresse_list = $expresse_bas->fetchAll(PDO::FETCH_ASSOC);

logMsg("  Comptes Expresse sous seuil : " . count($expresse_list));

foreach ($expresse_list as $e) {
    $solde_min = (float)$e['cotisation_mensuelle'] * 24;
    $mois_cotises = (int)($e['mois_cotises'] ?? 0);
    $titre = "⚠️ Solde minimum Expresse non atteint";

    if (notifDejaEnvoyee($pdo, (int)$e['membre_id'], "%Solde minimum Expresse%")) {
        continue;
    }

    try {
        $pdo->prepare("
            INSERT INTO notifications (membre_id, titre, message, type, lien, date_creation)
            VALUES (?, ?, ?, 'warning', 'fiche_epargne.php', NOW())
        ")->execute([
            $e['membre_id'],
            $titre,
            "Le solde de votre compte Expresse {$e['numero_compte']} est inférieur au minimum recommandé. "
            . "Solde actuel : " . number_format((float)$e['solde'], 0, ',', ' ') . " F. "
            . "Minimum requis : " . number_format($solde_min, 0, ',', ' ') . " F."
        ]);

        $corps = "
            <p style='color:#555;font-size:14px;line-height:1.6'>
                Le solde de votre compte <strong>Épargne Expresse</strong> est actuellement
                inférieur au minimum réglementaire observé par MA2E.
            </p>
            <table style='width:100%;border-collapse:collapse;margin:16px 0'>
                <tr style='background:#FFF8E1'><td style='padding:10px;border-bottom:1px solid #eee'><strong>Compte</strong></td><td style='padding:10px;border-bottom:1px solid #eee'>{$e['numero_compte']}</td></tr>
                <tr><td style='padding:10px;border-bottom:1px solid #eee'><strong>Solde actuel</strong></td><td style='padding:10px;border-bottom:1px solid #eee;color:#C62828;font-weight:700'>" . number_format((float)$e['solde'], 0, ',', ' ') . " F CFA</td></tr>
                <tr style='background:#FFF8E1'><td style='padding:10px;border-bottom:1px solid #eee'><strong>Minimum requis</strong></td><td style='padding:10px;border-bottom:1px solid #eee;color:#F57F17;font-weight:700'>" . number_format($solde_min, 0, ',', ' ') . " F CFA</td></tr>
                <tr><td style='padding:10px'><strong>Mois cotisés</strong></td><td style='padding:10px'>" . $mois_cotises . " / " . $solde_mini_mois . "</td></tr>
            </table>
            <p style='color:#555;font-size:13px'>
                Nous vous invitons à régulariser votre épargne Expresse pour préserver vos droits et votre capacité d'accès aux produits liés.
            </p>";

        if (!emailValide($e['email'])) {
            logMsg("  INFO {$e['numero_compte']} — email absent/invalide, notification base uniquement", 'DEBUG');
        }
        $envoye = envoyerAlerteSiPossible(
            $e['email'],
            $e['prenoms'] . ' ' . $e['nom'],
            "MA2E — Solde minimum Expresse non atteint",
            $corps
        );

        if ($envoye) $nb_emails_envoyes++;
        $nb_alertes_envoyees++;
        logMsg(sprintf("  ✅ %s %s | Expresse %s | Solde %.0f F / Min %.0f F",
            $e['prenoms'], $e['nom'], $e['numero_compte'], $e['solde'], $solde_min));
    } catch (Exception $ex) {
        $nb_erreurs++;
        logMsg("  ❌ Erreur solde expresse {$e['membre_id']} : " . $ex->getMessage(), 'ERROR');
    }
}

// ============================================================
// ALERTE 6 : Cotisation mensuelle non versée
// Pour les membres avec retenue_source=0 (versement manuel)
// qui n'ont pas de dépôt sur leur compte Expresse ce mois
// ============================================================
logMsg("── ALERTE 6 : Cotisations non versées ce mois ──");

$cotis_manquantes = $pdo->prepare("
    SELECT ce.id AS compte_id, ce.numero_compte, ce.solde,
           m.id AS membre_id, m.nom, m.prenoms, m.email,
           m.cotisation_mensuelle
    FROM comptes_epargne ce
    JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
    JOIN membres m ON ce.membre_id = m.id
    WHERE ce.statut = 'actif'
      AND tce.code = 'expresse'
      AND ce.retenue_source = 0        -- versement manuel uniquement
      AND m.statut = 'actif'
      -- Aucun dépôt/prélèvement ce mois sur ce compte
      AND NOT EXISTS (
          SELECT 1 FROM mouvements mo
          WHERE mo.compte_id = ce.id
            AND mo.type IN ('depot','prelevement_salaire')
            AND DATE(mo.date_operation) >= ?
      )
");
$cotis_manquantes->execute([$debut_mois]);
$cotis_list = $cotis_manquantes->fetchAll(PDO::FETCH_ASSOC);

logMsg("  Membres sans cotisation ce mois : " . count($cotis_list));

foreach ($cotis_list as $m) {
    $titre = "💳 Cotisation mensuelle non versée";

    if (notifDejaEnvoyee($pdo, $m['membre_id'], "%Cotisation mensuelle%")) continue;

    try {
        $pdo->prepare("
            INSERT INTO notifications (membre_id, titre, message, type, lien, date_creation)
            VALUES (?, ?, ?, 'warning', 'fiche_epargne.php', NOW())
        ")->execute([
            $m['membre_id'], $titre,
            "Votre cotisation mensuelle de "
            . number_format($m['cotisation_mensuelle'], 0, ',', ' ')
            . " F n'a pas encore été versée ce mois sur votre compte Expresse {$m['numero_compte']}. "
            . "Pensez à effectuer votre versement."
        ]);

        $corps = "
            <p style='color:#555;font-size:14px;line-height:1.6'>
                Nous avons constaté que votre <strong>cotisation mensuelle</strong> n'a pas encore
                été versée ce mois sur votre compte Épargne Expresse.
            </p>
            <table style='width:100%;border-collapse:collapse;margin:16px 0'>
                <tr style='background:#FFF8E1'><td style='padding:10px;border-bottom:1px solid #eee'><strong>Compte Expresse</strong></td><td style='padding:10px;border-bottom:1px solid #eee'>{$m['numero_compte']}</td></tr>
                <tr><td style='padding:10px;border-bottom:1px solid #eee'><strong>Cotisation due</strong></td><td style='padding:10px;border-bottom:1px solid #eee;color:#F57F17;font-weight:700;font-size:16px'>" . number_format($m['cotisation_mensuelle'], 0, ',', ' ') . " F CFA</td></tr>
                <tr style='background:#FFF8E1'><td style='padding:10px'><strong>Solde actuel</strong></td><td style='padding:10px'>" . number_format($m['solde'], 0, ',', ' ') . " F CFA</td></tr>
            </table>
            <p style='color:#555;font-size:13px'>
                💡 Effectuez votre versement en espèces, par virement ou via Mobile Money
                auprès de votre agence MA2E.
            </p>";

        if (!emailValide($m['email'])) {
            logMsg("  INFO {$m['numero_compte']} — email absent/invalide, notification base uniquement", 'DEBUG');
        }
        $envoye = envoyerAlerteSiPossible(
            $m['email'],
            $m['prenoms'] . ' ' . $m['nom'],
            "MA2E — Rappel : cotisation mensuelle non versée",
            $corps
        );

        if ($envoye) $nb_emails_envoyes++;
        $nb_alertes_envoyees++;
        logMsg(sprintf("  ✅ %s %s | Cotisation %.0f F | Compte %s",
            $m['prenoms'], $m['nom'], $m['cotisation_mensuelle'], $m['numero_compte']));

    } catch (Exception $ex) {
        $nb_erreurs++;
        logMsg("  ❌ Erreur cotisation {$m['membre_id']} : " . $ex->getMessage(), 'ERROR');
    }
}

// ============================================================
// BILAN DANS LOGS_CRON
// ============================================================
try {
    $pdo->prepare("
        INSERT INTO logs_cron
            (script, date_execution, nb_traites, nb_erreurs, montant_total, commentaire)
        VALUES ('alertes_relances', NOW(), ?, ?, 0, ?)
    ")->execute([
        $nb_alertes_envoyees,
        $nb_erreurs,
        "Alertes $today — "
        . count($echeances_7j) . " éch.7j | "
        . count($echeances_3j) . " éch.3j | "
        . count($retards) . " retards | "
        . count($dats_list) . " DATS/DATAVP | "
        . count($expresse_list) . " expresse bas | "
        . count($cotis_list) . " cotis. | "
        . "$nb_emails_envoyes emails envoyés"
    ]);
} catch (Exception $e) {
    logMsg("  ⚠️  logs_cron absent : " . $e->getMessage(), 'WARN');
}

// ============================================================
// RÉSUMÉ FINAL
// ============================================================
logMsg("─────────────────────────────────────────────────────");
logMsg("📅 Rappels 7 jours       : " . count($echeances_7j));
logMsg("🔔 Rappels 3 jours       : " . count($echeances_3j));
logMsg("🚨 Relances retards      : " . count($retards));
logMsg("📋 DATS/DATAVP 30 jours  : " . count($dats_list));
logMsg("⚠️ Expresse sous seuil   : " . count($expresse_list));
logMsg("💳 Cotisations manquantes: " . count($cotis_list));
logMsg("─────────────────────────────────────────────────────");
logMsg("✅ Alertes envoyées      : $nb_alertes_envoyees");
logMsg("📧 Emails envoyés        : $nb_emails_envoyes");
logMsg("❌ Erreurs               : $nb_erreurs");
logMsg("========== FIN ALERTES & RELANCES — " . date('d/m/Y') . " ==========");
