/**
 * Send - Liste des partages : sélection multiple et suppression groupée
 */
'use strict';

// Maj + clic : toute la plage depuis la dernière case cliquée prend l'état de la case cliquée
function bindShiftRange(rows, onChange) {
    let lastIndex = null;
    rows.forEach((row, index) => {
        // Sans cela, Maj + clic sélectionne aussi le texte du tableau
        row.addEventListener('mousedown', event => {
            if (event.shiftKey) event.preventDefault();
        });
        row.addEventListener('click', event => {
            if (event.shiftKey && lastIndex !== null && lastIndex !== index) {
                const [from, to] = [Math.min(lastIndex, index), Math.max(lastIndex, index)];
                rows.slice(from, to + 1).forEach(other => { other.checked = row.checked; });
                onChange();
            }
            lastIndex = index;
        });
    });
}

function initBulkSelection() {
    const form = document.getElementById('bulk-form');
    if (!form) return;

    const selectAll = document.getElementById('select-all');
    const rows = [...form.querySelectorAll('.row-check')];
    const button = document.getElementById('bulk-delete');
    const counter = document.getElementById('bulk-count');

    const refresh = () => {
        const checked = rows.filter(row => row.checked).length;
        button.disabled = checked === 0;
        button.textContent = checked > 0 ? `Supprimer la sélection (${checked})` : 'Supprimer la sélection';
        counter.textContent = checked > 0 ? `${checked} sur ${rows.length} sélectionné${checked > 1 ? 's' : ''}` : '';
        selectAll.checked = checked === rows.length;
        selectAll.indeterminate = checked > 0 && checked < rows.length;
    };

    selectAll.addEventListener('change', () => {
        rows.forEach(row => { row.checked = selectAll.checked; });
        refresh();
    });
    rows.forEach(row => row.addEventListener('change', refresh));
    bindShiftRange(rows, refresh);

    form.addEventListener('submit', event => {
        const checked = rows.filter(row => row.checked).length;
        const message = `Supprimer définitivement ${checked} partage${checked > 1 ? 's' : ''} et leurs fichiers ?`;
        if (!confirm(message)) event.preventDefault();
    });

    refresh();
}

document.addEventListener('DOMContentLoaded', initBulkSelection);
