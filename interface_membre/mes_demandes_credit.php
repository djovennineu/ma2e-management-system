<?php
require_once '../connexion/auth_check.php';
checkRole('membre');
require_once '../config/database.php';

$membre_id = (int)($_SESSION['membre_id'] ?? 0);
$stmt = $pdo->prepare("
    SELECT dc.id, dc.montant_demande, dc.duree_demandee_mois, dc.objet,
           dc.statut, dc.date_demande, dc.date_traitement, dc.motif_refus,
           tc.libelle AS type_libelle, c.id AS credit_id
    FROM demandes_credit dc
    JOIN types_credit tc ON tc.id = dc.type_credit_id
    LEFT JOIN credits c ON c.demande_id = dc.id
    WHERE dc.membre_id = ?
    ORDER BY dc.date_demande DESC, dc.id DESC
");
$stmt->execute([$membre_id]);
$demandes = $stmt->fetchAll(PDO::FETCH_ASSOC);
function h_demandes($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function statut_demandes($value): string {
    $labels=['en_attente'=>'En attente','en_etude'=>'En étude','approuve'=>'Approuvée','refuse'=>'Refusée'];
    return $labels[$value] ?? ucfirst(str_replace('_',' ',(string)$value));
}
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mes demandes de crédit — MA2E</title>
<style>*{box-sizing:border-box}body{margin:0;background:#f0ede8;color:#292929;font:15px Arial,sans-serif}.wrap{max-width:1100px;margin:auto;padding:28px 18px}.head{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap}.head h1{margin:0}.btn{display:inline-block;border:1px solid #ddd;border-radius:9px;padding:10px 14px;background:white;color:#333;text-decoration:none}.primary{background:#c9a84c;border-color:#c9a84c;color:white}.card{background:white;border:1px solid #e8e3db;border-radius:14px;padding:18px;margin-top:18px}.muted{color:#777}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:700px}th,td{text-align:left;padding:12px 10px;border-bottom:1px solid #eee;vertical-align:top}th{font-size:12px;color:#777;text-transform:uppercase}.badge{display:inline-block;padding:5px 9px;border-radius:20px;background:#eee;font-size:12px}.pending{background:#fff5d9;color:#80641f}.approved{background:#e5f5e9;color:#276738}.rejected{background:#ffeded;color:#9b2e2e}.empty{text-align:center;padding:30px}.actions{display:flex;gap:8px;flex-wrap:wrap}@media(max-width:650px){.wrap{padding:18px 12px}}</style></head><body><main class="wrap">
<header class="head"><div><h1>Mes demandes de crédit</h1><p class="muted">Suivez l’état de vos demandes soumises à la MA2E.</p></div><nav class="actions"><a class="btn" href="espace_membre.php">← Mon espace</a><a class="btn primary" href="demande_credit.php">Nouvelle demande</a></nav></header>
<section class="card"><?php if(!$demandes): ?><div class="empty"><h2>Aucune demande de crédit</h2><p class="muted">Vos demandes apparaîtront ici après leur soumission.</p><a class="btn primary" href="demande_credit.php">Faire une demande</a></div><?php else: ?><div class="table-wrap"><table><thead><tr><th>Date</th><th>Produit</th><th>Montant demandé</th><th>Durée</th><th>Statut</th><th>Information</th></tr></thead><tbody><?php foreach($demandes as $d): $class=in_array($d['statut'],['en_attente','en_etude'],true)?'pending':($d['statut']==='approuve'?'approved':($d['statut']==='refuse'?'rejected':'')); ?><tr><td><?= h_demandes(date('d/m/Y',strtotime($d['date_demande']))) ?></td><td><?= h_demandes($d['type_libelle']) ?><br><small class="muted">Demande #<?= (int)$d['id'] ?></small></td><td><?= number_format((float)$d['montant_demande'],0,',',' ') ?> F CFA</td><td><?= (int)$d['duree_demandee_mois'] ?> mois</td><td><span class="badge <?= $class ?>"><?= h_demandes(statut_demandes($d['statut'])) ?></span></td><td><?php if($d['statut']==='refuse'&&!empty($d['motif_refus'])): ?><?= nl2br(h_demandes($d['motif_refus'])) ?><?php elseif($d['credit_id']): ?><a href="mes_credits.php">Consulter le crédit</a><?php elseif($d['date_traitement']): ?>Traitée le <?= h_demandes(date('d/m/Y',strtotime($d['date_traitement']))) ?><?php else: ?><span class="muted">En cours de traitement</span><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
</main></body></html>
