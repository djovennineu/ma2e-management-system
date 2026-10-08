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
    background: url('../photo/hommedebout.png') no-repeat center center;
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

/* ===================== */
/* SECTION EQUIPE       */

.team-section {
    padding: 80px 5%;
    background-color: #ffffff;
}

.team-header {
    text-align: center;
    margin-bottom: 60px;
}

.team-header h1 {
    font-size: 36px;
    letter-spacing: 2px;
    position: relative;
    display: inline-block;
}

.team-header h1::after {
    content: "";
    display: block;
    width: 50%;
    height: 4px;
    background: #90F876;
    margin: 10px auto 0;
}

.team-header .subtitle {
    display: block;
    color: #888;
    text-transform: uppercase;
    font-size: 14px;
    margin-bottom: 5px;
}

/* La Grille */
.team-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); /* S'adapte au nombre de colonnes */
    gap: 30px;
    padding: 20px 0;
}

.team-card {
    text-align: center;
    transition: transform 0.3s ease;
}

.team-card:hover {
    transform: translateY(-10px);
}

.member-image {
    width: 100%;
    height: 300px; /* Hauteur fixe pour l'unité visuelle */
    border-radius: 15px;
    overflow: hidden;
    margin-bottom: 15px;
}

.member-image img {
    width: 100%;
    height: 100%;
    object-fit: cover; /* Recadre automatiquement les photos */
}

.team-card:hover img {
    filter: grayscale(0%);
}

.member-info h5 {
    font-size: 16px;
    margin-bottom: 8px;
    height: 40px; /* Aligne les noms sur deux lignes maximum */
}

.role {
    font-size: 13px;
    color: #90F876;
    background: #000;
    padding: 4px 10px;
    border-radius: 5px;
    display: inline-block;
}

.member-info .role {
    color: #90F876;
    font-weight: 600;
    font-size: 14px;
    background: #000;
    display: inline-block;
    padding: 2px 12px;
    border-radius: 4px;
}

/* Responsive */
@media (max-width: 768px) {
    .member-image {
        height: 300px;
    }
}

/* ===================== */
/* SECTION ORGANES       */
.organes-section {
    padding: 60px 5%;
    background-color: #fff;
}

.organes-header {
    text-align: center;
    margin-bottom: 40px;
}

.organes-header h1 {
    font-size: 28px;
    color: #1a1a1a;
    margin-bottom: 10px;
}

.badge-organes {
    display: inline-block;
    background-color: #90F876;
    color: #000;
    padding: 8px 20px;
    border-radius: 50px;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 14px;
}

/* Gestion du tableau */
.table-responsive {
    overflow-x: auto; /* Défilement horizontal sur mobile */
    margin: 0 auto;
    max-width: 900px;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.05);
}

.ma2e-table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    font-size: 15px;
    text-align: left;
}

.ma2e-table thead {
    background-color: #1a1a1a;
    color: #90F876;
}

.ma2e-table th, .ma2e-table td {
    padding: 15px 20px;
    border-bottom: 1px solid #eee;
}

/* Effet de zèbre pour la lecture */
.ma2e-table tbody tr:nth-child(even) {
    background-color: #f9f9f9;
}

.ma2e-table tbody tr:hover {
    background-color: rgba(144, 248, 118, 0.1);
    transition: 0.2s;
}

.ma2e-table td strong {
    color: #1a1a1a;
    font-weight: 800;
}

