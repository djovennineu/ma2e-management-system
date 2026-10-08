<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Page d'accueil avec carousel Arii">
    <title>Arii – Accueil</title>

    <!-- Bootstrap CSS -->
    <link 
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" 
        rel="stylesheet"
    >
    <!-- Bootstrap Icons -->
    <link 
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" 
        rel="stylesheet"
    >

         <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

</head>

<body>

<style>


/* ============================================================
       KEYFRAMES
       ============================================================ */
    @keyframes fadeUp {
        from { opacity: 0; transform: translateY(36px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    @keyframes fadeLeft {
        from { opacity: 0; transform: translateX(-40px); }
        to   { opacity: 1; transform: translateX(0); }
    }

    @keyframes scaleIn {
        from { opacity: 0; transform: scale(0.88); }
        to   { opacity: 1; transform: scale(1); }
    }

    @keyframes checkPop {
        0%   { opacity: 0; transform: scale(0) rotate(-20deg); }
        70%  { transform: scale(1.15) rotate(5deg); }
        100% { opacity: 1; transform: scale(1) rotate(0deg); }
    }

    @keyframes shimmer {
        0%   { background-position: -200% center; }
        100% { background-position:  200% center; }
    }

    @keyframes pulse {
        0%, 100% { box-shadow: 0 0 0 0   rgba(144, 248, 118, 0.4); }
        50%       { box-shadow: 0 0 0 12px rgba(144, 248, 118, 0); }
    }

    :root {
        /* Couleurs */
        --green:       #90F876;
        --green-dark:  #6cd452;
        --green-glow:  rgba(144, 248, 118, 0.15);
        --black:       #0c0c0c;
        --dark:        #141414;
        --dark-2:      #1e1e1e;
        --white:       #ffffff;
        --grey-bg:     #f6f6f4;
        --grey-light:  #eeeeea;
        --grey-text:   #666666;
        --orange:      #F3A93B;
        --red:         #e74c3c;

        /* Rayons */
        --radius-sm:   8px;
        --radius-md:   14px;
        --radius-lg:   22px;
        --radius-xl:   32px;

        /* Ombres */
        --shadow-sm:   0 2px 12px rgba(0, 0, 0, 0.06);
        --shadow-md:   0 8px 32px rgba(0, 0, 0, 0.10);
        --shadow-lg:   0 20px 60px rgba(0, 0, 0, 0.14);

        /* Easings */
        --ease-out:    cubic-bezier(0.4, 0, 0.2, 1);
        --ease-spring: cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }


    /* ============================================================
       UTILITAIRES — ANIMATIONS AU SCROLL
       ============================================================ */
    .anim-hidden {
        opacity: 0;
        transform: translateY(40px);
        transition:
            opacity   0.7s var(--ease-out),
            transform 0.7s var(--ease-out);
    }

    .anim-hidden.from-left  { transform: translateX(-45px); }
    .anim-hidden.from-right { transform: translateX(45px); }
    .anim-hidden.from-scale { transform: scale(0.88); }

    .anim-hidden.is-visible {
        opacity: 1;
        transform: translate(0) scale(1);
    }

    /* Cascade décalée */
    .anim-stagger .anim-hidden:nth-child(1) { transition-delay: 0.05s; }
    .anim-stagger .anim-hidden:nth-child(2) { transition-delay: 0.12s; }
    .anim-stagger .anim-hidden:nth-child(3) { transition-delay: 0.19s; }
    .anim-stagger .anim-hidden:nth-child(4) { transition-delay: 0.26s; }


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
        background: rgba(10, 10, 10, 0.9);
        backdrop-filter: blur(14px);
        border-bottom: 1px solid rgba(144, 248, 118, 0.15);
        z-index: 2000;
        transition:
            height     0.35s var(--ease-out),
            box-shadow 0.35s ease;
    }

    /* État scrollé */
    .navbar.scrolled {
        height: 65px;
        box-shadow: 0 4px 24px rgba(0, 0, 0, 0.4);
        border-bottom-color: rgba(144, 248, 118, 0.25);
    }

    /* Logo */
    .navbar-brand img {
        width: 120px;
        height: auto;
        display: block;
        filter: brightness(1.1);
    }


    /* ============================================================
       NAVBAR — MENU CAPSULE (DESKTOP)
       ============================================================ */
    .navbar-menu {
        position: absolute;
        top: calc(100% - 4px);
        left: 50%;
        transform: translateX(-50%);
        padding: 0 18px;
        background: rgba(12, 12, 12, 0.97);
        border-radius: 50px;
        border: 1px solid rgba(144, 248, 118, 0.12);
        box-shadow: 0 12px 48px rgba(0, 0, 0, 0.4);
        backdrop-filter: blur(14px);
        z-index: 1000;
    }

    .arii-menu {
        display: flex;
        list-style: none;
        align-items: center;
    }

    .arii-menu > li > a {
        display: block;
        padding: 16px 15px;
        color: rgba(255, 255, 255, 0.85);
        text-decoration: none;
        font-family: 'Syne', sans-serif;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        transition: color 0.25s ease;
        position: relative;
    }

    /* Soulignement animé */
    .arii-menu > li > a::after {
        content: '';
        position: absolute;
        bottom: 10px;
        left: 50%;
        width: calc(100% - 20px);
        height: 2px;
        background: var(--green);
        border-radius: 2px;
        transform: translateX(-50%) scaleX(0);
        transform-origin: center;
        transition: transform 0.3s var(--ease-spring);
    }

    .arii-menu > li > a:hover              { color: var(--green); }
    .arii-menu > li > a:hover::after       { transform: translateX(-50%) scaleX(1); }


    /* ============================================================
       NAVBAR — SOUS-MENUS
       ============================================================ */
    .submenu {
        position: absolute;
        top: calc(100% + 8px);
        left: 0;
        min-width: 210px;
        list-style: none;
        padding: 8px 0;
        z-index: 1001;
        background: #fff;
        border-radius: var(--radius-md);
        border: 1px solid rgba(0, 0, 0, 0.06);
        box-shadow: var(--shadow-lg);
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
        background: #f5fdf2;
        color: var(--green-dark);
        padding-left: 26px;
    }

    /* Affichage desktop */
    @media (min-width: 993px) {
        .arii-menu li:hover > .submenu {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        /* Niveau 3 — s'ouvre à droite */
        .submenu .has-submenu:hover > .submenu {
            top: 0;
            left: 100%;
        }
    }


    /* ============================================================
       NAVBAR — BOUTON ESPACE MEMBRE
       ============================================================ */
    .btn-espace-membre {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 10px 16px;
        background: var(--green);
        color: #000;
        border: 2px solid transparent;
        border-radius: var(--radius-sm);
        text-decoration: none;
        font-family: 'Syne', sans-serif;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        flex-shrink: 0;
        transition:
            background   0.3s ease,
            color        0.3s ease,
            border-color 0.3s ease,
            transform    0.3s var(--ease-spring),
            box-shadow   0.3s ease;
    }

    .btn-espace-membre:hover {
        background: transparent;
        color: var(--green);
        border-color: var(--green);
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(144, 248, 118, 0.2);
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
        padding: 6px;
        border-radius: 8px;
        z-index: 1100;
    }

    .menu-toggle span {
        display: block;
        width: 22px;
        height: 2px;
        background: #fff;
        border-radius: 2px;
        transition:
            transform  0.35s var(--ease-spring),
            opacity    0.25s ease,
            background 0.25s ease;
    }

    /* Animation en croix */
    .menu-toggle.active span:nth-child(1) { transform: translateY(7px) rotate(45deg);  background: var(--green); }
    .menu-toggle.active span:nth-child(2) { opacity: 0; }
    .menu-toggle.active span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); background: var(--green); }


    /* ============================================================
       NAVBAR — OVERLAY (MOBILE)
       ============================================================ */
    .menu-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0);
        backdrop-filter: blur(0px);
        z-index: 999;
        pointer-events: none;
        transition:
            background      0.4s ease,
            backdrop-filter 0.4s ease;
    }

    .menu-overlay.active {
        background: rgba(0, 0, 0, 0.6);
        backdrop-filter: blur(4px);
        pointer-events: auto;
    }


    /* ============================================================
       NAVBAR — RESPONSIVE MOBILE (≤ 992px)
       ============================================================ */
    @media (max-width: 992px) {

        .menu-toggle { display: flex; }

        /* Ordre des éléments */
        .navbar-brand      { order: 1; }
        .btn-espace-membre { order: 2; margin: 0 auto; padding: 8px 12px; font-size: 10px; }
        .menu-toggle       { order: 3; }

        /* Sidebar coulissante */
        .navbar-menu {
            position: fixed;
            top: 0;
            left: -100%;
            transform: none;
            width: min(290px, 80vw);
            height: 100vh;
            padding-top: 80px;
            margin-top: 0;
            border-radius: 0;
            overflow-y: auto;
            display: block;
            transition:
                left       0.42s var(--ease-out),
                box-shadow 0.4s ease;
        }

        .navbar-menu.active {
            left: 0;
            box-shadow: 8px 0 40px rgba(0, 0, 0, 0.5);
        }

        /* Menu vertical */
        .arii-menu {
            flex-direction: column;
            align-items: stretch;
            padding: 12px 0 40px;
        }

        .arii-menu > li {
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .arii-menu > li > a {
            padding: 16px 24px;
            font-size: 13px;
            color: rgba(255, 255, 255, 0.8);
        }

        .arii-menu > li > a::after { display: none; }

        /* Sous-menus en accordéon */
        .submenu {
            position: static;
            display: none;
            width: 100%;
            background: rgba(255, 255, 255, 0.04);
            box-shadow: none;
            border-radius: 0;
            padding-left: 20px;
            opacity: 1;
            visibility: visible;
            transform: none;
            border: none;
        }

        .arii-menu li.active > .submenu { display: block; }

        .submenu a {
            color: #ccc;
            padding: 12px 20px;
        }

        .submenu a:hover {
            background: rgba(144, 248, 118, 0.08);
            color: var(--green);
            padding-left: 28px;
        }
    }

/* ===================== */
/* CARDS                 */
/* ===================== */
.cards-container {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 25px;
    padding: 120px 5% 50px;
}

.card {
    background: #fff;
    border-radius: 15px;
    flex: 1 1 calc(33.333% - 25px);
    min-width: 280px;
    max-width: 380px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    overflow: hidden;
    padding: 20px;
}

.card:hover {
    transform: translateY(-10px);
    box-shadow: 0 12px 30px rgba(0,0,0,0.15);
}

/* ===================== */
/* HERO CAROUSEL         */
/* ===================== */
:root {
    --primary-color: #0056b3;
    --accent-color: #ffcc00;
    --text-light: #ffffff;
}

.hero-carousel {
    position: relative;
    width: 100%;
    height: 85vh;
    background: #000;
    overflow: hidden;
}

.carousel-slide {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    transition: opacity 1s ease-in-out, transform 1.4s cubic-bezier(0.4, 0, 0.2, 1);
    z-index: 1;
    transform: scale(1);
}

.carousel-slide.active {
    opacity: 1;
    z-index: 2;
    transform: scale(1.05);
}

.carousel-slide img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.carousel-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(to bottom, rgba(0,0,0,0.3), rgba(0,0,0,0.7));
    z-index: 2;
}

