<?php
// =============================================================================
// RAPPORT_MOUVEMENTS.PHP — Interface Comptable MA2E
// Rapport de tous les mouvements avec filtres + export CSV + export PDF
// =============================================================================

require_once '../connexion/auth_check.php';
checkRole('comptable');
require_once '../config/database.php';

// ── Paramètres de filtrage ──
$date_debut  = $_GET['date_debut']  ?? date('Y-m-01');         // 1er du mois courant
$date_fin    = $_GET['date_fin']    ?? date('Y-m-d');           // Aujourd'hui
$type_mouv   = $_GET['type']        ?? '';                      // Tous types
$canal       = $_GET['canal']       ?? '';                      // Tous canaux
$membre_id   = (int)($_GET['membre_id'] ?? 0);
$type_compte = $_GET['type_compte'] ?? '';
$export      = $_GET['export']      ?? '';                      // 'csv' ou 'pdf'

// ── Types de mouvements ──
$types_labels = [
    'depot'               => 'Dépôt',
    'retrait'             => 'Retrait',
    'interet'             => 'Intérêts',
    'prelevement_salaire' => 'Prélèvement salaire',
    'credit_octroi'       => 'Octroi crédit',
    'remboursement'       => 'Remboursement crédit',
    'penalite'            => 'Pénalité',
    'frais_dossier'       => 'Frais de dossier',
    'fonds_garantie'      => 'Fonds de garantie',
    'deblocage_garantie'  => 'Déblocage garantie',
];

$canaux_labels = [
    'especes'       => 'Espèces',
    'virement'      => 'Virement',
    'cheque'        => 'Chèque',
    'mobile_money'  => 'Mobile Money',
    'salaire'       => 'Prélèvement salaire',
    'systeme'       => 'Système',
];

// ── Construction de la requête avec filtres ──
$where  = ["DATE(mo.date_operation) BETWEEN :date_debut AND :date_fin"];
$params = [':date_debut' => $date_debut, ':date_fin' => $date_fin];

if (!empty($type_mouv)) {
    $where[]           = "mo.type = :type";
    $params[':type']   = $type_mouv;
}
if (!empty($canal)) {
    $where[]           = "mo.canal = :canal";
    $params[':canal']  = $canal;
}
if ($membre_id > 0) {
    $where[]                = "ce.membre_id = :membre_id";
    $params[':membre_id']   = $membre_id;
}
if (!empty($type_compte)) {
    $where[]                    = "tce.code = :type_compte";
    $params[':type_compte']     = $type_compte;
}

$where_sql = "WHERE " . implode(" AND ", $where);

$sql = "
    SELECT
        mo.id,
        mo.reference,
        mo.type,
        mo.montant,
        mo.solde_avant,
        mo.solde_apres,
        mo.canal,
        mo.commentaire,
        mo.date_operation,
        mo.date_valeur,
        ce.numero_compte,
        tce.libelle  AS type_compte_libelle,
        tce.code     AS type_compte_code,
        m.nom        AS membre_nom,
        m.prenoms    AS membre_prenoms,
        m.numero_membre,
        u.nom        AS operateur_nom,
        u.prenoms    AS operateur_prenoms
    FROM mouvements mo
    JOIN comptes_epargne ce      ON mo.compte_id       = ce.id
    JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
    JOIN membres m               ON ce.membre_id       = m.id
    LEFT JOIN utilisateurs u     ON mo.utilisateur_id  = u.id
    $where_sql
    ORDER BY mo.date_operation DESC, mo.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$mouvements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Statistiques globales ──
$total_depots       = 0;
$total_retraits     = 0;
$total_interets     = 0;
$total_credits      = 0;
$total_remboursements = 0;
$total_frais        = 0;

foreach ($mouvements as $mv) {
    switch ($mv['type']) {
        case 'depot':
        case 'prelevement_salaire':
            $total_depots += $mv['montant']; break;
        case 'retrait':
            $total_retraits += $mv['montant']; break;
        case 'interet':
        case 'deblocage_garantie':
            $total_interets += $mv['montant']; break;
        case 'credit_octroi':
            $total_credits += $mv['montant']; break;
        case 'remboursement':
            $total_remboursements += $mv['montant']; break;
        case 'penalite':
        case 'frais_dossier':
        case 'fonds_garantie':
            $total_frais += $mv['montant']; break;
    }
}

