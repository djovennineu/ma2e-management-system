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
   SECTION CONTACT - STYLE MA2E
   ========================================================================== */
.contact-section { padding: 100px 0; background: var(--bg-light); }

.contact-grid { 
    display: grid; 
    grid-template-columns: 0.8fr 1.2fr; 
    gap: 50px; 
    margin-top: 50px; 
}

/* Panneau d'informations */
.contact-info-panel { display: flex; flex-direction: column; gap: 30px; }

.info-item { 
    display: flex; 
    align-items: center; 
    gap: 20px; 
    background: var(--white); 
    padding: 25px; 
    border-radius: 15px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.02);
}

.icon-box { 
    width: 50px; height: 50px; 
    background: var(--primary-black); 
    display: flex; align-items: center; justify-content: center; 
    border-radius: 12px; font-size: 20px;
}

.text-box h4 { font-size: 16px; margin-bottom: 5px; color: var(--primary-black); }
.text-box p { font-size: 14px; color: #666; margin: 0; }

/* Status Pulse */
.status-card { 
    background: #e3fcef; 
    padding: 15px 25px; 
    border-radius: 50px; 
    display: flex; align-items: center; gap: 15px; 
    width: fit-content;
}
.pulse-icon { 
    width: 10px; height: 10px; 
    background: #27ae60; border-radius: 50%; 
    animation: pulse 1.5s infinite; 
}

@keyframes pulse { 
    0% { box-shadow: 0 0 0 0 rgba(39, 174, 96, 0.7); } 
    70% { box-shadow: 0 0 0 10px rgba(39, 174, 96, 0); } 
    100% { box-shadow: 0 0 0 0 rgba(39, 174, 96, 0); } 
}

/* Formulaire */
.contact-form-card { 
    background: var(--white); 
    padding: 50px; 
    border-radius: 25px; 
    box-shadow: 0 20px 40px rgba(0,0,0,0.05); 
}

.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }

.input-group { margin-bottom: 25px; }
.input-group label { 
    display: block; margin-bottom: 8px; 
    font-weight: 700; font-size: 14px; color: var(--primary-black); 
}

.input-group input, .input-group select, .input-group textarea {
    width: 100%; padding: 15px; border-radius: 10px; 
    border: 1px solid #ddd; background: #f9f9f9; 
    font-family: inherit; transition: 0.3s;
}

.input-group input:focus, .input-group select:focus, .input-group textarea:focus {
    outline: none; border-color: var(--accent-green); background: #fff;
}

.btn-submit {
    width: 100%; padding: 18px; border-radius: 50px; border: none;
    background: var(--primary-black); color: var(--white);
    font-weight: 800; font-size: 16px; cursor: pointer; transition: 0.3s;
}
.btn-submit:hover { background: var(--accent-green); color: var(--primary-black); }

@media (max-width: 992px) {
    .contact-grid { grid-template-columns: 1fr; }
    .form-row { grid-template-columns: 1fr; }
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

<section class="contact-section">
    <div class="container">
        <div class="product-header">
            <span class="tag-modern">Nous sommes à votre écoute</span>
            <h1>Parlons de vos projets</h1>
            <p class="lead">Une question sur votre épargne ou un projet immobilier ? Nos conseillers vous accompagnent du lundi au vendredi.</p>
        </div>

        <div class="contact-grid">
            <div class="contact-info-panel">
                <div class="info-item">
                    <div class="icon-box">📍</div>
                    <div class="text-box">
                        <h4>Siège Social</h4>
                        <p>Abidjan, Côte d'Ivoire<br>Plateau, Avenue Marchand</p>
                    </div>
                </div>

                <div class="info-item">
                    <div class="icon-box">📞</div>
                    <div class="text-box">
                        <h4>Ligne Directe</h4>
                        <p>+225 27 00 00 00 00<br>+225 07 00 00 00 00</p>
                    </div>
                </div>

                <div class="info-item">
                    <div class="icon-box">✉️</div>
                    <div class="text-box">
                        <h4>Emailing</h4>
                        <p>info@ma2e.ci<br>support.adherents@ma2e.ci</p>
                    </div>
                </div>

                <div class="status-card">
                    <span class="pulse-icon"></span>
                    <p>Nos bureaux sont actuellement <strong>ouverts</strong></p>
                </div>
            </div>

            <div class="contact-form-card">
                <form action="#" id="ma2eContactForm">
                    <div class="form-row">
                        <div class="input-group">
                            <label>Nom complet</label>
                            <input type="text" placeholder="Ex: Jean Kouassi" required>
                        </div>
                        <div class="input-group">
                            <label>Numéro Matricule</label>
                            <input type="text" placeholder="Votre matricule MA2E">
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Objet de votre demande</label>
                        <select required>
                            <option value="">Sélectionnez un motif</option>
                            <option value="epargne">Information Épargne</option>
                            <option value="credit">Demande de Crédit</option>
                            <option value="immobilier">Projet Immobilier</option>
                            <option value="autre">Autre demande</option>
                        </select>
                    </div>

                    <div class="input-group">
                        <label>Votre Message</label>
                        <textarea rows="5" placeholder="Comment pouvons-nous vous aider ?"></textarea>
                    </div>

                    <button type="submit" class="btn-submit">
                        Envoyer le message <span>🚀</span>
                    </button>
                </form>
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

<script>document.getElementById('ma2eContactForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = this.querySelector('.btn-submit');
    const originalText = btn.innerHTML;
    
    // Simulation d'envoi
    btn.innerHTML = "Envoi en cours... ⏳";
    btn.style.opacity = "0.7";
    btn.disabled = true;

    setTimeout(() => {
        btn.innerHTML = "Message envoyé avec succès ! ✅";
        btn.style.background = "#27ae60";
        this.reset();
        
        setTimeout(() => {
            btn.innerHTML = originalText;
            btn.style.background = "#000000";
            btn.style.opacity = "1";
            btn.disabled = false;
        }, 3000);
    }, 2000);
});</script>
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