.carousel-content {
    position: absolute;
    top: 50%;
    left: 10%;
    transform: translateY(-50%);
    color: var(--text-light);
    z-index: 10;
    max-width: 600px;
}

.carousel-content h1 {
    font-size: 3.5rem;
    font-weight: 800;
    margin-bottom: 20px;
    line-height: 1.2;
    opacity: 0;
}

.carousel-content p {
    font-size: 1.2rem;
    margin-bottom: 30px;
    opacity: 0;
}

.carousel-slide.active .carousel-content h1 {
    animation: fadeInUp 0.9s ease-out 0.2s both;
}

.carousel-slide.active .carousel-content p {
    animation: fadeInUp 0.9s ease-out 0.4s both;
}

.carousel-slide.active .btn-cta {
    animation: fadeInUp 0.9s ease-out 0.6s both;
}

.btn-cta {
    display: inline-block;
    padding: 15px 35px;
    background-color: var(--primary-color);
    color: white;
    text-decoration: none;
    border-radius: 50px;
    font-weight: bold;
    transition: background 0.3s ease, transform 0.3s ease, color 0.3s ease;
    opacity: 0;
}

.btn-cta:hover {
    background-color: white;
    color: var(--primary-color);
    transform: translateY(-3px);
}

.slide-counter {
    position: absolute;
    top: 40px;
    right: 50px;
    color: white;
    font-size: 1.5rem;
    font-weight: bold;
    z-index: 20;
}

