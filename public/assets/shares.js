/**
 * Send - Liste des partages : sélection multiple et suppression groupée
 */
'use strict';

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

    form.addEventListener('submit', event => {
        const checked = rows.filter(row => row.checked).length;
        const message = `Supprimer définitivement ${checked} partage${checked > 1 ? 's' : ''} et leurs fichiers ?`;
        if (!confirm(message)) event.preventDefault();
    });

    refresh();
}

document.addEventListener('DOMContentLoaded', initBulkSelection);
