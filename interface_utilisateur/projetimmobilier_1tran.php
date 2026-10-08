<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>
<body>
    <style>
                        /* ===================== */
/* STYLES GLOBAUX        */
/* ===================== */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    overflow-x: hidden;
    background-color: #f4f4f4;
}

/* ===================== */
/* NAVBAR PRINCIPALE     */
/* ===================== */
.navbar {
    position: fixed; /* Reste collée en haut */
    top: 0;
    left: 0;
    width: 100%;
    height: 80px; /* On fixe une hauteur */
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 5%; /* Réduit le padding pour ne pas manger tout l'écran */
    background: url('../photo/mrargent.png') no-repeat center center;
    background-size: cover;
    border-bottom: 2px solid #000;
    z-index: 2000; /* Très haut pour passer devant tout */
    transition: all 0.3s ease; /* Pour l'animation au scroll */
}

/* IMPORTANT : Ajouter une marge au body pour ne pas cacher le haut de ta page */
body {
    padding-top: 80px; 
}

.navbar-brand img {
    width: 140px;
    height: auto;
}

/* ===================== */
/* MENU CAPSULE (Desktop)*/
/* ===================== */
.navbar-menu {
    position: absolute;
    top: 100%; /* Se place juste en dessous de la navbar */
    left: 50%;
    transform: translateX(-50%);
    background-color: rgba(30, 30, 30, 0.95);
    border-radius: 50px;
    padding: 5px 25px;
    z-index: 1000;
    margin-top: -20px; /* Effet de chevauchement sur l'image */
}

.arii-menu {
    display: flex;
    list-style: none;
    align-items: center;
}

.arii-menu > li > a {
    display: block;
    padding: 15px 18px;
    color: #ffffff;
    text-decoration: none;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    transition: color 0.3s ease;
}

.arii-menu > li > a:hover {
    color: #90F876;
}

/* ===================== */
/* SOUS-MENUS (Desktop)  */
/* ===================== */
.submenu {
    position: absolute;
    top: 100%;
    left: 0;
    min-width: 220px;
    background-color: #ffffff;
    border-radius: 8px;
    box-shadow: 0 8px 16px rgba(0,0,0,0.2);
    list-style: none;
    display: none; /* Caché par défaut */
    padding: 10px 0;
    z-index: 1001;
}

.submenu a {
    padding: 10px 20px;
    color: #333;
    text-decoration: none;
    display: block;
    font-size: 13px;
    transition: background 0.2s;
}

.submenu a:hover {
    background-color: #f8f8f8;
    color: #90F876;
}

/* Affichage au survol sur PC uniquement */
@media (min-width: 993px) {
    .arii-menu li:hover > .submenu {
        display: block;
    }
    /* Sous-sous-menu (3ème niveau) */
    .submenu .has-submenu:hover > .submenu {
        left: 100%;
        top: 0;
        display: block;
    }
}

/* ===================== */
/* BOUTON ESPACE MEMBRE  */
/* ===================== */
.btn-espace-membre {
    background-color: #90F876;
    color: #000;
    border: 2px solid #000;
    border-radius: 4px;
    font-weight: 700;
    padding: 10px 18px;
    text-decoration: none;
    font-size: 12px;
    transition: all 0.3s ease;
}

.btn-espace-membre:hover {
    background-color: #000;
    color: #90F876;
}

/* ===================== */
/* RESPONSIVE (Mobile)   */
/* ===================== */

.menu-toggle {
    display: none;
    flex-direction: column;
    gap: 5px;
    cursor: pointer;
    background: none;
    border: none;
    z-index: 1100;
}
.menu-toggle span {
    width: 25px; height: 3px; background: #000; border-radius: 3px;
}

@media (max-width: 992px) {
    .menu-toggle { display: flex; }
    
    .navbar-menu {
        position: fixed;
        top: 0;
        left: -100%; /* Sidebar cachée à gauche */
        transform: none; /* Annule le centrage desktop */
        width: 280px;
        height: 100vh;
        background-color: #1e1e1e;
        border-radius: 0;
        flex-direction: column;
        padding-top: 80px;
        margin-top: 0;
        transition: left 0.4s ease;
        overflow-y: auto;
        display: block;
    }

    .navbar-menu.active { left: 0; }

    .arii-menu { flex-direction: column; align-items: flex-start; }
    .arii-menu > li { width: 100%; border-bottom: 1px solid rgba(255,255,255,0.1); }
    .arii-menu > li > a { padding: 18px 25px; font-size: 14px; }

    /* Accordéon Mobile */
    .submenu {
        position: static;
        display: none; 
        width: 100%;
        background-color: rgba(255,255,255,0.05);
        box-shadow: none;
        padding-left: 20px;
        opacity: 1; /* Force l'opacité sur mobile */
    }
    
    .arii-menu li.active > .submenu {
        display: block;
    }

    .submenu a { color: #fff; }

    .menu-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.6);
        z-index: 999;
    }
    .menu-overlay.active { display: block; }
}