.carousel-indicators-custom {
    position: absolute;
    bottom: 30px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 15px;
    z-index: 20;
}

.indicator-dot {
    width: 12px;
    height: 12px;
    border: 2px solid white;
    border-radius: 50%;
    cursor: pointer;
    transition: all 0.3s ease;
}

.indicator-dot.active {
    background: var(--primary-color);
    width: 30px;
    border-radius: 10px;
}

.carousel-controls {
    position: absolute;
    top: 50%;
    width: 100%;
    display: flex;
    justify-content: space-between;
    padding: 0 30px;
    transform: translateY(-50%);
    z-index: 20;
}

.carousel-btn {
    background: rgba(255, 255, 255, 0.2);
    border: none;
    color: white;
    width: 50px;
    height: 50px;
    border-radius: 50%;
    cursor: pointer;
    backdrop-filter: blur(5px);
    transition: background 0.3s ease, transform 0.3s ease;
}

.carousel-btn:hover {
    background: var(--primary-color);
    transform: scale(1.1);
}

/* ===================== */
/* SECTION PRÉSENTATION  */
/* ===================== */
.presentation-section {
    padding: 80px 0;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    position: relative;
    overflow: hidden;
}

.presentation-section::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 500px;
    height: 500px;
    background: radial-gradient(circle, rgba(243, 169, 59, 0.1) 0%, transparent 70%);
    border-radius: 50%;
    z-index: 0;
}

.presentation-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
    position: relative;
    z-index: 1;
}

.presentation-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 60px;
    align-items: center;
}

/* ===================== */
/* IMAGES PRÉSENTATION   */
/* ===================== */
.presentation-images {
    position: relative;
    height: 500px;
}

.presentation-image {
    position: absolute;
    overflow: hidden;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
    border-radius: 15px;
    transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1),
                box-shadow 0.4s ease;
}

.presentation-image:hover {
    transform: translateY(-10px) !important;
    box-shadow: 0 25px 50px rgba(0, 0, 0, 0.2);
}

.presentation-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform 0.6s ease;
}

.presentation-image:hover img {
    transform: scale(1.08);
}

.img-back {
    width: 65%;
    height: 350px;
    top: 0;
    right: 0;
    z-index: 1;
}

.img-front {
    width: 65%;
    height: 300px;
    bottom: 0;
    left: 0;
    z-index: 2;
    border: 8px solid #ffffff;
    box-shadow: 0 25px 50px rgba(0, 0, 0, 0.2);
}

.presentation-images::after {
    content: '';
    position: absolute;
    width: 200px;
    height: 200px;
    background-color: #F3A93B;
    border-radius: 50%;
    bottom: -50px;
    right: -50px;
    z-index: 0;
    opacity: 0.15;
    animation: floatY 8s ease-in-out infinite;
}

/* ===================== */
/* CONTENU PRÉSENTATION  */
/* ===================== */
.presentation-content {
    position: relative;
    padding: 20px;
}

.presentation-logo {
    max-width: 300px;
    margin-bottom: 80px;
    filter: drop-shadow(0 2px 8px rgba(0, 0, 0, 0.1));
    transition: transform 0.3s ease;
}

.presentation-logo:hover {
    transform: scale(1.05) rotate(-2deg);
}

.presentation-text {
    font-size: 1.1rem;
    line-height: 1.9;
    color: #444444;
    margin-bottom: 35px;
    text-align: justify;
}

.presentation-title {
    display: block;
    font-weight: 800;
    font-size: 1.4rem;
    color: #1a1a1a;
    margin-bottom: 15px;
    position: relative;
    padding-left: 20px;
}

.presentation-title::before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%) scaleY(0);
    transform-origin: center;
    width: 5px;
    height: 60%;
    background: linear-gradient(180deg, #F3A93B 0%, #e2962a 100%);
    border-radius: 3px;
    transition: transform 0.4s ease 0.3s;
}

.presentation-title.visible::before {
    transform: translateY(-50%) scaleY(1);
}

