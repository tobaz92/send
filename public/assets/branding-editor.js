/**
 * Send - Page Personnalisation : éditeur de l'encart et mise à l'échelle de l'aperçu
 *
 * L'éditeur ne fait que préparer du HTML : le serveur le nettoie par liste
 * blanche (HtmlSanitizer) avant de l'enregistrer.
 */
'use strict';

// Largeur de rendu de l'aperçu, réduite ensuite pour tenir dans son cadre
const PREVIEW_VIEWPORT_WIDTH = 1280;

function normalizeLinkUrl(input) {
    const value = input.trim();
    if (value === '') return null;
    const withScheme = /^https?:\/\//i.test(value) ? value : `https://${value}`;
    try {
        const url = new URL(withScheme);
        return ['http:', 'https:'].includes(url.protocol) && url.hostname.includes('.') ? url.href : null;
    } catch {
        return null;
    }
}

function runEditorCommand(area, command) {
    area.focus();
    if (command === 'h1' || command === 'p') {
        document.execCommand('formatBlock', false, `<${command}>`);
    } else if (command === 'bold' || command === 'unlink') {
        document.execCommand(command);
    } else if (command === 'link') {
        const url = normalizeLinkUrl(prompt('Adresse du lien (https://…)', 'https://') ?? '');
        if (url === null) return;
        document.execCommand('createLink', false, url);
    }
}

function bindAlignment(editor, area) {
    const field = editor.querySelector('input[name="promo_align"]');
    const buttons = [...editor.querySelectorAll('[data-align]')];
    buttons.forEach(button => {
        button.addEventListener('click', () => {
            field.value = button.dataset.align;
            buttons.forEach(other => other.setAttribute('aria-pressed', String(other === button)));
            // Mêmes classes que le bandeau public : l'éditeur montre l'alignement réel
            area.classList.remove('promo-align-left', 'promo-align-center', 'promo-align-right');
            area.classList.add(`promo-align-${button.dataset.align}`);
        });
    });
}

function initEditor(editor) {
    const area = editor.querySelector('.editor-area');
    const field = editor.querySelector('input[name="promo_html"]');
    bindAlignment(editor, area);
    document.execCommand('defaultParagraphSeparator', false, 'p');

    editor.querySelectorAll('[data-command]').forEach(button => {
        // mousedown empêché : la sélection reste dans la zone de texte
        button.addEventListener('mousedown', event => event.preventDefault());
        button.addEventListener('click', () => runEditorCommand(area, button.dataset.command));
    });

    // Collage en texte brut : pas de styles ni de balises venues d'ailleurs
    area.addEventListener('paste', event => {
        event.preventDefault();
        document.execCommand('insertText', false, event.clipboardData.getData('text/plain'));
    });

    editor.closest('form').addEventListener('submit', () => {
        field.value = area.innerHTML;
    });
}

function fitPreview(frame) {
    const iframe = frame.querySelector('iframe');
    const resize = () => {
        const scale = frame.clientWidth / PREVIEW_VIEWPORT_WIDTH;
        iframe.style.width = `${PREVIEW_VIEWPORT_WIDTH}px`;
        iframe.style.height = `${frame.clientHeight / scale}px`;
        iframe.style.transform = `scale(${scale})`;
    };
    resize();
    new ResizeObserver(resize).observe(frame);
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-editor]').forEach(initEditor);
    document.querySelectorAll('[data-preview-frame]').forEach(fitPreview);
});
