<?php
/**
 * Send - Nettoyage du HTML de l'encart par liste blanche
 *
 * Seuls p, h1, strong, a (http/https) et br survivent. Tout le reste est
 * déballé (son texte est gardé) ou supprimé avec son contenu (script, style…).
 * Le résultat est reconstruit dans un document neuf : aucun attribut ni nœud
 * de l'entrée n'est recopié tel quel.
 */

declare(strict_types=1);

class HtmlSanitizer
{
    private const BLOCK_TAGS = ['p', 'h1'];
    private const INLINE_TAGS = ['strong', 'a', 'br'];
    private const RENAMED_TAGS = ['b' => 'strong', 'div' => 'p', 'h2' => 'h1', 'h3' => 'h1'];
    private const DROPPED_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'template', 'svg', 'math',
        'noscript', 'textarea', 'select', 'button', 'form', 'head', 'title',
    ];
    private const LINK_REL = 'noopener noreferrer sponsored';

    public static function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $source = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // Le préfixe d'encodage force l'UTF-8, sinon libxml lit l'entrée en Latin-1
        $source->loadHTML(
            '<?xml encoding="UTF-8"><div>' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $source->getElementsByTagName('div')->item(0);
        if ($root === null) {
            return '';
        }

        $target = new DOMDocument('1.0', 'UTF-8');
        $container = $target->createElement('div');
        $target->appendChild($container);
        self::copyChildren($root, $container, $target, false);

        $output = '';
        foreach ($container->childNodes as $child) {
            $output .= $target->saveHTML($child);
        }
        return trim($output);
    }

    public static function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // trim() ignore les espaces Unicode : une suite d'insécables compterait comme du texte
        return preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $text) ?? '';
    }

    private static function copyChildren(DOMNode $from, DOMNode $to, DOMDocument $target, bool $insideBlock): void
    {
        foreach ($from->childNodes as $node) {
            if ($node instanceof DOMText) {
                $to->appendChild($target->createTextNode($node->data));
            } elseif ($node instanceof DOMElement) {
                self::copyElement($node, $to, $target, $insideBlock);
            }
        }
    }

    private static function copyElement(DOMElement $node, DOMNode $to, DOMDocument $target, bool $insideBlock): void
    {
        $tag = strtolower($node->tagName);
        $tag = self::RENAMED_TAGS[$tag] ?? $tag;

        if (in_array($tag, self::DROPPED_WITH_CONTENT, true)) {
            return;
        }

        $isBlock = in_array($tag, self::BLOCK_TAGS, true);
        $allowed = ($isBlock && !$insideBlock) || in_array($tag, self::INLINE_TAGS, true);
        $href = $tag === 'a' ? self::safeHref($node->getAttribute('href')) : null;

        // Lien sans adresse sûre, lien imbriqué ou bloc dans un bloc : on garde le texte seulement
        if (!$allowed || ($tag === 'a' && ($href === null || self::isInsideLink($to)))) {
            self::copyChildren($node, $to, $target, $insideBlock);
            return;
        }

        $clean = $target->createElement($tag);
        if ($tag === 'a') {
            $clean->setAttribute('href', $href);
            $clean->setAttribute('target', '_blank');
            $clean->setAttribute('rel', self::LINK_REL);
        }
        $to->appendChild($clean);
        self::copyChildren($node, $clean, $target, $insideBlock || $isBlock);
    }

    private static function isInsideLink(DOMNode $node): bool
    {
        for ($current = $node; $current instanceof DOMElement; $current = $current->parentNode) {
            if ($current->tagName === 'a') {
                return true;
            }
        }
        return false;
    }

    private static function safeHref(string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || strlen($href) > 500 || filter_var($href, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($href);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || !isset($parts['host'])) {
            return null;
        }
        // https://banque.fr@pirate.com : l'adresse affichée tromperait le visiteur
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        return $href;
    }
}