/* ===================== */
/* BOUTON PRÉSENTATION   */
/* ===================== */
.presentation-btn {
    display: inline-block;
    background: linear-gradient(135deg, #F3A93B 0%, #e2962a 100%);
    color: #ffffff;
    padding: 16px 45px;
    border-radius: 50px;
    text-decoration: none;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 0.9rem;
    letter-spacing: 1px;
    transition: transform 0.3s ease, box-shadow 0.3s ease, color 0.3s ease;
    box-shadow: 0 5px 20px rgba(243, 169, 59, 0.3);
    position: relative;
    overflow: hidden;
}

.presentation-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
    transition: left 0.5s ease;
}

.presentation-btn:hover::before { left: 100%; }

.presentation-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(243, 169, 59, 0.45);
    color: #ffffff;
}

.presentation-btn:active { transform: translateY(-1px); }

/* ===================== */
/* RESPONSIVE TABLETTE   */
/* ===================== */
@media (max-width: 992px) {
    .presentation-section { padding: 60px 0; }
    .presentation-grid { grid-template-columns: 1fr; gap: 50px; }
    .presentation-images { height: 450px; order: 2; margin: 0 auto; max-width: 500px; }
    .presentation-content { order: 1; text-align: center; padding: 0 10px; }
    .presentation-logo { margin-left: auto; margin-right: auto; }
    .presentation-text { text-align: center; }
    .presentation-title { padding-left: 0; }
    .presentation-title::before { display: none; }
}

@media (max-width: 768px) {
    .presentation-section { padding: 50px 0; }
    .presentation-images { height: 400px; }
    .img-back { width: 70%; height: 280px; }
    .img-front { width: 70%; height: 250px; border-width: 6px; }
    .presentation-text { font-size: 1rem; line-height: 1.7; }
    .presentation-title { font-size: 1.2rem; }
}

@media (max-width: 576px) {
    .presentation-section { padding: 40px 0; }
    .presentation-container { padding: 0 15px; }
    .presentation-images { height: 350px; }
    .img-back, .img-front { width: 75%; height: 220px; border-radius: 12px; }
    .img-front { border-width: 5px; }
    .presentation-logo { max-width: 160px; margin-bottom: 25px; }
    .presentation-text { font-size: 0.95rem; line-height: 1.6; margin-bottom: 25px; }
    .presentation-title { font-size: 1.1rem; margin-bottom: 12px; }
    .presentation-btn { padding: 14px 35px; font-size: 0.85rem; }
}

/* ===================== */
/* SECTION ADHÉSION      */
/* ===================== */
.section-adhesion {
    padding: 80px 20px;
    background-color: #ffffff;
    text-align: center;
}

.conteneur-adhesion {
    max-width: 900px;
    margin: 0 auto 50px auto;
}

.titre-principal {
    font-size: 2.5rem;
    font-weight: 800;
    color: #1a1a1a;
    margin-bottom: 20px;
    position: relative;
}

.description-adhesion {
    font-size: 1.1rem;
    color: #555;
    line-height: 1.6;
}

.grille-cartes {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 25px;
    max-width: 1200px;
    margin: 0 auto;
}

.carte-etape {
    flex: 1 1 calc(25% - 25px);
    min-width: 250px;
    background: #fdfdfd;
    border-radius: 15px;
    padding: 30px 20px;
    border: 1px solid #eee;
    transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                border-color 0.3s ease,
                box-shadow 0.35s ease;
    box-shadow: 0 10px 20px rgba(0,0,0,0.05);
}

.carte-etape:hover {
    transform: translateY(-12px);
    border-color: #F3A93B;
    box-shadow: 0 20px 40px rgba(243, 169, 59, 0.18);
}

.carte-numero {
    width: 50px;
    height: 50px;
    background-color: #F3A93B;
    color: #fff;
    font-size: 1.5rem;
    font-weight: 800;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px auto;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.carte-etape:hover .carte-numero {
    transform: scale(1.15) rotate(-5deg);
    box-shadow: 0 0 0 8px rgba(243, 169, 59, 0.15);
}

.carte-texte {
    font-size: 1rem;
    color: #333;
    font-weight: 500;
    line-height: 1.4;
}

@media (max-width: 992px) {
    .carte-etape { flex: 1 1 calc(50% - 25px); }
}

@media (max-width: 576px) {
    .titre-principal { font-size: 1.8rem; }
    .carte-etape { flex: 1 1 100%; }
}

/* Bouton télécharger */
.action-telechargement {
    margin-top: 50px;
    padding: 40px;
    background: #fdfdfd;
    border-top: 2px dashed #eee;
    text-align: center;
}

.texte-appel {
    font-size: 1.1rem;
    color: #666;
    margin-bottom: 25px;
}

.bouton-telecharger {
    display: inline-flex;
    align-items: center;
    gap: 12px;
    background: linear-gradient(135deg, #F3A93B 0%, #e2962a 100%);
    color: #ffffff;
    padding: 18px 35px;
    border-radius: 50px;
    text-decoration: none;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 0.95rem;
    letter-spacing: 0.5px;
    transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275),
                box-shadow 0.3s ease;
    box-shadow: 0 10px 20px rgba(243, 169, 59, 0.3);
}

.bouton-telecharger i {
    font-size: 1.3rem;
    transition: transform 0.3s ease;
}

.bouton-telecharger:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 30px rgba(243, 169, 59, 0.4);
    color: #ffffff;
}

.bouton-telecharger:hover i {
    animation: bounceIcon 0.6s ease-out;
}

.bouton-telecharger:active { transform: translateY(-2px); }

@media (max-width: 576px) {
    .bouton-telecharger {
        width: 100%;
        justify-content: center;
        padding: 15px 20px;
        font-size: 0.85rem;
    }
}

