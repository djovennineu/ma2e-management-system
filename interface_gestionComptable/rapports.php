<?php
require_once '../connexion/auth_check.php';
checkRole('comptable');
require_once '../config/database.php';

$annee = (int)($_GET['annee'] ?? date('Y'));
if ($annee < 2000 || $annee > 2100) $annee = (int)date('Y');
$stmt = $pdo->prepare("
    SELECT DATE_FORMAT(date_operation, '%Y-%m') AS mois,
      COALESCE(SUM(CASE WHEN type IN ('depot','prelevement_salaire') THEN montant ELSE 0 END),0) AS entrees,
      COALESCE(SUM(CASE WHEN type = 'retrait' THEN montant ELSE 0 END),0) AS retraits,
      COALESCE(SUM(CASE WHEN type = 'interet' THEN montant ELSE 0 END),0) AS interets,
      COALESCE(SUM(CASE WHEN type = 'credit_octroi' THEN montant ELSE 0 END),0) AS octrois,
      COALESCE(SUM(CASE WHEN type = 'remboursement' THEN montant ELSE 0 END),0) AS remboursements,
      COUNT(*) AS operations
    FROM mouvements
    WHERE date_operation >= ? AND date_operation < ?
    GROUP BY DATE_FORMAT(date_operation, '%Y-%m')
    ORDER BY mois DESC
");
$stmt->execute([$annee . '-01-01', ($annee + 1) . '-01-01']);
$mois = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totaux = ['entrees'=>0,'retraits'=>0,'interets'=>0,'octrois'=>0,'remboursements'=>0,'operations'=>0];
foreach ($mois as $ligne) foreach ($totaux as $key=>$_) $totaux[$key] += (float)$ligne[$key];
$mois_fr = [1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre'];
function h_rapports($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function f_rapports($value): string { return number_format((float)$value, 0, ',', ' '); }
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Rapports financiers — MA2E</title>
<style>*{box-sizing:border-box}body{margin:0;background:#f0ede8;color:#292929;font:15px Arial,sans-serif}.wrap{max-width:1200px;margin:auto;padding:28px 18px}.head{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap}.head h1{margin:0}.btn{display:inline-block;border:1px solid #ddd;border-radius:8px;padding:10px 13px;background:white;color:#333;text-decoration:none;font:inherit;cursor:pointer}.primary{background:#c9a84c;color:white;border-color:#c9a84c}.card{background:white;border:1px solid #e8e3db;border-radius:14px;padding:18px;margin-top:18px}.filter{display:flex;gap:10px;align-items:end}.filter label{display:grid;gap:6px;color:#666}.filter input{padding:10px;border:1px solid #ddd;border-radius:8px;font:inherit}.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}.stat{background:#fff;border:1px solid #e8e3db;border-radius:12px;padding:16px}.stat small{color:#777}.stat strong{display:block;margin-top:8px;font-size:21px}.muted{color:#777}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:780px}th,td{text-align:right;padding:11px 9px;border-bottom:1px solid #eee;white-space:nowrap}th:first-child,td:first-child{text-align:left}th{font-size:12px;text-transform:uppercase;color:#777}@media(max-width:650px){.wrap{padding:18px 12px}}</style></head><body><main class="wrap">
<header class="head"><div><h1>Rapports financiers</h1><p class="muted">Synthèse mensuelle des mouvements de l’année <?= $annee ?>.</p></div><a class="btn" href="dashboard_comptable.php">← Tableau de bord</a></header>
<section class="card"><form method="get" class="filter"><label>Année<input type="number" name="annee" min="2000" max="2100" value="<?= $annee ?>"></label><button class="btn primary">Afficher</button></form></section>
<section class="stats"><article class="stat"><small>Dépôts et prélèvements</small><strong><?= f_rapports($totaux['entrees']) ?> F</strong></article><article class="stat"><small>Retraits</small><strong><?= f_rapports($totaux['retraits']) ?> F</strong></article><article class="stat"><small>Intérêts crédités</small><strong><?= f_rapports($totaux['interets']) ?> F</strong></article><article class="stat"><small>Octrois de crédit</small><strong><?= f_rapports($totaux['octrois']) ?> F</strong></article><article class="stat"><small>Remboursements de crédit</small><strong><?= f_rapports($totaux['remboursements']) ?> F</strong></article><article class="stat"><small>Nombre d’opérations</small><strong><?= (int)$totaux['operations'] ?></strong></article></section>
<section class="card"><h2>Détail mensuel</h2><?php if(!$mois): ?><p class="muted">Aucune opération trouvée pour cette année.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Mois</th><th>Entrées</th><th>Retraits</th><th>Intérêts</th><th>Octrois</th><th>Remboursements</th><th>Opérations</th><th>Rapport</th></tr></thead><tbody><?php foreach($mois as $ligne): $n=(int)substr($ligne['mois'],5,2); ?><tr><td><?= h_rapports(($mois_fr[$n]??$ligne['mois']).' '.$annee) ?></td><td><?= f_rapports($ligne['entrees']) ?> F</td><td><?= f_rapports($ligne['retraits']) ?> F</td><td><?= f_rapports($ligne['interets']) ?> F</td><td><?= f_rapports($ligne['octrois']) ?> F</td><td><?= f_rapports($ligne['remboursements']) ?> F</td><td><?= (int)$ligne['operations'] ?></td><td><a href="rapport_mouvements.php?date_debut=<?= h_rapports($ligne['mois']) ?>-01&amp;date_fin=<?= h_rapports(date('Y-m-t',strtotime($ligne['mois'].'-01'))) ?>">Voir</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
</main></body></html>
