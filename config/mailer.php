<?php
/**
 * mailer.php — Configuration PHPMailer MA2E
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/env.php';

// Adapter le chemin selon votre installation de PHPMailer
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';

// ============================================================
// CONFIGURATION SMTP
// ============================================================
define('SMTP_HOST',      ma2eEnv('MA2E_SMTP_HOST', 'smtp.gmail.com'));
define('SMTP_PORT',      (int)ma2eEnv('MA2E_SMTP_PORT', '587'));
define('SMTP_USERNAME',  ma2eEnv('MA2E_SMTP_USERNAME'));
define('SMTP_PASSWORD',  ma2eEnv('MA2E_SMTP_PASSWORD'));
define('SMTP_FROM',      ma2eEnv('MA2E_SMTP_FROM', SMTP_USERNAME));
define('SMTP_FROM_NAME', ma2eEnv('MA2E_SMTP_FROM_NAME', 'MA2E — Mutuelle'));

/**
 * Crée et retourne une instance PHPMailer configurée
 */
function creerMailer(): PHPMailer {
    if (SMTP_USERNAME === '' || SMTP_PASSWORD === '') {
        throw new RuntimeException('Configurez les variables SMTP dans le fichier .env local.');
    }

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USERNAME;
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
    return $mail;
}

// ============================================================
// EMAIL : DEMANDE DE CRÉDIT APPROUVÉE
// ============================================================
function envoyerEmailCreditApprouve(
    string $email,
    string $nom,
    string $prenoms,
    string $type_credit,
    float $montant,
    int $duree,
    float $mensualite,
    float $taux,
    string $numero_credit
): bool {
    try {
        $mail = creerMailer();
        $mail->addAddress($email, $nom . ' ' . $prenoms);
        $mail->Subject = '✅ Votre demande de crédit MA2E a été approuvée';
        $mail->isHTML(true);
        
        $montant_fmt = number_format($montant, 0, ',', ' ');
        $mensualite_fmt = number_format($mensualite, 0, ',', ' ');
        
        $mail->Body = "
<!DOCTYPE html>
<html lang='fr'>
<head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f4f4f4;padding:30px 0'>
<tr><td align='center'>
<table width='600' cellpadding='0' cellspacing='0' style='background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.1)'>

<tr><td style='background:linear-gradient(135deg,#1a1a2e 0%,#C9A84C 100%);padding:40px 40px 30px;text-align:center'>
    <h1 style='margin:0;color:#ffffff;font-size:28px;font-weight:800;letter-spacing:2px'>MA2E</h1>
    <p style='margin:8px 0 0;color:rgba(255,255,255,0.8);font-size:13px'>Mutuelle des Agents de l'Eau et de l'Électricité</p>
</td></tr>

<tr><td style='padding:30px 40px 0;text-align:center'>
    <div style='width:70px;height:70px;background:#E8F5E9;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px'>
        <span style='font-size:36px'>✅</span>
    </div>
    <h2 style='margin:0 0 8px;color:#1a1a1a;font-size:22px;font-weight:700'>Crédit approuvé !</h2>
    <p style='margin:0;color:#666;font-size:15px'>Votre demande de crédit a été <strong style='color:#4CAF50'>acceptée</strong></p>
</td></tr>

<tr><td style='padding:28px 40px'>
    <p style='margin:0 0 20px;color:#333;font-size:15px;line-height:1.6'>
        Bonjour <strong>$prenoms $nom</strong>,<br><br>
        Nous avons le plaisir de vous informer que votre demande de <strong>$type_credit</strong> a été approuvée.
    </p>
    <table width='100%' cellpadding='0' cellspacing='0' style='background:#F5EDD6;border-radius:10px;overflow:hidden;margin-bottom:24px'>
        <tr><td style='background:#C9A84C;padding:12px 20px'>
            <p style='margin:0;color:#fff;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px'>💰 Détails du crédit</p>
        </td></tr>
        <tr><td style='padding:20px'>
            <table width='100%'>
                <tr><td style='padding:8px 0;border-bottom:1px solid rgba(201,168,76,0.3);color:#666'>Numéro de crédit</td>
                    <td style='padding:8px 0;border-bottom:1px solid rgba(201,168,76,0.3);text-align:right;font-weight:700'>$numero_credit</td></tr>
                <tr><td style='padding:8px 0;border-bottom:1px solid rgba(201,168,76,0.3);color:#666'>Montant accordé</td>
                    <td style='padding:8px 0;border-bottom:1px solid rgba(201,168,76,0.3);text-align:right;font-weight:700;color:#C9A84C'>$montant_fmt F</td></tr>
                <tr><td style='padding:8px 0;border-bottom:1px solid rgba(201,168,76,0.3);color:#666'>Durée</td>
                    <td style='padding:8px 0;border-bottom:1px solid rgba(201,168,76,0.3);text-align:right;font-weight:700'>$duree mois</td></tr>
                <tr><td style='padding:8px 0;border-bottom:1px solid rgba(201,168,76,0.3);color:#666'>Mensualité</td>
                    <td style='padding:8px 0;border-bottom:1px solid rgba(201,168,76,0.3);text-align:right;font-weight:700;color:#C9A84C'>$mensualite_fmt F</td></tr>
                <tr><td style='padding:8px 0;color:#666'>Taux annuel</td>
                    <td style='padding:8px 0;text-align:right;font-weight:700'>$taux%</td></tr>
            </table>
        </td></tr>
    </table>
    <p style='margin:0;color:#333;font-size:14px;line-height:1.7'>
        Votre crédit a été mis en place. Les remboursements commenceront selon les modalités convenues.
    </p>
</td></tr>

<tr><td style='background:#1a1a1a;padding:24px 40px;text-align:center'>
    <p style='margin:0 0 8px;color:#C9A84C;font-size:13px;font-weight:700'>MA2E — Mutuelle des Agents de l'Eau et de l'Électricité</p>
    <p style='margin:0 0 4px;color:#888;font-size:12px'>📍 Abidjan — Plateau, Immeuble SIDAM</p>
    <p style='margin:0 0 4px;color:#888;font-size:12px'>📞 +225 27 21 23 64 84 &nbsp;|&nbsp; ✉️ info@ma2e.ci</p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>";
        
        $mail->AltBody = "MA2E - Crédit approuvé\n\nMontant: $montant_fmt F\nDurée: $duree mois\nMensualité: $mensualite_fmt F";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Erreur envoi email crédit approuvé: " . $e->getMessage());
        return false;
    }
}