/* ===================== */
/* SECTION AVANTAGES     */
/* ===================== */
.section-avantages {
    padding: 100px 20px;
    background-color: #fcfcfc;
}

.conteneur-avantages {
    max-width: 1200px;
    margin: 0 auto;
    display: grid;
    grid-template-columns: 1fr 1.2fr;
    gap: 80px;
    align-items: center;
}

.avantages-visuel {
    position: relative;
}

.image-principale {
    width: 100%;
    border-radius: 20px;
    position: relative;
    z-index: 2;
    box-shadow: 0 20px 40px rgba(0,0,0,0.1);
    transition: transform 0.4s ease, box-shadow 0.4s ease;
}

.image-principale:hover {
    transform: scale(1.02);
    box-shadow: 0 30px 60px rgba(0,0,0,0.15);
}

.decoration-orange {
    position: absolute;
    width: 100px;
    height: 100px;
    background-color: #F3A93B;
    bottom: -20px;
    left: -20px;
    border-radius: 15px;
    z-index: 1;
    animation: floatY 5s ease-in-out infinite 1s;
}

.titre-avantages {
    font-size: 2.2rem;
    font-weight: 800;
    color: #1a1a1a;
    margin-bottom: 40px;
}

.liste-avantages {
    display: flex;
    flex-direction: column;
    gap: 30px;
}

.item-avantage {
    display: flex;
    align-items: center;
    gap: 25px;
    padding: 20px;
    background: #ffffff;
    border-radius: 15px;
    transition: transform 0.3s ease,
                border-left-color 0.3s ease,
                box-shadow 0.3s ease;
    border-left: 5px solid transparent;
    box-shadow: 0 5px 15px rgba(0,0,0,0.03);
}

.item-avantage:hover {
    transform: translateX(10px);
    border-left-color: #F3A93B;
    box-shadow: 0 10px 25px rgba(0,0,0,0.08);
}

.icone-enveloppe {
    width: 60px;
    height: 60px;
    min-width: 60px;
    background-color: rgba(243, 169, 59, 0.1);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.35s cubic-bezier(0.175, 0.885, 0.32, 1.275),
                background-color 0.3s ease;
}

.item-avantage:hover .icone-enveloppe {
    transform: rotate(-8deg) scale(1.12);
    background-color: rgba(243, 169, 59, 0.2);
}

.icone-enveloppe img {
    width: 35px;
    height: 35px;
    object-fit: contain;
}

.texte-avantage p {
    margin: 0;
    font-size: 1.1rem;
    color: #444;
    line-height: 1.5;
}

.texte-avantage strong { color: #F3A93B; }

@media (max-width: 992px) {
    .conteneur-avantages { grid-template-columns: 1fr; gap: 50px; }
    .avantages-visuel { max-width: 500px; margin: 0 auto; }
    .titre-avantages { text-align: center; }
}

/* ===================== */
/* SECTION STATS         */
/* ===================== */
.stats-ma2e {
    position: relative;
    width: 100%;
    min-height: 400px;
    background: url('../photo/arii.png') no-repeat center center/cover;
    display: flex;
    align-items: center;
    justify-content: center;
}

.stats-overlay {
    width: 100%;
    height: 100%;
    padding: 80px 20px;
    background-color: rgba(0, 0, 0, 0.7);
}

.stats-conteneur {
    max-width: 1200px;
    margin: 0 auto;
    display: flex;
    flex-wrap: wrap;
    justify-content: space-around;
    gap: 20px;
}

.stats-carte {
    flex: 1;
    min-width: 200px;
    text-align: center;
    color: #ffffff;
    padding: 20px;
}

.stats-nombre {
    font-size: 3rem;
    font-weight: 800;
    margin-bottom: 10px;
    background: linear-gradient(90deg, #F3A93B 0%, #ffde87 40%, #F3A93B 60%, #e2962a 100%);
    background-size: 200% auto;
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    display: inline-block;
}

.stats-nombre.shimmer-active {
    animation: shimmer 2.5s linear infinite;
}

.stats-label {
    font-size: 1.1rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    line-height: 1.4;
}

@media (max-width: 768px) {
    .stats-nombre { font-size: 2.2rem; }
    .stats-carte { flex: 1 1 45%; }
}

@media (max-width: 480px) {
    .stats-carte { flex: 1 1 100%; }
}

/* ===================== */
/* SECTION DEVENIR MEMBRE*/
/* ===================== */
.devenir-membre {
    background: linear-gradient(135deg, #012a4a 0%, #01497c 100%);
    padding: 100px 20px;
    text-align: center;
    color: #ffffff;
    margin: 60px auto;
    max-width: 1100px;
    border-radius: 20px;
    box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
}

.conteneur-devenir {
    max-width: 850px;
    margin: 0 auto;
}

.titre-devenir {
    font-size: 2.8rem;
    font-weight: 800;
    margin-bottom: 25px;
    letter-spacing: -1px;
}

.texte-devenir {
    font-size: 1.25rem;
    line-height: 1.7;
    margin-bottom: 40px;
    color: rgba(255, 255, 255, 0.9);
}

.btn-devenir {
    display: inline-block;
    background-color: #fbca05ff;
    color: #012a4a;
    padding: 18px 50px;
    font-size: 1.1rem;
    font-weight: bold;
    text-decoration: none;
    text-transform: uppercase;
    border-radius: 50px;
    transition: background 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275),
                color 0.3s ease,
                transform 0.3s ease,
                box-shadow 0.3s ease;
    box-shadow: 0 8px 20px rgba(255, 204, 0, 0.3);
    animation: attentionPulse 2.5s ease-in-out 2s infinite;
}

.btn-devenir:hover {
    background-color: #ffffff;
    color: #01497c;
    transform: translateY(-5px) scale(1.05);
    box-shadow: 0 12px 25px rgba(255, 255, 255, 0.4);
    animation: none;
}

@media (max-width: 768px) {
    .titre-devenir { font-size: 1.8rem; }
    .devenir-membre { padding: 60px 15px; }
}

/* ===================== */
/* FOOTER                */
/* ===================== */
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
    color: #F3A93B;
    font-size: 1.2rem;
    margin-bottom: 20px;
}

.footer-logo {
    max-width: 150px;
    margin-bottom: 20px;
    transition: opacity 0.3s ease;
}

.footer-logo:hover { opacity: 0.8; }

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
    transition: color 0.3s ease, padding-left 0.3s ease;
}

