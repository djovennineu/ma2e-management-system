<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>
<style>
/* ============================================================
   VARIABLES GLOBALES
   ============================================================ */
:root {
    /* Couleurs */
    --green:        #90F876;
    --green-dark:   #6ecc5a;
    --black:        #1a1a1a;
    --white:        #ffffff;
    --grey-bg:      #f4f4f4;
    --grey-text:    #555555;
    --orange:       #F3A93B;

    /* Ombres */
    --shadow-sm:    0 4px 15px rgba(0,0,0,0.08);
    --shadow-md:    0 10px 30px rgba(0,0,0,0.12);
    --shadow-lg:    0 20px 50px rgba(0,0,0,0.18);

    /* Rayons */
    --radius-sm:    8px;
    --radius-md:    16px;
    --radius-lg:    28px;

    /* Easings */
    --ease-out:     cubic-bezier(0.4, 0, 0.2, 1);
    --ease-spring:  cubic-bezier(0.175, 0.885, 0.32, 1.275);
}


/* ============================================================
   RESET & BASE
   ============================================================ */
*, *::before, *::after {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

html {
    scroll-behavior: smooth;
}

body {
    font-family: "DM Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    overflow-x: hidden;
    background-color: var(--grey-bg);
    color: var(--black);
    padding-top: 80px;
    line-height: 1.6;
}


/* ============================================================
   KEYFRAMES
   ============================================================ */
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(40px); }
    to   { opacity: 1; transform: translateY(0); }
}

@keyframes fadeInLeft {
    from { opacity: 0; transform: translateX(-50px); }
    to   { opacity: 1; transform: translateX(0); }
}

@keyframes fadeInRight {
    from { opacity: 0; transform: translateX(50px); }
    to   { opacity: 1; transform: translateX(0); }
}

@keyframes scaleIn {
    from { opacity: 0; transform: scale(0.85); }
    to   { opacity: 1; transform: scale(1); }
}

@keyframes floatY {
    0%, 100% { transform: translateY(0); }
    50%       { transform: translateY(-10px); }
}

@keyframes drawLine {
    from { transform: scaleX(0); }
    to   { transform: scaleX(1); }
}

@keyframes spinIn {
    from { opacity: 0; transform: rotate(-15deg) scale(0.7); }
    to   { opacity: 1; transform: rotate(0deg) scale(1); }
}

@keyframes slideInBar {
    from { width: 0; }
    to   { width: 60px; }
}

@keyframes pulse-green {
    0%, 100% { box-shadow: 0 0 0 0   rgba(144,248,118,0.5); }
    50%       { box-shadow: 0 0 0 12px rgba(144,248,118,0); }
}


/* ============================================================
   UTILITAIRES — ANIMATIONS AU SCROLL
   ============================================================ */

/* État initial (invisible) */
.anim-hidden {
    opacity: 0;
    transform: translateY(45px);
    transition:
        opacity   0.75s var(--ease-out),
        transform 0.75s var(--ease-out);
}

/* Variantes de direction */
.anim-hidden.from-left  { transform: translateX(-55px); }
.anim-hidden.from-right { transform: translateX(55px); }
.anim-hidden.from-scale { transform: scale(0.85); }

/* État final (visible) */
.anim-hidden.is-visible {
    opacity: 1;
    transform: translate(0) scale(1);
}

/* Cascade décalée pour les enfants d'un conteneur */
.anim-stagger .anim-hidden:nth-child(1) { transition-delay: 0.05s; }
.anim-stagger .anim-hidden:nth-child(2) { transition-delay: 0.15s; }
.anim-stagger .anim-hidden:nth-child(3) { transition-delay: 0.25s; }
.anim-stagger .anim-hidden:nth-child(4) { transition-delay: 0.35s; }
.anim-stagger .anim-hidden:nth-child(5) { transition-delay: 0.45s; }


/* ============================================================
   NAVBAR — STRUCTURE PRINCIPALE
   ============================================================ */
.navbar {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 80px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 5%;
    background: url('../photo/hommedebout.png') no-repeat center center / cover;
    border-bottom: 2px solid #000;
    z-index: 2000;
    transition:
        height           0.35s var(--ease-out),
        box-shadow       0.35s ease,
        background-color 0.35s ease;
}

/* État scrollé */
.navbar.scrolled {
    height: 65px;
    background-color: rgba(26,26,26,0.92);
    backdrop-filter: blur(12px);
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    border-bottom-color: rgba(144,248,118,0.4);
}