// ============================================================
// EMAIL : DEMANDE DE CRÉDIT REFUSÉE
// ============================================================
function envoyerEmailCreditRefuse(
    string $email,
    string $nom,
    string $prenoms,
    string $type_credit,
    float $montant,
    string $motif
): bool {
    try {
        $mail = creerMailer();
        $mail->addAddress($email, $nom . ' ' . $prenoms);
        $mail->Subject = '❌ Votre demande de crédit MA2E';
        $mail->isHTML(true);
        
        $montant_fmt = number_format($montant, 0, ',', ' ');
        
        $mail->Body = "
<!DOCTYPE html>
<html lang='fr'>
<head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f4f4f4;padding:30px 0'>
<tr><td align='center'>
<table width='600' cellpadding='0' cellspacing='0' style='background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.1)'>

<tr><td style='background:linear-gradient(135deg,#1a1a2e 0%,#C9A84C 100%);padding:40px 40px 30px;text-align:center'>
    <h1 style='margin:0;color:#ffffff;font-size:28px;font-weight:800;letter-spacing:2px'>MA2E</h1>
    <p style='margin:8px 0 0;color:rgba(255,255,255,0.8);font-size:13px'>Mutuelle des Agents de l'Eau et de l'Électricité</p>
</td></tr>

<tr><td style='padding:36px 40px'>
    <p style='margin:0 0 20px;color:#333;font-size:15px;line-height:1.7'>
        Bonjour <strong>$prenoms $nom</strong>,<br><br>
        Nous faisons suite à votre demande de <strong>$type_credit</strong> d'un montant de <strong>$montant_fmt F</strong>.
    </p>
    <table width='100%' cellpadding='0' cellspacing='0' style='background:#FFF3F3;border-left:4px solid #F44336;border-radius:0 8px 8px 0;margin-bottom:28px'>
        <tr><td style='padding:16px 20px'>
            <p style='margin:0 0 8px;color:#C62828;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px'>Motif du refus</p>
            <p style='margin:0;color:#555;font-size:14px;line-height:1.6'>$motif</p>
        </td></tr>
    </table>
    <p style='margin:0;color:#333;font-size:14px;line-height:1.7'>
        Vous pouvez à tout moment faire une nouvelle demande de crédit en tenant compte de ce motif.
    </p>
</td></tr>

<tr><td style='background:#1a1a1a;padding:24px 40px;text-align:center'>
    <p style='margin:0 0 8px;color:#C9A84C;font-size:13px;font-weight:700'>MA2E — Mutuelle des Agents de l'Eau et de l'Électricité</p>
    <p style='margin:0 0 4px;color:#888;font-size:12px'>📍 Abidjan — Plateau, Immeuble SIDAM</p>
    <p style='margin:0 0 4px;color:#888;font-size:12px'>📞 +225 27 21 23 64 84 &nbsp;|&nbsp; ✉️ info@ma2e.ci</p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>";
        
        $mail->AltBody = "MA2E - Demande de crédit refusée\n\nMotif: $motif";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Erreur envoi email crédit refusé: " . $e->getMessage());
        return false;
    }
}

