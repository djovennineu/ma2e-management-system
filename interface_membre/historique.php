<?php
require_once '../connexion/auth_check.php';
checkRole('membre');
require_once '../config/database.php';

$user_id   = $_SESSION['user_id'];
$membre_id = $_SESSION['membre_id'];

// Récupérer le membre
$membre = $pdo->prepare("
    SELECT m.*, s.nom AS societe_nom
    FROM membres m
    JOIN societes s ON m.societe_id = s.id
    WHERE m.id = ?
");
$membre->execute([$membre_id]);
$membre = $membre->fetch(PDO::FETCH_ASSOC);

// Récupérer les comptes du membre pour le filtre
$comptes = $pdo->prepare("
    SELECT ce.id, ce.numero_compte, tce.libelle as type_libelle, tce.code as type_code
    FROM comptes_epargne ce
    JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
    WHERE ce.membre_id = ? AND ce.statut != 'cloture'
    ORDER BY ce.date_ouverture ASC
");
$comptes->execute([$membre_id]);
$comptes_list = $comptes->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// TRAITEMENT DES FILTRES
// ============================================================
$date_debut = $_GET['date_debut'] ?? date('Y-m-01');
$date_fin = $_GET['date_fin'] ?? date('Y-m-d');
$type_mouvement = $_GET['type_mouvement'] ?? '';
$compte_id = $_GET['compte_id'] ?? '';
$search = $_GET['search'] ?? '';

// Construction de la requête avec filtres
$sql = "
    SELECT mo.*, tce.libelle AS compte_libelle, tce.code AS type_code,
           ce.numero_compte
    FROM mouvements mo
    JOIN comptes_epargne ce ON mo.compte_id = ce.id
    JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
    WHERE ce.membre_id = ?
      AND mo.date_operation BETWEEN ? AND ?
";

$params = [$membre_id, $date_debut, $date_fin];

if (!empty($type_mouvement)) {
    $sql .= " AND mo.type = ?";
    $params[] = $type_mouvement;
}

if (!empty($compte_id)) {
    $sql .= " AND mo.compte_id = ?";
    $params[] = $compte_id;
}

if (!empty($search)) {
    $sql .= " AND (mo.reference LIKE ? OR mo.commentaire LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY mo.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$mouvements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// STATISTIQUES POUR LE RÉSUMÉ
// ============================================================
$total_depots = 0;
$total_retraits = 0;
$total_interets = 0;
$total_remboursements = 0;

foreach ($mouvements as $mv) {
    if (in_array($mv['type'], ['depot', 'depot_mobile', 'prelevement_salaire'])) {
        $total_depots += $mv['montant'];
    } elseif ($mv['type'] === 'retrait') {
        $total_retraits += $mv['montant'];
    } elseif ($mv['type'] === 'interet') {
        $total_interets += $mv['montant'];
    } elseif ($mv['type'] === 'remboursement') {
        $total_remboursements += $mv['montant'];
    }
}

$solde_actuel = 0;
$stmt_solde = $pdo->prepare("
    SELECT SUM(solde) as total FROM comptes_epargne WHERE membre_id = ?
");
$stmt_solde->execute([$membre_id]);
$solde_actuel = $stmt_solde->fetchColumn() ?? 0;

// ============================================================
// LABELS ET ICÔNES PAR TYPE
// ============================================================
$type_labels = [
    'depot'               => 'Dépôt',
    'retrait'             => 'Retrait',
    'interet'             => 'Intérêts',
    'penalite'            => 'Pénalité',
    'remboursement'       => 'Remboursement crédit',
    'prelevement_salaire' => 'Prélèvement salaire',
    'garantie'            => 'Dépôt garantie',
    'deblocage_garantie'  => 'Déblocage garantie',
    'depot_mobile'        => 'Dépôt mobile money',
];

$type_icons = [
    'depot'               => '💵',
    'retrait'             => '💸',
    'interet'             => '📈',
    'penalite'            => '⚠️',
    'remboursement'       => '✅',
    'prelevement_salaire' => '🏢',
    'garantie'            => '🔒',
    'deblocage_garantie'  => '🔓',
    'depot_mobile'        => '📱',
];

$types_credit = ['depot', 'interet', 'remboursement', 'deblocage_garantie', 'prelevement_salaire', 'depot_mobile'];

// Récupérer les types de mouvements distincts pour le filtre
$types_disponibles = $pdo->query("
    SELECT DISTINCT type FROM mouvements ORDER BY type
")->fetchAll(PDO::FETCH_COLUMN);

// Fonction de formatage
function fmt($n) {
    return number_format($n, 0, ',', ' ');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Historique des mouvements — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
:root{--gold:#C9A84C;--gold-dark:#b8922e;--gold-bg:#F5EDD6;--dark:#1a1a1a;--bg:#F0EDE8;--text:#1a1a1a;--text-light:#888;--card:#fff;--border:#e8e3db;--green:#4CAF50;--red:#F44336;--blue:#2196F3}
.dark{--bg:#121212;--card:#1e1e1e;--text:#f0f0f0;--text-light:#777;--border:#2a2a2a}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;transition:background .3s,color .3s}

/* HEADER */
.header{background:linear-gradient(135deg,var(--dark),#2a2a2a);padding:20px 24px 65px;position:relative;overflow:hidden}
.header::after{content:'';position:absolute;bottom:-30px;left:0;right:0;height:60px;background:var(--bg);border-radius:30px 30px 0 0}
.header-top{display:flex;align-items:center;justify-content:space-between;position:relative;z-index:1}
.btn-back{display:flex;align-items:center;gap:6px;color:rgba(255,255,255,.85);text-decoration:none;font-size:14px;font-weight:600;background:rgba(0,0,0,.15);padding:8px 14px;border-radius:20px}
.btn-back:hover{background:rgba(0,0,0,.25);color:white}
.header-label{font-size:14px;font-weight:700;color:rgba(255,255,255,.8);text-transform:uppercase;letter-spacing:1px;margin-top:12px}
.t-btns{display:flex;gap:4px}
.t-btn{width:28px;height:28px;border-radius:50%;border:none;cursor:pointer;font-size:12px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center}
.t-btn.active{background:rgba(255,255,255,.4)}

/* CONTENT */
.content{max-width:1000px;margin:0 auto;padding:20px}

/* STATS CARDS */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}
.stat-card{background:var(--card);border-radius:16px;padding:16px;border:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.stat-left .stat-value{font-size:24px;font-weight:800;color:var(--text);line-height:1}
.stat-left .stat-label{font-size:11px;color:var(--text-light);margin-top:4px}
.stat-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:24px}
.stat-icon.green{background:#E8F5E9}
.stat-icon.red{background:#FFEBEE}
.stat-icon.blue{background:#E3F2FD}
.stat-icon.gold{background:var(--gold-bg)}

/* FILTRES */
.filter-card{background:var(--card);border-radius:16px;border:1px solid var(--border);padding:20px;margin-bottom:24px}
.filter-title{font-size:14px;font-weight:700;margin-bottom:16px;color:var(--text);display:flex;align-items:center;gap:8px}
.filter-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px}
.filter-group{display:flex;flex-direction:column;gap:6px}
.filter-group label{font-size:11px;font-weight:700;color:var(--text-light);text-transform:uppercase}
.filter-group input,.filter-group select{padding:10px 12px;border:1.5px solid var(--border);border-radius:10px;font-size:13px;background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif}
.filter-group input:focus,.filter-group select:focus{border-color:var(--gold);outline:none}
.filter-actions{display:flex;gap:12px;margin-top:16px}
.btn-filter{padding:10px 20px;background:var(--gold);color:white;border:none;border-radius:10px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif}
.btn-filter:hover{background:var(--gold-dark)}
.btn-reset{padding:10px 20px;background:transparent;border:1.5px solid var(--border);border-radius:10px;cursor:pointer;font-family:'DM Sans',sans-serif;color:var(--text)}
.btn-reset:hover{border-color:var(--gold);color:var(--gold)}

/* TABLEAU */
.table-card{background:var(--card);border-radius:16px;border:1px solid var(--border);overflow:hidden}
.table-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.table-title{font-size:14px;font-weight:700;color:var(--text)}
.table-count{font-size:12px;color:var(--text-light);background:var(--bg);padding:4px 10px;border-radius:20px}
.table-responsive{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th{text-align:left;padding:14px 16px;font-size:12px;font-weight:600;color:var(--text-light);background:var(--bg);border-bottom:1px solid var(--border)}
td{padding:14px 16px;font-size:13px;border-bottom:1px solid var(--border)}
.mvt-type{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:20px;font-size:12px;font-weight:500}
.mvt-plus{color:#2E7D32;background:#E8F5E9}
.mvt-minus{color:#C62828;background:#FFEBEE}
.mvt-ref{font-family:monospace;font-size:11px;color:var(--text-light)}
.empty-state{text-align:center;padding:60px;color:var(--text-light)}
.empty-icon{font-size:48px;margin-bottom:16px}

/* EXPORT */
.export-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:var(--bg);border:1px solid var(--border);border-radius:8px;font-size:12px;cursor:pointer;color:var(--text)}
.export-btn:hover{border-color:var(--gold);color:var(--gold)}

@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.fadeUp{animation:fadeUp .35s ease both}

@media(max-width:768px){
  .stats-grid{grid-template-columns:repeat(2,1fr)}
  .filter-grid{grid-template-columns:1fr}
  th,td{padding:10px 12px}
}
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
  <div class="header-label">📊 Historique des mouvements</div>
</header>

<div class="content">

  <!-- STATISTIQUES -->
  <div class="stats-grid fadeUp">
    <div class="stat-card">
      <div class="stat-left">
        <div class="stat-value"><?php echo fmt($total_depots); ?> F</div>
        <div class="stat-label">Total dépôts</div>
      </div>
      <div class="stat-icon green">📥</div>
    </div>
    <div class="stat-card">
      <div class="stat-left">
        <div class="stat-value"><?php echo fmt($total_retraits); ?> F</div>
        <div class="stat-label">Total retraits</div>
      </div>
      <div class="stat-icon red">📤</div>
    </div>
    <div class="stat-card">
      <div class="stat-left">
        <div class="stat-value"><?php echo fmt($total_interets); ?> F</div>
        <div class="stat-label">Intérêts perçus</div>
      </div>
      <div class="stat-icon gold">📈</div>
    </div>
    <div class="stat-card">
      <div class="stat-left">
        <div class="stat-value"><?php echo fmt($solde_actuel); ?> F</div>
        <div class="stat-label">Solde actuel</div>
      </div>
      <div class="stat-icon blue">💰</div>
    </div>
  </div>

  <!-- FILTRES -->
  <div class="filter-card fadeUp">
    <div class="filter-title">🔍 Filtrer les mouvements</div>
    <form method="GET" id="filterForm">
      <div class="filter-grid">
        <div class="filter-group">
          <label>Date de début</label>
          <input type="date" name="date_debut" value="<?php echo htmlspecialchars($date_debut); ?>">
        </div>
        <div class="filter-group">
          <label>Date de fin</label>
          <input type="date" name="date_fin" value="<?php echo htmlspecialchars($date_fin); ?>">
        </div>
        <div class="filter-group">
          <label>Type d'opération</label>
          <select name="type_mouvement">
            <option value="">Tous les types</option>
            <?php foreach ($types_disponibles as $type): ?>
            <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $type_mouvement === $type ? 'selected' : ''; ?>>
              <?php echo $type_labels[$type] ?? ucfirst($type); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-group">
          <label>Compte</label>
          <select name="compte_id">
            <option value="">Tous les comptes</option>
            <?php foreach ($comptes_list as $cpt): ?>
            <option value="<?php echo $cpt['id']; ?>" <?php echo $compte_id == $cpt['id'] ? 'selected' : ''; ?>>
              <?php echo $cpt['type_libelle']; ?> (<?php echo $cpt['numero_compte']; ?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-group">
          <label>Recherche</label>
          <input type="text" name="search" placeholder="Référence, commentaire..." value="<?php echo htmlspecialchars($search); ?>">
        </div>
      </div>
      <div class="filter-actions">
        <button type="submit" class="btn-filter">🔍 Appliquer les filtres</button>
        <a href="historique.php" class="btn-reset">🔄 Réinitialiser</a>
        <button type="button" class="export-btn" onclick="exportToCSV()">📎 Exporter CSV</button>
      </div>
    </form>
  </div>

  <!-- TABLEAU DES MOUVEMENTS -->
  <div class="table-card fadeUp">
    <div class="table-header">
      <span class="table-title">📋 Liste des mouvements</span>
      <span class="table-count"><?php echo count($mouvements); ?> mouvement(s)</span>
    </div>
    <div class="table-responsive">
      <?php if (empty($mouvements)): ?>
      <div class="empty-state">
        <div class="empty-icon">📭</div>
        <div>Aucun mouvement trouvé pour cette période.</div>
      </div>
      <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th>Compte</th>
            <th>Type</th>
            <th>Référence</th>
            <th>Montant</th>
            <th>Solde après</th>
            <th>Canal</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($mouvements as $mv):
            $is_credit = in_array($mv['type'], $types_credit);
          ?>
          <tr>
            <td><?php echo date('d/m/Y H:i', strtotime($mv['date_operation'])); ?></td>
            <td><?php echo htmlspecialchars($mv['compte_libelle']); ?></td>
            <td>
              <span class="mvt-type <?php echo $is_credit ? 'mvt-plus' : 'mvt-minus'; ?>">
                <?php echo $type_icons[$mv['type']] ?? '💰'; ?>
                <?php echo $type_labels[$mv['type']] ?? ucfirst($mv['type']); ?>
              </span>
            </td>
            <td class="mvt-ref"><?php echo htmlspecialchars($mv['reference']); ?></td>
            <td class="<?php echo $is_credit ? 'mvt-plus' : 'mvt-minus'; ?>" style="font-weight:600;">
              <?php echo $is_credit ? '+' : '-'; ?><?php echo fmt($mv['montant']); ?> F
            </td>
            <td><?php echo fmt($mv['solde_apres']); ?> F</td>
            <td><?php echo ucfirst($mv['canal']); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
function setTheme(theme, btn) {
  document.querySelectorAll('.t-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.body.classList.toggle('dark', theme === 'dark');
  localStorage.setItem('ma2e_theme', theme);
}

function exportToCSV() {
  // Récupérer les données du tableau
  const rows = document.querySelectorAll('table tbody tr');
  if (rows.length === 0) {
    alert('Aucune donnée à exporter');
    return;
  }
  
  let csvContent = "Date,Compte,Type,Référence,Montant,Solde après,Canal\n";
  
  rows.forEach(row => {
    const cells = row.querySelectorAll('td');
    const rowData = [];
    cells.forEach(cell => {
      let text = cell.innerText.trim();
      // Échapper les virgules
      if (text.includes(',')) text = '"' + text + '"';
      rowData.push(text);
    });
    csvContent += rowData.join(',') + '\n';
  });
  
  // Télécharger le fichier
  const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
  const link = document.createElement('a');
  const url = URL.createObjectURL(blob);
  link.setAttribute('href', url);
  link.setAttribute('download', 'historique_mouvements_' + new Date().toISOString().slice(0,10) + '.csv');
  link.style.display = 'none';
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}

const saved = localStorage.getItem('ma2e_theme');
if (saved === 'dark') {
  document.body.classList.add('dark');
  document.querySelectorAll('.t-btn').forEach((b,i) => b.classList.toggle('active', i===1));
}
</script>
</body>
</html>