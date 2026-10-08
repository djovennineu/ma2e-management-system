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

.datavp-section { padding: 80px 5%; background-color: #fdfdfd; }

.tag-modern { background: #1a1a1a; color: #90F876; padding: 5px 15px; border-radius: 20px; font-weight: 700; font-size: 11px; text-transform: uppercase; }

/* Grille Avantages */
.v-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin: 40px 0; }
.v-card { padding: 30px; background: #fff; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); text-align: center; border-bottom: 4px solid #eee; }
.v-card.highlight { border-color: #90F876; background: #1a1a1a; color: #fff; }
.v-icon { font-size: 30px; display: block; margin-bottom: 15px; }

/* Layout Central */
.datavp-body { display: grid; grid-template-columns: 1fr 1.5fr; gap: 40px; margin-top: 50px; }

.step-list { list-style: none; padding: 0; margin: 20px 0; }
.step-list li { padding: 10px 0; border-bottom: 1px solid #eee; font-size: 15px; }
.step-list li::before { content: "→ "; color: #90F876; font-weight: bold; }

.func-box { background: #e3fcef; padding: 20px; border-radius: 15px; margin-top: 20px; border-left: 5px solid #2e7d32; }
.func-box h5 { color: #2e7d32; margin-bottom: 5px; }

.best-rate { background: #90F876; color: #000; font-weight: 800; border-radius: 4px; }

/* Clôture Section */
.cloture-section { display: flex; gap: 20px; margin-top: 50px; }
.cloture-card { flex: 1; padding: 25px; background: white; border-radius: 15px; border: 1px solid #eee; box-shadow: 0 4px 10px rgba(0,0,0,0.02); }
.cloture-card.alert { border-left: 5px solid #ff4d4d; }
.cloture-card h5 { text-transform: uppercase; font-size: 13px; color: #888; margin-bottom: 10px; }

@media (max-width: 992px) {
    .datavp-body { grid-template-columns: 1fr; }
    .cloture-section { flex-direction: column; }
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

<section class="datavp-section">
    <div class="container">
        <div class="product-header">
            <span class="tag-modern">Solution Stratégique</span>
            <h1>Boostez vos projets avec le DAT Progressif (DATAVP)</h1>
            <p class="lead">Construisez un capital solide pour financer votre futur business tout en profitant de taux d'intérêt qui grimpent avec votre effort.</p>
        </div>

        <div class="v-grid">
            <div class="v-card">
                <span class="v-icon">🎯</span>
                <h4>Épargne sur-mesure</h4>
                <p>Déterminez vos versements périodiques selon vos propres objectifs.</p>
            </div>
            <div class="v-card highlight">
                <span class="v-icon">🚀</span>
                <h4>Rémunération Croissante</h4>
                <p>Jusqu'à <strong>4,00%</strong>. Plus vous épargnez, plus le taux augmente.</p>
            </div>
            <div class="v-card">
                <span class="v-icon">🤝</span>
                <h4>Garantie de Prêt</h4>
                <p>Le capital peut servir de garantie partielle pour vos crédits ordinaires.</p>
            </div>
        </div>

        <div class="datavp-body">
            <div class="datavp-info">
                <h3>Conditions d’Ouverture</h3>
                <ul class="step-list">
                    <li>Détention d'un compte Épargne Expresse ou Ordinaire.</li>
                    <li>Versement initial : <strong>10 000 F CFA</strong> minimum.</li>
                    <li>Engagement de durée : <strong>2 à 5 ans</strong>.</li>
                </ul>
                <div class="func-box">
                    <h5>Fonctionnement</h5>
                    <p>Alimentation mensuelle (min 5 000 F). Fonds bloqués pour garantir l'objectif (min 120 000 F au terme).</p>
                </div>
            </div>

            <div class="datavp-table">
                <h3>Barème des Taux</h3>
                <div class="table-responsive">
                    <table class="ma2e-table">
                        <thead>
                            <tr>
                                <th>Durée du contrat</th>
                                <th>Montant épargné</th>
                                <th>Taux Annuel</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td rowspan="2">≤ 2 ans</td><td>≤ 500 000 F</td><td>3,50%</td></tr>
                            <tr><td>> 500 000 F</td><td>3,60%</td></tr>
                            <tr><td rowspan="2">2 à 4 ans</td><td>≤ 1 000 000 F</td><td>3,70%</td></tr>
                            <tr><td>> 1 000 000 F</td><td>3,80%</td></tr>
                            <tr><td rowspan="2">4 à 5 ans</td><td>≤ 2 000 000 F</td><td>3,90%</td></tr>
                            <tr><td>> 2 000 000 F</td><td class="best-rate">4,00%</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="cloture-section">
            <div class="cloture-card">
                <h5>Échéance Normale</h5>
                <p>Capital + Intérêts reversés sur votre compte <strong>Épargne Ordinaire</strong>.</p>
            </div>
            <div class="cloture-card alert">
                <h5>Retrait Anticipé</h5>
                <p>Retenue de 2% (avant 2 ans) ou 1% (après 2 ans). <br><small>*Sauf décès, invalidité ou départ involontaire.</small></p>
            </div>
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