// ============================================================
// EMAIL : DEMANDE DE RETRAIT VALIDÉE
// ============================================================
function envoyerEmailRetraitValide(
    string $email,
    string $nom,
    string $prenoms,
    float $montant,
    string $reference,
    string $canal
): bool {
    try {
        $mail = creerMailer();
        $mail->addAddress($email, $nom . ' ' . $prenoms);
        $mail->Subject = '✅ Votre demande de retrait MA2E a été validée';
        $mail->isHTML(true);
        
        $montant_fmt = number_format($montant, 0, ',', ' ');
        
        $mail->Body = "
<!DOCTYPE html>
<html lang='fr'>
<head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f4f4f4;padding:30px 0'>
<tr><td align='center'>
<table width='600' cellpadding='0' cellspacing='0' style='background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.1)'>

<tr><td style='background:linear-gradient(135deg,#1a1a2e 0%,#C9A84C 100%);padding:40px 40px 30px;text-align:center'>
    <h1 style='margin:0;color:#ffffff;font-size:28px;font-weight:800;letter-spacing:2px'>MA2E</h1>
    <p style='margin:8px 0 0;color:rgba(255,255,255,0.8);font-size:13px'>Mutuelle des Agents de l'Eau et de l'Électricité</p>
</td></tr>

<tr><td style='padding:30px 40px 0;text-align:center'>
    <div style='width:70px;height:70px;background:#E8F5E9;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px'>
        <span style='font-size:36px'>✅</span>
    </div>
    <h2 style='margin:0 0 8px;color:#1a1a1a;font-size:22px;font-weight:700'>Retrait validé !</h2>
</td></tr>

<tr><td style='padding:28px 40px'>
    <p style='margin:0 0 20px;color:#333;font-size:15px;line-height:1.6'>
        Bonjour <strong>$prenoms $nom</strong>,<br><br>
        Votre demande de retrait a été validée.
    </p>
    <table width='100%' cellpadding='0' cellspacing='0' style='background:#F5EDD6;border-radius:10px;overflow:hidden;margin-bottom:24px'>
        <tr><td style='background:#C9A84C;padding:12px 20px'>
            <p style='margin:0;color:#fff;font-size:13px;font-weight:700'>💸 Détails du retrait</p>
        </td></tr>
        <tr><td style='padding:20px'>
            <table width='100%'>
                <tr><td style='padding:8px 0;border-bottom:1px solid rgba(201,168,76,0.3);color:#666'>Référence</td>
                    <td style='padding:8px 0;border-bottom:1px solid rgba(201,168,76,0.3);text-align:right;font-weight:700'>$reference</td></tr>
                <tr><td style='padding:8px 0;border-bottom:1px solid rgba(201,168,76,0.3);color:#666'>Montant</td>
                    <td style='padding:8px 0;border-bottom:1px solid rgba(201,168,76,0.3);text-align:right;font-weight:700;color:#C9A84C'>$montant_fmt F</td></tr>
                <tr><td style='padding:8px 0;color:#666'>Mode de récupération</td>
                    <td style='padding:8px 0;text-align:right;font-weight:700'>$canal</td></tr>
            </table>
        </td></tr>
    </table>
    <p style='margin:0;color:#333;font-size:14px;line-height:1.7'>Votre argent est disponible selon le mode choisi.</p>
</td></tr>

<tr><td style='background:#1a1a1a;padding:24px 40px;text-align:center'>
    <p style='margin:0 0 4px;color:#888;font-size:12px'>📞 +225 27 21 23 64 84 &nbsp;|&nbsp; ✉️ info@ma2e.ci</p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>";
        
        $mail->AltBody = "MA2E - Retrait validé\n\nMontant: $montant_fmt F";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Erreur envoi email retrait validé: " . $e->getMessage());
        return false;
    }
}

