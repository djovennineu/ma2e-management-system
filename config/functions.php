<?php
/**
 * config/functions.php
 * Fonctions communes pour MA2E
 */

/**
 * Calcule la date de valeur pour un compte Ordinaire
 * @param string $date_operation Date de l'opération (Y-m-d)
 * @param string $type 'depot' ou 'retrait'
 * @return string Date de valeur au format Y-m-d
 */
function calculerDateValeurOrdinaire($date_operation, $type) {
    $date = new DateTime($date_operation);
    $jour = (int)$date->format('d');
    $mois = (int)$date->format('m');
    $annee = (int)$date->format('Y');
    
    if ($type === 'depot' || $type === 'depot_mobile') {
        // Dépôt du 01 au 15 → valeur au 16 du mois
        if ($jour <= 15) {
            return date('Y-m-d', mktime(0, 0, 0, $mois, 16, $annee));
        } 
        // Dépôt du 16 au 31 → valeur au 1er du mois suivant
        else {
            return date('Y-m-d', mktime(0, 0, 0, $mois + 1, 1, $annee));
        }
    } 
    elseif ($type === 'retrait') {
        // Retrait du 01 au 15 → valeur au 1er du mois
        if ($jour <= 15) {
            return date('Y-m-d', mktime(0, 0, 0, $mois, 1, $annee));
        } 
        // Retrait du 16 au 31 → valeur au 16 du mois
        else {
            return date('Y-m-d', mktime(0, 0, 0, $mois, 16, $annee));
        }
    }
    
    // Par défaut, date de valeur = date d'opération
    return $date_operation;
}

/**
 * Calcule la date de valeur pour tous les types de comptes
 * @param string $date_operation Date de l'opération
 * @param string $type_code Code du type de compte (expresse, ordinaire, etc.)
 * @param string $type_mouvement 'depot', 'depot_mobile' ou 'retrait'
 * @return string Date de valeur
 */
function calculerDateValeur($date_operation, $type_code, $type_mouvement) {
    if ($type_code === 'ordinaire') {
        return calculerDateValeurOrdinaire($date_operation, $type_mouvement);
    }
    // Pour les autres comptes, date de valeur = date d'opération
    return $date_operation;
}

/**
 * Formate un nombre en F CFA
 * @param float $montant
 * @return string
 */
function formatMontant($montant) {
    return number_format($montant, 0, ',', ' ') . ' F CFA';
}

/**
 * Génère une référence unique
 * @param string $prefix
 * @return string
 */
function genererReference($prefix) {
    return $prefix . '-' . date('YmdHis') . '-' . rand(100, 999);
}
?>