/* ===================== */
/* CARDS                 */
/* ===================== */
.cards-container {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 25px;
    padding: 50px 5%;
}

.card {
    background: #fff;
    border-radius: 15px;
    flex: 1 1 calc(33.333% - 25px);
    min-width: 280px;
    max-width: 380px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    transition: 0.3s;
}

@media (max-width: 992px) {
    .navbar {
        padding: 15px 5%; /* Réduction du padding pour gagner de la place */
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    /* 1. Logo à GAUCHE */
    .navbar-brand {
        order: 1;
        flex: 1;
    }

    /* 2. Espace membre au MILIEU */
    .btn-espace-membre {
        order: 2;
        display: block; /* On le force à rester visible */
        margin: 0 auto;
        padding: 8px 12px;
        font-size: 10px; /* Un peu plus petit pour que ça tienne */
        white-space: nowrap;
    }

    /* 3. Hamburger à DROITE */
    .menu-toggle {
        display: flex;
        order: 3;
        flex: 1;
        align-items: flex-end; /* Aligne le bouton à droite */
    }

    /* Ajustement de la sidebar mobile */
    .navbar-menu {
        position: fixed;
        top: 0;
        left: -100%;
        width: 280px;
        height: 100vh;
        background-color: #1e1e1e;
        border-radius: 0;
        flex-direction: column;
        padding-top: 80px;
        margin-top: 0;
        transform: none;
        transition: left 0.4s ease;
        overflow-y: auto;
        display: block;
    }

    .navbar-menu.active {
        left: 0;
    }

    .arii-menu {
        flex-direction: column;
        align-items: flex-start;
    }

    .arii-menu > li {
        width: 100%;
        border-bottom: 1px solid rgba(255,255,255,0.1);
    }

    .submenu {
        position: static;
        display: none;
        width: 100%;
        background-color: rgba(255,255,255,0.05);
        padding-left: 20px;
    }

    .arii-menu li.active > .submenu {
        display: block;
    }
}



/* ==========================================================================
   1. CONFIGURATION DES COULEURS (Palette Noir & Vert)
   ========================================================================== */
:root {
    --primary-black: #000000;    /* Noir Institutionnel */
    --accent-green: #06cb58;     /* Vert MA2E dynamique */
    --accent-hover: #27ae60;     /* Vert croissance (survols) */
    --bg-light: #f4f7f9;         /* Fond de page gris bleuté très clair */
    --text-main: #000000;        /* Texte principal */
    --white: #ffffff;
    --border-light: #edf2f7;
}

/* ==========================================================================
   2. RÉGLAGES GLOBAUX
   ========================================================================== */
body {
    font-family: 'Poppins', 'Segoe UI', sans-serif;
    background-color: var(--bg-light);
    color: var(--text-main);
    line-height: 1.7;
    -webkit-font-smoothing: antialiased;
}

.container {
    max-width: 1140px;
    margin: 0 auto;
    padding: 0 20px;
}

/* ==========================================================================
   3. DESIGN DES TITRES & EN-TÊTES
   ========================================================================== */
.product-header {
    text-align: center;
    padding: 60px 0 40px;
}

.product-header h1 {
    color: var(--primary-black);
    font-size: 2.2rem;
    font-weight: 700;
    margin: 10px 0;
    position: relative;
    padding-bottom: 20px;
}

.product-header h1::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 80px;
    height: 5px;
    background: var(--accent-green);
    border-radius: 10px;
}

/* ==========================================================================
   4. LES CARTES (CARDS)
   ========================================================================== */
.v-card, .stat-card, .info-card, .point-card, .adv-item {
    background: var(--white);
    border-radius: 16px;
    padding: 30px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
    border: 1px solid rgba(0,0,0,0.02);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.v-card:hover, .stat-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.08);
    border-color: var(--accent-green);
}