/* Adaptations Mobile */
@media (max-width: 600px) {
    .ma2e-table th, .ma2e-table td {
        padding: 12px 10px;
        font-size: 13px;
    }
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

/* ===================== */
/* ANIMATIONS AJOUTEES   */
/* ===================== */

/* Animation d'apparition pour les cartes équipe */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.team-card {
    opacity: 0;
    transform: translateY(30px);
    animation: fadeInUp 0.6s ease forwards;
}

/* Délais progressifs pour chaque carte */
.team-card:nth-child(1) { animation-delay: 0.05s; }
.team-card:nth-child(2) { animation-delay: 0.1s; }
.team-card:nth-child(3) { animation-delay: 0.15s; }
.team-card:nth-child(4) { animation-delay: 0.2s; }
.team-card:nth-child(5) { animation-delay: 0.25s; }
.team-card:nth-child(6) { animation-delay: 0.3s; }
.team-card:nth-child(7) { animation-delay: 0.35s; }
.team-card:nth-child(8) { animation-delay: 0.4s; }
.team-card:nth-child(9) { animation-delay: 0.45s; }
.team-card:nth-child(10) { animation-delay: 0.5s; }
.team-card:nth-child(11) { animation-delay: 0.55s; }
.team-card:nth-child(12) { animation-delay: 0.6s; }
.team-card:nth-child(13) { animation-delay: 0.65s; }

/* Animation apparition pour les sections organes */
.organes-section {
    opacity: 0;
    transform: translateY(30px);
    animation: fadeInUp 0.6s ease forwards;
}

.organes-section:nth-of-type(1) { animation-delay: 0.1s; }
.organes-section:nth-of-type(2) { animation-delay: 0.2s; }
.organes-section:nth-of-type(3) { animation-delay: 0.3s; }
.organes-section:nth-of-type(4) { animation-delay: 0.4s; }

/* Animation du trait sous le titre */
@keyframes expandWidth {
    from {
        width: 0;
        opacity: 0;
    }
    to {
        width: 50%;
        opacity: 1;
    }
}

.team-header h1::after {
    animation: expandWidth 0.8s ease forwards;
}

/* Animation légère du badge */
.badge-organes {
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.badge-organes:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(144, 248, 118, 0.4);
}

/* Animation des lignes de tableau au survol */
.ma2e-table tbody tr {
    transition: all 0.2s ease;
}

.ma2e-table tbody tr:hover {
    background-color: rgba(144, 248, 118, 0.15);
    transform: scale(1.01);
}

/* Animation du bouton espace membre */
@keyframes subtlePulse {
    0% {
        box-shadow: 0 0 0 0 rgba(144, 248, 118, 0.4);
    }
    70% {
        box-shadow: 0 0 0 6px rgba(144, 248, 118, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(144, 248, 118, 0);
    }
}

.btn-espace-membre {
    animation: subtlePulse 2s infinite;
}

.btn-espace-membre:hover {
    animation: none;
}

/* Animation d'apparition pour le header de l'équipe */
.team-header {
    opacity: 0;
    animation: fadeInUp 0.6s ease forwards;
    animation-delay: 0.1s;
}

/* Animation des sous-menus desktop */
@media (min-width: 993px) {
    .submenu {
        opacity: 0;
        transform: translateY(-10px);
        transition: opacity 0.3s ease, transform 0.3s ease, display 0s;
        display: block;
        visibility: hidden;
        pointer-events: none;
    }
    
    .arii-menu li:hover > .submenu {
        opacity: 1;
        transform: translateY(0);
        visibility: visible;
        pointer-events: auto;
    }
    
    .submenu .has-submenu > .submenu {
        opacity: 0;
        transform: translateX(-10px);
        transition: opacity 0.3s ease, transform 0.3s ease;
        visibility: hidden;
        pointer-events: none;
    }
    
    .submenu .has-submenu:hover > .submenu {
        opacity: 1;
        transform: translateX(0);
        visibility: visible;
        pointer-events: auto;
    }
}

/* Animation du menu mobile */
@media (max-width: 992px) {
    .submenu {
        transition: all 0.3s ease;
    }
    
    .arii-menu li.active > .submenu {
        animation: slideDown 0.3s ease;
    }
    
    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
}

/* Animation du overlay */
.menu-overlay {
    transition: opacity 0.3s ease;
}

/* Animation des liens du footer */
.liens-footer li {
    transition: transform 0.2s ease;
}

.liens-footer li:hover {
    transform: translateX(5px);
}

/* Animation des images des cartes */
.member-image img {
    transition: transform 0.4s ease, filter 0.3s ease;
}

.team-card:hover .member-image img {
    transform: scale(1.05);
}

/* Animation du rôle */
.role {
    transition: all 0.3s ease;
}

.team-card:hover .role {
    background-color: #90F876;
    color: #000;
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
                    <li><a href="#">Organisation</a></li>
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

<section class="team-section">
    <div class="container">
        <div class="team-header">
            <span class="subtitle">L'Excellence au service des membres</span>
            <h1>NOTRE ÉQUIPE</h1>
        </div>

        <div class="team-grid">
            <div class="team-card">
                <div class="member-image"><img src="../photo/BONNY.png" alt="N'DRIN BONNY EMMA"></div>
                <div class="member-info"><h5>N'DRIN BONNY EMMA</h5><p class="role">Directeur Général</p></div>
            </div>

            <div class="team-card">
                <div class="member-image"><img src="../photo/femmeaccueile.png" alt="KONE MADOUSSOU"></div>
                <div class="member-info"><h5>KONE MADOUSSOU Epse SOMBO</h5><p class="role">Directeur Administration Gestion Finance</p></div>
            </div>

            <div class="team-card">
                <div class="member-image"><img src="../photo/akoue.png" alt="AKPOUE AFFOUET ROSABELLE"></div>
                <div class="member-info"><h5>AKPOUE AFFOUET ROSABELLE</h5><p class="role">Responsable Exploitation</p></div>
            </div>

            <div class="team-card">
                <div class="member-image"><img src="../photo/natacha.png" alt="DJEDJERO NATACHA"></div>
                <div class="member-info"><h5>DJEDJERO NATACHA</h5><p class="role">Cadre Études et Gestion</p></div>
            </div>

            <div class="team-card">
                <div class="member-image"><img src="../photo/kossi.png" alt="KOISSI AYA PHILOMENE"></div>
                <div class="member-info"><h5>KOISSI AYA PHILOMENE</h5><p class="role">Resp. Audit Interne et QSE</p></div>
            </div>

            <div class="team-card">
                <div class="member-image"><img src="../photo/toureadama.png" alt="TOURE ADAMA"></div>
                <div class="member-info"><h5>TOURE ADAMA</h5><p class="role">Ingénieur Informaticien</p></div>
            </div>

            <div class="team-card">
                <div class="member-image"><img src="../photo/bery.png" alt="BERY OBODJI MICHELINE"></div>
                <div class="member-info"><h5>BERY OBODJI MICHELINE EPSE N’ZI</h5><p class="role">Assistante de direction</p></div>
            </div>

            <div class="team-card">
                <div class="member-image"><img src="../photo/assi.png" alt="ASSI AMON ANNA PATRICIA"></div>
                <div class="member-info"><h5>ASSI AMON ANNA PATRICIA</h5><p class="role">Gestionnaire CIE</p></div>
            </div>

            <div class="team-card">
                <div class="member-image"><img src="../photo/kouasi.png" alt="KOUASSI AFFOUA ELISABETH"></div>
                <div class="member-info"><h5>KOUASSI AFFOUA ELISABETH</h5><p class="role">Gestionnaire</p></div>
            </div>

            <div class="team-card">
                <div class="member-image"><img src="../photo/gui.png" alt="GUIE BANOU TRESORE"></div>
                <div class="member-info"><h5>GUIE BANOU TRESORE Epse M'BEDJI</h5><p class="role">Gestionnaire</p></div>
            </div>

            <div class="team-card">
                <div class="member-image"><img src="../photo/zeze.png" alt="ZEZE Amenan Marie Sophie Ange"></div>
                <div class="member-info"><h5>ZEZE Amenan Marie Sophie Ange</h5><p class="role">Gestionnaire</p></div>
            </div>

            <div class="team-card">
                <div class="member-image"><img src="../photo/akoue.png" alt="BONOUMAN EFFOSSY MARIE ESTHER"></div>
                <div class="member-info"><h5>BONOUMAN EFFOSSY MARIE ESTHER</h5><p class="role">Caissière</p></div>
            </div>

            <div class="team-card">
                <div class="member-image"><img src="../photo/konan.png" alt="KONAN LEOPOLD"></div>
                <div class="member-info"><h5>KONAN LEOPOLD</h5><p class="role">Chauffeur DAGF</p></div>
            </div>
        </div>
    </div>
</section>



<section class="organes-section">
    <div class="container">
        <div class="organes-header">
            <h1>Membres des organes de la MA2E</h1>
            <div class="badge-organes">Conseil d'administration</div>
        </div>

        <div class="table-responsive">
            <table class="ma2e-table">
                <thead>
                    <tr>
                        <th>Noms et prénoms</th>
                        <th>Société</th>
                        <th>Fonctions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>AHMADOU BAKAYOKO</td><td>CIE/SODECI</td><td><strong>PCA</strong></td></tr>
                    <tr><td>DEGNY Guy Florent</td><td>SODECI</td><td>1er Vice Président</td></tr>
                    <tr><td>SOUMAHORO Namory Hamed</td><td>CIE</td><td>2e Vice Président</td></tr>
                    <tr><td>SOKOLO-KACOU Moroh Maxime</td><td>GS2E</td><td>Administrateur</td></tr>
                    <tr><td>KEITA Epse DIALLO KADIDJATOU</td><td>CIPREL</td><td>Administrateur</td></tr>
                    <tr><td>N'DRI Léandre</td><td>CIE</td><td>Administrateur</td></tr>
                    <tr><td>BITTY AYE Marie</td><td>CIE</td><td>Administrateur</td></tr>
                    <tr><td>KOUADIO KOUADIO Christophe</td><td>SODECI</td><td>Administrateur</td></tr>
                    <tr><td>BAGAYOGO Harouna</td><td>GS2E</td><td>Administrateur</td></tr>
                    <tr><td>BEHI TOGBA Fulbert</td><td>CIE</td><td>Administrateur</td></tr>
                    <tr><td>HOUNNOU Cédrick Brice</td><td>SODECI</td><td>Administrateur</td></tr>
                    <tr><td>KOUADIO Koffi N’Da</td><td>SYNACIE</td><td>Administrateur</td></tr>
                    <tr><td>EHUI EHUI Eugène</td><td>CIE FRATERNITE</td><td>Administrateur</td></tr>
                    <tr><td>FIENIN Barnabe</td><td>AXE</td><td>Administrateur</td></tr>
                    <tr><td>OKROU G. Thomas</td><td>SYNASOD</td><td>Administrateur</td></tr>
                    <tr><td>LIBRONO Arsène</td><td>SYNATRASE</td><td>Administrateur</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</section>


<section class="organes-section">
    <div class="container">
        <div class="organes-header">
            <div class="badge-organes">Conseil de Surveillance</div>
        </div>

        <div class="table-responsive">
            <table class="ma2e-table">
                <thead>
                    <tr>
                        <th>N°</th>
                        <th>Qualité</th>
                        <th>Noms et prénoms</th>
                        <th>Entités</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>1</td><td><strong>Président</strong></td><td>SANGARE Marie-Claire</td><td>CIE</td></tr>
                    <tr><td>2</td><td>Vice-président</td><td>REGNIER-ONDOMAT Stéphane Antoine</td><td>GS2E</td></tr>
                    <tr><td>3</td><td>Secrétaire</td><td>ZAMA Johan</td><td>SODECI</td></tr>
                    <tr><td>4</td><td>Membre</td><td>KASSI N’Goran Franck</td><td>CIE</td></tr>
                    <tr><td>5</td><td>Membre</td><td>DJELOU Djelou Fabrice</td><td>AXE</td></tr>
                    <tr><td>6</td><td>Membre</td><td>TOURE ASSETA Epse ASSIFFOUA</td><td>SYNASOD</td></tr>
                    <tr><td>7</td><td>Membre</td><td>DOUA Gisèle Epse KEBE</td><td>SYNATRASE</td></tr>
                    <tr><td>8</td><td>Membre</td><td>KACOU-KONIN Effi Anne</td><td>CIPREL</td></tr>
                    <tr><td>9</td><td>Membre</td><td>BOHIAN BAMBA MAH</td><td>FRATERNITE</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</section>


<section class="organes-section">
    <div class="container">
        <div class="organes-header">
            <div class="badge-organes">Comité de Déontologie</div>
        </div>

        <div class="table-responsive">
            <table class="ma2e-table">
                <thead>
                    <tr>
                        <th>N°</th>
                        <th>Qualité</th>
                        <th>Noms et prénoms</th>
                        <th>Entités</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>1</td>
                        <td><strong>Président</strong></td>
                        <td>GUEYE ALIOUNE</td>
                        <td>GS2E</td>
                    </tr>
                    <tr>
                        <td>2</td>
                        <td>Membre</td>
                        <td>KISSIEDOU épse DAKHAM Valérie</td>
                        <td>SODECI</td>
                    </tr>
                    <tr>
                        <td>3</td>
                        <td>Membre</td>
                        <td>AGGREY Stéphane</td>
                        <td>CIE</td>
                    </tr>
                </tbody>
            </table>
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
                <li><a href="#">Épargne</a></li>
                <li><a href="#">Crédit</a></li>
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