// ============================================================
// EMAIL : REMBOURSEMENT ENREGISTRÉ
// ============================================================
function envoyerEmailRemboursement(
    string $email,
    string $nom,
    string $prenoms,
    float $montant,
    float $capital_restant,
    string $numero_credit
): bool {
    try {
        $mail = creerMailer();
        $mail->addAddress($email, $nom . ' ' . $prenoms);
        $mail->Subject = '💰 Remboursement de crédit MA2E';
        $mail->isHTML(true);
        
        $montant_fmt = number_format($montant, 0, ',', ' ');
        $capital_fmt = number_format($capital_restant, 0, ',', ' ');
        
        $mail->Body = "
<!DOCTYPE html>
<html lang='fr'>
<head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f4f4f4;padding:30px 0'>
<tr><td align='center'>
<table width='600' cellpadding='0' cellspacing='0' style='background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.1)'>

<tr><td style='background:linear-gradient(135deg,#1a1a2e 0%,#C9A84C 100%);padding:40px 40px 30px;text-align:center'>
    <h1 style='margin:0;color:#ffffff;font-size:28px;font-weight:800;letter-spacing:2px'>MA2E</h1>
</td></tr>

<tr><td style='padding:28px 40px'>
    <p style='margin:0 0 20px;color:#333;font-size:15px;line-height:1.6'>
        Bonjour <strong>$prenoms $nom</strong>,<br><br>
        Un remboursement a été enregistré sur votre crédit <strong>$numero_credit</strong>.
    </p>
    <table width='100%' cellpadding='0' cellspacing='0' style='background:#F5EDD6;border-radius:10px;overflow:hidden;margin-bottom:24px'>
        <tr><td style='background:#C9A84C;padding:12px 20px'>
            <p style='margin:0;color:#fff;font-size:13px;font-weight:700'>📊 Récapitulatif</p>
        </td></tr>
        <tr><td style='padding:20px'>
            <table width='100%'>
                <tr><td style='padding:8px 0;border-bottom:1px solid rgba(201,168,76,0.3);color:#666'>Montant remboursé</td>
                    <td style='padding:8px 0;border-bottom:1px solid rgba(201,168,76,0.3);text-align:right;font-weight:700;color:#C9A84C'>$montant_fmt F</td></tr>
                <tr><td style='padding:8px 0;color:#666'>Capital restant</td>
                    <td style='padding:8px 0;text-align:right;font-weight:700'>$capital_fmt F</td></tr>
            </table>
        </td></tr>
    </table>
    <p style='margin:0;color:#333;font-size:14px;line-height:1.7'>Vous pouvez suivre l'évolution de votre crédit dans votre espace membre.</p>
</td></tr>

<tr><td style='background:#1a1a1a;padding:24px 40px;text-align:center'>
    <p style='margin:0 0 4px;color:#888;font-size:12px'>📞 +225 27 21 23 64 84 &nbsp;|&nbsp; ✉️ info@ma2e.ci</p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>";
        
        $mail->AltBody = "MA2E - Remboursement de crédit\n\nMontant remboursé: $montant_fmt F\nCapital restant: $capital_fmt F";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Erreur envoi email remboursement: " . $e->getMessage());
        return false;
    }
}
?>