.liens-footer a:hover {
    color: #F3A93B;
    padding-left: 5px;
}

.bas-footer {
    text-align: center;
    margin-top: 50px;
    padding-top: 20px;
    border-top: 1px solid #333;
    font-size: 0.9rem;
    color: #777;
}

.reseaux-sociaux a {
    color: white;
    font-size: 1.5rem;
    margin-right: 15px;
    transition: color 0.3s ease, transform 0.3s ease;
    display: inline-block;
}

.reseaux-sociaux a:hover {
    color: #F3A93B;
    transform: translateY(-3px) scale(1.1);
}

@media (max-width: 768px) {
    .conteneur-footer { grid-template-columns: 1fr; text-align: center; }
    .infos-contact li { justify-content: center; }
}

/* ============================================================ */
/* KEYFRAMES GLOBAUX                                            */
/* ============================================================ */

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

@keyframes floatY {
    0%, 100% { transform: translateY(0px); }
    50%       { transform: translateY(-12px); }
}

@keyframes shimmer {
    0%   { background-position: -200% center; }
    100% { background-position:  200% center; }
}

@keyframes bounceIcon {
    0%, 100% { transform: translateY(0); }
    40%       { transform: translateY(-7px); }
    70%       { transform: translateY(-3px); }
}

@keyframes attentionPulse {
    0%, 100% { box-shadow: 0 8px 20px rgba(251, 202, 5, 0.3); }
    50%       { box-shadow: 0 8px 40px rgba(251, 202, 5, 0.65); }
}

@keyframes scaleIn {
    from { opacity: 0; transform: scale(0.8); }
    to   { opacity: 1; transform: scale(1); }
}

/* ============================================================ */
/* SCROLL-TRIGGERED — classes utilitaires (gérées par JS)       */
/* ============================================================ */

.anim-hidden {
    opacity: 0;
    transform: translateY(40px);
    transition: opacity 0.75s cubic-bezier(0.4, 0, 0.2, 1),
                transform 0.75s cubic-bezier(0.4, 0, 0.2, 1);
}

.anim-hidden.from-left  { transform: translateX(-55px); }
.anim-hidden.from-right { transform: translateX(55px); }
.anim-hidden.from-scale { transform: scale(0.85); opacity: 0; }

.anim-hidden.is-visible {
    opacity: 1;
    transform: translate(0) scale(1);
}

/* Délais de cascade automatiques */
.anim-stagger .anim-hidden:nth-child(1) { transition-delay: 0.05s; }
.anim-stagger .anim-hidden:nth-child(2) { transition-delay: 0.15s; }
.anim-stagger .anim-hidden:nth-child(3) { transition-delay: 0.25s; }
.anim-stagger .anim-hidden:nth-child(4) { transition-delay: 0.35s; }
.anim-stagger .anim-hidden:nth-child(5) { transition-delay: 0.45s; }

/* Images présentation : flottement après apparition */
.img-back.is-visible  { animation: floatY 7s ease-in-out infinite 0.5s; }
.img-front.is-visible { animation: floatY 7s ease-in-out infinite 1.2s; }

/* Chiffres stats : entrée avec rebond */
.stats-carte.is-visible .stats-nombre {
    animation: scaleIn 0.7s cubic-bezier(0.175, 0.885, 0.32, 1.275) both;
}
</style>

<!-- Overlay mobile -->
<!-- ===== OVERLAY ===== -->
<div class="menu-overlay" id="menuOverlay"></div>

<!-- ===== NAVBAR ===== -->
<nav class="navbar navbar-light bg-transparent w-100">
    <div class="container-fluid justify-content-between">
        <a class="navbar-brand" href="#"><img src="../photo/images.png" alt="Logo Arii"></a>

        <!-- Bouton hamburger -->
        <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">
            <span></span><span></span><span></span>
        </button>

        <!-- Espace membre -->
        <a href="../connexion/pageconnexion.php" class="btn btn-espace-membre">
                    <i class="bi bi-person-circle"></i>
ESPACE MEMBRE</a>
    </div>

    <!-- MENU -->
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
</nav>


