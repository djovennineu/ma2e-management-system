<?php
require_once '../connexion/auth_check.php';
checkRole('gestionnaire_immobilier');
require_once '../config/database.php';

$success = '';
$error = '';
$edit_id = (int)($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['create', 'update'], true)) {
        $id = (int)($_POST['id'] ?? 0);
        $site_id = (int)($_POST['site_id'] ?? 0);
        $libelle = trim($_POST['libelle'] ?? '');
        $superficie_m2 = $_POST['superficie_m2'] !== '' ? (float)$_POST['superficie_m2'] : null;
        $prix_total = (float)($_POST['prix_total'] ?? 0);
        $apport_obligatoire = (float)($_POST['apport_obligatoire'] ?? 0);
        $nombre_total = (int)($_POST['nombre_total'] ?? 0);
        $nombre_disponible = (int)($_POST['nombre_disponible'] ?? 0);
        $duree_credit_mois = $_POST['duree_credit_mois'] !== '' ? (int)$_POST['duree_credit_mois'] : null;
        $taux_credit = $_POST['taux_credit'] !== '' ? (float)$_POST['taux_credit'] : null;

        if ($site_id <= 0 || $libelle === '' || $prix_total <= 0 || $apport_obligatoire < 0 || $nombre_total < 0 || $nombre_disponible < 0) {
            $error = "Merci de renseigner correctement les champs obligatoires.";
        } elseif ($nombre_disponible > $nombre_total) {
            $error = "Le nombre disponible ne peut pas depasser le nombre total.";
        } else {
            try {
                if ($action === 'create') {
                    $pdo->prepare("
                        INSERT INTO types_logement
                            (site_id, libelle, superficie_m2, prix_total, apport_obligatoire,
                             nombre_total, nombre_disponible, duree_credit_mois, taux_credit)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $site_id, $libelle, $superficie_m2, $prix_total, $apport_obligatoire,
                        $nombre_total, $nombre_disponible, $duree_credit_mois, $taux_credit
                    ]);
                    $success = "Type de logement ajoute avec succes.";
                } else {
                    $pdo->prepare("
                        UPDATE types_logement
                        SET site_id = ?, libelle = ?, superficie_m2 = ?, prix_total = ?,
                            apport_obligatoire = ?, nombre_total = ?, nombre_disponible = ?,
                            duree_credit_mois = ?, taux_credit = ?
                        WHERE id = ?
                    ")->execute([
                        $site_id, $libelle, $superficie_m2, $prix_total,
                        $apport_obligatoire, $nombre_total, $nombre_disponible,
                        $duree_credit_mois, $taux_credit, $id
                    ]);
                    $success = "Type de logement mis a jour avec succes.";
                    $edit_id = 0;
                }
            } catch (Exception $e) {
                $error = "Erreur lors de l'enregistrement : " . $e->getMessage();
            }
        }
    }
}

