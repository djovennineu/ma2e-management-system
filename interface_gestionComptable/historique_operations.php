<?php
require_once '../connexion/auth_check.php';
checkRole('comptable');
require_once '../config/database.php';

$date_debut = $_GET['date_debut'] ?? date('Y-m-01');
$date_fin = $_GET['date_fin'] ?? date('Y-m-d');
$type = trim($_GET['type'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_debut) || !strtotime($date_debut)) $date_debut = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_fin) || !strtotime($date_fin)) $date_fin = date('Y-m-d');
$types = ['depot'=>'Dépôt','retrait'=>'Retrait','interet'=>'Intérêt','prelevement_salaire'=>'Prélèvement salaire','credit_octroi'=>'Octroi crédit','remboursement'=>'Remboursement','penalite'=>'Pénalité','frais_dossier'=>'Frais de dossier','fonds_garantie'=>'Fonds de garantie','deblocage_garantie'=>'Déblocage garantie'];
$where = ['DATE(mo.date_operation) BETWEEN ? AND ?'];
$params = [$date_debut, $date_fin];
if ($type !== '' && isset($types[$type])) { $where[] = 'mo.type = ?'; $params[] = $type; }
$stmt = $pdo->prepare("
    SELECT mo.id, mo.reference, mo.type, mo.montant, mo.solde_avant, mo.solde_apres,
           mo.date_operation, mo.canal, mo.commentaire,
           ce.numero_compte, tce.libelle AS compte_libelle,
           m.nom, m.prenoms, m.numero_membre
    FROM mouvements mo
    JOIN comptes_epargne ce ON ce.id = mo.compte_id
    JOIN types_compte_epargne tce ON tce.id = ce.type_compte_id
    JOIN membres m ON m.id = ce.membre_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY mo.date_operation DESC, mo.id DESC LIMIT 500
");
$stmt->execute($params);
$operations = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total = array_sum(array_map(static fn($row) => (float)$row['montant'], $operations));
function h_historique($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Historique des opérations — MA2E</title>
<style>*{box-sizing:border-box}body{margin:0;background:#f0ede8;color:#292929;font:15px Arial,sans-serif}.wrap{max-width:1250px;margin:auto;padding:28px 18px}.head{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap}.head h1{margin:0}.btn{border:1px solid #ddd;border-radius:8px;padding:10px 13px;background:white;color:#333;text-decoration:none;font:inherit;cursor:pointer}.primary{background:#c9a84c;color:white;border-color:#c9a84c}.card{background:#fff;border:1px solid #e8e3db;border-radius:14px;padding:18px;margin-top:18px}.filters{display:flex;align-items:end;gap:10px;flex-wrap:wrap}.filters label{display:grid;gap:6px;color:#666;font-size:13px}.filters input,.filters select{padding:10px;border:1px solid #ddd;border-radius:8px;font:inherit}.muted{color:#777}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:850px}th,td{text-align:left;padding:11px 9px;border-bottom:1px solid #eee}th{font-size:12px;text-transform:uppercase;color:#777}.right{text-align:right;white-space:nowrap}.pill{display:inline-block;padding:4px 8px;border-radius:20px;background:#f5edd6;color:#80641f;font-size:12px}@media(max-width:650px){.wrap{padding:18px 12px}}</style></head><body><main class="wrap">
<header class="head"><div><h1>Historique des opérations</h1><p class="muted">Opérations enregistrées sur les comptes d’épargne.</p></div><a class="btn" href="dashboard_comptable.php">← Tableau de bord</a></header>
<section class="card"><form class="filters" method="get"><label>Du<input type="date" name="date_debut" value="<?= h_historique($date_debut) ?>"></label><label>Au<input type="date" name="date_fin" value="<?= h_historique($date_fin) ?>"></label><label>Type<select name="type"><option value="">Tous</option><?php foreach($types as $key=>$label): ?><option value="<?= h_historique($key) ?>" <?= $type===$key?'selected':'' ?>><?= h_historique($label) ?></option><?php endforeach; ?></select></label><button class="btn primary" type="submit">Filtrer</button><a class="btn" href="historique_operations.php">Réinitialiser</a></form></section>
<section class="card"><p><strong><?= count($operations) ?></strong> opération(s) · Total des montants affichés : <strong><?= number_format($total,0,',',' ') ?> F CFA</strong><?php if(count($operations)===500): ?> <span class="muted">(limite d’affichage atteinte)</span><?php endif; ?></p><?php if(!$operations): ?><p class="muted">Aucune opération pour cette période.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Date</th><th>Membre</th><th>Compte</th><th>Type</th><th>Référence</th><th>Canal</th><th class="right">Montant</th><th class="right">Solde après</th></tr></thead><tbody><?php foreach($operations as $op): ?><tr><td><?= h_historique(date('d/m/Y H:i',strtotime($op['date_operation']))) ?></td><td><?= h_historique(trim($op['prenoms'].' '.$op['nom'])) ?><br><small class="muted"><?= h_historique($op['numero_membre']) ?></small></td><td><?= h_historique($op['compte_libelle']) ?><br><small class="muted"><?= h_historique($op['numero_compte']) ?></small></td><td><span class="pill"><?= h_historique($types[$op['type']]??$op['type']) ?></span></td><td><?= h_historique($op['reference']) ?></td><td><?= h_historique($op['canal']) ?></td><td class="right"><?= number_format((float)$op['montant'],0,',',' ') ?> F</td><td class="right"><?= number_format((float)$op['solde_apres'],0,',',' ') ?> F</td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<p><a class="btn" href="rapport_mouvements.php?date_debut=<?= urlencode($date_debut) ?>&amp;date_fin=<?= urlencode($date_fin) ?>">Ouvrir le rapport détaillé / export</a></p>
</main></body></html>