<!-- ===== CAROUSEL ===== -->
<div class="hero-carousel" id="heroCarousel">
    <div class="slide-counter">
        <span id="currentSlide">01</span> / <span id="totalSlides">02</span>
    </div>

    <div class="carousel-slide active" data-slide="1">
        <img src="../photo/arii.png" alt="Présentation Arii">
        <div class="carousel-overlay"></div>
        <div class="carousel-content">
            <h1>Bienvenue chez MA<span style="color:#ffdb0f;">2</span>E</h1>
            <p>Votre partenaire de confiance pour tous vos projets d'épargne et de crédit. Construisons ensemble votre avenir financier.</p>
            <a href="#" class="btn-cta">Découvrir nos services</a>
        </div>
    </div>

    <div class="carousel-slide" data-slide="2">
        <img src="../photo/femme.png" alt="Femme utilisant Arii">
        <div class="carousel-overlay"></div>
        <div class="carousel-content">
            <h1>Des solutions adaptées</h1>
            <p>Bénéficiez de nos produits d'épargne et de crédit sur mesure, conçus pour répondre à vos besoins spécifiques.</p>
            <a href="#" class="btn-cta">Nos produits</a>
        </div>
    </div>

    <div class="carousel-indicators-custom">
        <span class="indicator-dot active" data-slide="1"></span>
        <span class="indicator-dot" data-slide="2"></span>
    </div>

    <div class="carousel-controls">
        <button class="carousel-btn" id="prevBtn" aria-label="Slide précédent">
            <i class="bi bi-chevron-left"></i>
        </button>
        <button class="carousel-btn" id="nextBtn" aria-label="Slide suivant">
            <i class="bi bi-chevron-right"></i>
        </button>
    </div>
</div>

<!-- ===== CARDS ===== -->
<div class="cards-container">
<div class="card" >
  <div class="card-body">
    <img src="../photo/argent.png" class="card-img-left" alt="..." width="50" height="50">
    <h5 class="card-title">Produits Epagne</h5>
    <p class="card-text">Différents types d'épargnes pour le bien-être financier de nos adhérents</p>
  </div>
</div>

<div class="card" >
  <div class="card-body">
        <img src="../photo/carte.png" class="card-img-left" alt="..." width="50" height="50">

    <h5 class="card-title">Formule De Credit</h5>
    <p class="card-text">Un financement simple pour concrétiser vos projet</p>
  </div>
</div>

<div class="card" >
  <div class="card-body">
        <img src="../photo/maison.png" class="card-img-left" alt="..." width="50" height="50">
    <h5 class="card-title">Projet Imobilier</h5>
    <p class="card-text">Offrez-vous la maison de votre choix.</p>
  </div>
</div>
</div>


<!-- ===== HTML ===== -->
<section class="presentation-section">
    <div class="presentation-container">
        <div class="presentation-grid">
            
            <!-- Images -->
            <div class="presentation-images">
                <div class="presentation-image img-back">
                    <img src="../photo/hommecie.JPG" alt="Ouvrier technique">
                </div>
                <div class="presentation-image img-front">
                    <img src="../photo/femmetravaillee.jpg" alt="Ingénieur avec tablette">
                </div>
            </div>

            <!-- Contenu -->
            <div class="presentation-content">
                <img src="../photo/logosansnom.png" alt="Logo MA2E" class="presentation-logo">
                <p class="presentation-text">
                    <span class="presentation-title">La Mutuelle des Agents de l'Eau de l'Électricité</span> 
                    est une institution de micro finance initiée par le Président Marcel Zadi Kessy en vue d’offrir à ses adhérents un accès équitable à des services financiers de qualité, de façon durable, avec professionnalisme pour l’amélioration de leurs conditions sociales. L’adhésion libre et volontaire est exclusivement réservée au personnel de SODECI, CIE, GS2E et CIPREL en activité.
                </p>
                <a href="#" class="presentation-btn">VOIR PLUS</a>
            </div>

        </div>
    </div>
</section>


<section class="section-adhesion">
    <div class="conteneur-adhesion"> 
      <h2 class="titre-principal">QUI PEUT ADHÉRER ?</h2>
      <p class="description-adhesion">
        L’adhésion est ouverte exclusivement aux agents en activité et aux retraités de la <strong>CIE, SODECI, CIPREL, GS2E, SGA2E et SIVE</strong>. Pour faciliter votre inscription, merci de prévoir les éléments suivants :
      </p>
    </div>

    <div class="grille-cartes">
        <div class="carte-etape">
            <div class="carte-corps">
                <div class="carte-numero">1</div>
                <p class="carte-texte">Une demande d’adhésion (formulaire à télécharger sur le site)</p>
            </div>
        </div>

        <div class="carte-etape">
            <div class="carte-corps">
                <div class="carte-numero">2</div>
                <p class="carte-texte">Une copie de la pièce d’identité en cours de validité</p>
            </div>
        </div>

        <div class="carte-etape">
            <div class="carte-corps">
                <div class="carte-numero">3</div>
                <p class="carte-texte">Le dernier bulletin de salaire</p>
            </div>
        </div>

        <div class="carte-etape">
            <div class="carte-corps">
                <div class="carte-numero">4</div>
                <p class="carte-texte">Deux photos d’identité</p>
            </div>
        </div>
    </div>

    <div class="action-telechargement">
    <p class="texte-appel">Prêt à nous rejoindre ? Téléchargez le formulaire officiel pour commencer votre démarche.</p>
    <a href="../document/formulaire_adhesion_MA2E.pdf" class="bouton-telecharger" download target="_blank">
        <i class="bi bi-file-earmark-pdf-fill"></i>
        Télécharger le formulaire d'adhésion
    </a>
</div>
</section>

