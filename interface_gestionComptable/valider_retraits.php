<?php
require_once '../connexion/auth_check.php';
checkRole('comptable');
require_once '../config/database.php';
require_once '../config/mailer.php';

$user_id = $_SESSION['user_id'];

$success = '';
$error   = '';
$rapport = null;

// ============================================================
// RÉCUPÉRATION DES DEMANDES EN ATTENTE
// ============================================================
$demandes = $pdo->prepare("
    SELECT dr.*, 
           m.nom, m.prenoms, m.numero_membre, m.email, m.telephone,
           tce.libelle AS compte_libelle, tce.code AS type_code,
           ce.numero_compte
    FROM demandes_retrait dr
    JOIN membres m ON dr.membre_id = m.id
    JOIN comptes_epargne ce ON dr.compte_id = ce.id
    JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
    WHERE dr.statut = 'en_attente'
    ORDER BY dr.created_at ASC
");
$demandes->execute();
$demandes_list = $demandes->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// STATISTIQUES
// ============================================================
$nb_attente = count($demandes_list);
$nb_total_attente = $pdo->query("SELECT COUNT(*) FROM demandes_retrait WHERE statut='en_attente'")->fetchColumn();
$nb_validees_mois = $pdo->query("
    SELECT COUNT(*) FROM demandes_retrait 
    WHERE statut='validee' 
    AND DATE_FORMAT(created_at,'%Y-%m') = DATE_FORMAT(NOW(),'%Y-%m')
")->fetchColumn();
$montant_total_attente = array_sum(array_column($demandes_list, 'montant'));

// ============================================================
// TRAITEMENT DU FORMULAIRE
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $demande_id = (int)($_POST['demande_id'] ?? 0);
    $motif = trim($_POST['motif'] ?? '');
    
    // Récupérer la demande
    $demande = $pdo->prepare("
        SELECT dr.*, m.nom, m.prenoms, m.email, m.numero_membre,
               ce.id as compte_id, ce.solde, ce.solde_disponible, ce.solde_bloque,
               tce.code as type_code
        FROM demandes_retrait dr
        JOIN membres m ON dr.membre_id = m.id
        JOIN comptes_epargne ce ON dr.compte_id = ce.id
        JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
        WHERE dr.id = ? AND dr.statut = 'en_attente'
    ");
    $demande->execute([$demande_id]);
    $demande = $demande->fetch(PDO::FETCH_ASSOC);
    
    if (!$demande) {
        $error = "Demande introuvable ou déjà traitée.";
    } elseif ($action === 'valider') {
        try {
            $pdo->beginTransaction();
            
            // Vérifier que le solde est toujours suffisant
            $compte = $pdo->prepare("
                SELECT solde, solde_disponible, solde_bloque 
                FROM comptes_epargne 
                WHERE id = ? AND statut = 'actif'
            ");
            $compte->execute([$demande['compte_id']]);
            $compte = $compte->fetch(PDO::FETCH_ASSOC);
            
            if (!$compte) {
                throw new Exception("Compte introuvable ou inactif.");
            }
            
            if ($compte['solde_disponible'] < $demande['montant']) {
                throw new Exception("Solde disponible insuffisant. Solde actuel : " . number_format($compte['solde_disponible'], 0, ',', ' ') . " F");
            }
            
            // Calculer la date de valeur pour le compte Ordinaire
            $date_operation = date('Y-m-d');
            $date_valeur = $date_operation;
            
            if ($demande['type_code'] === 'ordinaire') {
                $jour = (int)date('d');
                $mois = (int)date('m');
                $annee = (int)date('Y');
                if ($jour <= 15) {
                    $date_valeur = date('Y-m-d', mktime(0, 0, 0, $mois, 1, $annee));
                } else {
                    $date_valeur = date('Y-m-d', mktime(0, 0, 0, $mois, 16, $annee));
                }
            }
            
            // Calculer les nouveaux soldes
            $nouveau_solde = $compte['solde'] - $demande['montant'];
            $nouveau_solde_disponible = $compte['solde_disponible'] - $demande['montant'];
            
            // Générer la référence du mouvement
            $ref = 'RET-' . date('YmdHis') . '-' . str_pad($demande_id, 5, '0', STR_PAD_LEFT);
            
            // Enregistrer le mouvement de retrait
            $pdo->prepare("
                INSERT INTO mouvements
                    (compte_id, reference, type, montant, solde_avant, solde_apres,
                     date_valeur, date_operation, canal, utilisateur_id, commentaire)
                VALUES (?, ?, 'retrait', ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $demande['compte_id'],
                $ref,
                $demande['montant'],
                $compte['solde'],
                $nouveau_solde,
                $date_valeur,
                $date_operation,
                $demande['canal'],
                $user_id,
                "Retrait validé - Réf demande: " . $demande['reference']
            ]);
            
            // Mettre à jour le solde du compte
            $pdo->prepare("
                UPDATE comptes_epargne
                SET solde = ?, solde_disponible = ?
                WHERE id = ?
            ")->execute([$nouveau_solde, $nouveau_solde_disponible, $demande['compte_id']]);
            
            // Mettre à jour le statut de la demande
            $pdo->prepare("
                UPDATE demandes_retrait
                SET statut = 'validee', traite_par = ?, date_traitement = NOW()
                WHERE id = ?
            ")->execute([$user_id, $demande_id]);
            
            $pdo->commit();
            
            // Créer une notification pour le membre
            $pdo->prepare("
                INSERT INTO notifications (membre_id, titre, message, type, lien, date_creation)
                VALUES (?, ?, ?, ?, ?, NOW())
            ")->execute([
                $demande['membre_id'],
                "Demande de retrait validée",
                "Votre demande de retrait de " . number_format($demande['montant'], 0, ',', ' ') . " F a été validée. L'argent est disponible.",
                "success",
                "historique.php"
            ]);
            
            // Envoyer un email au membre
            $canal_label = match($demande['canal']) {
                'especes' => 'au bureau MA2E',
                'virement' => 'par virement bancaire',
                'mobile_money' => 'via Mobile Money',
                default => $demande['canal']
            };
            
            $email_envoye = false;
            if (file_exists('../config/mailer.php')) {
                require_once '../config/mailer.php';
                
                $sujet = "MA2E - Votre demande de retrait a été validée";
                $message = "
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset='UTF-8'>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: #C9A84C; padding: 20px; text-align: center; color: white; border-radius: 10px 10px 0 0; }
                        .content { padding: 20px; background: #f9f9f9; }
                        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
                        .montant { font-weight: bold; color: #C9A84C; font-size: 18px; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='header'>
                            <h2>MA2E - Demande de retrait validée</h2>
                        </div>
                        <div class='content'>
                            <p>Bonjour <strong>" . htmlspecialchars($demande['prenoms']) . "</strong>,</p>
                            <p>Votre demande de retrait a été <strong style='color:#4CAF50'>validée</strong>.</p>
                            <table style='width:100%; margin:20px 0; border-collapse:collapse;'>
                                <tr><td style='padding:8px; border-bottom:1px solid #ddd;'><strong>Référence</strong></td><td>" . htmlspecialchars($demande['reference']) . "</td></tr>
                                <tr><td style='padding:8px; border-bottom:1px solid #ddd;'><strong>Montant</strong></td><td class='montant'>" . number_format($demande['montant'], 0, ',', ' ') . " F</td></tr>
                                <tr><td style='padding:8px; border-bottom:1px solid #ddd;'><strong>Mode de retrait</strong></td><td>$canal_label</td></tr>
                            </table>
                            <p>Cordialement,<br>L'équipe MA2E</p>
                        </div>
                        <div class='footer'>&copy; " . date('Y') . " MA2E</div>
                    </div>
                </body>
                </html>
                ";
                $email_envoye = envoyerEmail($demande['email'], $sujet, $message);
            }
            
            $success = "Demande de retrait validée avec succès. " . ($email_envoye ? "Un email a été envoyé au membre." : "");
            
            // Recharger les demandes
            $demandes->execute();
            $demandes_list = $demandes->fetchAll(PDO::FETCH_ASSOC);
            $nb_attente = count($demandes_list);
            $montant_total_attente = array_sum(array_column($demandes_list, 'montant'));
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Erreur : " . $e->getMessage();
        }
        
    } elseif ($action === 'rejeter') {
        if (empty($motif)) {
            $error = "Veuillez indiquer un motif de rejet.";
        } else {
            try {
                $pdo->beginTransaction();
                
                $pdo->prepare("
                    UPDATE demandes_retrait
                    SET statut = 'rejetee', motif_rejet = ?, traite_par = ?, date_traitement = NOW()
                    WHERE id = ?
                ")->execute([$motif, $user_id, $demande_id]);
                
                $pdo->commit();
                
                           // Créer une notification pour le membre
            $pdo->prepare("
                INSERT INTO notifications (membre_id, titre, message, type, lien, date_creation)
                VALUES (?, ?, ?, ?, ?, NOW())
            ")->execute([
                $demande['membre_id'],
                "Demande de retrait validée",
                "Votre demande de retrait de " . number_format($demande['montant'], 0, ',', ' ') . " F a été validée. L'argent est disponible.",
                "success",
                "historique.php"
            ]);
            
            // Envoyer un email au membre - VERSION CORRIGÉE
            $email_envoye = false;
            if (file_exists('../config/mailer.php')) {
                require_once '../config/mailer.php';
                
                $canal_label = match($demande['canal']) {
                    'especes' => 'au bureau MA2E',
                    'virement' => 'par virement bancaire',
                    'mobile_money' => 'via Mobile Money',
                    default => $demande['canal']
                };
                
                // Utiliser la fonction dédiée dans mailer.php
                $email_envoye = envoyerEmailRetraitValide(
                    $demande['email'],
                    $demande['nom'],
                    $demande['prenoms'],
                    $demande['montant'],
                    $demande['reference'],
                    $canal_label
                );
            }
            
            $success = "Demande de retrait validée avec succès. " . ($email_envoye ? "Un email a été envoyé au membre." : "");
                
                $demandes->execute();
                $demandes_list = $demandes->fetchAll(PDO::FETCH_ASSOC);
                $nb_attente = count($demandes_list);
                $montant_total_attente = array_sum(array_column($demandes_list, 'montant'));
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Erreur : " . $e->getMessage();
            }
        }
    }
}

$mois_fr = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
$mois_label = $mois_fr[(int)date('n')] . ' ' . date('Y');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Valider les retraits — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
:root{--gold:#C9A84C;--gold-bg:#F5EDD6;--dark:#1a1a1a;--sidebar-w:220px;--bg:#F0EDE8;--text:#333;--text-light:#888;--card:#fff;--border:#e8e3db;--green:#4CAF50;--red:#F44336;--blue:#2196F3;--orange:#FF9800}
.dark{--bg:#181818;--card:#242424;--text:#f0f0f0;--text-light:#777;--border:#333}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);display:flex;height:100vh;overflow:hidden;transition:background .3s,color .3s}

/* SIDEBAR */
.sidebar{width:var(--sidebar-w);background:var(--dark);display:flex;flex-direction:column;padding:24px 0;flex-shrink:0;z-index:100}
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

/* MAIN */
.main{flex:1;overflow-y:auto;padding:28px;display:flex;flex-direction:column;gap:20px}
.breadcrumb{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-light)}
.breadcrumb a{color:var(--text-light);text-decoration:none}
.breadcrumb a:hover{color:var(--gold)}
.breadcrumb .sep{opacity:.4}
.breadcrumb .cur{color:var(--text);font-weight:600}

/* STATS GRID */
.stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.stat-card{background:var(--card);border-radius:14px;padding:20px;border:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.stat-left .stat-value{font-size:28px;font-weight:800;color:var(--text);line-height:1}
.stat-left .stat-label{font-size:12px;color:var(--text-light);margin-top:4px}
.stat-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:24px}

/* CARD */
.card{background:var(--card);border-radius:14px;border:1px solid var(--border);overflow:hidden}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.card-header-icon{font-size:20px}
.card-header-title{font-size:14px;font-weight:700;color:var(--text)}
.card-body{padding:0}

/* TABLE */
.table-responsive{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead th{padding:12px 16px;text-align:left;font-size:11px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid var(--border);background:var(--bg)}
tbody tr{border-bottom:1px solid var(--border);transition:background .15s}
tbody tr:hover{background:var(--bg)}
tbody tr:last-child{border-bottom:none}
td{padding:12px 16px;font-size:13px;vertical-align:middle}
.badge{padding:4px 10px;border-radius:20px;font-size:11px;font-weight:600;display:inline-block}
.badge-especes{background:#E8F5E9;color:#2E7D32}
.badge-virement{background:#E3F2FD;color:#1565C0}
.badge-mobile{background:#F3E5F5;color:#6A1B9A}
.montant{font-weight:700;color:var(--gold)}
.btn-valider{background:var(--green);color:white;border:none;padding:6px 12px;border-radius:6px;cursor:pointer;font-family:'DM Sans',sans-serif;margin-right:8px;transition:all .2s}
.btn-valider:hover{background:#388E3C}
.btn-rejeter{background:var(--red);color:white;border:none;padding:6px 12px;border-radius:6px;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .2s}
.btn-rejeter:hover{background:#c62828}

/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:var(--card);border-radius:16px;padding:24px;width:100%;max-width:480px}
.modal-title{font-size:18px;font-weight:700;margin-bottom:16px}
.modal-body{margin-bottom:20px}
.modal-footer{display:flex;gap:12px}
.btn-primary{flex:1;padding:12px;background:var(--green);color:white;border:none;border-radius:8px;font-weight:600;cursor:pointer}
.btn-danger{flex:1;padding:12px;background:var(--red);color:white;border:none;border-radius:8px;font-weight:600;cursor:pointer}
.btn-secondary{flex:1;padding:12px;background:var(--bg);color:var(--text);border:1.5px solid var(--border);border-radius:8px;cursor:pointer}
.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:16px}
.form-group label{font-size:12px;font-weight:700;color:var(--text-light);text-transform:uppercase}
.form-group textarea{padding:12px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;background:var(--bg);color:var(--text);resize:vertical;min-height:100px;font-family:'DM Sans',sans-serif}
.form-group textarea:focus{border-color:var(--gold);outline:none}
.empty-state{text-align:center;padding:60px;color:var(--text-light)}
.empty-icon{font-size:48px;margin-bottom:16px}

/* TOPBAR MOBILE */
.topbar-mobile{display:none;align-items:center;justify-content:space-between;padding:14px 20px;background:var(--card);border-bottom:1px solid var(--border)}
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99}
.overlay.open{display:block}

@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
.fadeUp{animation:fadeUp .35s ease both}

@media(max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:768px){
  body{flex-direction:column;height:auto;overflow-y:auto}
  .topbar-mobile{display:flex}
  .sidebar{position:fixed;top:0;left:0;height:100vh;z-index:200;transform:translateX(-100%);transition:transform .3s}
  .sidebar.open{transform:translateX(0)}
  .main{padding:16px}
  .stats-grid{grid-template-columns:1fr}
  .modal{width:calc(100% - 32px);margin:16px}
}
</style>
</head>
<body>

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
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      Dashboard
    </a>
    <div class="nav-section">Opérations</div>
    <a class="nav-item" href="prelevement_salaire.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
      Prélèvement salaire
    </a>
    <a class="nav-item" href="saisir_versement.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
      Versement espèces/virement
    </a>
    <a class="nav-item active" href="valider_retraits.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 11 12 6 7 11"/><line x1="12" y1="18" x2="12" y2="6"/></svg>
      Valider retraits
    </a>
    <a class="nav-item" href="remboursements.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      Remboursements crédit
    </a>
    <div class="nav-section">Calculs</div>
    <a class="nav-item" href="calcul_interets.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/></svg>
      Calcul des intérêts
    </a>
    <a class="nav-item" href="rapports.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
      Rapports financiers
    </a>
    <div class="nav-section">Compte</div>
    <a class="nav-item" href="../connexion/logout.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 17l5-5-5-5"/><line x1="21" y1="12" x2="9" y2="12"/><path d="M13 5H5a2 2 0 00-2 2v10a2 2 0 002 2h8"/></svg>
      Déconnexion
    </a>
  </nav>
  <div class="sidebar-bottom">
    <div class="user-card">
      <div class="avatar"><?php echo strtoupper(substr($_SESSION['nom'],0,1)); ?></div>
      <div class="user-info">
        <div class="name"><?php echo htmlspecialchars($_SESSION['prenoms'].' '.$_SESSION['nom']); ?></div>
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
    <span class="cur">Valider les retraits</span>
  </div>

  <div class="fadeUp">
    <h1 style="font-family:'Playfair Display',serif;font-size:24px;font-weight:600;color:var(--text)">Validation des retraits</h1>
    <p style="font-size:13.5px;color:var(--text-light);margin-top:4px">Traitez les demandes de retrait des membres</p>
  </div>

  <!-- STATISTIQUES -->
  <div class="stats-grid fadeUp">
    <div class="stat-card">
      <div class="stat-left">
        <div class="stat-value"><?php echo $nb_attente; ?></div>
        <div class="stat-label">Demandes en attente</div>
      </div>
      <div class="stat-icon" style="background:#FFF8E1;color:#F57F17">⏳</div>
    </div>
    <div class="stat-card">
      <div class="stat-left">
        <div class="stat-value"><?php echo number_format($montant_total_attente, 0, ',', ' '); ?> F</div>
        <div class="stat-label">Montant total à valider</div>
      </div>
      <div class="stat-icon" style="background:#E3F2FD;color:#1976D2">💰</div>
    </div>
    <div class="stat-card">
      <div class="stat-left">
        <div class="stat-value"><?php echo $nb_validees_mois; ?></div>
        <div class="stat-label">Retraits validés ce mois</div>
      </div>
      <div class="stat-icon" style="background:#E8F5E9;color:#2E7D32">✅</div>
    </div>
  </div>

  <!-- LISTE DES DEMANDES -->
  <div class="card fadeUp">
    <div class="card-header">
      <span class="card-header-icon">📋</span>
      <span class="card-header-title">Demandes de retrait à traiter</span>
    </div>
    <div class="card-body">
      <?php if (empty($demandes_list)): ?>
      <div class="empty-state">
        <div class="empty-icon">✅</div>
        <div>Aucune demande de retrait en attente</div>
        <div style="font-size:12px; margin-top:8px;">Toutes les demandes ont été traitées</div>
      </div>
      <?php else: ?>
      <div class="table-responsive">
         <table>
          <thead>
             <tr>
              <th>Date</th>
              <th>Membre</th>
              <th>Compte</th>
              <th>Montant</th>
              <th>Canal</th>
              <th>Motif</th>
              <th>Actions</th>
             </tr>
          </thead>
          <tbody>
            <?php foreach ($demandes_list as $d): 
                $canal_label = match($d['canal']) {
                    'especes' => '🏢 Bureau',
                    'virement' => '🏦 Virement',
                    'mobile_money' => '📱 Mobile Money',
                    default => $d['canal']
                };
                $canal_class = match($d['canal']) {
                    'especes' => 'badge-especes',
                    'virement' => 'badge-virement',
                    'mobile_money' => 'badge-mobile',
                    default => ''
                };
            ?>
            <tr>
              <td style="white-space:nowrap"><?php echo date('d/m/Y H:i', strtotime($d['created_at'])); ?></td>
              <td>
                <div style="font-weight:600"><?php echo htmlspecialchars($d['prenoms'] . ' ' . $d['nom']); ?></div>
                <div style="font-size:11px;color:var(--text-light)"><?php echo htmlspecialchars($d['numero_membre']); ?></div>
              </td>
              <td>
                <div><?php echo htmlspecialchars($d['compte_libelle']); ?></div>
                <div style="font-size:11px;color:var(--text-light)"><?php echo htmlspecialchars($d['numero_compte']); ?></div>
              </td>
              <td class="montant"><?php echo number_format($d['montant'], 0, ',', ' '); ?> F</td>
              <td><span class="badge <?php echo $canal_class; ?>"><?php echo $canal_label; ?></span></td>
              <td style="max-width:150px; word-break:break-word;">
                <?php echo !empty($d['motif']) ? nl2br(htmlspecialchars(substr($d['motif'], 0, 50))) : '<span style="color:var(--text-light);">—</span>'; ?>
              </td>
              <td style="white-space:nowrap">
                <button class="btn-valider" onclick="validerRetrait(<?php echo $d['id']; ?>, <?php echo $d['montant']; ?>, '<?php echo htmlspecialchars($d['prenoms'] . ' ' . $d['nom']); ?>')">✅ Valider</button>
                <button class="btn-rejeter" onclick="rejeterRetrait(<?php echo $d['id']; ?>, '<?php echo htmlspecialchars($d['prenoms'] . ' ' . $d['nom']); ?>')">❌ Rejeter</button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
         </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

</main>

<!-- MODAL VALIDATION -->
<div class="modal-overlay" id="modalValidation">
  <div class="modal">
    <div class="modal-title">✅ Valider le retrait</div>
    <div class="modal-body">
      <p id="validationMessage"></p>
      <div style="background:var(--bg); padding:12px; border-radius:8px; margin-top:12px;">
        <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
          <span>Membre :</span>
          <strong id="validationMembre"></strong>
        </div>
        <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
          <span>Montant :</span>
          <strong id="validationMontant" style="color:var(--gold);"></strong>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <form method="POST" id="formValidation" style="width:100%; display:flex; gap:12px">
        <input type="hidden" name="action" value="valider">
        <input type="hidden" name="demande_id" id="validationDemandeId">
        <button type="submit" class="btn-primary">✅ Confirmer</button>
        <button type="button" class="btn-secondary" onclick="closeModals()">Annuler</button>
      </form>
    </div>
  </div>
</div>

<!-- MODAL REJET -->
<div class="modal-overlay" id="modalRejet">
  <div class="modal">
    <div class="modal-title">❌ Rejeter le retrait</div>
    <form method="POST" id="formRejet">
      <input type="hidden" name="action" value="rejeter">
      <input type="hidden" name="demande_id" id="rejetDemandeId">
      <div class="modal-body">
        <p id="rejetMessage"></p>
        <div class="form-group">
          <label>Motif du rejet *</label>
          <textarea name="motif" id="motifRejet" placeholder="Indiquez la raison du rejet (solde insuffisant, document manquant, etc.)" required></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn-danger">❌ Confirmer le rejet</button>
        <button type="button" class="btn-secondary" onclick="closeModals()">Annuler</button>
      </div>
    </form>
  </div>
</div>

<script>
function validerRetrait(id, montant, nom) {
  const modal = document.getElementById('modalValidation');
  document.getElementById('validationMessage').innerHTML = `Confirmer la validation du retrait de <strong>${nom}</strong> ?`;
  document.getElementById('validationMembre').textContent = nom;
  document.getElementById('validationMontant').textContent = new Intl.NumberFormat('fr-FR').format(montant) + ' F';
  document.getElementById('validationDemandeId').value = id;
  modal.classList.add('open');
}

function rejeterRetrait(id, nom) {
  const modal = document.getElementById('modalRejet');
  document.getElementById('rejetMessage').innerHTML = `Rejeter la demande de retrait de <strong>${nom}</strong> ?`;
  document.getElementById('rejetDemandeId').value = id;
  document.getElementById('motifRejet').value = '';
  modal.classList.add('open');
}

function closeModals() {
  document.getElementById('modalValidation').classList.remove('open');
  document.getElementById('modalRejet').classList.remove('open');
}

document.querySelectorAll('.modal-overlay').forEach(overlay => {
  overlay.addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
  });
});

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