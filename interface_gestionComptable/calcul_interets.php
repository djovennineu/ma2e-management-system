<?php
require_once '../connexion/auth_check.php';
checkRole('comptable');
require_once '../config/database.php';

$periode = $_GET['periode'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periode)) {
    $periode = date('Y-m');
}

$resume = $pdo->prepare("
    SELECT COUNT(*) AS nombre, COALESCE(SUM(mo.montant), 0) AS total
    FROM mouvements mo
    WHERE mo.type = 'interet' AND DATE_FORMAT(mo.date_operation, '%Y-%m') = ?
");
$resume->execute([$periode]);
$resume = $resume->fetch(PDO::FETCH_ASSOC);

$par_type = $pdo->prepare("
    SELECT tce.libelle, COUNT(mo.id) AS nombre, COALESCE(SUM(mo.montant), 0) AS total
    FROM mouvements mo
    JOIN comptes_epargne ce ON ce.id = mo.compte_id
    JOIN types_compte_epargne tce ON tce.id = ce.type_compte_id
    WHERE mo.type = 'interet' AND DATE_FORMAT(mo.date_operation, '%Y-%m') = ?
    GROUP BY tce.id, tce.libelle
    ORDER BY tce.libelle
");
$par_type->execute([$periode]);
$par_type = $par_type->fetchAll(PDO::FETCH_ASSOC);

$operations = $pdo->prepare("
    SELECT mo.reference, mo.montant, mo.date_operation, mo.commentaire,
           ce.numero_compte, tce.libelle AS compte_libelle,
           m.nom, m.prenoms, m.numero_membre
    FROM mouvements mo
    JOIN comptes_epargne ce ON ce.id = mo.compte_id
    JOIN types_compte_epargne tce ON tce.id = ce.type_compte_id
    JOIN membres m ON m.id = ce.membre_id
    WHERE mo.type = 'interet' AND DATE_FORMAT(mo.date_operation, '%Y-%m') = ?
    ORDER BY mo.date_operation DESC, mo.id DESC
    LIMIT 200
");
$operations->execute([$periode]);
$operations = $operations->fetchAll(PDO::FETCH_ASSOC);

function h_interets($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function f_interets($value): string { return number_format((float)$value, 0, ',', ' '); }
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Intérêts crédités — MA2E</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f0ede8;color:#292929;font:15px Arial,sans-serif}.wrap{max-width:1180px;margin:auto;padding:28px 18px}.top{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}.top h1{margin:0;font-size:26px}.links{display:flex;gap:10px;flex-wrap:wrap}.btn{display:inline-block;padding:10px 14px;border-radius:9px;text-decoration:none;color:#333;background:#fff;border:1px solid #ddd}.btn.primary{background:#c9a84c;color:white;border-color:#c9a84c}.card{background:#fff;border:1px solid #e8e3db;border-radius:14px;padding:18px;margin-top:18px}.summary{display:flex;gap:16px;flex-wrap:wrap}.metric{flex:1;min-width:220px}.metric small{display:block;color:#777}.metric strong{display:block;font-size:25px;margin-top:8px}.filter{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.filter label{display:grid;gap:6px;color:#666;font-size:13px}.filter input{padding:10px;border:1px solid #ddd;border-radius:8px;font:inherit}table{width:100%;border-collapse:collapse;margin-top:10px}th,td{text-align:left;padding:11px 9px;border-bottom:1px solid #eee;vertical-align:top}th{font-size:12px;color:#777;text-transform:uppercase}td.amount{text-align:right;white-space:nowrap;font-weight:bold}.muted{color:#777}.notice{line-height:1.55}.table-wrap{overflow:auto}@media(max-width:650px){.wrap{padding:18px 12px}th,td{padding:9px 6px;font-size:13px}}
</style></head>
<body><main class="wrap">
<header class="top"><div><h1>Intérêts crédités</h1><p class="muted">Consultation des opérations d’intérêt enregistrées dans les mouvements.</p></div><nav class="links"><a class="btn" href="dashboard_comptable.php">← Tableau de bord</a><a class="btn" href="rapport_mouvements.php?type=interet">Rapport des mouvements</a></nav></header>
<section class="card"><form class="filter" method="get"><label>Période<input type="month" name="periode" value="<?= h_interets($periode) ?>"></label><button class="btn primary" type="submit">Afficher</button></form></section>
<section class="summary"><article class="card metric"><small>Intérêts crédités en <?= h_interets($periode) ?></small><strong><?= f_interets($resume['total'] ?? 0) ?> F CFA</strong></article><article class="card metric"><small>Nombre d’opérations</small><strong><?= (int)($resume['nombre'] ?? 0) ?></strong></article></section>
<section class="card"><h2>Répartition par compte</h2><?php if (!$par_type): ?><p class="muted">Aucun intérêt n’a été enregistré pour cette période.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Type de compte</th><th>Opérations</th><th>Total crédité</th></tr></thead><tbody><?php foreach ($par_type as $ligne): ?><tr><td><?= h_interets($ligne['libelle']) ?></td><td><?= (int)$ligne['nombre'] ?></td><td class="amount"><?= f_interets($ligne['total']) ?> F</td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<section class="card"><h2>Détail des opérations</h2><?php if (!$operations): ?><p class="muted">Aucune opération à afficher.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Date</th><th>Membre</th><th>Compte</th><th>Référence</th><th>Montant</th><th>Commentaire</th></tr></thead><tbody><?php foreach ($operations as $op): ?><tr><td><?= h_interets(date('d/m/Y', strtotime($op['date_operation']))) ?></td><td><?= h_interets(trim($op['prenoms'].' '.$op['nom'])) ?><br><span class="muted"><?= h_interets($op['numero_membre']) ?></span></td><td><?= h_interets($op['compte_libelle']) ?><br><span class="muted"><?= h_interets($op['numero_compte']) ?></span></td><td><?= h_interets($op['reference']) ?></td><td class="amount"><?= f_interets($op['montant']) ?> F</td><td><?= h_interets($op['commentaire']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<p class="muted notice">Cette page consulte les intérêts déjà crédités. Le traitement automatique se lance par le script <code>cron/interets_mensuels.php</code>, qui est réservé à l’exécution en ligne de commande.</p>
</main></body></html>
