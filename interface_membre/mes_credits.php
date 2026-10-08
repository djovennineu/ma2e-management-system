<?php
require_once '../connexion/auth_check.php';
checkRole('membre');
require_once '../config/database.php';

$user_id   = $_SESSION['user_id'];
$membre_id = $_SESSION['membre_id'];

// ============================================================
// FONCTION : Récupérer ou créer le compte CRÉDITS du membre
// ============================================================
function getCompteCreditsId($pdo, $membre_id) {
    $stmt = $pdo->prepare("
        SELECT ce.id FROM comptes_epargne ce
        JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
        WHERE ce.membre_id = ? AND tce.code = 'credits'
        LIMIT 1
    ");
    $stmt->execute([$membre_id]);
    $compte = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$compte) {
        $type_credits = $pdo->query("SELECT id FROM types_compte_epargne WHERE code = 'credits' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $numero_compte = 'CRED-' . $membre_id . '-' . date('Ymd') . '-' . rand(100, 999);
        $pdo->prepare("
            INSERT INTO comptes_epargne (membre_id, type_compte_id, numero_compte, solde, solde_disponible, date_ouverture)
            VALUES (?, ?, ?, 0, 0, CURDATE())
        ")->execute([$membre_id, $type_credits['id'], $numero_compte]);
        return $pdo->lastInsertId();
    }
    return $compte['id'];
}

// ============================================================
// FONCTION : Libérer la garantie → virement vers compte ordinaire
// CORRECTION #4 : mouvement enregistré sur le bon compte (ordinaire)
// ============================================================
function libererGarantie($pdo, $credit_id, $membre_id, $user_id) {
    $garantie = $pdo->prepare("
        SELECT ce.id, ce.solde FROM comptes_epargne ce
        JOIN credits c ON c.compte_garantie_id = ce.id
        WHERE c.id = ?
        LIMIT 1
    ");
    $garantie->execute([$credit_id]);
    $garantie = $garantie->fetch(PDO::FETCH_ASSOC);

    if ($garantie && $garantie['solde'] > 0) {
        // Chercher le compte ordinaire du membre
        $ordinaire = $pdo->prepare("
            SELECT ce.id, ce.solde FROM comptes_epargne ce
            JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
            WHERE ce.membre_id = ? AND tce.code = 'ordinaire' AND ce.statut = 'actif'
            LIMIT 1
        ");
        $ordinaire->execute([$membre_id]);
        $ordinaire = $ordinaire->fetch(PDO::FETCH_ASSOC);

        if ($ordinaire) {
            $montant      = $garantie['solde'];
            $solde_avant  = $ordinaire['solde'];
            $solde_apres  = $solde_avant + $montant;

            // Crédit sur le compte ordinaire
            $pdo->prepare("
                UPDATE comptes_epargne
                SET solde = solde + ?, solde_disponible = solde_disponible + ?
                WHERE id = ?
            ")->execute([$montant, $montant, $ordinaire['id']]);

            // Clôture du compte garantie
            $pdo->prepare("
                UPDATE comptes_epargne
                SET solde = 0, solde_bloque = 0, statut = 'cloture'
                WHERE id = ?
            ")->execute([$garantie['id']]);

            // CORRECTION #4 : mouvement sur le compte ORDINAIRE (pas crédits)
            $ref = 'LIB-' . date('YmdHis') . '-' . rand(100, 999);
            $pdo->prepare("
                INSERT INTO mouvements
                    (compte_id, reference, type, montant, solde_avant, solde_apres,
                     date_valeur, date_operation, canal, utilisateur_id, commentaire)
                VALUES (?, ?, 'deblocage_garantie', ?, ?, ?, ?, ?, 'systeme', ?, ?)
            ")->execute([
                $ordinaire['id'],   // ← compte ordinaire, pas compte crédits
                $ref,
                $montant,
                $solde_avant,
                $solde_apres,
                date('Y-m-d'),
                date('Y-m-d'),
                $user_id,
                "Libération de la garantie du crédit #$credit_id → versement sur compte Ordinaire"
            ]);
        }
    }
}

$compte_credits_id = getCompteCreditsId($pdo, $membre_id);

$membre = $pdo->prepare("
    SELECT m.*, s.nom AS societe_nom, s.code AS societe_code
    FROM membres m
    JOIN societes s ON m.societe_id = s.id
    WHERE m.id = ?
");
$membre->execute([$membre_id]);
$membre = $membre->fetch(PDO::FETCH_ASSOC);
if (!$membre) { header('Location: espace_membre.php'); exit; }

$success = '';
$error   = '';

// Récupération des crédits
$credits = $pdo->prepare("
    SELECT c.*, tc.libelle AS type_libelle, tc.code AS type_code
    FROM credits c
    JOIN types_credit tc ON c.type_credit_id = tc.id
    WHERE c.membre_id = ?
    ORDER BY FIELD(c.statut, 'en_cours', 'en_retard', 'solde'), c.date_mise_en_place DESC
");
$credits->execute([$membre_id]);
$credits_list = $credits->fetchAll(PDO::FETCH_ASSOC);

$credits_actifs = array_filter($credits_list, fn($c) => in_array($c['statut'], ['en_cours', 'en_retard']));
$credits_soldes = array_filter($credits_list, fn($c) => $c['statut'] === 'solde');

$echeanciers         = [];
$totaux_restants     = [];
$prochaines_echeances = [];

foreach ($credits_actifs as $credit) {
    $stmt = $pdo->prepare("SELECT * FROM echeanciers WHERE credit_id = ? ORDER BY numero_echeance ASC");
    $stmt->execute([$credit['id']]);
    $echeanciers[$credit['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================
// TRAITEMENT DU REMBOURSEMENT
// CORRECTION #5 : type_remboursement explicite (partiel ou total)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rembourser') {

    $credit_id          = (int)($_POST['credit_id'] ?? 0);
    $montant_saisi      = (float)($_POST['montant'] ?? 0);
    $operateur          = $_POST['operateur'] ?? '';
    $telephone          = trim($_POST['telephone'] ?? '');
    $type_remboursement = $_POST['type_remboursement'] ?? 'partiel'; // 'partiel' ou 'total'
    $operateurs_valides = ['orange', 'mtn', 'wave'];

    // Charger le crédit
    $credit = $pdo->prepare("
        SELECT c.*, tc.code AS type_code
        FROM credits c
        JOIN types_credit tc ON c.type_credit_id = tc.id
        WHERE c.id = ? AND c.membre_id = ? AND c.statut IN ('en_cours', 'en_retard')
    ");
    $credit->execute([$credit_id, $membre_id]);
    $credit = $credit->fetch(PDO::FETCH_ASSOC);

    if (!$credit) {
        $error = "Crédit introuvable ou déjà soldé.";
    } elseif (!in_array($operateur, $operateurs_valides)) {
        $error = "Opérateur invalide.";
    } elseif (empty($telephone)) {
        $error = "Numéro de téléphone requis.";
    } elseif ($montant_saisi <= 0) {
        $error = "Montant invalide.";
    // CORRECTION #3 : minimum 5 000 F (était 500 F)
    } elseif ($montant_saisi < 5000) {
        $error = "Le montant minimum de remboursement est de 5 000 F CFA.";
    } else {
        try {
            $pdo->beginTransaction();

            // Toutes les échéances à venir
            $echeances_stmt = $pdo->prepare("
                SELECT * FROM echeanciers
                WHERE credit_id = ? AND statut IN ('a_venir','paye_partiellement','en_retard')
                ORDER BY numero_echeance ASC
            ");
            $echeances_stmt->execute([$credit_id]);
            $echeances = $echeances_stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($echeances)) {
                throw new Exception("Aucune échéance à payer.");
            }

            // Totaux restants
            $capital_restant_total   = array_sum(array_column($echeances, 'capital_du'));
            $interets_restants_total = array_sum(array_column($echeances, 'interet_du'));
            $total_restant           = $capital_restant_total + $interets_restants_total;

            // CORRECTION #2 : anticipé = il reste des échéances futures après la dernière
            // = on solde avant la date de la DERNIÈRE échéance
            $derniere_echeance = end($echeances);
            $date_aujourdhui   = date('Y-m-d');
            $est_anticipe      = ($date_aujourdhui < $derniere_echeance['date_echeance']);

            $ops_labels = ['orange' => 'Orange Money', 'mtn' => 'MTN MoMo', 'wave' => 'Wave'];
            $op_label   = $ops_labels[$operateur] ?? $operateur;

            // ─────────────────────────────────────
            // CAS 1 : REMBOURSEMENT TOTAL (solder)
            // CORRECTION #5 : basé sur type_remboursement explicite
            // ─────────────────────────────────────
            if ($type_remboursement === 'total') {

                // Pénalité 1% sur capital restant si anticipé
                // CORRECTION #2 : pénalité si on solde avant la dernière échéance
                $penalite    = $est_anticipe ? round($capital_restant_total * 0.01) : 0;
                $total_requis = $total_restant + $penalite;

                if ($montant_saisi < $total_requis) {
                    $pdo->rollBack();
                    $error = "Pour solder ce crédit, vous devez payer exactement "
                           . number_format($total_requis, 0, ',', ' ') . " F.<br>"
                           . "• Capital restant : " . number_format($capital_restant_total, 0, ',', ' ') . " F<br>"
                           . "• Intérêts restants : " . number_format($interets_restants_total, 0, ',', ' ') . " F"
                           . ($penalite > 0 ? "<br>• Pénalité anticipation (1%) : " . number_format($penalite, 0, ',', ' ') . " F" : "");
                } else {
                    // CORRECTION #3 : on accepte montant >= total_requis (on solde quand même)
                    // Marquer toutes les échéances comme payées
                    foreach ($echeances as $e) {
                        $pdo->prepare("
                            UPDATE echeanciers
                            SET statut = 'paye', date_paiement = ?,
                                capital_rembourse = capital_du,
                                interet_rembourse = interet_du
                            WHERE id = ?
                        ")->execute([date('Y-m-d'), $e['id']]);
                    }

                    // Solder le crédit
                    $pdo->prepare("
                        UPDATE credits SET capital_restant_du = 0, statut = 'solde' WHERE id = ?
                    ")->execute([$credit_id]);

                    // Libérer la garantie
                    libererGarantie($pdo, $credit_id, $membre_id, $user_id);

                    // Mouvement capital
                    $ref = 'REM-' . date('YmdHis') . '-' . rand(100, 999);
                    $pdo->prepare("
                        INSERT INTO mouvements
                            (compte_id, reference, type, montant, solde_avant, solde_apres,
                             date_valeur, date_operation, canal, utilisateur_id, commentaire)
                        VALUES (?, ?, 'remboursement', ?, 0, 0, ?, ?, 'mobile_money', ?, ?)
                    ")->execute([
                        $compte_credits_id, $ref, $capital_restant_total,
                        date('Y-m-d'), date('Y-m-d'), $user_id,
                        "Remboursement total crédit #{$credit['numero_credit']} via $op_label"
                    ]);

                    // Mouvement intérêts
                    if ($interets_restants_total > 0) {
                        $ref_int = 'INT-' . date('YmdHis') . '-' . rand(100, 999);
                        $pdo->prepare("
                            INSERT INTO mouvements
                                (compte_id, reference, type, montant, solde_avant, solde_apres,
                                 date_valeur, date_operation, canal, utilisateur_id, commentaire)
                            VALUES (?, ?, 'interet', ?, 0, 0, ?, ?, 'mobile_money', ?, ?)
                        ")->execute([
                            $compte_credits_id, $ref_int, $interets_restants_total,
                            date('Y-m-d'), date('Y-m-d'), $user_id,
                            "Intérêts remboursement total crédit #{$credit['numero_credit']}"
                        ]);
                    }

                    // Mouvement pénalité si anticipé
                    if ($penalite > 0) {
                        $ref_pen = 'PEN-' . date('YmdHis') . '-' . rand(100, 999);
                        $pdo->prepare("
                            INSERT INTO mouvements
                                (compte_id, reference, type, montant, solde_avant, solde_apres,
                                 date_valeur, date_operation, canal, utilisateur_id, commentaire)
                            VALUES (?, ?, 'penalite', ?, 0, 0, ?, ?, 'mobile_money', ?, ?)
                        ")->execute([
                            $compte_credits_id, $ref_pen, $penalite,
                            date('Y-m-d'), date('Y-m-d'), $user_id,
                            "Pénalité remboursement anticipé 1% — crédit #{$credit['numero_credit']}"
                        ]);
                    }

                    $pdo->commit();

                    $success = "✅ Crédit soldé avec succès !<br>"
                             . "Total payé : " . number_format($total_requis, 0, ',', ' ') . " F<br>"
                             . "• Capital : " . number_format($capital_restant_total, 0, ',', ' ') . " F<br>"
                             . "• Intérêts : " . number_format($interets_restants_total, 0, ',', ' ') . " F"
                             . ($penalite > 0 ? "<br>• Pénalité : " . number_format($penalite, 0, ',', ' ') . " F" : "")
                             . "<br>La garantie a été restituée sur votre compte Ordinaire.";
                }

            // ─────────────────────────────────────
            // CAS 2 : REMBOURSEMENT PARTIEL
            // CORRECTION #5 : basé sur type_remboursement explicite
            // ─────────────────────────────────────
            } else {

                // Pas de pénalité sur remboursement partiel
                $reste                   = $montant_saisi;
                $capital_rembourse_total = 0;
                $interet_rembourse_total = 0;

                foreach ($echeances as $e) {
                    if ($reste <= 0) break;

                    $capital_du = (float)$e['capital_du'];
                    $interet_du = (float)$e['interet_du'];
                    $total_due  = $capital_du + $interet_du;

                    if ($reste >= $total_due) {
                        // Payer l'échéance complète
                        $pdo->prepare("
                            UPDATE echeanciers
                            SET statut = 'paye', date_paiement = ?,
                                capital_rembourse = capital_du,
                                interet_rembourse = interet_du
                            WHERE id = ?
                        ")->execute([date('Y-m-d'), $e['id']]);

                        $capital_rembourse_total += $capital_du;
                        $interet_rembourse_total += $interet_du;
                        $reste -= $total_due;

                    } else {
                        // Paiement partiel : priorité intérêts puis capital
                        $interet_paye  = min($reste, $interet_du);
                        $capital_paye  = max(0, $reste - $interet_paye);
                        $nouveau_interet = $interet_du - $interet_paye;
                        $nouveau_capital = $capital_du - $capital_paye;

                        $pdo->prepare("
                            UPDATE echeanciers
                            SET capital_du = ?, interet_du = ?,
                                statut = 'paye_partiellement', date_paiement = ?,
                                capital_rembourse = capital_rembourse + ?,
                                interet_rembourse = interet_rembourse + ?
                            WHERE id = ?
                        ")->execute([
                            $nouveau_capital, $nouveau_interet,
                            date('Y-m-d'),
                            $capital_paye, $interet_paye,
                            $e['id']
                        ]);

                        $capital_rembourse_total += $capital_paye;
                        $interet_rembourse_total += $interet_paye;
                        $reste = 0;
                    }
                }

                // Mettre à jour le capital restant du crédit
                $nouveau_capital_credit = $credit['capital_restant_du'] - $capital_rembourse_total;
                $nouveau_capital_credit = max(0, $nouveau_capital_credit);

                $pdo->prepare("
                    UPDATE credits
                    SET capital_restant_du = ?,
                        statut = CASE WHEN ? <= 0 THEN 'solde' ELSE statut END
                    WHERE id = ?
                ")->execute([$nouveau_capital_credit, $nouveau_capital_credit, $credit_id]);

                // Si soldé via partiel (edge case), libérer la garantie
                if ($nouveau_capital_credit <= 0) {
                    libererGarantie($pdo, $credit_id, $membre_id, $user_id);
                }

                // Mouvement remboursement partiel
                $ref = 'REM-' . date('YmdHis') . '-' . rand(100, 999);
                $pdo->prepare("
                    INSERT INTO mouvements
                        (compte_id, reference, type, montant, solde_avant, solde_apres,
                         date_valeur, date_operation, canal, utilisateur_id, commentaire)
                    VALUES (?, ?, 'remboursement', ?, 0, 0, ?, ?, 'mobile_money', ?, ?)
                ")->execute([
                    $compte_credits_id, $ref, $montant_saisi,
                    date('Y-m-d'), date('Y-m-d'), $user_id,
                    "Remboursement partiel crédit #{$credit['numero_credit']} via $op_label"
                ]);

                $pdo->commit();

                $success = "✅ Remboursement de " . number_format($montant_saisi, 0, ',', ' ') . " F effectué !<br>"
                         . "• Capital remboursé : " . number_format($capital_rembourse_total, 0, ',', ' ') . " F<br>"
                         . "• Intérêts remboursés : " . number_format($interet_rembourse_total, 0, ',', ' ') . " F";
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Erreur lors du remboursement : " . $e->getMessage();
        }
    }
}

// ── Rechargement des données après traitement ──
$credits->execute([$membre_id]);
$credits_list   = $credits->fetchAll(PDO::FETCH_ASSOC);
$credits_actifs = array_filter($credits_list, fn($c) => in_array($c['statut'], ['en_cours', 'en_retard']));
$credits_soldes = array_filter($credits_list, fn($c) => $c['statut'] === 'solde');

foreach ($credits_actifs as $credit) {
    $stmt = $pdo->prepare("SELECT * FROM echeanciers WHERE credit_id = ? ORDER BY numero_echeance ASC");
    $stmt->execute([$credit['id']]);
    $echeanciers[$credit['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Calculer totaux restants et prochaines échéances
foreach ($credits_actifs as $credit) {
    $echeances = $echeanciers[$credit['id']] ?? [];

    $restantes = array_values(array_filter($echeances,
        fn($e) => in_array($e['statut'], ['a_venir', 'paye_partiellement', 'en_retard'])
    ));

    $capital_restant   = array_sum(array_column($restantes, 'capital_du'));
    $interets_restants = array_sum(array_column($restantes, 'interet_du'));

    $totaux_restants[$credit['id']] = [
        'capital'  => $capital_restant,
        'interets' => $interets_restants,
        'total'    => $capital_restant + $interets_restants,
        'nb_echeances_restantes' => count($restantes),
    ];

    $prochaines_echeances[$credit['id']] = !empty($restantes) ? $restantes[0] : null;

    // CORRECTION #2 : est_anticipe basé sur la DERNIÈRE échéance restante
    $derniere = !empty($restantes) ? end($restantes) : null;
    $est_anticipes[$credit['id']] = $derniere
        ? (date('Y-m-d') < $derniere['date_echeance'])
        : false;
}

function fmt($n) {
    return number_format($n, 0, ',', ' ');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mes crédits — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
:root{--gold:#C9A84C;--gold-dark:#b8922e;--gold-bg:#F5EDD6;--dark:#1a1a1a;--bg:#F0EDE8;--text:#1a1a1a;--text-light:#888;--card:#fff;--border:#e8e3db;--green:#4CAF50;--red:#F44336;--orange:#FF9800}
.dark{--bg:#121212;--card:#1e1e1e;--text:#f0f0f0;--text-light:#777;--border:#2a2a2a}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;transition:background .3s,color .3s}
.header{background:linear-gradient(135deg,var(--dark),#2a2a2a);padding:20px 24px 65px;position:relative;overflow:hidden}
.header::after{content:'';position:absolute;bottom:-30px;left:0;right:0;height:60px;background:var(--bg);border-radius:30px 30px 0 0}
.header-top{display:flex;align-items:center;justify-content:space-between;position:relative;z-index:1}
.btn-back{display:flex;align-items:center;gap:6px;color:rgba(255,255,255,.85);text-decoration:none;font-size:14px;font-weight:600;background:rgba(0,0,0,.15);padding:8px 14px;border-radius:20px}
.btn-back:hover{background:rgba(0,0,0,.25);color:white}
.header-label{font-size:14px;font-weight:700;color:rgba(255,255,255,.8);text-transform:uppercase;letter-spacing:1px;margin-top:12px}
.t-btns{display:flex;gap:4px}
.t-btn{width:28px;height:28px;border-radius:50%;border:none;cursor:pointer;font-size:12px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center}
.t-btn.active{background:rgba(255,255,255,.4)}
.content{max-width:800px;margin:0 auto;padding:20px}
.alert{padding:14px 18px;border-radius:10px;font-size:14px;display:flex;align-items:flex-start;gap:10px;margin-bottom:20px}
.alert-success{background:#E8F5E9;color:#1B5E20;border-left:4px solid #4CAF50}
.alert-error{background:#FFEBEE;color:#B71C1C;border-left:4px solid #F44336}
.card{background:var(--card);border-radius:16px;border:1px solid var(--border);overflow:hidden;margin-bottom:20px}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.card-header-left{display:flex;align-items:center;gap:10px}
.card-header-icon{font-size:22px}
.card-header-title{font-size:16px;font-weight:700;color:var(--text)}
.card-header-badge{padding:4px 10px;border-radius:20px;font-size:11px;font-weight:600}
.badge-cours{background:#E8F5E9;color:#2E7D32}
.badge-retard{background:#FFEBEE;color:#C62828}
.card-body{padding:20px}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px}
.info-item{background:var(--bg);border-radius:10px;padding:12px}
.info-label{font-size:11px;color:var(--text-light);margin-bottom:4px}
.info-value{font-size:15px;font-weight:700;color:var(--text)}
.info-value.gold{color:var(--gold)}
.info-value.red{color:var(--red)}
.progression-bar{height:6px;background:var(--border);border-radius:3px;overflow:hidden;margin:12px 0}
.progression-fill{height:100%;border-radius:3px;background:var(--gold)}

/* BOUTONS REMBOURSEMENT */
.btn-row{display:flex;gap:10px;margin-top:14px}
.btn-rembourser-partiel{flex:1;padding:12px;background:var(--bg);color:var(--text);border:2px solid var(--gold);border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;transition:all .2s}
.btn-rembourser-partiel:hover{background:var(--gold-bg)}
.btn-rembourser-total{flex:1;padding:12px;background:var(--gold);color:white;border:none;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;transition:all .2s}
.btn-rembourser-total:hover{background:var(--gold-dark)}

.echeancier-table{width:100%;margin-top:12px;overflow-x:auto}
.echeancier-table table{width:100%;border-collapse:collapse}
.echeancier-table th{text-align:left;padding:10px 8px;font-size:11px;color:var(--text-light);border-bottom:1px solid var(--border)}
.echeancier-table td{padding:8px;font-size:12px;border-bottom:1px solid var(--border)}
.btn-detail{background:none;border:none;color:var(--gold);cursor:pointer;font-size:11px;margin-top:12px;width:100%}
.btn-detail:hover{text-decoration:underline}

/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:flex-end;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:var(--card);border-radius:20px 20px 0 0;padding:24px;width:100%;max-width:520px;animation:slideUp .3s ease;max-height:90vh;overflow-y:auto}
.modal-title{font-size:17px;font-weight:700;margin-bottom:4px;color:var(--text)}
.modal-subtitle{font-size:13px;color:var(--text-light);margin-bottom:18px}

/* TABS type remboursement */
.type-tabs{display:flex;background:var(--bg);border-radius:10px;padding:4px;gap:4px;margin-bottom:18px}
.type-tab{flex:1;padding:10px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;background:transparent;color:var(--text-light);font-family:'DM Sans',sans-serif;transition:all .2s;text-align:center}
.type-tab.active{background:var(--gold);color:white}

/* Résumé solde box */
.solde-box{background:linear-gradient(135deg,#1a1a2e,#16213e);border-radius:12px;padding:14px 16px;margin-bottom:16px}
.solde-row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.08);font-size:13px}
.solde-row:last-child{border-bottom:none;padding-top:10px;margin-top:4px}
.solde-label{color:rgba(255,255,255,.6)}
.solde-val{color:white;font-weight:700}
.solde-val.gold{color:var(--gold)}
.solde-val.red{color:#EF9A9A}

/* Avertissement pénalité */
.penalite-box{background:#FFF3E0;border-left:3px solid #FF9800;border-radius:0 8px 8px 0;padding:10px 14px;margin-bottom:14px;font-size:12.5px;color:#E65100;line-height:1.5}

.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:14px}
.form-group label{font-size:12px;font-weight:700;color:var(--text-light)}
.form-group input{padding:12px;border:1.5px solid var(--border);border-radius:10px;font-size:14px;background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif}
.form-group input:focus{border-color:var(--gold);outline:none}
.operateur-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:14px}
.operateur-btn{padding:10px 6px;border:2px solid var(--border);border-radius:10px;text-align:center;cursor:pointer;background:var(--bg);font-size:12px;font-weight:600;transition:all .2s}
.operateur-btn.active{border-color:var(--gold);background:var(--gold-bg)}
.btn-submit{width:100%;padding:12px;background:var(--gold);color:white;border:none;border-radius:10px;font-weight:600;cursor:pointer;font-size:14px;font-family:'DM Sans',sans-serif;transition:all .2s}
.btn-submit:hover{background:var(--gold-dark)}
.btn-submit:disabled{opacity:.4;cursor:not-allowed}
.btn-cancel{width:100%;padding:12px;background:transparent;border:1.5px solid var(--border);border-radius:10px;margin-top:8px;cursor:pointer;font-size:14px;font-family:'DM Sans',sans-serif;color:var(--text-light)}

.empty-state{text-align:center;padding:60px;background:var(--card);border-radius:16px}
.empty-icon{font-size:48px;margin-bottom:16px}
.empty-title{font-size:18px;font-weight:600;margin-bottom:8px}
.empty-text{color:var(--text-light);margin-bottom:20px}

@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
@keyframes slideUp{from{transform:translateY(100%)}to{transform:none}}
.fadeUp{animation:fadeUp .35s ease both}
</style>
</head>
<body>

<header class="header">
  <div class="header-top fadeUp">
    <a href="espace_membre.php" class="btn-back">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
      Retour
    </a>
    <div class="t-btns">
      <button class="t-btn active" onclick="setTheme('light',this)">☀</button>
      <button class="t-btn" onclick="setTheme('dark',this)">🌙</button>
    </div>
  </div>
  <div class="header-label">💰 Mes crédits</div>
</header>

<div class="content">

  <?php if ($success): ?>
  <div class="alert alert-success fadeUp">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    <div><?php echo $success; ?></div>
  </div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="alert alert-error fadeUp">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
    <div><?php echo $error; ?></div>
  </div>
  <?php endif; ?>

  <!-- CRÉDITS ACTIFS -->
  <?php if (!empty($credits_actifs)): ?>
    <?php foreach ($credits_actifs as $credit):
        $total       = $totaux_restants[$credit['id']] ?? ['capital' => 0, 'interets' => 0, 'total' => 0];
        $prochaine   = $prochaines_echeances[$credit['id']] ?? null;
        $est_anticipe = $est_anticipes[$credit['id']] ?? false;
        $penalite    = $est_anticipe ? round($total['capital'] * 0.01) : 0;
        $total_solde = $total['total'] + $penalite;
        $progression = $credit['montant_accorde'] > 0
            ? (($credit['montant_accorde'] - $credit['capital_restant_du']) / $credit['montant_accorde'] * 100)
            : 0;
    ?>
    <div class="card fadeUp">
      <div class="card-header">
        <div class="card-header-left">
          <span class="card-header-icon">
            <?php echo $credit['type_code'] === 'express' ? '⚡' : ($credit['type_code'] === 'ordinaire' ? '🏦' : ($credit['type_code'] === 'immobilier' ? '🏠' : '📋')); ?>
          </span>
          <span class="card-header-title"><?php echo htmlspecialchars($credit['type_libelle']); ?></span>
        </div>
        <span class="card-header-badge <?php echo $credit['statut'] === 'en_cours' ? 'badge-cours' : 'badge-retard'; ?>">
          <?php echo $credit['statut'] === 'en_cours' ? '✅ En cours' : '⚠️ En retard'; ?>
        </span>
      </div>

      <div class="card-body">
        <div class="info-grid">
          <div class="info-item">
            <div class="info-label">Numéro de crédit</div>
            <div class="info-value" style="font-size:13px"><?php echo htmlspecialchars($credit['numero_credit']); ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Mise en place</div>
            <div class="info-value" style="font-size:13px"><?php echo date('d/m/Y', strtotime($credit['date_mise_en_place'])); ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Montant accordé</div>
            <div class="info-value gold"><?php echo fmt($credit['montant_accorde']); ?> F</div>
          </div>
          <div class="info-item">
            <div class="info-label">Capital restant</div>
            <div class="info-value"><?php echo fmt($total['capital']); ?> F</div>
          </div>
          <div class="info-item">
            <div class="info-label">Intérêts restants</div>
            <div class="info-value red"><?php echo fmt($total['interets']); ?> F</div>
          </div>
          <div class="info-item">
            <div class="info-label">Taux annuel</div>
            <div class="info-value"><?php echo $credit['taux_annuel']; ?>%</div>
          </div>
          <div class="info-item">
            <div class="info-label">Échéances restantes</div>
            <div class="info-value"><?php echo $total['nb_echeances_restantes']; ?> mois</div>
          </div>
          <div class="info-item">
            <div class="info-label">Total restant dû</div>
            <div class="info-value gold"><?php echo fmt($total['total']); ?> F</div>
          </div>
        </div>

        <!-- Barre de progression -->
        <div class="progression-bar">
          <div class="progression-fill" style="width:<?php echo min(100, round($progression)); ?>%"></div>
        </div>
        <div style="font-size:11px;color:var(--text-light);text-align:right;margin-bottom:12px">
          <?php echo round($progression); ?>% remboursé
        </div>

        <!-- Prochaine échéance -->
        <?php if ($prochaine): ?>
        <div class="info-item" style="margin-bottom:12px">
          <div class="info-label">Prochaine échéance</div>
          <div class="info-value" style="font-size:13px">
            <?php echo date('d/m/Y', strtotime($prochaine['date_echeance'])); ?> —
            <strong><?php echo fmt($prochaine['capital_du'] + $prochaine['interet_du']); ?> F</strong>
            <span style="font-size:11px;color:var(--text-light)">
              (Capital: <?php echo fmt($prochaine['capital_du']); ?> F +
               Intérêts: <?php echo fmt($prochaine['interet_du']); ?> F)
            </span>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($est_anticipe): ?>
        <div style="background:#FFF3E0;border-radius:8px;padding:8px 12px;font-size:12px;color:#E65100;margin-bottom:12px">
          ⚠️ Remboursement anticipé : une pénalité de 1% sur le capital restant
          (<?php echo fmt($penalite); ?> F) sera appliquée en cas de solde total.
        </div>
        <?php endif; ?>

        <!-- CORRECTION #5 : deux boutons explicites -->
        <div class="btn-row">
          <button class="btn-rembourser-partiel" onclick="openModal(
            <?php echo $credit['id']; ?>,
            <?php echo $total['capital']; ?>,
            <?php echo $total['interets']; ?>,
            <?php echo $est_anticipe ? 'true' : 'false'; ?>,
            'partiel'
          )">
            📅 Payer des échéances
          </button>
          <button class="btn-rembourser-total" onclick="openModal(
            <?php echo $credit['id']; ?>,
            <?php echo $total['capital']; ?>,
            <?php echo $total['interets']; ?>,
            <?php echo $est_anticipe ? 'true' : 'false'; ?>,
            'total'
          )">
            ✅ Solder le crédit
          </button>
        </div>

        <button class="btn-detail" onclick="toggleEcheancier(<?php echo $credit['id']; ?>)">
          📋 Voir l'échéancier détaillé
        </button>

        <div id="echeancier-<?php echo $credit['id']; ?>" style="display:none;margin-top:16px">
          <div class="echeancier-table">
            <table>
              <thead>
                <tr>
                  <th>N°</th><th>Date</th><th>Capital</th>
                  <th>Intérêts</th><th>Mensualité</th><th>Statut</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($echeanciers[$credit['id']] as $e): ?>
                <tr style="opacity:<?php echo $e['statut'] === 'paye' ? '.5' : '1'; ?>">
                  <td>#<?php echo $e['numero_echeance']; ?></td>
                  <td><?php echo date('d/m/Y', strtotime($e['date_echeance'])); ?></td>
                  <td><?php echo fmt($e['capital_du']); ?> F</td>
                  <td><?php echo fmt($e['interet_du']); ?> F</td>
                  <td><strong><?php echo fmt($e['capital_du'] + $e['interet_du']); ?> F</strong></td>
                  <td>
                    <?php if ($e['statut'] === 'paye'): ?>✅ Payé
                    <?php elseif ($e['statut'] === 'paye_partiellement'): ?>⚠️ Partiel
                    <?php else: ?>⏳ À venir
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- CRÉDITS SOLDÉS -->
  <?php if (!empty($credits_soldes)): ?>
  <div class="card fadeUp">
    <div class="card-header">
      <div class="card-header-left">
        <span class="card-header-icon">✅</span>
        <span class="card-header-title">Crédits soldés</span>
      </div>
    </div>
    <div class="card-body">
      <?php foreach ($credits_soldes as $credit): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid var(--border)">
        <div>
          <div style="font-weight:600"><?php echo htmlspecialchars($credit['type_libelle']); ?></div>
          <div style="font-size:11px;color:var(--text-light)"><?php echo htmlspecialchars($credit['numero_credit']); ?></div>
        </div>
        <div style="text-align:right">
          <div style="font-weight:600"><?php echo fmt($credit['montant_accorde']); ?> F</div>
          <div style="font-size:11px;color:#2E7D32">✅ Soldé</div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ÉTAT VIDE -->
  <?php if (empty($credits_actifs) && empty($credits_soldes)): ?>
  <div class="empty-state fadeUp">
    <div class="empty-icon">💰</div>
    <div class="empty-title">Aucun crédit</div>
    <div class="empty-text">Vous n'avez pas encore de crédit en cours.</div>
    <a href="demande_credit.php" style="display:inline-block;text-decoration:none;padding:12px 24px;background:var(--gold);color:white;border-radius:10px;font-weight:600">
      Faire une demande
    </a>
  </div>
  <?php endif; ?>

</div>

<!-- ══════════════════════════════════════
     MODAL REMBOURSEMENT UNIFIÉ
     CORRECTION #5 : tabs partiel / total
═══════════════════════════════════════ -->
<div class="modal-overlay" id="modalRemboursement">
  <div class="modal">

    <div class="modal-title" id="modalTitle">💰 Remboursement</div>
    <div class="modal-subtitle" id="modalSubtitle"></div>

    <!-- CORRECTION #5 : tabs explicites -->
    <div class="type-tabs">
      <button class="type-tab" id="tabPartiel" onclick="switchTab('partiel')">
        📅 Payer des échéances
      </button>
      <button class="type-tab" id="tabTotal" onclick="switchTab('total')">
        ✅ Solder le crédit
      </button>
    </div>

    <!-- Résumé solde -->
    <div class="solde-box" id="soldeBox"></div>

    <!-- Avertissement pénalité (affiché si anticipé + total) -->
    <div class="penalite-box" id="penaliteBox" style="display:none"></div>

    <form method="POST" id="formRemboursement">
      <input type="hidden" name="action"               value="rembourser">
      <input type="hidden" name="credit_id"            id="remboursement_credit_id">
      <!-- CORRECTION #5 : champ type_remboursement explicite -->
      <input type="hidden" name="type_remboursement"   id="type_remboursement_hidden" value="partiel">
      <input type="hidden" name="operateur"            id="operateur_hidden" value="">

      <!-- Montant (masqué en mode "total" car calculé automatiquement) -->
      <div class="form-group" id="montantGroup">
        <label id="montantLabel">Montant à rembourser (F CFA) *</label>
        <input type="number" name="montant" id="remboursement_montant"
               step="100" placeholder="Ex: 50 000" required>
        <span style="font-size:11.5px;color:var(--text-light)" id="montantHint"></span>
      </div>

      <!-- Opérateur -->
      <div style="font-size:12px;font-weight:700;margin-bottom:8px;color:var(--text-light)">
        Opérateur Mobile Money *
      </div>
      <div class="operateur-grid">
        <div class="operateur-btn" onclick="selectOperateur('orange', this)">
          <div>🟠</div><div>Orange Money</div>
        </div>
        <div class="operateur-btn" onclick="selectOperateur('mtn', this)">
          <div>🟡</div><div>MTN MoMo</div>
        </div>
        <div class="operateur-btn" onclick="selectOperateur('wave', this)">
          <div>🔵</div><div>Wave</div>
        </div>
      </div>

      <div class="form-group">
        <label>Numéro de téléphone *</label>
        <input type="tel" name="telephone" id="telephone_input"
               placeholder="Ex: 07 XX XX XX XX" maxlength="14" required>
      </div>

      <button type="submit" class="btn-submit" id="btnSubmitModal">
        Confirmer le paiement
      </button>
      <button type="button" class="btn-cancel" onclick="closeModal()">Annuler</button>
    </form>
  </div>
</div>

<script>
// Variables globales du modal
let modalCapital   = 0;
let modalInterets  = 0;
let modalAnticipe  = false;
let modalTab       = 'partiel';

function openModal(creditId, capital, interets, anticipe, tab) {
  modalCapital  = capital;
  modalInterets = interets;
  modalAnticipe = anticipe;

  document.getElementById('remboursement_credit_id').value = creditId;
  document.getElementById('remboursement_montant').value   = '';
  document.getElementById('operateur_hidden').value        = '';
  document.getElementById('telephone_input').value         = '';
  document.querySelectorAll('.operateur-btn').forEach(b => b.classList.remove('active'));

  switchTab(tab);
  document.getElementById('modalRemboursement').classList.add('open');
}

function switchTab(tab) {
  modalTab = tab;
  document.getElementById('type_remboursement_hidden').value = tab;

  // Activer le bon tab
  document.getElementById('tabPartiel').classList.toggle('active', tab === 'partiel');
  document.getElementById('tabTotal').classList.toggle('active', tab === 'total');

  const penalite   = modalAnticipe ? Math.round(modalCapital * 0.01) : 0;
  const totalSolde = modalCapital + modalInterets + penalite;

  // Résumé solde
  let soldeHtml = `
    <div class="solde-row"><span class="solde-label">Capital restant</span><span class="solde-val">${fmt(modalCapital)} F</span></div>
    <div class="solde-row"><span class="solde-label">Intérêts restants</span><span class="solde-val red">${fmt(modalInterets)} F</span></div>
  `;
  if (tab === 'total') {
    if (penalite > 0) {
      soldeHtml += `<div class="solde-row"><span class="solde-label">Pénalité anticipation (1%)</span><span class="solde-val red">${fmt(penalite)} F</span></div>`;
    }
    soldeHtml += `<div class="solde-row"><span class="solde-label" style="color:white;font-weight:700">Total à payer</span><span class="solde-val gold">${fmt(totalSolde)} F</span></div>`;
  } else {
    soldeHtml += `<div class="solde-row"><span class="solde-label">Total restant dû</span><span class="solde-val gold">${fmt(modalCapital + modalInterets)} F</span></div>`;
  }
  document.getElementById('soldeBox').innerHTML = soldeHtml;

  // Mode TOTAL : montant automatique, champ masqué
  const montantGroup = document.getElementById('montantGroup');
  const montantInput = document.getElementById('remboursement_montant');

  if (tab === 'total') {
    montantGroup.style.display = 'none';
    montantInput.value         = totalSolde;
    montantInput.removeAttribute('required');

    // Avertissement pénalité
    const penBox = document.getElementById('penaliteBox');
    if (modalAnticipe && penalite > 0) {
      penBox.style.display = 'block';
      penBox.innerHTML = `⚠️ <strong>Remboursement anticipé détecté.</strong><br>
        Une pénalité de 1% sur le capital restant s'applique : <strong>${fmt(penalite)} F</strong>.<br>
        Montant total à payer : <strong>${fmt(totalSolde)} F</strong>.`;
    } else {
      penBox.style.display = 'none';
    }

    document.getElementById('modalTitle').textContent    = '✅ Solder le crédit';
    document.getElementById('modalSubtitle').textContent = 'Le crédit sera intégralement soldé.';

  } else {
    // Mode PARTIEL
    montantGroup.style.display = 'block';
    montantInput.value         = '';
    montantInput.setAttribute('required', 'required');
    montantInput.min           = 5000; // CORRECTION #3 : min 5 000 F
    montantInput.max           = modalCapital + modalInterets;

    document.getElementById('penaliteBox').style.display = 'none';
    document.getElementById('montantHint').textContent   =
      'Minimum : 5 000 F — Maximum : ' + fmt(modalCapital + modalInterets) + ' F';

    document.getElementById('modalTitle').textContent    = '📅 Payer des échéances';
    document.getElementById('modalSubtitle').textContent = 'Payez une ou plusieurs échéances sans solder le crédit.';
  }
}

function closeModal() {
  document.getElementById('modalRemboursement').classList.remove('open');
}

function selectOperateur(op, btn) {
  document.getElementById('operateur_hidden').value = op;
  document.querySelectorAll('.operateur-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
}

function toggleEcheancier(creditId) {
  const div = document.getElementById('echeancier-' + creditId);
  div.style.display = div.style.display === 'none' ? 'block' : 'none';
}

// Validation avant soumission
document.getElementById('formRemboursement').addEventListener('submit', function(e) {
  const operateur  = document.getElementById('operateur_hidden').value;
  const telephone  = document.getElementById('telephone_input').value.trim();
  const montant    = parseFloat(document.getElementById('remboursement_montant').value) || 0;
  const tab        = document.getElementById('type_remboursement_hidden').value;
  const penalite   = modalAnticipe ? Math.round(modalCapital * 0.01) : 0;
  const totalSolde = modalCapital + modalInterets + penalite;

  if (!operateur) {
    alert('Veuillez choisir un opérateur Mobile Money.'); e.preventDefault(); return;
  }
  if (!telephone) {
    alert('Veuillez saisir votre numéro de téléphone.'); e.preventDefault(); return;
  }

  if (tab === 'partiel') {
    // CORRECTION #3 : min 5 000 F
    if (montant < 5000) {
      alert('Le montant minimum de remboursement est de 5 000 F CFA.'); e.preventDefault(); return;
    }
    if (montant > modalCapital + modalInterets) {
      alert('Le montant dépasse le total restant dû (' + fmt(modalCapital + modalInterets) + ' F).');
      e.preventDefault(); return;
    }
  }

  // Confirmation pour solde total
  if (tab === 'total') {
    const msg = modalAnticipe
      ? `Confirmer le solde total du crédit ?\nMontant : ${fmt(totalSolde)} F (dont pénalité : ${fmt(penalite)} F)`
      : `Confirmer le solde total du crédit ?\nMontant : ${fmt(totalSolde)} F`;
    if (!confirm(msg)) { e.preventDefault(); return; }
  }
});

function fmt(n) {
  return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}

function setTheme(theme, btn) {
  document.querySelectorAll('.t-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.body.classList.toggle('dark', theme === 'dark');
  localStorage.setItem('ma2e_theme', theme);
}

const saved = localStorage.getItem('ma2e_theme');
if (saved === 'dark') {
  document.body.classList.add('dark');
  document.querySelectorAll('.t-btn').forEach((b, i) => b.classList.toggle('active', i === 1));
}

// Fermer modal en cliquant dehors
document.getElementById('modalRemboursement').addEventListener('click', function(e) {
  if (e.target === this) closeModal();
});
</script>
</body>
</html>