// ── Liste des membres pour le filtre ──
$membres_list = $pdo->query("
    SELECT id, nom, prenoms, numero_membre
    FROM membres WHERE statut = 'actif'
    ORDER BY nom, prenoms
")->fetchAll(PDO::FETCH_ASSOC);

// ── Types de comptes pour le filtre ──
$types_comptes = $pdo->query("
    SELECT code, libelle FROM types_compte_epargne ORDER BY libelle
")->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// EXPORT CSV
// ============================================================
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mouvements_' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8

    fputcsv($out, [
        'ID', 'Référence', 'Date opération', 'Date valeur',
        'Membre', 'N° Membre', 'Compte', 'Type compte',
        'Type mouvement', 'Canal', 'Montant (F CFA)',
        'Solde avant', 'Solde après', 'Opérateur', 'Commentaire'
    ], ';');

    foreach ($mouvements as $mv) {
        fputcsv($out, [
            $mv['id'],
            $mv['reference'],
            date('d/m/Y H:i', strtotime($mv['date_operation'])),
            $mv['date_valeur'] ? date('d/m/Y', strtotime($mv['date_valeur'])) : '',
            $mv['membre_prenoms'] . ' ' . $mv['membre_nom'],
            $mv['numero_membre'],
            $mv['numero_compte'],
            $mv['type_compte_libelle'],
            $types_labels[$mv['type']] ?? $mv['type'],
            $canaux_labels[$mv['canal']] ?? $mv['canal'],
            number_format($mv['montant'], 0, ',', ' '),
            number_format($mv['solde_avant'], 0, ',', ' '),
            number_format($mv['solde_apres'], 0, ',', ' '),
            trim(($mv['operateur_prenoms'] ?? '') . ' ' . ($mv['operateur_nom'] ?? '')),
            $mv['commentaire'] ?? '',
        ], ';');
    }
    fclose($out);
    exit;
}

// ============================================================
// EXPORT PDF (HTML → PDF via ob_start + conversion navigateur)
// On génère une page HTML optimisée print que le navigateur
// peut imprimer/sauvegarder en PDF
// ============================================================
if ($export === 'pdf') {
    // On génère une page HTML print-friendly
    $periode = date('d/m/Y', strtotime($date_debut)) . ' au ' . date('d/m/Y', strtotime($date_fin));
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Rapport Mouvements — MA2E — <?php echo $periode; ?></title>
<style>
  @page { size: A4 landscape; margin: 15mm 10mm; }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: Arial, sans-serif; font-size: 9px; color: #222; }

  .header-pdf { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px; padding-bottom:10px; border-bottom:2px solid #C9A84C; }
  .header-pdf h1 { font-size:20px; color:#C9A84C; font-family:Georgia,serif; letter-spacing:2px; }
  .header-pdf h2 { font-size:13px; color:#333; margin-top:4px; }
  .header-pdf .meta { font-size:9px; color:#666; text-align:right; line-height:1.8; }

  .stats-grid { display:grid; grid-template-columns:repeat(6,1fr); gap:6px; margin-bottom:14px; }
  .stat-box { background:#f8f8f8; border:1px solid #ddd; border-radius:5px; padding:7px 8px; text-align:center; }
  .stat-box .val { font-size:11px; font-weight:700; color:#1a1a1a; }
  .stat-box .lbl { font-size:7.5px; color:#888; margin-top:2px; }
  .stat-box.gold .val { color:#C9A84C; }
  .stat-box.green .val { color:#2E7D32; }
  .stat-box.red .val { color:#C62828; }

  table { width:100%; border-collapse:collapse; font-size:8px; }
  thead tr { background:#1a1a2e; color:white; }
  thead th { padding:6px 5px; text-align:left; font-weight:600; font-size:7.5px; }
  tbody tr:nth-child(even) { background:#f9f9f9; }
  tbody tr:hover { background:#f0ede8; }
  tbody td { padding:5px; border-bottom:1px solid #eee; vertical-align:top; }
  .badge { display:inline-block; padding:2px 6px; border-radius:10px; font-size:7px; font-weight:700; }
  .b-depot     { background:#E8F5E9; color:#2E7D32; }
  .b-retrait   { background:#FFEBEE; color:#C62828; }
  .b-interet   { background:#E3F2FD; color:#1565C0; }
  .b-credit    { background:#FFF8E1; color:#F57F17; }
  .b-remb      { background:#F3E5F5; color:#6A1B9A; }
  .b-frais     { background:#FBE9E7; color:#BF360C; }
  .b-autre     { background:#ECEFF1; color:#455A64; }
  .montant-pos { color:#2E7D32; font-weight:700; }
  .montant-neg { color:#C62828; font-weight:700; }
  .footer-pdf  { margin-top:14px; padding-top:8px; border-top:1px solid #ddd; font-size:7.5px; color:#aaa; display:flex; justify-content:space-between; }
  .total-row   { background:#1a1a2e !important; color:white; font-weight:700; }
  .total-row td { color:white; border:none; padding:7px 5px; }

  @media print {
    .no-print { display:none; }
    body { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  }
</style>
</head>
<body>

<div class="no-print" style="background:#1a1a2e;padding:12px 20px;display:flex;align-items:center;gap:12px;margin-bottom:0">
  <span style="color:#C9A84C;font-family:Arial;font-weight:700;font-size:15px">MA2E</span>
  <button onclick="window.print()" style="background:#C9A84C;color:white;border:none;padding:8px 18px;border-radius:7px;font-weight:700;cursor:pointer;font-size:13px">🖨️ Imprimer / Enregistrer PDF</button>
  <button onclick="window.close()" style="background:#555;color:white;border:none;padding:8px 14px;border-radius:7px;cursor:pointer;font-size:13px">✕ Fermer</button>
</div>

<div style="padding:10px 14px">

  <!-- EN-TÊTE -->
  <div class="header-pdf">
    <div>
      <h1>MA2E</h1>
      <h2>Rapport des mouvements financiers</h2>
      <div style="font-size:9px;color:#666;margin-top:4px">Période : <?php echo $periode; ?></div>
    </div>
    <div class="meta">
      Généré le <?php echo date('d/m/Y à H:i'); ?><br>
      Par : <?php echo htmlspecialchars(($_SESSION['prenoms'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')); ?><br>
      Rôle : Comptable<br>
      Total lignes : <?php echo count($mouvements); ?>
    </div>
  </div>

  <!-- STATS -->
  <div class="stats-grid">
    <div class="stat-box green">
      <div class="val"><?php echo number_format($total_depots, 0, ',', ' '); ?> F</div>
      <div class="lbl">Dépôts & Prélèvements</div>
    </div>
    <div class="stat-box red">
      <div class="val"><?php echo number_format($total_retraits, 0, ',', ' '); ?> F</div>
      <div class="lbl">Retraits</div>
    </div>
    <div class="stat-box">
      <div class="val"><?php echo number_format($total_interets, 0, ',', ' '); ?> F</div>
      <div class="lbl">Intérêts versés</div>
    </div>
    <div class="stat-box gold">
      <div class="val"><?php echo number_format($total_credits, 0, ',', ' '); ?> F</div>
      <div class="lbl">Crédits octroyés</div>
    </div>
    <div class="stat-box">
      <div class="val"><?php echo number_format($total_remboursements, 0, ',', ' '); ?> F</div>
      <div class="lbl">Remboursements</div>
    </div>
    <div class="stat-box red">
      <div class="val"><?php echo number_format($total_frais, 0, ',', ' '); ?> F</div>
      <div class="lbl">Frais & Pénalités</div>
    </div>
  </div>

  <!-- TABLEAU -->
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Référence</th>
        <th>Membre</th>
        <th>Compte</th>
        <th>Type mvt</th>
        <th>Canal</th>
        <th style="text-align:right">Montant (F)</th>
        <th style="text-align:right">Solde avant</th>
        <th style="text-align:right">Solde après</th>
        <th>Commentaire</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $types_credit_side = ['depot','prelevement_salaire','interet','credit_octroi','deblocage_garantie'];
      $total_general = 0;
      foreach ($mouvements as $mv):
          $label = $types_labels[$mv['type']] ?? $mv['type'];
          $is_credit = in_array($mv['type'], $types_credit_side);
          $badge_class = match($mv['type']) {
              'depot','prelevement_salaire'  => 'b-depot',
              'retrait'                     => 'b-retrait',
              'interet','deblocage_garantie' => 'b-interet',
              'credit_octroi'               => 'b-credit',
              'remboursement'               => 'b-remb',
              'penalite','frais_dossier','fonds_garantie' => 'b-frais',
              default                       => 'b-autre',
          };
          $total_general += $is_credit ? $mv['montant'] : -$mv['montant'];
      ?>
      <tr>
        <td><?php echo date('d/m/Y', strtotime($mv['date_operation'])); ?></td>
        <td style="font-family:monospace;font-size:7px"><?php echo htmlspecialchars($mv['reference']); ?></td>
        <td>
          <strong><?php echo htmlspecialchars($mv['membre_nom'] . ' ' . $mv['membre_prenoms']); ?></strong><br>
          <span style="color:#888"><?php echo htmlspecialchars($mv['numero_membre']); ?></span>
        </td>
        <td>
          <?php echo htmlspecialchars($mv['numero_compte']); ?><br>
          <span style="color:#888"><?php echo htmlspecialchars($mv['type_compte_libelle']); ?></span>
        </td>
        <td><span class="badge <?php echo $badge_class; ?>"><?php echo $label; ?></span></td>
        <td><?php echo htmlspecialchars($canaux_labels[$mv['canal']] ?? $mv['canal']); ?></td>
        <td style="text-align:right" class="<?php echo $is_credit ? 'montant-pos' : 'montant-neg'; ?>">
          <?php echo ($is_credit ? '+' : '−') . number_format($mv['montant'], 0, ',', ' '); ?>
        </td>
        <td style="text-align:right"><?php echo number_format($mv['solde_avant'], 0, ',', ' '); ?></td>
        <td style="text-align:right"><?php echo number_format($mv['solde_apres'], 0, ',', ' '); ?></td>
        <td style="color:#555;max-width:120px"><?php echo htmlspecialchars($mv['commentaire'] ?? ''); ?></td>
      </tr>
      <?php endforeach; ?>

      <!-- Ligne total -->
      <tr class="total-row">
        <td colspan="6" style="text-align:right">TOTAL NET</td>
        <td style="text-align:right;color:<?php echo $total_general >= 0 ? '#81C784' : '#EF9A9A'; ?>">
          <?php echo ($total_general >= 0 ? '+' : '') . number_format($total_general, 0, ',', ' '); ?> F
        </td>
        <td colspan="3"></td>
      </tr>
    </tbody>
  </table>

  <div class="footer-pdf">
    <span>MA2E — Rapport confidentiel — Usage interne uniquement</span>
    <span>© <?php echo date('Y'); ?> Mutuelle des Agents de l'Eau et de l'Électricité</span>
  </div>

</div>
</body>
</html>
    <?php
    exit;
}

// ============================================================
// AFFICHAGE PRINCIPAL
// ============================================================
function fmt($n) { return number_format($n, 0, ',', ' '); }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rapport Mouvements — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
:root{--gold:#C9A84C;--gold-bg:#F5EDD6;--dark:#1a1a2e;--bg:#F0EDE8;--text:#1e1e2e;--text-light:#888;--card:#fff;--border:#e8e3db;--green:#4CAF50;--red:#F44336}
.dark{--bg:#181818;--card:#242424;--text:#f0f0f0;--text-light:#888;--border:#333}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex}

/* SIDEBAR */
.sidebar{width:210px;background:var(--dark);display:flex;flex-direction:column;padding:24px 0;flex-shrink:0;position:sticky;top:0;height:100vh;z-index:100}
.logo{display:flex;align-items:center;gap:8px;padding:0 18px 24px;border-bottom:1px solid rgba(255,255,255,.08)}
.logo-text{font-family:'Playfair Display',serif;font-size:20px;color:white;letter-spacing:1px}
.nav{flex:1;padding:16px 10px;display:flex;flex-direction:column;gap:3px}
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:9px;color:#777;font-size:13px;font-weight:500;text-decoration:none;transition:all .2s}
.nav-item:hover{background:rgba(255,255,255,.05);color:#ccc}
.nav-item.active{background:var(--gold);color:white}
.nav-item svg{width:15px;height:15px;flex-shrink:0}
.sidebar-footer{padding:14px 10px;border-top:1px solid rgba(255,255,255,.08)}
.user-card{display:flex;align-items:center;gap:9px;padding:8px}
.avatar{width:32px;height:32px;border-radius:50%;background:var(--gold);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:white;flex-shrink:0}
.user-name{font-size:12px;font-weight:600;color:white}
.user-role{font-size:10px;color:#e8b84b}
.theme-toggle{display:flex;background:#2a2a2a;border-radius:20px;padding:3px;margin-top:10px;gap:2px}
.theme-btn{flex:1;padding:5px 0;border:none;background:transparent;color:#777;font-size:11px;font-weight:500;border-radius:16px;cursor:pointer;transition:all .2s;font-family:'DM Sans',sans-serif}
.theme-btn.active{background:var(--gold);color:white}

/* MAIN */
.main{flex:1;padding:28px;overflow-x:hidden;display:flex;flex-direction:column;gap:20px}
.page-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.page-header h1{font-family:'Playfair Display',serif;font-size:24px;color:var(--text)}
.page-header p{font-size:13px;color:var(--text-light);margin-top:2px}
.export-btns{display:flex;gap:8px}
.btn-export{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:none;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;text-decoration:none;transition:all .2s}
.btn-csv{background:#1B5E20;color:white}
.btn-csv:hover{background:#2E7D32}
.btn-pdf{background:#B71C1C;color:white}
.btn-pdf:hover{background:#C62828}

/* STATS */
.stats-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:14px}
.stat-card{background:var(--card);border-radius:12px;border:1px solid var(--border);padding:16px;text-align:center}
.stat-val{font-size:17px;font-weight:800;margin-bottom:4px}
.stat-lbl{font-size:11px;color:var(--text-light)}
.stat-val.green{color:#2E7D32}
.stat-val.red{color:#C62828}
.stat-val.gold{color:var(--gold)}
.stat-val.blue{color:#1565C0}
.stat-val.purple{color:#6A1B9A}

/* FILTRES */
.filtres-card{background:var(--card);border-radius:14px;border:1px solid var(--border);padding:18px 22px}
.filtres-title{font-size:13px;font-weight:700;color:var(--text);margin-bottom:14px;display:flex;align-items:center;gap:7px}
.filtres-grid{display:grid;grid-template-columns:repeat(3,1fr) repeat(2,1fr) auto;gap:12px;align-items:end}
.form-group{display:flex;flex-direction:column;gap:4px}
.form-label{font-size:11px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.4px}
.form-control{padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;color:var(--text);background:var(--bg);font-family:'DM Sans',sans-serif;outline:none;transition:border-color .2s}
.form-control:focus{border-color:var(--gold)}
.btn-filtrer{padding:10px 18px;background:var(--gold);color:white;border:none;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;white-space:nowrap}
.btn-filtrer:hover{background:#b8922e}
.btn-reset{padding:10px 12px;background:transparent;color:var(--text-light);border:1.5px solid var(--border);border-radius:9px;font-size:13px;cursor:pointer;font-family:'DM Sans',sans-serif;text-decoration:none;display:inline-flex;align-items:center}
.btn-reset:hover{border-color:var(--red);color:var(--red)}

/* TABLEAU */
.table-card{background:var(--card);border-radius:14px;border:1px solid var(--border);overflow:hidden}
.table-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.table-title{font-size:14px;font-weight:700;color:var(--text)}
.table-count{font-size:12px;color:var(--text-light)}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead tr{background:var(--dark)}
thead th{padding:11px 12px;text-align:left;font-size:11px;font-weight:700;color:rgba(255,255,255,.75);white-space:nowrap}
tbody tr{border-bottom:1px solid var(--border);transition:background .15s}
tbody tr:hover{background:var(--bg)}
tbody td{padding:10px 12px;font-size:12.5px;vertical-align:middle}
.badge{display:inline-flex;align-items:center;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;white-space:nowrap}
.b-depot    {background:#E8F5E9;color:#2E7D32}
.b-retrait  {background:#FFEBEE;color:#C62828}
.b-interet  {background:#E3F2FD;color:#1565C0}
.b-credit   {background:#FFF8E1;color:#F57F17}
.b-remb     {background:#F3E5F5;color:#6A1B9A}
.b-frais    {background:#FBE9E7;color:#BF360C}
.b-autre    {background:#ECEFF1;color:#455A64}
.montant-pos{color:#2E7D32;font-weight:700}
.montant-neg{color:#C62828;font-weight:700}
.total-row{background:var(--dark) !important}
.total-row td{color:white;font-weight:700;padding:12px}
.empty-state{text-align:center;padding:50px;color:var(--text-light)}
.empty-state .icon{font-size:40px;margin-bottom:12px}

/* Pagination */
.pagination{display:flex;align-items:center;justify-content:center;gap:6px;padding:16px}
.page-btn{padding:7px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;cursor:pointer;background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;transition:all .2s}
.page-btn:hover,.page-btn.active{background:var(--gold);color:white;border-color:var(--gold)}

@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
.fadeUp{animation:fadeUp .35s ease both}

/* Topbar mobile */
.topbar{display:none;align-items:center;justify-content:space-between;padding:14px 20px;background:var(--card);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99}
.overlay.open{display:block}

@media(max-width:1200px){.stats-grid{grid-template-columns:repeat(3,1fr)}.filtres-grid{grid-template-columns:1fr 1fr 1fr}}
@media(max-width:900px){.filtres-grid{grid-template-columns:1fr 1fr}}
@media(max-width:768px){
  body{flex-direction:column}
  .topbar{display:flex}
  .sidebar{position:fixed;top:0;left:0;height:100vh;transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .main{padding:16px}
  .stats-grid{grid-template-columns:repeat(2,1fr)}
  .filtres-grid{grid-template-columns:1fr}
  .export-btns{flex-wrap:wrap}
}
</style>
</head>
<body>

<!-- TOPBAR MOBILE -->
<div class="topbar">
  <span style="font-family:'Playfair Display',serif;font-size:17px;font-weight:600">MA2E</span>
  <button style="background:none;border:none;cursor:pointer" onclick="toggleSidebar()">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
  </button>
</div>
<div class="overlay" id="overlay" onclick="toggleSidebar()"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="logo"><span class="logo-text">MA2E</span></div>
  <nav class="nav">
    <a class="nav-item" href="dashboard_comptable.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Dashboard
    </a>
    <a class="nav-item" href="prelevement_salaire.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>Prélèvements
    </a>
    <a class="nav-item" href="saisir_versement.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>Versements
    </a>
    <a class="nav-item" href="valider_retraits.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>Retraits
    </a>
    <a class="nav-item" href="remboursements.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>Remboursements
    </a>
    <a class="nav-item active" href="rapport_mouvements.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>Rapports
    </a>
    <a class="nav-item" href="../connexion/logout.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>Déconnexion
    </a>
  </nav>
  <div class="sidebar-footer">
    <div class="user-card">
      <div class="avatar"><?php echo strtoupper(substr($_SESSION['nom'] ?? 'C', 0, 1)); ?></div>
      <div>
        <div class="user-name"><?php echo htmlspecialchars(($_SESSION['prenoms'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')); ?></div>
        <div class="user-role">Comptable</div>
      </div>
    </div>
    <div class="theme-toggle">
      <button class="theme-btn active" onclick="setTheme('light',this)">☀ Light</button>
      <button class="theme-btn" onclick="setTheme('dark',this)">🌙 Dark</button>
    </div>
  </div>
</aside>

<!-- MAIN -->
<main class="main">

  <!-- EN-TÊTE -->
  <div class="page-header fadeUp">
    <div>
      <h1>📊 Rapport des mouvements</h1>
      <p>Période : <?php echo date('d/m/Y', strtotime($date_debut)); ?> → <?php echo date('d/m/Y', strtotime($date_fin)); ?> — <?php echo count($mouvements); ?> mouvement(s)</p>
    </div>
    <div class="export-btns">
      <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>"
         class="btn-export btn-csv">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
        Export CSV
      </a>
      <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'pdf'])); ?>"
         target="_blank" class="btn-export btn-pdf">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        Export PDF
      </a>
    </div>
  </div>

  <!-- STATISTIQUES -->
  <div class="stats-grid fadeUp">
    <div class="stat-card">
      <div class="stat-val green"><?php echo fmt($total_depots); ?> F</div>
      <div class="stat-lbl">Dépôts & Prélèvements</div>
    </div>
    <div class="stat-card">
      <div class="stat-val red"><?php echo fmt($total_retraits); ?> F</div>
      <div class="stat-lbl">Retraits</div>
    </div>
    <div class="stat-card">
      <div class="stat-val blue"><?php echo fmt($total_interets); ?> F</div>
      <div class="stat-lbl">Intérêts versés</div>
    </div>
    <div class="stat-card">
      <div class="stat-val gold"><?php echo fmt($total_credits); ?> F</div>
      <div class="stat-lbl">Crédits octroyés</div>
    </div>
    <div class="stat-card">
      <div class="stat-val purple"><?php echo fmt($total_remboursements); ?> F</div>
      <div class="stat-lbl">Remboursements</div>
    </div>
    <div class="stat-card">
      <div class="stat-val red"><?php echo fmt($total_frais); ?> F</div>
      <div class="stat-lbl">Frais & Pénalités</div>
    </div>
  </div>

  <!-- FILTRES -->
  <div class="filtres-card fadeUp">
    <div class="filtres-title">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
      Filtres
    </div>
    <form method="GET">
      <div class="filtres-grid">
        <div class="form-group">
          <label class="form-label">Date début</label>
          <input type="date" name="date_debut" class="form-control" value="<?php echo $date_debut; ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Date fin</label>
          <input type="date" name="date_fin" class="form-control" value="<?php echo $date_fin; ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Type de mouvement</label>
          <select name="type" class="form-control">
            <option value="">Tous les types</option>
            <?php foreach ($types_labels as $val => $lbl): ?>
            <option value="<?php echo $val; ?>" <?php echo $type_mouv === $val ? 'selected' : ''; ?>>
              <?php echo $lbl; ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Canal</label>
          <select name="canal" class="form-control">
            <option value="">Tous les canaux</option>
            <?php foreach ($canaux_labels as $val => $lbl): ?>
            <option value="<?php echo $val; ?>" <?php echo $canal === $val ? 'selected' : ''; ?>>
              <?php echo $lbl; ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Type de compte</label>
          <select name="type_compte" class="form-control">
            <option value="">Tous les comptes</option>
            <?php foreach ($types_comptes as $tc): ?>
            <option value="<?php echo $tc['code']; ?>" <?php echo $type_compte === $tc['code'] ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($tc['libelle']); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Membre</label>
          <select name="membre_id" class="form-control">
            <option value="">Tous les membres</option>
            <?php foreach ($membres_list as $mb): ?>
            <option value="<?php echo $mb['id']; ?>" <?php echo $membre_id === $mb['id'] ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($mb['nom'] . ' ' . $mb['prenoms']); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="display:flex;gap:8px;align-items:flex-end">
          <button type="submit" class="btn-filtrer">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            Filtrer
          </button>
          <a href="rapport_mouvements.php" class="btn-reset" title="Réinitialiser">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.85"/></svg>
          </a>
        </div>
      </div>
    </form>
  </div>

  <!-- TABLEAU -->
  <div class="table-card fadeUp">
    <div class="table-header">
      <span class="table-title">Liste des mouvements</span>
      <span class="table-count"><?php echo count($mouvements); ?> résultat(s)</span>
    </div>

    <?php if (empty($mouvements)): ?>
    <div class="empty-state">
      <div class="icon">📭</div>
      <div>Aucun mouvement trouvé pour les filtres sélectionnés.</div>
    </div>
    <?php else: ?>
    <div class="table-wrap">
      <table id="tableMovements">
        <thead>
          <tr>
            <th>Date</th>
            <th>Référence</th>
            <th>Membre</th>
            <th>Compte</th>
            <th>Type</th>
            <th>Canal</th>
            <th style="text-align:right">Montant</th>
            <th style="text-align:right">Solde avant</th>
            <th style="text-align:right">Solde après</th>
            <th>Commentaire</th>
          </tr>
        </thead>
        <tbody id="tableBody">
          <?php
          $types_credit_side = ['depot','prelevement_salaire','interet','credit_octroi','deblocage_garantie'];
          $total_net = 0;
          foreach ($mouvements as $mv):
              $label = $types_labels[$mv['type']] ?? $mv['type'];
              $is_credit = in_array($mv['type'], $types_credit_side);
              $badge_class = match($mv['type']) {
                  'depot','prelevement_salaire'           => 'b-depot',
                  'retrait'                               => 'b-retrait',
                  'interet','deblocage_garantie'          => 'b-interet',
                  'credit_octroi'                         => 'b-credit',
                  'remboursement'                         => 'b-remb',
                  'penalite','frais_dossier','fonds_garantie' => 'b-frais',
                  default                                 => 'b-autre',
              };
              $total_net += $is_credit ? $mv['montant'] : -$mv['montant'];
          ?>
          <tr>
            <td style="white-space:nowrap">
              <?php echo date('d/m/Y', strtotime($mv['date_operation'])); ?><br>
              <span style="font-size:11px;color:var(--text-light)"><?php echo date('H:i', strtotime($mv['date_operation'])); ?></span>
            </td>
            <td style="font-family:monospace;font-size:11px;color:var(--text-light)">
              <?php echo htmlspecialchars($mv['reference']); ?>
            </td>
            <td>
              <div style="font-weight:600"><?php echo htmlspecialchars($mv['membre_nom'] . ' ' . $mv['membre_prenoms']); ?></div>
              <div style="font-size:11px;color:var(--text-light)"><?php echo htmlspecialchars($mv['numero_membre']); ?></div>
            </td>
            <td>
              <div style="font-size:11.5px"><?php echo htmlspecialchars($mv['numero_compte']); ?></div>
              <div style="font-size:11px;color:var(--text-light)"><?php echo htmlspecialchars($mv['type_compte_libelle']); ?></div>
            </td>
            <td><span class="badge <?php echo $badge_class; ?>"><?php echo $label; ?></span></td>
            <td style="font-size:12px"><?php echo htmlspecialchars($canaux_labels[$mv['canal']] ?? $mv['canal']); ?></td>
            <td style="text-align:right;white-space:nowrap" class="<?php echo $is_credit ? 'montant-pos' : 'montant-neg'; ?>">
              <?php echo ($is_credit ? '+' : '−') . fmt($mv['montant']); ?> F
            </td>
            <td style="text-align:right;white-space:nowrap;font-size:12px"><?php echo fmt($mv['solde_avant']); ?> F</td>
            <td style="text-align:right;white-space:nowrap;font-size:12px"><?php echo fmt($mv['solde_apres']); ?> F</td>
            <td style="font-size:11.5px;color:var(--text-light);max-width:180px">
              <?php echo htmlspecialchars($mv['commentaire'] ?? ''); ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="total-row">
            <td colspan="6" style="text-align:right;font-size:13px">TOTAL NET</td>
            <td style="text-align:right;font-size:14px;color:<?php echo $total_net >= 0 ? '#81C784' : '#EF9A9A'; ?>">
              <?php echo ($total_net >= 0 ? '+' : '') . fmt($total_net); ?> F
            </td>
            <td colspan="3"></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endif; ?>
  </div>

</main>

<script>
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('open');
}
function setTheme(theme, btn) {
  document.querySelectorAll('.theme-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.body.classList.toggle('dark', theme === 'dark');
  localStorage.setItem('ma2e_theme', theme);
}
const saved = localStorage.getItem('ma2e_theme');
if (saved === 'dark') {
  document.body.classList.add('dark');
  document.querySelectorAll('.theme-btn').forEach((b,i) => b.classList.toggle('active', i===1));
}
</script>
</body>
</html>