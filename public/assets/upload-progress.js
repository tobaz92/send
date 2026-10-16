/**
 * Send - Visualisation de l'envoi : barre globale (débit, temps restant)
 * et une ligne par fichier avec son état
 */
'use strict';

// Débit calculé sur les dernières secondes : réactif sans trop sauter
const SPEED_WINDOW_MS = 5000;
const TEXT_REFRESH_MS = 1000;
const STATUS_LABELS = {
    waiting: 'En attente',
    sending: 'Envoi…',
    sent: 'Envoyé',
    checking: 'Vérification…',
    done: 'Terminé',
    rejected: 'Refusé',
};

function formatDuration(seconds) {
    if (!Number.isFinite(seconds) || seconds < 0) return '…';
    const total = Math.ceil(seconds);
    if (total < 60) return `${total} s`;
    const minutes = Math.floor(total / 60);
    if (minutes < 60) return `${minutes} min ${String(total % 60).padStart(2, '0')} s`;
    return `${Math.floor(minutes / 60)} h ${String(minutes % 60).padStart(2, '0')} min`;
}

function createSpeedMeter() {
    const samples = [];
    return {
        add(totalBytes) {
            const now = performance.now();
            samples.push([now, totalBytes]);
            // Fenêtre stricte : après la pause d'un réessai, les vieux échantillons fausseraient le débit
            while (samples.length > 1 && now - samples[0][0] > SPEED_WINDOW_MS) {
                samples.shift();
            }
        },
        bytesPerSecond() {
            if (samples.length < 2) return 0;
            const [firstTime, firstBytes] = samples[0];
            const [lastTime, lastBytes] = samples[samples.length - 1];
            const speed = lastTime > firstTime ? ((lastBytes - firstBytes) * 1000) / (lastTime - firstTime) : 0;
            return Math.max(0, speed);
        },
    };
}

function setBar(bar, fill, percent) {
    fill.style.width = `${percent}%`;
    bar.setAttribute('aria-valuenow', String(percent));
}

function buildProgressRow(file) {
    const item = document.createElement('li');
    item.className = 'progress-file is-waiting';

    const name = document.createElement('span');
    name.className = 'progress-file-name';
    name.textContent = file.name;
    const status = document.createElement('span');
    status.className = 'progress-file-status';
    const bar = document.createElement('div');
    bar.className = 'progress-bar progress-bar-sm';
    bar.setAttribute('role', 'progressbar');
    bar.setAttribute('aria-label', file.name);
    bar.setAttribute('aria-valuemin', '0');
    bar.setAttribute('aria-valuemax', '100');
    const fill = document.createElement('div');
    fill.className = 'progress-fill';
    bar.appendChild(fill);
    const meta = document.createElement('span');
    meta.className = 'progress-file-meta';

    item.append(name, status, bar, meta);

    const row = {
        item,
        bar,
        fill,
        setStatus(key, detail = '') {
            item.className = `progress-file is-${key}`;
            status.textContent = detail ? `${STATUS_LABELS[key]} : ${detail}` : STATUS_LABELS[key];
        },
        setBytes(bytes) {
            setBar(bar, fill, file.size > 0 ? Math.floor((bytes / file.size) * 100) : 0);
            meta.textContent = `${formatSize(bytes)} / ${formatSize(file.size)}`;
        },
    };
    row.setStatus('waiting');
    row.setBytes(0);
    return row;
}

function createProgressView(els, files) {
    const rows = files.map(buildProgressRow);
    const sentPerFile = files.map(() => 0);
    const totalBytes = files.reduce((sum, file) => sum + file.size, 0);
    const meter = createSpeedMeter();
    let frame = null;
    let lastTextAt = 0;

    els.progressFiles.replaceChildren(...rows.map(row => row.item));
    setBar(els.progressBar, els.progressFill, 0);
    els.progressText.textContent = 'Préparation…';

    function render() {
        frame = null;
        const sentBytes = sentPerFile.reduce((sum, bytes) => sum + bytes, 0);
        const percent = totalBytes > 0 ? Math.floor((sentBytes / totalBytes) * 100) : 100;
        setBar(els.progressBar, els.progressFill, percent);

        const now = performance.now();
        if (now - lastTextAt < TEXT_REFRESH_MS && percent < 100) return;
        lastTextAt = now;
        const speed = meter.bytesPerSecond();
        const remaining = speed > 0 ? (totalBytes - sentBytes) / speed : Infinity;
        els.progressText.textContent = `${percent} % · ${formatSize(sentBytes)} / ${formatSize(totalBytes)}`
            + ` · ${formatSize(speed)}/s · ${formatDuration(remaining)} restant`;
    }

    return {
        fileStarted(index) {
            rows[index].setStatus('sending');
        },
        fileBytes(index, bytes) {
            // Un morceau réessayé repart de zéro : la progression affichée ne recule pas
            if (bytes <= sentPerFile[index]) return;
            sentPerFile[index] = bytes;
            rows[index].setBytes(bytes);
            meter.add(sentPerFile.reduce((sum, value) => sum + value, 0));
            if (frame === null) frame = requestAnimationFrame(render);
        },
        fileSent(index) {
            sentPerFile[index] = files[index].size;
            rows[index].setBytes(files[index].size);
            if (files[index].size === 0) setBar(rows[index].bar, rows[index].fill, 100);
            rows[index].setStatus('sent');
        },
        cancel() {
            if (frame !== null) cancelAnimationFrame(frame);
            frame = null;
        },
        finishing() {
            this.cancel();
            setBar(els.progressBar, els.progressFill, 100);
            rows.forEach(row => row.setStatus('checking'));
            els.progressText.textContent = 'Vérification des fichiers sur le serveur…';
        },
        showResults(uploadIds, rejected) {
            const reasons = new Map((rejected || []).map(entry => [entry.upload_id, entry.error]));
            rows.forEach((row, index) => {
                const reason = reasons.get(uploadIds[index]);
                row.setStatus(reason === undefined ? 'done' : 'rejected', reason);
            });
            const accepted = files.length - reasons.size;
            els.progressText.textContent = `${accepted} fichier${accepted > 1 ? 's' : ''} sur ${files.length} enregistré${accepted > 1 ? 's' : ''}.`;
        },
    };
}
