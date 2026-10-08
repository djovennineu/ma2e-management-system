<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MA2E - Épargne Expresse</title>
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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
            padding-top: 80px;
            scroll-behavior: smooth;
        }

        /* ===================== */
        /* NAVBAR PRINCIPALE     */
        /* ===================== */
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
            background: url('../photo/mrargent.png') no-repeat center center;
            background-size: cover;
            border-bottom: 2px solid #000;
            z-index: 2000;
            transition: all 0.4s cubic-bezier(0.2, 0.9, 0.4, 1.1);
        }

        /* Animation navbar au scroll */
        .navbar.scrolled {
            height: 65px;
            background-color: rgba(0, 0, 0, 0.85);
            background-blend-mode: overlay;
            border-bottom-color: #90F876;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
        }

        .navbar-brand img {
            width: 140px;
            height: auto;
            transition: transform 0.3s ease;
        }

        .navbar-brand img:hover {
            transform: scale(1.05);
        }

        /* ===================== */
        /* MENU CAPSULE (Desktop)*/
        /* ===================== */
        .navbar-menu {
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            background-color: rgba(30, 30, 30, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 50px;
            padding: 5px 25px;
            z-index: 1000;
            margin-top: -20px;
            transition: all 0.3s ease;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }

        .navbar.scrolled .navbar-menu {
            margin-top: -10px;
            background-color: rgba(0, 0, 0, 0.92);
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
            transition: all 0.3s ease;
            position: relative;
        }

        .arii-menu > li > a::after {
            content: '';
            position: absolute;
            bottom: 8px;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 2px;
            background: #90F876;
            transition: width 0.3s ease;
        }

        .arii-menu > li > a:hover::after {
            width: 30px;
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
            min-width: 240px;
            background-color: #ffffff;
            border-radius: 12px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
            list-style: none;
            padding: 10px 0;
            z-index: 1001;
            opacity: 0;
            transform: translateY(-10px);
            visibility: hidden;
            transition: opacity 0.3s ease, transform 0.3s ease, visibility 0.3s;
        }

        .submenu a {
            padding: 12px 24px;
            color: #1a1a1a;
            text-decoration: none;
            display: block;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.25s ease;
        }

        .submenu a:hover {
            background-color: #f0f9ec;
            color: #90F876;
            padding-left: 32px;
        }

        @media (min-width: 993px) {
            .arii-menu li:hover > .submenu {
                opacity: 1;
                transform: translateY(0);
                visibility: visible;
            }
            .submenu .has-submenu:hover > .submenu {
                left: 100%;
                top: 0;
                opacity: 1;
                transform: translateX(0);
                visibility: visible;
            }
            .submenu .has-submenu > .submenu {
                left: 95%;
                top: 0;
                opacity: 0;
                transform: translateX(-10px);
                visibility: hidden;
                transition: opacity 0.3s ease, transform 0.3s ease, visibility 0.3s;
            }
        }

        /* ===================== */
        /* BOUTON ESPACE MEMBRE  */
        /* ===================== */
        .btn-espace-membre {
            background-color: #90F876;
            color: #000;
            border: 2px solid #000;
            border-radius: 10px;
            font-weight: 800;
            padding: 10px 24px;
            text-decoration: none;
            font-size: 12px;
            letter-spacing: 1px;
            transition: all 0.4s cubic-bezier(0.2, 0.9, 0.4, 1.1);
            animation: subtlePulse 2s infinite;
        }

        .btn-espace-membre:hover {
            background-color: #000;
            color: #90F876;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(144, 248, 118, 0.3);
            border-color: #90F876;
            animation: none;
        }

        @keyframes subtlePulse {
            0% { box-shadow: 0 0 0 0 rgba(144, 248, 118, 0.4); }
            70% { box-shadow: 0 0 0 8px rgba(144, 248, 118, 0); }
            100% { box-shadow: 0 0 0 0 rgba(144, 248, 118, 0); }
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
            padding: 8px;
            border-radius: 8px;
            transition: background 0.2s;
        }

        .menu-toggle span {
            width: 28px;
            height: 3px;
            background: #000;
            border-radius: 4px;
            transition: all 0.3s ease;
        }

        .menu-toggle:hover span {
            background: #90F876;
        }

        @media (max-width: 992px) {
            .menu-toggle { display: flex; }
            
            .navbar-menu {
                position: fixed;
                top: 0;
                left: -100%;
                transform: none;
                width: 300px;
                height: 100vh;
                background: linear-gradient(135deg, #1e1e1e 0%, #0a0a0a 100%);
                border-radius: 0;
                flex-direction: column;
                padding-top: 100px;
                margin-top: 0;
                transition: left 0.4s cubic-bezier(0.2, 0.9, 0.4, 1.1);
                overflow-y: auto;
                display: block;
                backdrop-filter: none;
            }

            .navbar-menu.active { left: 0; }

            .arii-menu { flex-direction: column; align-items: flex-start; width: 100%; }
            .arii-menu > li { width: 100%; border-bottom: 1px solid rgba(255, 255, 255, 0.08); }
            .arii-menu > li > a { padding: 18px 25px; font-size: 14px; }
            .arii-menu > li > a::after { display: none; }

            .submenu {
                position: static;
                display: none;
                width: 100%;
                background-color: rgba(255, 255, 255, 0.05);
                box-shadow: none;
                padding-left: 20px;
                opacity: 1;
                transform: none;
                visibility: visible;
                border-radius: 0;
            }
            
            .arii-menu li.active > .submenu {
                display: block;
                animation: slideDown 0.3s ease;
            }

            .submenu a { color: #ddd; }
            .submenu a:hover { background: rgba(144, 248, 118, 0.1); color: #90F876; }

            .menu-overlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.7);
                backdrop-filter: blur(3px);
                z-index: 999;
                opacity: 0;
                transition: opacity 0.3s ease;
            }
            .menu-overlay.active {
                display: block;
                opacity: 1;
            }

            .navbar {
                padding: 15px 5%;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .navbar-brand { order: 1; flex: 1; }
            .btn-espace-membre { order: 2; margin: 0 auto; padding: 8px 12px; font-size: 10px; white-space: nowrap; animation: none; }
            .menu-toggle { order: 3; display: flex; flex: 1; align-items: flex-end; }
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ===================== */
        /* SECTION ÉPARGNE EXPRESSE - STYLES CONSERVÉS + ANIMATIONS */
        /* ===================== */
        .epargne-expresse { 
            padding: 80px 5%; 
            background: linear-gradient(135deg, #fdfdfd 0%, #f8f8f8 100%);
        }

        .product-header { 
            text-align: center; 
            max-width: 800px; 
            margin: 0 auto 60px;
            opacity: 0;
            transform: translateY(30px);
            animation: fadeInUp 0.7s ease forwards;
        }

        .tag { 
            background: #90F876; 
            color: #000; 
            padding: 5px 18px; 
            border-radius: 30px; 
            font-weight: 700; 
            font-size: 12px; 
            text-transform: uppercase;
            display: inline-block;
            transition: all 0.3s ease;
        }

        .tag:hover {
            transform: scale(1.05);
            box-shadow: 0 5px 15px rgba(144, 248, 118, 0.3);
        }

        .product-header h1 { 
            font-size: 38px; 
            margin: 20px 0; 
            color: #1a1a1a;
            background: linear-gradient(135deg, #1a1a1a, #333);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .product-header .lead {
            font-size: 1.1rem;
            color: #555;
            line-height: 1.6;
        }

        /* Grille des avantages avec animations */
        .features-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); 
            gap: 30px; 
            margin-bottom: 60px; 
        }

        .feature-card { 
            background: #fff; 
            padding: 35px 25px; 
            border-radius: 24px; 
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05); 
            text-align: center; 
            border-bottom: 4px solid #eee;
            transition: all 0.4s cubic-bezier(0.2, 0.9, 0.4, 1.1);
            opacity: 0;
            transform: translateY(30px);
            animation: fadeInUp 0.6s ease forwards;
        }

        .feature-card:nth-child(1) { animation-delay: 0.1s; }
        .feature-card:nth-child(2) { animation-delay: 0.2s; }
        .feature-card:nth-child(3) { animation-delay: 0.3s; }

        .feature-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(144, 248, 118, 0.15);
            border-bottom-color: #90F876;
        }

        .feature-card.highlight {
            border-bottom-color: #90F876;
            position: relative;
            overflow: hidden;
        }

        .feature-card.highlight::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(144, 248, 118, 0.1), transparent);
            transition: left 0.6s ease;
        }

        .feature-card.highlight:hover::before {
            left: 100%;
        }

        .feature-icon { 
            font-size: 48px; 
            margin-bottom: 20px; 
            display: inline-block;
            transition: transform 0.3s ease;
        }

        .feature-card:hover .feature-icon {
            transform: scale(1.1);
        }

        .feature-card h3 {
            margin-bottom: 12px;
            color: #1a1a1a;
        }

        /* Section Infos Split */
        .info-split { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); 
            gap: 30px; 
            margin-bottom: 60px; 
        }

        .info-box { 
            padding: 40px; 
            background: #fff; 
            border-radius: 28px; 
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.03);
            transition: all 0.4s ease;
            opacity: 0;
            transform: translateY(30px);
            animation: fadeInUp 0.6s ease forwards;
        }

        .info-box:nth-child(1) { animation-delay: 0.15s; }
        .info-box:nth-child(2) { animation-delay: 0.25s; }

        .info-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        }

        .info-box.dark { 
            background: #1a1a1a; 
            color: #fff;
            position: relative;
            overflow: hidden;
        }

        .info-box.dark::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(144, 248, 118, 0.05), transparent);
            opacity: 0;
            transition: opacity 0.6s ease;
        }

        .info-box.dark:hover::after {
            opacity: 1;
        }

        .step-num { 
            color: #90F876; 
            font-weight: 900; 
            margin-right: 10px;
            font-size: 1.2rem;
        }

        /* Liste des tarifs */
        .price-list { 
            list-style: none; 
            margin: 25px 0; 
        }

        .price-list li { 
            display: flex; 
            justify-content: space-between; 
            padding: 14px 0; 
            border-bottom: 1px dashed #ddd;
            transition: all 0.3s ease;
        }

        .price-list li:hover {
            padding-left: 10px;
            border-bottom-color: #90F876;
        }

        .price-list li span:first-child {
            color: #555;
        }

        .price-list li strong { 
            color: #1a1a1a; 
            font-size: 1rem;
        }

        .info-box.dark .price-list li strong {
            color: #90F876;
        }

        .func-item { 
            margin-bottom: 25px;
            transition: transform 0.3s ease;
        }

        .func-item:hover {
            transform: translateX(5px);
        }

        .func-item strong { 
            color: #90F876; 
            display: block; 
            margin-bottom: 8px;
            font-size: 1rem;
        }

        /* Section Levier */
        .leverage-section { 
            margin-top: 60px; 
            text-align: center;
            opacity: 0;
            transform: translateY(30px);
            animation: fadeInUp 0.6s ease forwards;
            animation-delay: 0.35s;
        }

        .leverage-section h2 {
            margin-bottom: 20px;
        }

        .best-rate { 
            background: #90F876; 
            color: #000; 
            padding: 4px 12px; 
            border-radius: 20px; 
            font-weight: 700;
            display: inline-block;
            transition: all 0.3s ease;
        }

        .best-rate:hover {
            transform: scale(1.05);
            box-shadow: 0 3px 10px rgba(144, 248, 118, 0.5);
        }

        /* Tableau */
        .table-responsive {
            overflow-x: auto;
            margin: 30px auto 0;
            max-width: 700px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .table-responsive:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.12);
        }

        .ma2e-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            font-size: 14px;
            text-align: left;
        }

        .ma2e-table thead {
            background: linear-gradient(135deg, #1a1a1a, #2a2a2a);
            color: #90F876;
        }

        .ma2e-table th, .ma2e-table td {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
        }

        .ma2e-table tbody tr {
            transition: all 0.25s ease;
        }

        .ma2e-table tbody tr:hover {
            background-color: #f0f9ec;
            transform: scale(1.01);
        }

        /* Note de clôture */
        .cloture-note { 
            margin-top: 50px; 
            padding: 20px 25px; 
            background: linear-gradient(135deg, #fff5f5, #fff0f0);
            border-left: 5px solid #ff4d4d; 
            border-radius: 16px; 
            font-size: 14px;
            transition: all 0.3s ease;
            opacity: 0;
            transform: translateY(30px);
            animation: fadeInUp 0.6s ease forwards;
            animation-delay: 0.45s;
        }

        .cloture-note:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
        }

        /* ===================== */
        /* PIED DE PAGE          */
        /* ===================== */
        .pied-de-page {
            background: linear-gradient(135deg, #1a1a1a, #111);
            color: #ffffff;
            padding: 60px 0 20px 0;
            margin-top: 40px;
        }

        .conteneur-footer {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 50px;
            padding: 0 30px;
        }

        .section-footer h3 {
            color: #F3A93B;
            font-size: 1.3rem;
            margin-bottom: 25px;
            position: relative;
            display: inline-block;
        }

        .section-footer h3::after {
            content: '';
            position: absolute;
            bottom: -8px;
            left: 0;
            width: 40px;
            height: 3px;
            background: #F3A93B;
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .section-footer:hover h3::after {
            width: 70px;
        }

        .footer-logo {
            max-width: 150px;
            margin-bottom: 20px;
            transition: transform 0.3s;
        }

        .footer-logo:hover {
            transform: scale(1.05);
        }

        .liens-footer, .infos-contact {
            list-style: none;
            padding: 0;
        }

        .liens-footer li, .infos-contact li {
            margin-bottom: 14px;
            color: #ccc;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: transform 0.2s ease;
        }

        .liens-footer li:hover {
            transform: translateX(5px);
        }

        .liens-footer a {
            color: #ccc;
            text-decoration: none;
            transition: color 0.3s;
        }

        .liens-footer a:hover {
            color: #F3A93B;
        }

        .bas-footer {
            text-align: center;
            margin-top: 50px;
            padding-top: 25px;
            border-top: 1px solid #333;
            font-size: 0.85rem;
            color: #888;
        }

        .reseaux-sociaux a {
            color: white;
            font-size: 1.8rem;
            margin-right: 20px;
            transition: all 0.3s;
            display: inline-block;
        }

        .reseaux-sociaux a:hover {
            color: #F3A93B;
            transform: translateY(-4px);
        }

        /* ===================== */
        /* KEYFRAMES ANIMATIONS  */
        /* ===================== */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(40px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
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
            .section-footer h3::after {
                left: 50%;
                transform: translateX(-50%);
            }
            .product-header h1 { font-size: 28px; }
            .info-split { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="menu-overlay" id="menuOverlay"></div>

<nav class="navbar" id="mainNavbar">
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

<section class="epargne-expresse">
    <div class="container">
        <div class="product-header">
            <span class="tag">Produit Phare</span>
            <h1>1. L'Épargne Expresse : Votre Passeport vers le Crédit</h1>
            <p class="lead">L'Épargne Expresse est le socle de votre relation avec la MA2E. Ce compte, ouvert dès votre adhésion, est la clé qui vous rend éligible à l'ensemble de nos solutions de financement.</p>
        </div>

        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">🔓</div>
                <h3>Accessibilité Totale</h3>
                <p>Accès à tous les produits de crédit de l'institution.</p>
            </div>
            <div class="feature-card highlight">
                <div class="feature-icon">📈</div>
                <h3>3,5% d'Intérêt</h3>
                <p>Une rémunération attractive pour faire fructifier votre capital.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">⚙️</div>
                <h3>Zéro Frais</h3>
                <p>Gestion transparente sans frais d'ouverture, de clôture ou de tenue de compte.</p>
            </div>
        </div>

        <div class="info-split">
            <div class="info-box">
                <h2><span class="step-num">2</span> Conditions d'Ouverture</h2>
                <ul class="price-list">
                    <li><span>Cadres de Direction</span> <strong>10 000 F CFA</strong></li>
                    <li><span>Cadres</span> <strong>5 000 F CFA</strong></li>
                    <li><span>Maîtrise Supérieure</span> <strong>3 000 F CFA</strong></li>
                    <li><span>Employés et Ouvriers</span> <strong>1 500 F CFA</strong></li>
                </ul>
                <p class="note">* Prélèvements mensuels à la source</p>
            </div>

            <div class="info-box dark">
                <h2><span class="step-num">3</span> Fonctionnement</h2>
                <div class="func-item">
                    <strong>Alimentation</strong>
                    <p>Retenue à la source, versements espèces ou virements libres.</p>
                </div>
                <div class="func-item">
                    <strong>Retraits</strong>
                    <p>1 retrait/mois autorisé (min. 5 000 F). Solde doit rester ≥ 24 fois votre cotisation.</p>
                </div>
            </div>
        </div>

        <div class="leverage-section">
            <h2><span class="step-num">4</span> Un Levier pour vos Crédits</h2>
            <p>Plus vous épargnez longtemps, plus le taux d'intérêt de vos futurs crédits diminue :</p>
            
            <div class="table-responsive">
                <table class="ma2e-table">
                    <thead>
                        <tr>
                            <th>Durée de l'Épargne Expresse</th>
                            <th>Taux d'Intérêt du Crédit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>Entre 24 et 48 mois</td><td><strong>9%</strong></td></tr>
                        <tr><td>Entre 48 et 72 mois</td><td><strong>8,5%</strong></td></tr>
                        <tr><td>Entre 72 et 96 mois</td><td><strong>8%</strong></td></tr>
                        <tr><td>Entre 96 et 120 mois</td><td><strong>7,5%</strong></td></tr>
                        <tr><td>Plus de 120 mois</td><td><span class="best-rate">7% (Meilleur taux)</span></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="cloture-note">
            <p><strong>Note sur la Clôture :</strong> Le compte peut être clôturé sur demande écrite en cas de rupture d'adhésion. Aucun frais de clôture n'est appliqué.</p>
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
    const navbar = document.getElementById("mainNavbar");

    // Animation navbar au scroll
    window.addEventListener("scroll", () => {
        if (window.scrollY > 50) {
            navbar.classList.add("scrolled");
        } else {
            navbar.classList.remove("scrolled");
        }
    });

    // Ouvrir/Fermer le menu mobile
    if (toggle) {
        toggle.addEventListener("click", () => {
            menu.classList.toggle("active");
            overlay.classList.toggle("active");
            document.body.style.overflow = menu.classList.contains("active") ? "hidden" : "";
        });
    }

    // Fermer en cliquant sur l'ombre
    if (overlay) {
        overlay.addEventListener("click", () => {
            menu.classList.remove("active");
            overlay.classList.remove("active");
            document.body.style.overflow = "";
        });
    }

    // Sous-menus sur mobile
    triggers.forEach(trigger => {
        trigger.addEventListener("click", function(e) {
            if (window.innerWidth <= 992) {
                e.preventDefault();
                this.parentElement.classList.toggle("active");
            }
        });
    });

    // Fermer le menu au redimensionnement
    window.addEventListener("resize", () => {
        if (window.innerWidth > 992 && menu.classList.contains("active")) {
            menu.classList.remove("active");
            overlay.classList.remove("active");
            document.body.style.overflow = "";
        }
    });
});
</script>
</body>
</html>