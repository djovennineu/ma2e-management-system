document.addEventListener('DOMContentLoaded', () => {
    const menuToggle = document.getElementById('menuToggle');
    const navbarMenu = document.getElementById('navbarMenu');
    const menuOverlay = document.getElementById('menuOverlay');

    function closeMenu() {
        menuToggle.classList.remove('active');
        navbarMenu.classList.remove('active');
        menuOverlay.classList.remove('active');
        document.body.classList.remove('menu-open');
        document.querySelectorAll('.has-submenu.active').forEach(item => {
            item.classList.remove('active');
        });
    }

    menuToggle.addEventListener('click', e => {
        e.stopPropagation();
        const isActive = menuToggle.classList.contains('active');
        if (isActive) closeMenu();
        else {
            menuToggle.classList.add('active');
            navbarMenu.classList.add('active');
            menuOverlay.classList.add('active');
            document.body.classList.add('menu-open');
        }
    });

    menuOverlay.addEventListener('click', closeMenu);

    document.querySelectorAll('.submenu-trigger').forEach(trigger => {
        trigger.addEventListener('click', e => {
            if (window.innerWidth > 768) return;
            e.preventDefault(); e.stopPropagation();
            const parent = trigger.parentElement;
            const submenu = parent.querySelector(':scope > .submenu');
            const isActive = parent.classList.contains('active');

            // Fermer les autres
            Array.from(parent.parentElement.children).forEach(sibling => {
                if (sibling !== parent && sibling.classList.contains('has-submenu')) sibling.classList.remove('active');
            });

            // Toggle actuel
            if (isActive) parent.classList.remove('active');
            else parent.classList.add('active');
        });
    });

    // Fermer au resize
    window.addEventListener('resize', () => { if (window.innerWidth > 768) closeMenu(); });

    // Carousel
    const slides = document.querySelectorAll('.carousel-slide');
    const indicators = document.querySelectorAll('.indicator-dot');
    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');
    const currentSlideEl = document.getElementById('currentSlide');
    const totalSlidesEl = document.getElementById('totalSlides');
    let currentIndex = 0, autoplayInterval;

    if (totalSlidesEl) totalSlidesEl.textContent = String(slides.length).padStart(2,'0');

    function showSlide(i){
        slides.forEach(s => s.classList.remove('active'));
        indicators.forEach(d => d.classList.remove('active'));
        slides[i]?.classList.add('active');
        indicators[i]?.classList.add('active');
        if(currentSlideEl) currentSlideEl.textContent = String(i+1).padStart(2,'0');
        currentIndex = i;
    }

    function nextSlide(){ showSlide((currentIndex+1)%slides.length); }
    function prevSlide(){ showSlide((currentIndex-1+slides.length)%slides.length); }

    nextBtn?.addEventListener('click', ()=>{ nextSlide(); resetAutoplay(); });
    prevBtn?.addEventListener('click', ()=>{ prevSlide(); resetAutoplay(); });
    indicators.forEach((dot,i)=>dot.addEventListener('click',()=>{ showSlide(i); resetAutoplay(); }));

    function startAutoplay(){ autoplayInterval = setInterval(nextSlide,6000); }
    function resetAutoplay(){ clearInterval(autoplayInterval); startAutoplay(); }
    startAutoplay();

    const carousel = document.getElementById('heroCarousel');
    if(carousel){
        carousel.addEventListener('mouseenter',()=>clearInterval(autoplayInterval));
        carousel.addEventListener('mouseleave',startAutoplay);

        let startX=0;
        carousel.addEventListener('touchstart', e=>{ startX=e.changedTouches[0].screenX; });
        carousel.addEventListener('touchend', e=>{
            const diff=startX-e.changedTouches[0].screenX;
            if(Math.abs(diff)>50){ diff>0?nextSlide():prevSlide(); resetAutoplay(); }
        });
    }
});