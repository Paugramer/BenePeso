document.addEventListener('DOMContentLoaded', () => {
    const modules = Array.from(document.querySelectorAll('.bp-content-module'));
    if (!modules.length) return;

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const saveData = navigator.connection && navigator.connection.saveData;

    const staggerSelector = [
        '.service-step-grid li',
        '.application-journey li',
        '.applicant-guide-grid article',
        '.program-faq',
        '.public-service-grid article',
        '.verification-status-grid article'
    ].join(',');

    modules.forEach((module) => {
        module.querySelectorAll(staggerSelector).forEach((item, index) => {
            item.style.setProperty('--bp-content-order', String(index));
            const marker = item.querySelector(':scope > span');
            if (marker) marker.style.setProperty('--bp-content-order', String(index));
        });
    });

    if (reduceMotion || saveData || !('IntersectionObserver' in window)) {
        modules.forEach((module) => module.classList.add('is-visible'));
        return;
    }

    document.documentElement.classList.add('bp-content-motion');
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
        });
    }, { threshold: 0.12, rootMargin: '0px 0px -6% 0px' });

    modules.forEach((module) => observer.observe(module));
});