<section class="section-avantages">
    <div class="conteneur-avantages">
        <div class="avantages-visuel">
            <img src="../photo/femmeaccueile.png" alt="Membre MA2E satisfaite" class="image-principale">
            <div class="decoration-orange"></div>
        </div>

        <div class="avantages-contenu">
            <h2 class="titre-avantages">Les avantages immédiats de l'adhésion</h2>
            
            <div class="liste-avantages">
                <div class="item-avantage">
                    <div class="icone-enveloppe">
                        <img src="../photo/chronometre.png" alt="Icône Chronomètre">
                    </div>
                    <div class="texte-avantage">
                        <p>Accès immédiat à l'épargne rémunérée <strong>(3,5% à 4%)</strong>.</p>
                    </div>
                </div>

                <div class="item-avantage">
                    <div class="icone-enveloppe">
                        <img src="../photo/famile.png" alt="Icône Famille">
                    </div>
                    <div class="texte-avantage">
                        <p>Protection sociale étendue à la famille.</p>
                    </div>
                </div>

                <div class="item-avantage">
                    <div class="icone-enveloppe">
                        <img src="../photo/calendrier.png" alt="Icône Calendrier">
                    </div>
                    <div class="texte-avantage">
                        <p>Éligibilité aux crédits après une période d'observation <strong>(6 mois d'épargne)</strong>.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="stats-ma2e">
  <div class="stats-overlay">
    <div class="stats-conteneur">
      
      <div class="stats-carte">
        <h2 class="stats-nombre" data-cible="7335">0</h2>
        <p class="stats-label">Adhérents</p>
      </div>

      <div class="stats-carte">
        <h2 class="stats-nombre" data-cible="9">0</h2>
        <p class="stats-label">Produits</p>
      </div>

      <div class="stats-carte">
        <h2 class="stats-nombre" data-cible="14">0</h2>
        <p class="stats-label">Années d’activité</p>
      </div>

      <div class="stats-carte">
        <h2 class="stats-nombre" data-cible="2.4">0</h2>
        <p class="stats-label">Milliards de FCFA de crédits</p>
      </div>

    </div>
  </div>
</section>

<section class="devenir-membre">
    <div class="conteneur-devenir">
        <h2 class="titre-devenir">Prêt à rejoindre la famille MA2E ?</h2>
        <p class="texte-devenir">Rejoignez-nous dès aujourd'hui et bénéficiez de tous les avantages que nous offrons à nos membres. Ensemble, construisons un avenir financier solide.</p>
        <a href="teste.php" class="btn-devenir">Devenir membre</a>
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
        <p>&copy; 2026 MA2E-ARISTIDE DEIN- Tous droits réservés.</p>
    </div>
</footer>

<script src="script.js"></script>

<!-- JS personnalisé -->
<!-- ============================================================
     ANIMATIONS SCROLL-TRIGGERED — MA2E
     Copiez ce bloc entier juste avant </body>
     ============================================================ -->
<script>
(function () {
  'use strict';

  // ── 1. Éléments à animer au scroll ──────────────────────────
  const targets = [
    // Section Présentation
    { selector: '.presentation-images',   classes: ['from-left'] },
    { selector: '.presentation-content',  classes: ['from-right'] },
    { selector: '.presentation-title',    classes: [] },

    // Section Adhésion
    { selector: '.conteneur-adhesion',    classes: [] },
    { selector: '.carte-etape',           classes: [] },

    // Section Avantages
    { selector: '.avantages-visuel',      classes: ['from-left'] },
    { selector: '.titre-avantages',       classes: ['from-right'] },
    { selector: '.item-avantage',         classes: [] },

    // Section Stats
    { selector: '.stats-carte',           classes: [] },

    // Section Devenir membre
    { selector: '.devenir-membre',        classes: ['from-scale'] },

    // Footer
    { selector: '.section-footer',        classes: [] },
  ];

  // ── 2. Appliquer les classes initiales ───────────────────────
  targets.forEach(({ selector, classes }) => {
    document.querySelectorAll(selector).forEach(el => {
      el.classList.add('anim-hidden', ...classes);
    });
  });

  // ── 3. Cascade automatique pour grilles ──────────────────────
  document.querySelectorAll('.grille-cartes, .liste-avantages, .stats-conteneur, .conteneur-footer')
    .forEach(g => g.classList.add('anim-stagger'));

  // ── 4. IntersectionObserver ───────────────────────────────────
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;

      const el = entry.target;
      el.classList.add('is-visible');

      // Shimmer sur les chiffres quand visibles
      if (el.classList.contains('stats-carte')) {
        const num = el.querySelector('.stats-nombre');
        if (num) {
          setTimeout(() => num.classList.add('shimmer-active'), 700);
        }
      }

      // Barre décorative des titres présentation
      if (el.classList.contains('presentation-title')) {
        el.classList.add('visible');
      }

      observer.unobserve(el);
    });
  }, {
    threshold: 0.15,
    rootMargin: '0px 0px -40px 0px'
  });

  // ── 5. Observer tous les éléments cachés ─────────────────────
  document.querySelectorAll('.anim-hidden').forEach(el => observer.observe(el));

})();
document.addEventListener("DOMContentLoaded", () => {
  const nbrs = document.querySelectorAll(".stats-nombre");

  const animerCompteur = (entries, observer) => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;

      const element = entry.target;
      const cible = parseFloat(element.dataset.cible);
      const duree = 2000; // 2 secondes
      const intervalle = 40;
      const increment = cible / (duree / intervalle);

      let valeur = 0;

      const update = () => {
        valeur += increment;

        if (valeur < cible) {
          element.innerText =
            cible % 1 !== 0
              ? valeur.toFixed(1).replace(".", ",")
              : Math.floor(valeur).toLocaleString("fr-FR");

          setTimeout(update, intervalle);
        } else {
          element.innerText =
            cible % 1 !== 0
              ? cible.toString().replace(".", ",")
              : cible.toLocaleString("fr-FR") + (cible === 7335 ? "+" : "");
        }
      };

      update();
      observer.unobserve(element);
    });
  };

  const observer = new IntersectionObserver(animerCompteur, {
    threshold: 0.5
  });

  nbrs.forEach(el => observer.observe(el));
});
</script>


</body>
</html>