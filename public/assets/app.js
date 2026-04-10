/**
 * Send - JavaScript principal
 */

document.addEventListener('DOMContentLoaded', function() {
    // Page d'upload
    initUpload();

    // Boutons de copie
    initCopyButtons();

    // Confirmations d'actions
    initConfirmActions();
});

/**
 * Initialise la page d'upload
 */

function initUpload() {
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('file-input');
    const form = document.getElementById('upload-form');
    const submitBtn = document.getElementById('submit-btn');
    const fileList = document.getElementById('file-list');
    const filesUl = document.getElementById('files-ul');
    const totalSizeEl = document.getElementById('total-size');

    if (!dropZone || !fileInput || !form) return;

    let selectedFiles = [];

    // Événements drag and drop
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, preventDefaults, false);
        document.body.addEventListener(eventName, preventDefaults, false);
    });

    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }

    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => {
            dropZone.classList.add('drag-over');
        });
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => {
            dropZone.classList.remove('drag-over');
        });
    });

    dropZone.addEventListener('drop', (e) => {
        const dt = e.dataTransfer;
        const files = dt.files;
        handleFiles(files);
    });

    fileInput.addEventListener('change', (e) => {
        handleFiles(e.target.files);
    });

    function handleFiles(files) {
        selectedFiles = [...selectedFiles, ...Array.from(files)];
        updateFileList();
    }

    function updateFileList() {
        filesUl.innerHTML = '';
        let totalSize = 0;

        selectedFiles.forEach((file, index) => {
            totalSize += file.size;

            const li = document.createElement('li');
            li.innerHTML = `
                <span class="file-name">${escapeHtml(file.name)}</span>
                <span class="file-size">${formatSize(file.size)}</span>
                <button type="button" class="btn-remove" data-index="${index}">&times;</button>
            `;
            filesUl.appendChild(li);
        });

        // Boutons de suppression
        filesUl.querySelectorAll('.btn-remove').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const index = parseInt(e.target.dataset.index);
                selectedFiles.splice(index, 1);
                updateFileList();
            });
        });

        fileList.style.display = selectedFiles.length > 0 ? 'block' : 'none';
        totalSizeEl.textContent = formatSize(totalSize);
        submitBtn.disabled = selectedFiles.length === 0;
    }

    // Bascule du type de slug
    const slugRadios = document.querySelectorAll('input[name="slug_type"]');
    const customSlugGroup = document.getElementById('custom-slug-group');

    slugRadios.forEach(radio => {
        radio.addEventListener('change', () => {
            customSlugGroup.style.display = radio.value === 'custom' && radio.checked ? 'block' : 'none';
        });
    });

    // Soumission du formulaire
    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        if (selectedFiles.length === 0) return;

        const formData = new FormData(form);

        // Supprimer les fichiers existants et ajouter les nôtres
        formData.delete('files[]');
        selectedFiles.forEach(file => {
            formData.append('files[]', file);
        });

        const progressSection = document.getElementById('progress-section');
        const progressFill = document.getElementById('progress-fill');
        const progressText = document.getElementById('progress-text');
        const resultSection = document.getElementById('result-section');

        submitBtn.disabled = true;
        dropZone.style.display = 'none';
        fileList.style.display = 'none';
        document.querySelector('.form-section').style.display = 'none';
        progressSection.style.display = 'block';

        try {
            const xhr = new XMLHttpRequest();

            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    const percent = Math.round((e.loaded / e.total) * 100);
                    progressFill.style.width = percent + '%';
                    progressText.textContent = `Upload en cours... ${percent}%`;
                }
            });

            xhr.addEventListener('load', () => {
                progressSection.style.display = 'none';

                try {
                    const response = JSON.parse(xhr.responseText);

                    if (response.success) {
                        resultSection.style.display = 'block';
                        document.getElementById('share-url').value = response.url;
                        document.getElementById('view-share-link').href =
                            form.action.replace('/upload', '/share/') + response.slug;

                        if (response.errors && response.errors.length > 0) {
                            const warningDiv = document.createElement('div');
                            warningDiv.className = 'alert alert-warning';

                            // Manipulation DOM au lieu de innerHTML pour éviter les XSS
                            const strong = document.createElement('strong');
                            strong.textContent = 'Attention :';
                            warningDiv.appendChild(strong);
                            warningDiv.appendChild(document.createTextNode(' Certains fichiers n\'ont pas pu être uploadés :'));
                            warningDiv.appendChild(document.createElement('br'));

                            response.errors.forEach((error, index) => {
                                if (index > 0) warningDiv.appendChild(document.createElement('br'));
                                warningDiv.appendChild(document.createTextNode(error));
                            });

                            resultSection.querySelector('.result-success').prepend(warningDiv);
                        }
                    } else {
                        showError(response.error || 'Erreur lors de l\'upload.');
                    }
                } catch (e) {
                    showError('Erreur serveur inattendue.');
                }
            });

            xhr.addEventListener('error', () => {
                progressSection.style.display = 'none';
                showError('Erreur de connexion.');
            });

            xhr.open('POST', form.action);
            xhr.send(formData);

        } catch (error) {
            progressSection.style.display = 'none';
            showError(error.message);
        }
    });

    function showError(message) {
        alert(message);
        location.reload();
    }
}

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

/**
 * Formate une taille en octets
 */

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

/**
 * Échappe le HTML
 */

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