/* Logo */
.navbar-brand img {
    width: 140px;
    height: auto;
    display: block;
    transition: transform 0.3s ease, opacity 0.3s ease;
}

.navbar-brand img:hover {
    transform: scale(1.05);
    opacity: 0.9;
}


/* ============================================================
   NAVBAR — MENU CAPSULE (DESKTOP)
   ============================================================ */
.navbar-menu {
    position: absolute;
    top: 100%;
    left: 50%;
    transform: translateX(-50%);
    background-color: rgba(26,26,26,0.96);
    border-radius: 50px;
    padding: 0 25px;
    z-index: 1000;
    margin-top: -20px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.25);
    backdrop-filter: blur(8px);
    border: 1px solid rgba(144,248,118,0.12);
    transition: all 0.35s var(--ease-out);
}

/* Liste des liens */
.arii-menu {
    display: flex;
    list-style: none;
    align-items: center;
}

.arii-menu > li {
    position: relative;
}

.arii-menu > li > a {
    display: block;
    padding: 15px 18px;
    color: #fff;
    text-decoration: none;
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    transition: color 0.3s ease;
    position: relative;
}

/* Soulignement animé au hover */
.arii-menu > li > a::after {
    content: '';
    position: absolute;
    bottom: 10px;
    left: 18px;
    right: 18px;
    height: 2px;
    background: var(--green);
    border-radius: 2px;
    transform: scaleX(0);
    transform-origin: center;
    transition: transform 0.3s ease;
}

.arii-menu > li > a:hover {
    color: var(--green);
}

.arii-menu > li > a:hover::after {
    transform: scaleX(1);
}


/* ============================================================
   NAVBAR — SOUS-MENUS
   ============================================================ */
.submenu {
    position: absolute;
    top: calc(100% + 8px);
    left: 0;
    min-width: 220px;
    list-style: none;
    padding: 8px 0;
    z-index: 1001;

    background: #fff;
    border-radius: var(--radius-md);
    border: 1px solid rgba(0,0,0,0.06);
    box-shadow: var(--shadow-lg);

    /* Masqué par défaut */
    opacity: 0;
    visibility: hidden;
    transform: translateY(8px);
    transition:
        opacity    0.25s ease,
        transform  0.25s ease,
        visibility 0.25s;
}

.submenu a {
    display: block;
    padding: 10px 20px;
    color: #333;
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    transition:
        background   0.2s ease,
        color        0.2s ease,
        padding-left 0.2s ease;
}

.submenu a:hover {
    background-color: #f5fdf2;
    color: var(--green-dark);
    padding-left: 26px;
}

/* Affichage au survol — desktop uniquement */
@media (min-width: 993px) {
    .arii-menu li:hover > .submenu {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
    }

    /* Sous-sous-menu (niveau 3) — s'ouvre à droite */
    .submenu .has-submenu:hover > .submenu {
        top: 0;
        left: 100%;
    }
}


/* ============================================================
   NAVBAR — BOUTON ESPACE MEMBRE
   ============================================================ */
.btn-espace-membre {
    background-color: var(--green);
    color: #000;
    border: 2px solid #000;
    border-radius: var(--radius-sm);
    padding: 10px 18px;
    text-decoration: none;
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;s
    transition:
        background  0.3s ease,
        color       0.3s ease,
        transform   0.25s ease,
        box-shadow  0.25s ease;
}

.btn-espace-membre:hover {
    background-color: #000;
    color: var(--green);
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0,0,0,0.2);
}


/* ============================================================
   NAVBAR — HAMBURGER (MOBILE)
   ============================================================ */
.menu-toggle {
    display: none;
    flex-direction: column;
    gap: 5px;
    cursor: pointer;
    background: none;
    border: none;
    padding: 4px;
    z-index: 1100;
}

.menu-toggle span {
    display: block;
    width: 25px;
    height: 3px;
    background: #000;
    border-radius: 3px;
    transition:
        transform  0.3s ease,
        opacity    0.3s ease,
        background 0.3s ease;
}

/* Animation croix */
.menu-toggle.active span                { background: var(--green); }
.menu-toggle.active span:nth-child(1)   { transform: translateY(8px) rotate(45deg); }
.menu-toggle.active span:nth-child(2)   { opacity: 0; }
.menu-toggle.active span:nth-child(3)   { transform: translateY(-8px) rotate(-45deg); }


/* ============================================================
   NAVBAR — OVERLAY (MOBILE)
   ============================================================ */
.menu-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(3px);
    z-index: 999;
    transition: opacity 0.3s ease;
}

.menu-overlay.active {
    display: block;
}


