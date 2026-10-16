/**
 * Send - Page d'upload : sélection, progression, résultat
 */
'use strict';

function formatSize(bytes) {
    const units = ['o', 'Ko', 'Mo', 'Go', 'To'];
    let i = 0;
    let size = bytes;

    while (size >= 1024 && i < units.length - 1) {
        size /= 1024;
        i++;
    }

    return size.toFixed(i > 0 ? 2 : 0) + ' ' + units[i];
}

function buildWarning(errors) {
    const warning = document.createElement('div');
    warning.className = 'alert alert-warning';

    const strong = document.createElement('strong');
    strong.textContent = 'Attention :';
    warning.appendChild(strong);
    warning.appendChild(document.createTextNode(' Certains fichiers n\'ont pas pu être uploadés :'));

    errors.forEach(message => {
        warning.appendChild(document.createElement('br'));
        warning.appendChild(document.createTextNode(message));
    });
    return warning;
}

function findElements() {
    const form = document.getElementById('upload-form');
    if (!form) return null;
    return {
        form,
        dropZone: document.getElementById('drop-zone'),
        fileInput: document.getElementById('file-input'),
        submitBtn: document.getElementById('submit-btn'),
        fileList: document.getElementById('file-list'),
        filesUl: document.getElementById('files-ul'),
        totalSize: document.getElementById('total-size'),
        optionsSection: form.querySelector('.form-section'),
        progressSection: document.getElementById('progress-section'),
        progressFill: document.getElementById('progress-fill'),
        progressText: document.getElementById('progress-text'),
        resultSection: document.getElementById('result-section'),
        errorBox: document.getElementById('upload-error'),
    };
}

function buildFileItem(file, onRemove) {
    const name = document.createElement('span');
    name.className = 'file-name';
    name.textContent = file.name;
    const size = document.createElement('span');
    size.className = 'file-size';
    size.textContent = formatSize(file.size);
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'btn-remove';
    remove.textContent = '×';
    remove.addEventListener('click', onRemove);

    const item = document.createElement('li');
    item.append(name, size, remove);
    return item;
}

function createSelection(els) {
    let files = [];

    function render() {
        els.filesUl.replaceChildren();
        files.forEach((file, index) => {
            els.filesUl.appendChild(buildFileItem(file, () => {
                files.splice(index, 1);
                render();
            }));
        });
        els.fileList.style.display = files.length > 0 ? 'block' : 'none';
        els.totalSize.textContent = formatSize(files.reduce((sum, file) => sum + file.size, 0));
        els.submitBtn.disabled = files.length === 0;
    }

    return {
        render,
        list: () => [...files],
        add(newFiles) {
            files = [...files, ...Array.from(newFiles)];
            render();
        },
    };
}

function bindDropZone(els, selection) {
    const { dropZone, fileInput } = els;
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, event => event.preventDefault());
        document.body.addEventListener(eventName, event => event.preventDefault());
    });
    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => dropZone.classList.add('drag-over'));
    });
    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => dropZone.classList.remove('drag-over'));
    });
    dropZone.addEventListener('drop', event => selection.add(event.dataTransfer.files));
    fileInput.addEventListener('change', event => {
        selection.add(event.target.files);
        fileInput.value = '';
    });
}

function bindSlugToggle() {
    const customSlugGroup = document.getElementById('custom-slug-group');
    document.querySelectorAll('input[name="slug_type"]').forEach(radio => {
        radio.addEventListener('change', () => {
            customSlugGroup.style.display = radio.value === 'custom' && radio.checked ? 'block' : 'none';
        });
    });
}

function setSending(els, selection, sending) {
    els.dropZone.style.display = sending ? 'none' : '';
    els.optionsSection.style.display = sending ? 'none' : '';
    els.progressSection.style.display = sending ? 'block' : 'none';
    if (!sending) {
        selection.render();
        return;
    }
    els.fileList.style.display = 'none';
    els.submitBtn.disabled = true;
    els.errorBox.style.display = 'none';
    els.progressFill.style.width = '0%';
}

function showResult(els, apiBase, result) {
    els.progressSection.style.display = 'none';
    els.submitBtn.style.display = 'none';
    els.resultSection.style.display = 'block';
    document.getElementById('share-url').value = result.url;
    document.getElementById('view-share-link').href = apiBase.replace(/\/upload$/, '/share/') + encodeURIComponent(result.slug);
    if (result.errors && result.errors.length > 0) {
        els.resultSection.querySelector('.result-success').prepend(buildWarning(result.errors));
    }
}

function showError(els, message) {
    els.errorBox.textContent = message;
    els.errorBox.style.display = 'block';
}

function readShareOptions(form) {
    const data = new FormData(form);
    return ['title', 'password', 'slug_type', 'custom_slug'].map(key => [key, data.get(key) ?? '']);
}

function trackProgress(els, totalBytes) {
    return sentBytes => {
        const percent = totalBytes > 0 ? Math.round((sentBytes / totalBytes) * 100) : 100;
        els.progressFill.style.width = percent + '%';
        els.progressText.textContent = `Upload en cours... ${percent}%`;
    };
}

async function submitUpload(els, selection, apiBase) {
    const files = selection.list();
    const session = { apiBase, token: els.form.dataset.uploadToken, openIds: [] };
    const totalBytes = files.reduce((sum, file) => sum + file.size, 0);

    setSending(els, selection, true);
    let result;
    try {
        result = await runUpload(files, readShareOptions(els.form), session, trackProgress(els, totalBytes));
    } catch (error) {
        setSending(els, selection, false);
        showError(els, error.message);
        console.error('Upload échoué :', error);
        return;
    }
    // Hors du try : le partage existe, un incident d'affichage ne doit pas inviter à renvoyer
    showResult(els, apiBase, result);
}

function initUploadPage() {
    const els = findElements();
    if (!els) return;

    const apiBase = location.pathname.replace(/\/$/, '');
    const selection = createSelection(els);

    bindDropZone(els, selection);
    bindSlugToggle();
    els.form.addEventListener('submit', event => {
        event.preventDefault();
        if (selection.list().length > 0) submitUpload(els, selection, apiBase);
    });
}

document.addEventListener('DOMContentLoaded', initUploadPage);