$sites = $pdo->query("SELECT * FROM sites_immobiliers ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$types = $pdo->query("
    SELECT tl.*, si.nom AS site_nom, si.localite
    FROM types_logement tl
    JOIN sites_immobiliers si ON si.id = tl.site_id
    ORDER BY si.nom, tl.libelle
")->fetchAll(PDO::FETCH_ASSOC);

$type_edit = null;
if ($edit_id > 0) {
    $edit_stmt = $pdo->prepare("SELECT * FROM types_logement WHERE id = ?");
    $edit_stmt->execute([$edit_id]);
    $type_edit = $edit_stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestion logements — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
:root{--gold:#C9A84C;--gold-dark:#b8922e;--bg:#F4F2EE;--card:#fff;--text:#1f1f1f;--muted:#777;--border:#e9e3d9;--green:#4CAF50;--red:#F44336}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text)}
.page{max-width:1200px;margin:0 auto;padding:28px}
.top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:20px}
.title{font-family:'Playfair Display',serif;font-size:30px}
.sub{color:var(--muted);font-size:14px;margin-top:4px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 16px;border-radius:12px;text-decoration:none;font-weight:700}
.btn-primary{background:var(--gold);color:white}
.card{background:var(--card);border:1px solid var(--border);border-radius:18px;overflow:hidden}
.card-header{padding:18px 20px;border-bottom:1px solid var(--border);font-weight:700}
.card-body{padding:20px}
.grid{display:grid;grid-template-columns:380px 1fr;gap:20px}
.alert{padding:14px 16px;border-radius:12px;margin-bottom:16px;font-size:14px}
.alert-success{background:#E8F5E9;color:#1B5E20}
.alert-error{background:#FFEBEE;color:#B71C1C}
.form-group{margin-bottom:14px}
.form-group label{display:block;font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:700;margin-bottom:6px}
.form-group input,.form-group select{width:100%;padding:12px 14px;border:1.5px solid var(--border);border-radius:12px;font:inherit;background:#faf8f3}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
table{width:100%;border-collapse:collapse}
th,td{padding:12px 14px;text-align:left;border-bottom:1px solid var(--border);font-size:13px}
th{background:#faf8f3;color:var(--muted);text-transform:uppercase;font-size:11px}
.actions a{color:#8a6a1d;text-decoration:none;font-weight:700}
@media(max-width:960px){.grid{grid-template-columns:1fr}.form-row{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="page">
  <div class="top">
    <div>
      <div class="title">Gestion des logements</div>
      <div class="sub">Tu geres ici les types de logement, le stock disponible et les parametres de financement.</div>
    </div>
    <a href="dashboard_gestionnaire_immo.php" class="btn btn-primary">Retour dashboard</a>
  </div>

  <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

  <div class="grid">
    <section class="card">
      <div class="card-header"><?php echo $type_edit ? 'Modifier un type de logement' : 'Ajouter un type de logement'; ?></div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="action" value="<?php echo $type_edit ? 'update' : 'create'; ?>">
          <input type="hidden" name="id" value="<?php echo (int)($type_edit['id'] ?? 0); ?>">
          <div class="form-group">
            <label>Site</label>
            <select name="site_id" required>
              <option value="">Choisir un site</option>
              <?php foreach ($sites as $site): ?>
              <option value="<?php echo (int)$site['id']; ?>" <?php echo ((int)($type_edit['site_id'] ?? 0) === (int)$site['id']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($site['nom']); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Libelle</label>
            <input type="text" name="libelle" required value="<?php echo htmlspecialchars($type_edit['libelle'] ?? ''); ?>">
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Superficie (m2)</label>
              <input type="number" step="0.01" name="superficie_m2" value="<?php echo htmlspecialchars((string)($type_edit['superficie_m2'] ?? '')); ?>">
            </div>
            <div class="form-group">
              <label>Prix total</label>
              <input type="number" step="0.01" name="prix_total" required value="<?php echo htmlspecialchars((string)($type_edit['prix_total'] ?? '')); ?>">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Apport obligatoire</label>
              <input type="number" step="0.01" name="apport_obligatoire" required value="<?php echo htmlspecialchars((string)($type_edit['apport_obligatoire'] ?? '')); ?>">
            </div>
            <div class="form-group">
              <label>Duree credit (mois)</label>
              <input type="number" name="duree_credit_mois" value="<?php echo htmlspecialchars((string)($type_edit['duree_credit_mois'] ?? '')); ?>">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Taux credit (%)</label>
              <input type="number" step="0.01" name="taux_credit" value="<?php echo htmlspecialchars((string)($type_edit['taux_credit'] ?? '')); ?>">
            </div>
            <div class="form-group">
              <label>Nombre total</label>
              <input type="number" name="nombre_total" required value="<?php echo htmlspecialchars((string)($type_edit['nombre_total'] ?? '0')); ?>">
            </div>
          </div>
          <div class="form-group">
            <label>Nombre disponible</label>
            <input type="number" name="nombre_disponible" required value="<?php echo htmlspecialchars((string)($type_edit['nombre_disponible'] ?? '0')); ?>">
          </div>
          <button type="submit" class="btn btn-primary"><?php echo $type_edit ? 'Mettre a jour' : 'Ajouter'; ?></button>
        </form>
      </div>
    </section>

    <section class="card">
      <div class="card-header">Catalogue actuel</div>
      <div class="card-body" style="overflow:auto">
        <table>
          <thead>
            <tr>
              <th>Site</th>
              <th>Libelle</th>
              <th>Prix</th>
              <th>Apport</th>
              <th>Stock</th>
              <th>Credit</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($types as $type): ?>
            <tr>
              <td><?php echo htmlspecialchars($type['site_nom']); ?></td>
              <td><?php echo htmlspecialchars($type['libelle']); ?></td>
              <td><?php echo number_format($type['prix_total'], 0, ',', ' '); ?> F</td>
              <td><?php echo number_format($type['apport_obligatoire'], 0, ',', ' '); ?> F</td>
              <td><?php echo (int)$type['nombre_disponible']; ?> / <?php echo (int)$type['nombre_total']; ?></td>
              <td><?php echo (int)($type['duree_credit_mois'] ?: 168); ?> mois · <?php echo number_format((float)($type['taux_credit'] ?: 7.00), 2, ',', ' '); ?>%</td>
              <td class="actions"><a href="?edit=<?php echo (int)$type['id']; ?>">Modifier</a></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</div>
</body>
</html>