/* ============================================================
   NAVBAR — RESPONSIVE MOBILE (≤ 992px)
   ============================================================ */
@media (max-width: 992px) {

    /* Afficher le hamburger */
    .menu-toggle {
        display: flex;
    }

    /* Réorganisation des éléments de la navbar */
    .navbar {
        padding: 0 5%;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .navbar-brand       { order: 1; flex: 1; }
    .btn-espace-membre  { order: 2; margin: 0 auto; padding: 8px 12px; font-size: 10px; white-space: nowrap; }
    .menu-toggle        { order: 3; flex: 1; align-items: flex-end; }

    /* Sidebar coulissante */
    .navbar-menu {
        position: fixed;
        top: 0;
        left: -100%;
        transform: none;
        width: 280px;
        height: 100vh;
        background-color: #141414;
        border-radius: 0;
        padding-top: 80px;
        margin-top: 0;
        overflow-y: auto;
        display: block;
        border-right: 1px solid rgba(144,248,118,0.15);
        transition: left 0.4s var(--ease-out);
    }

    .navbar-menu.active {
        left: 0;
    }

    /* Menu vertical */
    .arii-menu {
        flex-direction: column;
        align-items: flex-start;
    }

    .arii-menu > li {
        width: 100%;
        border-bottom: 1px solid rgba(255,255,255,0.07);
    }

    .arii-menu > li > a {
        padding: 18px 25px;
        font-size: 13px;
    }

    /* Pas de soulignement sur mobile */
    .arii-menu > li > a::after {
        display: none;
    }

    /* Sous-menus en accordéon */
    .submenu {
        position: static;
        display: none;
        width: 100%;
        background-color: rgba(255,255,255,0.04);
        box-shadow: none;
        border-radius: 0;
        padding-left: 20px;
        opacity: 1;
        visibility: visible;
        transform: none;
        border: none;
    }

    .arii-menu li.active > .submenu {
        display: block;
    }

    .submenu a {
        color: #ccc;
        padding: 12px 20px;
    }

    .submenu a:hover {
        background: rgba(144,248,118,0.08);
        color: var(--green);
        padding-left: 28px;
    }
}






 /* ============================================================ */
/*  FOOTER                                                      */
/* ============================================================ */
.pied-de-page {
    background: linear-gradient(180deg, #141414 0%, #1a1a1a 100%);
    color: var(--white);
    padding: 70px 0 24px;
}

.conteneur-footer {
    max-width: 1200px;
    margin: 0 auto;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 48px;
    padding: 0 5%;
}

.section-footer h3 {
    color: var(--orange);
    font-size: 1.1rem;
    font-weight: 700;
    margin-bottom: 22px;
    text-transform: uppercase;
    letter-spacing: 1px;
    position: relative;
    padding-bottom: 12px;
}

/* Ligne déco sous les titres footer */
.section-footer h3::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 30px;
    height: 2px;
    background: var(--orange);
    border-radius: 2px;
    transition: width 0.4s ease;
}

.section-footer:hover h3::after { width: 50px; }

.footer-logo {
    max-width: 140px;
    margin-bottom: 20px;
    display: block;
    transition: opacity 0.3s ease, transform 0.3s ease;
}

.footer-logo:hover { opacity: 0.85; transform: scale(1.03); }

.liens-footer, .infos-contact {
    list-style: none;
    padding: 0;
}

.liens-footer li, .infos-contact li {
    margin-bottom: 12px;
    color: #aaa;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
    transition: color 0.3s ease;
}

.liens-footer a {
    color: #aaa;
    text-decoration: none;
    transition: color 0.3s ease, padding-left 0.3s ease;
    font-size: 14px;
}

.liens-footer a:hover {
    color: var(--orange);
    padding-left: 6px;
}

.bas-footer {
    text-align: center;
    margin-top: 60px;
    padding-top: 24px;
    border-top: 1px solid rgba(255,255,255,0.08);
    font-size: 0.85rem;
    color: #555;
}

.reseaux-sociaux a {
    color: #aaa;
    font-size: 1.4rem;
    margin-right: 16px;
    display: inline-block;
    transition: color 0.3s ease, transform 0.3s ease;
}

.reseaux-sociaux a:hover {
    color: var(--orange);
    transform: translateY(-4px) scale(1.1);
}

@media (max-width: 768px) {
    .conteneur-footer { grid-template-columns: 1fr; text-align: center; }
    .infos-contact li { justify-content: center; }
    .section-footer h3::after { left: 50%; transform: translateX(-50%); }
}


</style>

<body>
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

</body>
</html>