/* ==========================================================================
   5. TABLEAUX MA2E (Le standard de données)
   ========================================================================== */
.table-responsive {
    overflow-x: auto;
    border-radius: 12px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.04);
    margin: 30px 0;
}

.ma2e-table {
    width: 100%;
    background: var(--white);
    border-collapse: collapse;
}

.ma2e-table th {
    background-color: var(--primary-black);
    color: var(--white);
    padding: 20px;
    text-transform: uppercase;
    font-size: 13px;
    font-weight: 600;
    letter-spacing: 0.5px;
    text-align: left;
}

.ma2e-table td {
    padding: 18px 20px;
    border-bottom: 1px solid var(--border-light);
}

.ma2e-table tr:hover {
    background-color: #fcfdfc;
}

.best-rate {
    background-color: #e8f5e9 !important;
    color: #1b5e20;
    font-weight: 700;
}

/* ==========================================================================
   6. LES BADGES & ÉTIQUETTES
   ========================================================================== */
.tag-gold, .tag-modern, .tag-fast, .tag-secure {
    padding: 6px 18px;
    border-radius: 50px;
    font-size: 11px;
    font-weight: 700;
    display: inline-block;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}

.tag-gold { background: var(--accent-green); color: var(--primary-black); }
.tag-modern { background: var(--primary-black); color: var(--white); }
.tag-fast { background: var(--accent-hover); color: var(--white); }
.tag-secure { border: 1px solid var(--primary-black); color: var(--primary-black); }

/* ==========================================================================
   7. SECTIONS & GRILLES (RESPONSIVE)
   ========================================================================== */
.main-content-grid, .immobilier-grid, .express-grid, .datavp-main {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 40px;
    margin-top: 40px;
}

@media (max-width: 992px) {
    .main-content-grid, .immobilier-grid, .express-grid, .datavp-main {
        grid-template-columns: 1fr;
    }
    .product-header h1 { font-size: 1.8rem; }
    .v-card { padding: 20px; }
}

/* Footer de section rapide */
.repayment-footer, .cloture-banner {
    background: var(--white);
    padding: 25px;
    border-radius: 12px;
    margin-top: 40px;
    border-left: 5px solid var(--accent-green);
    font-size: 14px;
}

/* ===================== */
/* PIED DE PAGE          */
.pied-de-page {
    background-color: #1a1a1a;
    color: #ffffff;
    padding: 60px 0 20px 0;
    font-family: sans-serif;
}

.conteneur-footer {
    max-width: 1200px;
    margin: 0 auto;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 40px;
    padding: 0 20px;
}

.section-footer h3 {
    color: #F3A93B; /* Ton orange */
    font-size: 1.2rem;
    margin-bottom: 20px;
    position: relative;
}

.footer-logo {
    max-width: 150px;
    margin-bottom: 20px;
}

/* Listes de liens */
.liens-footer, .infos-contact {
    list-style: none;
    padding: 0;
}

.liens-footer li, .infos-contact li {
    margin-bottom: 12px;
    color: #ccc;
    display: flex;
    align-items: center;
    gap: 10px;
}

.liens-footer a {
    color: #ccc;
    text-decoration: none;
    transition: color 0.3s;
}

.liens-footer a:hover {
    color: #F3A93B;
    padding-left: 5px;
}

/* Bas du footer */
.bas-footer {
    text-align: center;
    margin-top: 50px;
    padding-top: 20px;
    border-top: 1px solid #333;
    font-size: 0.9rem;
    color: #777;
}

/* Icônes */
.reseaux-sociaux a {
    color: white;
    font-size: 1.5rem;
    margin-right: 15px;
    transition: color 0.3s;
}

.reseaux-sociaux a:hover {
    color: #F3A93B;
}

/* Responsive */
@media (max-width: 768px) {
    .conteneur-footer {
        grid-template-columns: 1fr;
        text-align: center;
    }
    .infos-contact li {
        justify-content: center;
    }
}


    </style>
<div class="menu-overlay" id="menuOverlay"></div>

