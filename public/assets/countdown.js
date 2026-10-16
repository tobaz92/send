/**
 * Send - Compte à rebours du blocage de mot de passe, puis rechargement
 */
'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const counter = document.getElementById('countdown');
    if (!counter) return;

    let seconds = parseInt(counter.dataset.seconds, 10);
    const timer = setInterval(() => {
        seconds--;
        counter.textContent = String(Math.max(seconds, 0));
        if (seconds <= 0) {
            clearInterval(timer);
            location.reload();
        }
    }, 1000);
});
