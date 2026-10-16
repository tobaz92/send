/**
 * Send - JavaScript principal
 */

document.addEventListener('DOMContentLoaded', function() {
    // Boutons de copie
    initCopyButtons();

    // Confirmations d'actions
    initConfirmActions();
});

/**
 * Initialise les boutons de copie
 */

function initCopyButtons() {
    document.querySelectorAll('#copy-btn, .copy-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const input = btn.previousElementSibling ||
                         btn.closest('.share-url-box')?.querySelector('input');

            if (!input) return;

            try {
                await navigator.clipboard.writeText(input.value);
                const originalText = btn.textContent;
                btn.textContent = 'Copié !';
                setTimeout(() => {
                    btn.textContent = originalText;
                }, 2000);
            } catch (err) {
                input.select();
                document.execCommand('copy');
            }
        });
    });
}

/**
 * Initialise les confirmations d'actions
 */

function initConfirmActions() {
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', (e) => {
            if (!confirm(el.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });
}
