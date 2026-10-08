<?php
require_once '../connexion/auth_check.php';
checkRole('membre');
require_once '../config/database.php';

$membre_id = $_SESSION['membre_id'];

// Récupérer toutes les notifications
$notifications = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE membre_id = ? 
    ORDER BY date_creation DESC
");
$notifications->execute([$membre_id]);
$notifications_list = $notifications->fetchAll(PDO::FETCH_ASSOC);

// Marquer toutes comme lues
$pdo->prepare("
    UPDATE notifications 
    SET est_lu = 1, date_lecture = NOW() 
    WHERE membre_id = ? AND est_lu = 0
")->execute([$membre_id]);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes notifications — MA2E</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'DM Sans',sans-serif;background:#F0EDE8;padding:20px}
        .container{max-width:800px;margin:0 auto}
        .header{background:#1a1a1a;padding:20px;border-radius:16px;color:white;margin-bottom:20px}
        .notif-card{background:white;border-radius:12px;margin-bottom:12px;padding:16px;border-left:4px solid #C9A84C}
        .notif-card.refus{border-left-color:#F44336}
        .notif-title{font-weight:600;margin-bottom:6px}
        .notif-message{font-size:13px;color:#666;margin-bottom:8px}
        .notif-date{font-size:11px;color:#999}
        .btn-back{display:inline-block;margin-top:20px;color:#C9A84C;text-decoration:none}
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📬 Mes notifications</h1>
            <p style="color:#ccc; margin-top:5px;">Historique de toutes vos notifications</p>
        </div>
        
        <?php if (empty($notifications_list)): ?>
        <div style="text-align:center; padding:60px; background:white; border-radius:16px;">
            <div style="font-size:48px; margin-bottom:16px;">📭</div>
            <p>Aucune notification</p>
        </div>
        <?php else: ?>
            <?php foreach ($notifications_list as $notif): ?>
            <div class="notif-card <?php echo $notif['type'] === 'refus' ? 'refus' : ''; ?>">
                <div class="notif-title"><?php echo htmlspecialchars($notif['titre']); ?></div>
                <div class="notif-message"><?php echo nl2br(htmlspecialchars($notif['message'])); ?></div>
                <div class="notif-date"><?php echo date('d/m/Y H:i', strtotime($notif['date_creation'])); ?></div>
                <?php if ($notif['lien']): ?>
                <a href="<?php echo $notif['lien']; ?>" style="display:inline-block; margin-top:10px; color:#C9A84C; text-decoration:none; font-size:12px;">Voir le détail →</a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <a href="espace_membre.php" class="btn-back">← Retour à mon espace</a>
    </div>
</body>
</html>