<nav class="navbar">
    <div class="navbar-brand">
        <img src="../photo/images.png" alt="Logo">
    </div>

    <button class="menu-toggle" id="menuToggle">
        <span></span>
        <span></span>
        <span></span>
    </button>

   <div class="navbar-menu" id="navbarMenu">
        <ul class="arii-menu">
            <li><a href="page_home.php">Accueil</a></li>
            <li class="has-submenu">
                <a href="#" class="submenu-trigger">MA2E</a>
                <ul class="submenu">
                    <li><a href="ma2e_histoie.php">Histoire</a></li>
                    <li><a href="organisation.php">Organisation</a></li>
                </ul>
            </li>
            <li class="has-submenu">
                <a href="#" class="submenu-trigger">PRODUITS</a>
                <ul class="submenu">
                    <li class="has-submenu">
                        <a href="#" class="submenu-trigger">Produits Épargne</a>
                        <ul class="submenu">
                            <li><a href="epargne_expresse.php">Épargne expresse</a></li>
                            <li><a href="epargne_ordinaire.php">Épargne ordinaire</a></li>
                            <li><a href="epargne_logement.php">Épargne logement</a></li>
                            <li><a href="epargne_depot.php">Dépôts à terme simple</a></li>
                            <li><a href="epargne_depotprogressive.php">Dépôts à terme progressifs</a></li>
                        </ul>
                    </li>
                    <li><a href="depot_de_garantie.php">Dépôt de garantie</a></li>
                    <li class="has-submenu">
                        <a href="#" class="submenu-trigger">Formules de crédit</a>
                        <ul class="submenu">
                            <li><a href="fcredit_ordinaire.php">Crédit ordinaire</a></li>
                            <li><a href="fcredit_expresse.php">Crédit expresse</a></li>
                            <li><a href="fcredit_immobilier.php">Crédit immobilier</a></li>
                            <li><a href="fcredit_immobiliermoderer.php">Crédit immobilier différé</a></li>
                        </ul>
                    </li>
                    <li class="has-submenu">
                        <a href="#" class="submenu-trigger">Projet immobilier</a>
                        <ul class="submenu">
                            <li><a href="projetimmobilier_1tran.php">1ère tranche</a></li>
                            <li><a href="projetimmobilier_2tran.php">2ème tranche</a></li>
                        </ul>
                    </li>
                </ul>
            </li>
            <li><a href="actualiter.php">ACTUALITES</a></li>
            <li><a href="contacte.php">CONTACT</a></li>
        </ul>
    </div>

    <a href="../connexion/pageconnexion.php" class="btn-espace-membre">ESPACE MEMBRE</a>
</nav>

<section class="immobilier-programmes">
    <div class="container">
        <div class="product-header">
            <span class="tag-gold">Nos Programmes Immobiliers</span>
            <h1>Devenez Propriétaire avec la MA2E</h1>
            <p class="lead">Découvrez nos sites d'exception à Songon et Bingerville, conçus pour offrir confort et sécurité à nos adhérents.</p>
        </div>

        <div class="attribution-grid">
            <div class="cond-card">
                <h3><span class="icon">📋</span> Critères d'Éligibilité</h3>
                <ul class="check-list-gold">
                    <li>Salarié (CIE, SODECI, CIPREL, GS2E, SGA2E, SIVE).</li>
                    <li>Membre actif de la MA2E.</li>
                    <li>Apport initial de <strong>35%</strong> du coût total.</li>
                    <li>Quotité disponible suffisante (incluant le crédit différé).</li>
                </ul>
            </div>
            <div class="cond-card priority">
                <h3><span class="icon">⚖️</span> Priorités (si forte demande)</h3>
                <p>En cas de sureffectif, les critères suivants s'appliquent :</p>
                <ol>
                    <li>Ordre d'inscription (date du 1er paiement).</li>
                    <li>Régularité et ancienneté à la MA2E.</li>
                    <li>Priorité hiérarchique par type de logement.</li>
                </ol>
            </div>
        </div>

        <div class="site-section">
            <div class="site-header">
                <h2>Site SONGON KASSEMBLE <small>(Route de Dabou)</small></h2>
                <span class="total-badge">110 Logements</span>
            </div>
            <div class="table-responsive">
                <table class="ma2e-table immobilie-table">
                    <thead>
                        <tr>
                            <th>Type de Logement</th>
                            <th>Terrain</th>
                            <th>Coût (FCFA)</th>
                            <th>Apport (35%)</th>
                            <th>Dispo.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>3 pièces jumelées (Social)</td><td>120 m²</td><td>9 600 000</td><td>3 360 000</td><td>46</td></tr>
                        <tr><td>2 pièces jumelées</td><td>-</td><td>7 500 000</td><td>2 625 000</td><td>2</td></tr>
                        <tr><td>3 pièces jumelées (Eco)</td><td>150 m²</td><td>12 000 000</td><td>4 200 000</td><td>26</td></tr>
                        <tr><td>4 pièces jumelées (Social)</td><td>130 m²</td><td>14 000 000</td><td>4 900 000</td><td>36</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="site-section">
            <div class="site-header bg-dark">
                <h2>Site AKANDJE <small>(Bingerville)</small></h2>
                <span class="total-badge">90 Logements</span>
            </div>
            <div class="table-responsive">
                <table class="ma2e-table immobilie-table">
                    <thead>
                        <tr>
                            <th>Type de Logement</th>
                            <th>Terrain</th>
                            <th>Coût (FCFA)</th>
                            <th>Apport (35%)</th>
                            <th>Dispo.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>3 pièces en bande</td><td>200 m²</td><td>13 200 000</td><td>4 620 000</td><td>42</td></tr>
                        <tr><td>3 pièces individuelles (Social)</td><td>200 m²</td><td>15 000 000</td><td>5 250 000</td><td>8</td></tr>
                        <tr><td>3 pièces individuelles (Eco)</td><td>260 m²</td><td>20 000 000</td><td>7 000 000</td><td>5</td></tr>
                        <tr><td>4 pièces individuelles (Social)</td><td>260 m²</td><td>27 000 000</td><td>9 450 000</td><td>35</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="ceremonie-box">
            <div class="ceremonie-content">
                <h3>🔑 Cérémonie de remise des clés</h3>
                <p>Un moment historique marqué par les allocutions du Directeur Général et du PCA de la MA2E.</p>
            </div>
            <div class="ceremonie-icon">🏠</div>
        </div>
    </div>
