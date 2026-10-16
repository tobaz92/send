/**
 * Send - Client de l'API d'upload découpé (start / chunk / finish / cancel)
 */
'use strict';

const CHUNK_MAX_ATTEMPTS = 3;
// Un morceau de 32 Mio sur une liaison montante lente peut prendre plusieurs minutes
const REQUEST_TIMEOUT_MS = 15 * 60 * 1000;
const RETRY_BASE_DELAY_MS = 1000;
const HTTP_INSUFFICIENT_STORAGE = 507;
const HTTP_MESSAGES = {
    401: 'Session expirée, reconnectez-vous.',
    413: 'Morceau refusé par le serveur (trop gros).',
    502: 'Serveur indisponible.',
    503: 'Serveur indisponible.',
    504: 'Serveur indisponible.',
};

class UploadError extends Error {
    constructor(message, status = 0) {
        super(message);
        this.status = status;
    }

    // status 0 = erreur réseau
    get retryable() {
        if (this.status === HTTP_INSUFFICIENT_STORAGE) return false;
        return this.status === 0 || this.status === 408 || this.status === 429 || this.status >= 500;
    }
}

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

function parseResponse(status, text) {
    let payload;
    try {
        payload = JSON.parse(text);
    } catch {
        const fallback = `Réponse inattendue du serveur (HTTP ${status}).`;
        throw new UploadError(HTTP_MESSAGES[status] || fallback, status);
    }

    if (status < 200 || status >= 300 || !payload || !payload.success) {
        throw new UploadError(payload?.error || `Échec de l'envoi (HTTP ${status}).`, status);
    }
    return payload;
}

// XHR plutôt que fetch : seul XHR expose la progression d'envoi d'un morceau
function callApi(url, token, fields, onSent = null) {
    const body = new FormData();
    for (const [key, ...value] of fields) {
        body.append(key, ...value);
    }

    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.setRequestHeader('X-CSRF-Token', token);
        if (onSent) {
            xhr.upload.addEventListener('progress', event => onSent(event.loaded));
        }
        xhr.addEventListener('load', () => {
            try {
                resolve(parseResponse(xhr.status, xhr.responseText));
            } catch (error) {
                reject(error);
            }
        });
        xhr.addEventListener('error', () => reject(new UploadError('Erreur de connexion au serveur.')));
        xhr.addEventListener('abort', () => reject(new UploadError('Envoi interrompu.')));
        xhr.timeout = REQUEST_TIMEOUT_MS;
        xhr.addEventListener('timeout', () => reject(new UploadError('Le serveur ne répond plus.')));
        xhr.send(body);
    });
}

async function callApiWithRetry(url, token, fields, onSent) {
    for (let attempt = 1; ; attempt++) {
        try {
            return await callApi(url, token, fields, onSent);
        } catch (error) {
            if (!(error instanceof UploadError) || !error.retryable || attempt >= CHUNK_MAX_ATTEMPTS) {
                throw error;
            }
            await sleep(RETRY_BASE_DELAY_MS * attempt);
        }
    }
}

async function sendFile(file, session, onBytes) {
    const started = await callApi(`${session.apiBase}/start`, session.token, [
        ['name', file.name],
        ['size', String(file.size)],
    ]);
    if (!Number.isInteger(started.chunk_size) || started.chunk_size <= 0) {
        throw new UploadError('Réponse invalide du serveur (taille de morceau).');
    }
    session.openIds.push(started.upload_id);

    for (let index = 0; index * started.chunk_size < file.size; index++) {
        const start = index * started.chunk_size;
        const chunk = file.slice(start, start + started.chunk_size);
        // event.loaded inclut l'enveloppe multipart : borné à la taille réelle du morceau
        await callApiWithRetry(`${session.apiBase}/chunk`, session.token, [
            ['upload_id', started.upload_id],
            ['index', String(index)],
            ['chunk', chunk, `${index}.part`],
        ], loaded => onBytes(start + Math.min(loaded, chunk.size)));
        onBytes(start + chunk.size);
    }
    return started.upload_id;
}

async function cancelOpenUploads(session) {
    await Promise.all(session.openIds.map(async uploadId => {
        try {
            await callApi(`${session.apiBase}/cancel`, session.token, [['upload_id', uploadId]]);
        } catch (cancelError) {
            console.warn(`Annulation de l'upload ${uploadId} impossible : ${cancelError.message}`);
        }
    }));
}

function warnBeforeUnload(event) {
    event.preventDefault();
    event.returnValue = '';
}

/**
 * progress : { fileStarted(i), fileBytes(i, octetsEnvoyésDuFichier), fileSent(i), finishing() }
 * Renvoie le résultat de finish complété par uploadIds (dans l'ordre de files).
 */
async function runUpload(files, shareOptions, session, progress) {
    window.addEventListener('beforeunload', warnBeforeUnload);
    try {
        const uploadIds = [];
        for (const [index, file] of files.entries()) {
            progress.fileStarted(index);
            uploadIds.push(await sendFile(file, session, bytes => progress.fileBytes(index, bytes)));
            progress.fileSent(index);
        }
        progress.finishing();
        const result = await callApi(`${session.apiBase}/finish`, session.token, [
            ...uploadIds.map(id => ['upload_ids[]', id]),
            ...shareOptions,
        ]);
        session.openIds.length = 0;
        return { ...result, uploadIds };
    } catch (error) {
        await cancelOpenUploads(session);
        throw error;
    } finally {
        window.removeEventListener('beforeunload', warnBeforeUnload);
    }
}
