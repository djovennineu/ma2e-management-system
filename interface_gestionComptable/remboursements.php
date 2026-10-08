<?php
require_once '../connexion/auth_check.php';
checkRole('comptable');
require_once '../config/database.php';
require_once '../config/mailer.php';

$user_id = $_SESSION['user_id'];

$success = '';
$error   = '';

// Récupérer les crédits en cours
$credits = $pdo->prepare("
    SELECT c.*, tc.libelle AS type_libelle, tc.code AS type_code,
           m.nom, m.prenoms, m.numero_membre, m.email, m.id AS membre_id
    FROM credits c
    JOIN types_credit tc ON c.type_credit_id = tc.id
    JOIN membres m ON c.membre_id = m.id
    WHERE c.statut IN ('en_cours', 'en_retard')
    ORDER BY c.statut DESC, m.nom ASC
");
$credits->execute();
$credits_list = $credits->fetchAll(PDO::FETCH_ASSOC);

// Statistiques
$nb_credits   = count($credits_list);
$nb_retard    = $pdo->query("SELECT COUNT(*) FROM credits WHERE statut='en_retard'")->fetchColumn();
$total_encours = $pdo->query("SELECT COALESCE(SUM(capital_restant_du),0) FROM credits WHERE statut IN ('en_cours','en_retard')")->fetchColumn();

// ============================================================
// TRAITEMENT REMBOURSEMENT
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rembourser') {

    $credit_id      = (int)($_POST['credit_id'] ?? 0);
    $montant        = (float)($_POST['montant'] ?? 0);
    $canal          = $_POST['canal'] ?? '';
    $date_operation = $_POST['date_operation'] ?? date('Y-m-d');
    $reference      = trim($_POST['reference'] ?? '');
    $commentaire    = trim($_POST['commentaire'] ?? '');
    $telephone      = trim($_POST['telephone'] ?? '');
    $canaux_valides = ['especes', 'virement', 'cheque', 'mobile_money'];

    $credit = $pdo->prepare("
        SELECT c.*, tc.code AS type_code, tc.libelle AS type_libelle,
               m.nom, m.prenoms, m.email, m.id AS membre_id
        FROM credits c
        JOIN types_credit tc ON c.type_credit_id = tc.id
        JOIN membres m ON c.membre_id = m.id
        WHERE c.id = ? AND c.statut IN ('en_cours','en_retard')
    ");
    $credit->execute([$credit_id]);
    $credit = $credit->fetch(PDO::FETCH_ASSOC);

    if (!$credit) {
        $error = "Crédit introuvable ou déjà soldé.";
    } elseif ($montant <= 0) {
        $error = "Le montant doit être supérieur à 0.";
    } elseif ($montant < 5000) {
        $error = "Le montant minimum de remboursement est de 5 000 F CFA.";
    } elseif ($montant > $credit['capital_restant_du']) {
        $error = "Le montant ne peut pas dépasser le capital restant dû (" . number_format($credit['capital_restant_du'], 0, ',', ' ') . " F).";
    } elseif (!in_array($canal, $canaux_valides)) {
        $error = "Canal de paiement invalide.";
    } elseif ($canal === 'mobile_money' && empty($telephone)) {
        $error = "Le numéro de téléphone est requis pour un paiement Mobile Money.";
    } else {
        try {
            $pdo->beginTransaction();

            // Traiter les échéances
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

            $reste                   = $montant;
            $capital_rembourse_total = 0;
            $interet_rembourse_total = 0;

            foreach ($echeances as $e) {
                if ($reste <= 0) break;

                $capital_du = (float)$e['capital_du'];
                $interet_du = (float)$e['interet_du'];
                $total_due  = $capital_du + $interet_du;

                if ($reste >= $total_due) {
                    $pdo->prepare("
                        UPDATE echeanciers
                        SET statut = 'paye', date_paiement = ?,
                            capital_rembourse = capital_rembourse + ?,
                            interet_rembourse = interet_rembourse + ?
                        WHERE id = ?
                    ")->execute([date('Y-m-d'), $capital_du, $interet_du, $e['id']]);

                    $capital_rembourse_total += $capital_du;
                    $interet_rembourse_total += $interet_du;
                    $reste -= $total_due;

                } else {
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
                    ")->execute([$nouveau_capital, $nouveau_interet, date('Y-m-d'), $capital_paye, $interet_paye, $e['id']]);

                    $capital_rembourse_total += $capital_paye;
                    $interet_rembourse_total += $interet_paye;
                    $reste = 0;
                }
            }

            // Mettre à jour le crédit
            $nouveau_capital_credit = max(0, $credit['capital_restant_du'] - $capital_rembourse_total);
            $nouveau_statut = $nouveau_capital_credit <= 0 ? 'solde' : $credit['statut'];

            $pdo->prepare("
                UPDATE credits SET capital_restant_du = ?, statut = ? WHERE id = ?
            ")->execute([$nouveau_capital_credit, $nouveau_statut, $credit_id]);

            // Générer la référence si absente
            if (empty($reference)) {
                $prefix    = match($canal) { 'virement' => 'VIR', 'cheque' => 'CHQ', 'mobile_money' => 'MOB', default => 'ESP' };
                $reference = $prefix . '-REM-' . date('YmdHis') . '-' . str_pad($credit_id, 5, '0', STR_PAD_LEFT);
            }

            $commentaire_final = $commentaire ?: "Remboursement crédit " . $credit['numero_credit'];
            if ($canal === 'mobile_money' && !empty($telephone)) {
                $commentaire_final .= " — Tél: $telephone";
            }

            // Mouvement
            $pdo->prepare("
                INSERT INTO mouvements
                    (compte_id, reference, type, montant, solde_avant, solde_apres,
                     date_valeur, date_operation, canal, utilisateur_id, commentaire)
                VALUES (NULL, ?, 'remboursement', ?, 0, 0, ?, ?, ?, ?, ?)
            ")->execute([
                $reference, $montant,
                $date_operation, $date_operation,
                $canal, $user_id, $commentaire_final
            ]);

            // Notification
            $pdo->prepare("
                INSERT INTO notifications (membre_id, titre, message, type, lien, date_creation)
                VALUES (?, 'Remboursement enregistré', ?, 'success', 'mes_credits.php', NOW())
            ")->execute([
                $credit['membre_id'],
                "Remboursement de " . number_format($montant, 0, ',', ' ') . " F enregistré sur votre crédit " . $credit['type_libelle'] . ". Capital restant : " . number_format($nouveau_capital_credit, 0, ',', ' ') . " F."
            ]);

            $pdo->commit();

            $success = "✅ Remboursement de <strong>" . number_format($montant, 0, ',', ' ') . " F</strong> enregistré avec succès sur le crédit <strong>" . $credit['numero_credit'] . "</strong>.";
            if ($nouveau_statut === 'solde') {
                $success .= "<br>🎉 Ce crédit est maintenant <strong>soldé</strong> !";
            }

            // Recharger
            $credits->execute();
            $credits_list  = $credits->fetchAll(PDO::FETCH_ASSOC);
            $nb_credits    = count($credits_list);
            $nb_retard     = $pdo->query("SELECT COUNT(*) FROM credits WHERE statut='en_retard'")->fetchColumn();
            $total_encours = $pdo->query("SELECT COALESCE(SUM(capital_restant_du),0) FROM credits WHERE statut IN ('en_cours','en_retard')")->fetchColumn();

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Erreur : " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Remboursements crédit — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
:root{--gold:#C9A84C;--gold-bg:#F5EDD6;--dark:#1a1a1a;--sidebar-w:220px;--bg:#F0EDE8;--text:#333;--text-light:#888;--card:#fff;--border:#e8e3db;--green:#4CAF50;--red:#F44336}
.dark{--bg:#181818;--card:#242424;--text:#f0f0f0;--text-light:#777;--border:#333}
*{margin:0;padding:0;box-sizing:border-box}

/* ── CORRECTION SCROLL ──
   body en flex row, sidebar sticky, main scrollable */
body{
  font-family:'DM Sans',sans-serif;
  background:var(--bg);
  color:var(--text);
  display:flex;
  min-height:100vh;         /* ← min-height au lieu de height */
  overflow-x:hidden;        /* ← plus de overflow:hidden global */
  transition:background .3s,color .3s;
}

/* SIDEBAR — sticky sur toute la hauteur visible */
.sidebar{
  width:var(--sidebar-w);
  background:var(--dark);
  display:flex;
  flex-direction:column;
  padding:24px 0;
  flex-shrink:0;
  position:sticky;          /* ← sticky */
  top:0;
  height:100vh;
  overflow-y:auto;          /* ← scroll interne si menu long */
  z-index:100;
}

/* MAIN — scroll libre */
.main{
  flex:1;
  overflow-y:auto;          /* ← scroll vertical libre */
  overflow-x:hidden;
  padding:28px;
  display:flex;
  flex-direction:column;
  gap:20px;
  min-height:100vh;         /* ← s'étend sur toute la hauteur */
}

.logo{padding:0 20px 28px;border-bottom:1px solid #333}
.logo-text{font-family:'Playfair Display',serif;font-size:20px;color:white;letter-spacing:1px}
.logo-sub{font-size:10px;color:#C9A84C;letter-spacing:.5px;text-transform:uppercase;margin-top:2px}
.nav{flex:1;padding:20px 12px;display:flex;flex-direction:column;gap:4px}
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;color:#999;font-size:13.5px;font-weight:500;cursor:pointer;transition:all .2s;text-decoration:none}
.nav-item:hover{background:#2a2a2a;color:#ccc}
.nav-item.active{background:var(--gold);color:white}
.nav-item svg{width:16px;height:16px;flex-shrink:0}
.nav-section{font-size:10px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:1px;padding:12px 12px 6px}
.sidebar-bottom{padding:16px 12px;border-top:1px solid #333}
.user-card{display:flex;align-items:center;gap:10px;padding:10px}
.avatar{width:34px;height:34px;border-radius:50%;background:var(--gold);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:white}
.user-info .name{font-size:13px;font-weight:600;color:white}
.user-info .role{font-size:11px;color:#C9A84C}
.theme-toggle{display:flex;background:#2a2a2a;border-radius:20px;padding:3px;margin-top:12px;gap:2px}
.theme-btn{flex:1;padding:6px 0;border:none;background:transparent;color:#777;font-size:12px;font-weight:500;border-radius:16px;cursor:pointer;transition:all .2s;font-family:'DM Sans',sans-serif}
.theme-btn.active{background:var(--gold);color:white}

.breadcrumb{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-light)}
.breadcrumb a{color:var(--text-light);text-decoration:none}
.breadcrumb a:hover{color:var(--gold)}
.breadcrumb .sep{opacity:.4}
.breadcrumb .cur{color:var(--text);font-weight:600}

.stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.stat-card{background:var(--card);border-radius:14px;padding:20px;border:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.stat-left .stat-value{font-size:28px;font-weight:800;color:var(--text);line-height:1}
.stat-left .stat-label{font-size:12px;color:var(--text-light);margin-top:4px}
.stat-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:24px}

.card{background:var(--card);border-radius:14px;border:1px solid var(--border);overflow:hidden}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.card-header-icon{font-size:20px}
.card-header-title{font-size:14px;font-weight:700;color:var(--text)}
.card-body{padding:20px}

.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:16px}
.form-group label{font-size:12px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px}
.form-group input,.form-group select,.form-group textarea{padding:12px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;color:var(--text);background:var(--bg);font-family:'DM Sans',sans-serif;outline:none;transition:border-color .2s}
.form-group input:focus,.form-group select:focus{border-color:var(--gold)}
.form-hint{font-size:11px;color:var(--text-light);margin-top:4px}
.form-row{display:flex;gap:16px}
.form-row .form-group{flex:1}

.canal-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:20px}
.canal-btn{padding:12px;border:2px solid var(--border);border-radius:10px;text-align:center;cursor:pointer;transition:all .2s;background:var(--bg)}
.canal-btn:hover{border-color:var(--gold)}
.canal-btn.active{border-color:var(--gold);background:var(--gold-bg)}
.canal-emoji{font-size:24px;display:block;margin-bottom:6px}
.canal-label{font-size:12px;font-weight:600;color:var(--text-light)}
.canal-btn.active .canal-label{color:var(--gold)}

.credits-wrap{border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:16px}
.credits-scroll{max-height:280px;overflow-y:auto}   /* ← scroll propre sur la liste crédits */
table{width:100%;border-collapse:collapse}
thead tr{background:var(--dark)}
thead th{padding:10px 14px;text-align:left;font-size:11px;font-weight:700;color:rgba(255,255,255,.75);white-space:nowrap}
tbody tr{border-bottom:1px solid var(--border);transition:background .15s;cursor:pointer}
tbody tr:hover{background:var(--bg)}
tbody td{padding:10px 14px;font-size:13px}
.badge-retard{background:#FFEBEE;color:#C62828;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600}
.badge-cours {background:#E8F5E9;color:#2E7D32;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600}
.montant-val{font-weight:700;color:var(--gold)}

.info-box{background:var(--bg);border-radius:10px;padding:14px 16px;margin-bottom:16px;display:none;border-left:3px solid var(--gold)}
.info-row{display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px}
.info-row:last-child{margin-bottom:0}
.info-row span{color:var(--text-light)}
.info-row strong{color:var(--gold)}

.preview-box{background:var(--bg);border-radius:10px;padding:14px 16px;margin-bottom:16px;display:none;border-left:3px solid #2E7D32}
.preview-row{display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px}
.preview-row:last-child{margin-bottom:0}

.btn-submit{width:100%;padding:14px;background:var(--green);color:white;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:8px;margin-top:8px}
.btn-submit:hover{background:#388E3C}
.btn-submit:disabled{opacity:.5;cursor:not-allowed}

.alert{padding:14px 18px;border-radius:10px;font-size:14px;display:flex;align-items:flex-start;gap:10px}
.alert-success{background:#E8F5E9;color:#1B5E20;border-left:4px solid #4CAF50}
.alert-error  {background:#FFEBEE;color:#B71C1C;border-left:4px solid #F44336}

/* TOPBAR MOBILE */
.topbar-mobile{display:none;align-items:center;justify-content:space-between;padding:14px 20px;background:var(--card);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99}
.overlay.open{display:block}

@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
.fadeUp{animation:fadeUp .35s ease both}

@media(max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:768px){
  /* Sur mobile body redevient colonne, scroll natif */
  body{flex-direction:column}
  .topbar-mobile{display:flex}
  .sidebar{
    position:fixed;top:0;left:0;height:100vh;
    transform:translateX(-100%);transition:transform .3s;
  }
  .sidebar.open{transform:translateX(0)}
  .main{padding:16px;min-height:unset;overflow-y:unset}
  .stats-grid{grid-template-columns:1fr}
  .form-row{flex-direction:column}
  .canal-grid{grid-template-columns:repeat(2,1fr)}
}
</style>
</head>
<body>

<!-- TOPBAR MOBILE -->
<div class="topbar-mobile">
  <button onclick="toggleSidebar()" style="background:none;border:none;cursor:pointer;color:var(--text)">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
  </button>
  <span style="font-family:'Playfair Display',serif;font-size:18px">MA2E</span>
  <div style="width:34px"></div>
</div>
<div class="overlay" id="overlay" onclick="toggleSidebar()"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="logo">
    <div class="logo-text">MA2E</div>
    <div class="logo-sub">Comptable</div>
  </div>
  <nav class="nav">
    <div class="nav-section">Principal</div>
    <a class="nav-item" href="dashboard_comptable.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Dashboard
    </a>
    <div class="nav-section">Opérations</div>
    <a class="nav-item" href="prelevement_salaire.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>Prélèvement salaire
    </a>
    <a class="nav-item" href="saisir_versement.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>Versement espèces/virement
    </a>
    <a class="nav-item" href="valider_retraits.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 11 12 6 7 11"/><line x1="12" y1="18" x2="12" y2="6"/></svg>Valider retraits
    </a>
    <a class="nav-item active" href="remboursements.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>Remboursements crédit
    </a>
    <div class="nav-section">Rapports</div>
    <a class="nav-item" href="rapport_mouvements.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>Rapport mouvements
    </a>
    <div class="nav-section">Compte</div>
    <a class="nav-item" href="../connexion/logout.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 17l5-5-5-5"/><line x1="21" y1="12" x2="9" y2="12"/><path d="M13 5H5a2 2 0 00-2 2v10a2 2 0 002 2h8"/></svg>Déconnexion
    </a>
  </nav>
  <div class="sidebar-bottom">
    <div class="user-card">
      <div class="avatar"><?php echo strtoupper(substr($_SESSION['nom'], 0, 1)); ?></div>
      <div class="user-info">
        <div class="name"><?php echo htmlspecialchars($_SESSION['prenoms'] . ' ' . $_SESSION['nom']); ?></div>
        <div class="role">Comptable</div>
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

  <div class="breadcrumb fadeUp">
    <a href="dashboard_comptable.php">Dashboard</a>
    <span class="sep">›</span>
    <span class="cur">Remboursements crédit</span>
  </div>

  <div class="fadeUp">
    <h1 style="font-family:'Playfair Display',serif;font-size:24px;font-weight:600;color:var(--text)">Remboursements de crédit</h1>
    <p style="font-size:13.5px;color:var(--text-light);margin-top:4px">Enregistrez les remboursements de crédit des membres</p>
  </div>

  <?php if ($success): ?>
  <div class="alert alert-success fadeUp">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    <div><?php echo $success; ?></div>
  </div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="alert alert-error fadeUp">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
    <div><?php echo htmlspecialchars($error); ?></div>
  </div>
  <?php endif; ?>

  <!-- STATISTIQUES -->
  <div class="stats-grid fadeUp">
    <div class="stat-card">
      <div class="stat-left">
        <div class="stat-value"><?php echo $nb_credits; ?></div>
        <div class="stat-label">Crédits en cours</div>
      </div>
      <div class="stat-icon" style="background:#E3F2FD">💰</div>
    </div>
    <div class="stat-card">
      <div class="stat-left">
        <div class="stat-value" style="color:<?php echo $nb_retard > 0 ? '#C62828' : 'var(--text)'; ?>"><?php echo $nb_retard; ?></div>
        <div class="stat-label">Crédits en retard</div>
      </div>
      <div class="stat-icon" style="background:#FFEBEE">⚠️</div>
    </div>
    <div class="stat-card">
      <div class="stat-left">
        <div class="stat-value" style="font-size:20px"><?php echo number_format($total_encours / 1000, 0, ',', ' '); ?>k F</div>
        <div class="stat-label">Encours total</div>
      </div>
      <div class="stat-icon" style="background:#E8F5E9">📊</div>
    </div>
  </div>

  <!-- FORMULAIRE -->
  <div class="card fadeUp">
    <div class="card-header">
      <span class="card-header-icon">💰</span>
      <span class="card-header-title">Enregistrer un remboursement</span>
    </div>
    <div class="card-body">

      <!-- Liste des crédits -->
      <div class="form-group">
        <label>Sélectionner le crédit à rembourser *</label>
        <?php if (empty($credits_list)): ?>
        <div style="text-align:center;padding:30px;color:var(--text-light);border:1px solid var(--border);border-radius:10px">
          Aucun crédit en cours actuellement.
        </div>
        <?php else: ?>
        <div class="credits-wrap">
          <div class="credits-scroll">
            <table>
              <thead>
                <tr>
                  <th></th><th>Membre</th><th>Type crédit</th>
                  <th>Montant accordé</th><th>Capital restant</th><th>Statut</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($credits_list as $c): ?>
                <tr class="credit-row"
                    data-id="<?php echo $c['id']; ?>"
                    data-restant="<?php echo $c['capital_restant_du']; ?>"
                    data-numero="<?php echo htmlspecialchars($c['numero_credit']); ?>"
                    data-membre="<?php echo htmlspecialchars($c['prenoms'] . ' ' . $c['nom']); ?>"
                    onclick="selectCreditRow(this)">
                  <td><input type="radio" name="credit_select" value="<?php echo $c['id']; ?>" class="credit-radio" style="accent-color:var(--gold);width:15px;height:15px"></td>
                  <td>
                    <div style="font-weight:600"><?php echo htmlspecialchars($c['prenoms'] . ' ' . $c['nom']); ?></div>
                    <div style="font-size:11px;color:var(--text-light)"><?php echo htmlspecialchars($c['numero_membre']); ?></div>
                  </td>
                  <td><?php echo htmlspecialchars($c['type_libelle']); ?></td>
                  <td class="montant-val"><?php echo number_format($c['montant_accorde'], 0, ',', ' '); ?> F</td>
                  <td class="montant-val"><?php echo number_format($c['capital_restant_du'], 0, ',', ' '); ?> F</td>
                  <td>
                    <span class="badge <?php echo $c['statut'] === 'en_retard' ? 'badge-retard' : 'badge-cours'; ?>">
                      <?php echo $c['statut'] === 'en_retard' ? '⚠️ En retard' : '✅ En cours'; ?>
                    </span>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Info crédit sélectionné -->
      <div class="info-box" id="creditInfo">
        <div class="info-row"><span>Crédit sélectionné</span><strong id="infoNumero">—</strong></div>
        <div class="info-row"><span>Membre</span><strong id="infoMembre" style="color:var(--text)">—</strong></div>
        <div class="info-row"><span>Capital restant dû</span><strong id="infoRestant">—</strong></div>
      </div>

      <form method="POST" id="formRemboursement">
        <input type="hidden" name="action" value="rembourser">
        <input type="hidden" name="credit_id" id="credit_id" value="">

        <!-- Canal -->
        <div style="font-size:12px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">Canal de paiement *</div>
        <div class="canal-grid">
          <div class="canal-btn" onclick="selectCanal('especes',this)">
            <span class="canal-emoji">💵</span>
            <span class="canal-label">Espèces</span>
          </div>
          <div class="canal-btn" onclick="selectCanal('virement',this)">
            <span class="canal-emoji">🏦</span>
            <span class="canal-label">Virement</span>
          </div>
          <div class="canal-btn" onclick="selectCanal('cheque',this)">
            <span class="canal-emoji">📄</span>
            <span class="canal-label">Chèque</span>
          </div>
          <div class="canal-btn" onclick="selectCanal('mobile_money',this)">
            <span class="canal-emoji">📱</span>
            <span class="canal-label">Mobile Money</span>
          </div>
        </div>
        <input type="hidden" name="canal" id="canalHidden" value="">

        <!-- Téléphone Mobile Money -->
        <div id="telephoneGroup" class="form-group" style="display:none">
          <label>Numéro de téléphone *</label>
          <input type="tel" name="telephone" id="telephoneInput" placeholder="Ex: 07 XX XX XX XX" maxlength="14">
          <span class="form-hint">Numéro associé au compte Mobile Money</span>
        </div>

        <!-- Référence virement/chèque -->
        <div id="refGroup" class="form-group" style="display:none">
          <label>Référence <span id="refLabel" style="font-weight:400;text-transform:none;font-size:11px"></span></label>
          <input type="text" name="reference" placeholder="Ex: VIR-20260321-001">
          <span class="form-hint">Optionnel — générée automatiquement si vide</span>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Montant remboursé (F CFA) *</label>
            <input type="number" name="montant" id="montantInput"
                   min="5000" step="100" placeholder="Ex: 50 000"
                   required oninput="updatePreview()">
            <span class="form-hint" id="montantHint">Minimum : 5 000 F CFA</span>
          </div>
          <div class="form-group">
            <label>Date de l'opération *</label>
            <input type="date" name="date_operation" value="<?php echo date('Y-m-d'); ?>" required>
          </div>
        </div>

        <div class="form-group">
          <label>Commentaire <span style="font-weight:400;text-transform:none">— optionnel</span></label>
          <input type="text" name="commentaire" placeholder="Ex: Remboursement mensuel avril 2026">
        </div>

        <!-- Récapitulatif -->
        <div class="preview-box" id="previewBox">
          <div class="preview-row">
            <span style="color:var(--text-light)">Crédit</span>
            <strong id="previewCredit">—</strong>
          </div>
          <div class="preview-row">
            <span style="color:var(--text-light)">Montant</span>
            <strong id="previewMontant" style="color:var(--gold)">—</strong>
          </div>
          <div class="preview-row">
            <span style="color:var(--text-light)">Capital restant après</span>
            <strong id="previewRestant" style="color:#2E7D32">—</strong>
          </div>
        </div>

        <button type="submit" class="btn-submit" id="btnSubmit" disabled
                onclick="return confirm('Confirmer l\'enregistrement de ce remboursement ?')">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          Enregistrer le remboursement
        </button>

      </form>
    </div>
  </div>

</main>

<script>
let selectedCreditId      = null;
let selectedCreditRestant = 0;
let selectedCreditNumero  = '';
let selectedCreditMembre  = '';
let selectedCanal         = '';

// Clic sur une ligne du tableau
function selectCreditRow(row) {
  document.querySelectorAll('.credit-row').forEach(r => r.style.background = '');
  row.style.background = 'var(--gold-bg)';

  const radio = row.querySelector('input[type=radio]');
  if (radio) radio.checked = true;

  selectedCreditId      = row.dataset.id;
  selectedCreditRestant = parseFloat(row.dataset.restant);
  selectedCreditNumero  = row.dataset.numero;
  selectedCreditMembre  = row.dataset.membre;

  document.getElementById('credit_id').value  = selectedCreditId;
  document.getElementById('infoNumero').textContent  = selectedCreditNumero;
  document.getElementById('infoMembre').textContent  = selectedCreditMembre;
  document.getElementById('infoRestant').textContent = fmt(selectedCreditRestant) + ' F';
  document.getElementById('creditInfo').style.display = 'block';

  const montantInput = document.getElementById('montantInput');
  montantInput.max = selectedCreditRestant;
  document.getElementById('montantHint').textContent =
    'Minimum : 5 000 F — Maximum : ' + fmt(selectedCreditRestant) + ' F CFA';

  updatePreview();
  checkBtn();
}

function selectCanal(canal, btn) {
  selectedCanal = canal;
  document.getElementById('canalHidden').value = canal;
  document.querySelectorAll('.canal-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');

  const telGroup  = document.getElementById('telephoneGroup');
  const refGroup  = document.getElementById('refGroup');
  const refLabel  = document.getElementById('refLabel');

  telGroup.style.display = canal === 'mobile_money' ? 'flex' : 'none';
  refGroup.style.display = (canal === 'virement' || canal === 'cheque') ? 'flex' : 'none';
  if (canal === 'virement') refLabel.textContent = '(numéro de virement)';
  if (canal === 'cheque')   refLabel.textContent = '(numéro de chèque)';

  updatePreview();
  checkBtn();
}

function updatePreview() {
  const montant = parseFloat(document.getElementById('montantInput').value) || 0;
  const box     = document.getElementById('previewBox');

  if (!selectedCreditId || !selectedCanal || montant < 5000) {
    box.style.display = 'none'; checkBtn(); return;
  }

  const restant = Math.max(0, selectedCreditRestant - montant);
  document.getElementById('previewCredit').textContent  = selectedCreditNumero + ' — ' + selectedCreditMembre;
  document.getElementById('previewMontant').textContent = fmt(montant) + ' F';
  document.getElementById('previewRestant').textContent = fmt(restant) + ' F';
  box.style.display = 'block';
  checkBtn();
}

function checkBtn() {
  const montant = parseFloat(document.getElementById('montantInput').value) || 0;
  let ok = selectedCreditId && selectedCanal && montant >= 5000 && montant <= selectedCreditRestant;
  if (selectedCanal === 'mobile_money') {
    ok = ok && (document.getElementById('telephoneInput').value.trim().length >= 8);
  }
  document.getElementById('btnSubmit').disabled = !ok;
}

document.getElementById('montantInput')?.addEventListener('input', () => { updatePreview(); checkBtn(); });
document.getElementById('telephoneInput')?.addEventListener('input', checkBtn);

function fmt(n) {
  return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}
function setTheme(theme, btn) {
  document.querySelectorAll('.theme-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.body.classList.toggle('dark', theme === 'dark');
  localStorage.setItem('ma2e_theme', theme);
}
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('open');
}
const saved = localStorage.getItem('ma2e_theme');
if (saved === 'dark') {
  document.body.classList.add('dark');
  document.querySelectorAll('.theme-btn').forEach((b,i) => b.classList.toggle('active', i===1));
}
</script>
</body>
</html>