</section>

<footer class="pied-de-page">
    <div class="conteneur-footer">
        
        <div class="section-footer a-propos">
            <img src="../photo/logosansnom.png" alt="Logo MA2E" class="footer-logo">
            <p>La mutuelle au service des agents de l'eau et de l'électricité depuis 14 ans.</p>
            <div class="reseaux-sociaux">
                <a href="#"><i class="bi bi-facebook"></i></a>
                <a href="#"><i class="bi bi-linkedin"></i></a>
            </div>
        </div>

        <div class="section-footer">
            <h3>Nos Produits</h3>
            <ul class="liens-footer">
                <li><a href="fcredit_ordinaire.php">Épargne</a></li>
                <li><a href="fcredit_expresse.php">Crédit</a></li>
                <li><a href="#">Immobilier</a></li>
                <li><a href="#">Espace Adhérent</a></li>
            </ul>
        </div>

        <div class="section-footer">
            <h3>Contactez-nous</h3>
            <ul class="infos-contact">
                <li><i class="bi bi-telephone"></i> +225 27 21 23 64 84</li>
                <li><i class="bi bi-envelope"></i> info@ma2e.ci</li>
                <li><i class="bi bi-geo-alt"></i> ABIDJAN / Plateau Immeuble SIDAM</li>
            </ul>
        </div>

    </div>
    
    <div class="bas-footer">
        <p>&copy; 2026 MA2E - Tous droits réservés.</p>
    </div>
</footer>
<script>
document.addEventListener("DOMContentLoaded", () => {
    const toggle = document.getElementById("menuToggle");
    const menu = document.getElementById("navbarMenu");
    const overlay = document.getElementById("menuOverlay");
    const triggers = document.querySelectorAll(".submenu-trigger");

    // Ouvrir/Fermer le menu mobile
    toggle.addEventListener("click", () => {
        menu.classList.toggle("active");
        overlay.classList.toggle("active");
    });

    // Fermer en cliquant sur l'ombre
    overlay.addEventListener("click", () => {
        menu.classList.remove("active");
        overlay.classList.remove("active");
    });

    // Sous-menus sur mobile (clic sur le texte MA2E ou PRODUITS)
    triggers.forEach(trigger => {
        trigger.addEventListener("click", function(e) {
            if (window.innerWidth <= 992) {
                e.preventDefault();
                this.parentElement.classList.toggle("active");
            }
        });
    });
});
</script>
</body>
</html>