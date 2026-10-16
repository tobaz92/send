/**
 * Send - Rotation aléatoire des images de fond, en fondu
 */
'use strict';

const WALLPAPER_INTERVAL_MS = 7000;
// Doit rester égal à la transition d'opacité de .page-wallpaper (style.css)
const WALLPAPER_FADE_MS = 1500;

function pickNextWallpaper(urls, currentUrl) {
    const others = urls.filter(url => url !== currentUrl);
    return others[Math.floor(Math.random() * others.length)];
}

function showWallpaper(holder, url) {
    return new Promise(resolve => {
        const next = new Image();
        next.alt = '';
        next.className = 'page-wallpaper';
        // Le fondu ne démarre qu'une fois l'image chargée : jamais de fond vide
        next.onload = () => {
            const previous = holder.querySelector('.page-wallpaper.is-visible');
            holder.appendChild(next);
            requestAnimationFrame(() => requestAnimationFrame(() => next.classList.add('is-visible')));
            setTimeout(() => {
                previous?.remove();
                resolve();
            }, WALLPAPER_FADE_MS);
        };
        next.onerror = () => {
            console.warn(`Image de fond indisponible : ${url}`);
            resolve();
        };
        next.src = url;
    });
}

function startWallpaperRotation() {
    const holder = document.querySelector('.page-wallpapers[data-wallpapers]');
    if (!holder || matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const urls = JSON.parse(holder.dataset.wallpapers);
    if (urls.length < 2) return;

    let currentUrl = holder.querySelector('.page-wallpaper')?.getAttribute('src');
    const tick = async () => {
        // Onglet caché : pas de téléchargement d'images que personne ne voit
        if (!document.hidden) {
            const nextUrl = pickNextWallpaper(urls, currentUrl);
            await showWallpaper(holder, nextUrl);
            currentUrl = nextUrl;
        }
        setTimeout(tick, WALLPAPER_INTERVAL_MS);
    };
    setTimeout(tick, WALLPAPER_INTERVAL_MS);
}

document.addEventListener('DOMContentLoaded', startWallpaperRotation);
