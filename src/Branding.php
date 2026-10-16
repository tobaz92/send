<?php
/**
 * Send - Personnalisation des pages de téléchargement : images de fond
 * (tirées au hasard, en fondu) et encart HTML, communs à tous les partages
 *
 * Les images vivent dans storage/branding (fermé au web) et ne sont servies
 * que par /branding/wallpaper/<nom>, avec leur type MIME vérifié.
 */

declare(strict_types=1);

class Branding
{
    public const MAX_WALLPAPERS = 20;
    public const MAX_WALLPAPER_BYTES = 8 * 1024 * 1024;
    public const MAX_PROMO_TEXT_LENGTH = 600;
    public const STORED_NAME_PATTERN = '[a-f0-9]{32}\.(?:jpg|png|webp)';

    // SVG exclu : il peut porter du script
    private const WALLPAPER_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    private const MAX_WALLPAPER_PIXELS = 40_000_000;
    private const MAX_PROMO_HTML_BYTES = 20_000;

    private const KEY_WALLPAPERS = 'branding_wallpapers';
    private const KEY_PROMO_HTML = 'branding_promo_html';

    /** @return string[] noms stockés, dans l'ordre d'ajout */
    public static function wallpapers(): array
    {
        $names = json_decode(Settings::get(self::KEY_WALLPAPERS) ?? '[]', true);
        return is_array($names) ? array_values(array_filter($names, [self::class, 'isStoredName'])) : [];
    }

    /** @return string[] */
    public static function wallpaperUrls(): array
    {
        return array_map(fn(string $name) => BASE_URL . '/branding/wallpaper/' . $name, self::wallpapers());
    }

    public static function promoHtml(): string
    {
        return Settings::get(self::KEY_PROMO_HTML) ?? '';
    }

    /**
     * @return array{path: string, mime: string}|null
     */
    public static function wallpaperFile(string $name): ?array
    {
        if (!in_array($name, self::wallpapers(), true)) {
            return null;
        }
        $path = self::dir() . '/' . $name;
        if (!is_file($path)) {
            return null;
        }
        $mime = array_search(pathinfo($name, PATHINFO_EXTENSION), self::WALLPAPER_TYPES, true);
        return ['path' => $path, 'mime' => (string) $mime];
    }

    /**
     * Retire puis ajoute : tout est validé avant la première écriture
     *
     * @param string[] $toRemove noms stockés à retirer
     * @param array[] $uploads entrées $_FILES normalisées
     * @throws Exception message destiné à l'admin si une image est refusée
     */
    public static function updateWallpapers(array $toRemove, array $uploads): void
    {
        $current = self::wallpapers();
        $kept = array_values(array_diff($current, $toRemove));
        if (count($kept) + count($uploads) > self::MAX_WALLPAPERS) {
            throw new Exception('Trop d\'images : ' . self::MAX_WALLPAPERS . ' au maximum.');
        }

        $extensions = [];
        foreach ($uploads as $file) {
            $extensions[] = self::validateWallpaper($file);
        }

        self::ensureDir();
        $added = [];
        foreach ($uploads as $index => $file) {
            $added[] = self::storeUpload($file, $extensions[$index]);
        }

        Settings::set(self::KEY_WALLPAPERS, json_encode(array_merge($kept, $added)));
        foreach (array_intersect($current, $toRemove) as $removed) {
            self::deleteStored($removed);
        }
    }

    /**
     * @return string le HTML nettoyé, vide si l'encart doit être masqué
     * @throws Exception si le contenu est trop long
     */
    public static function normalizePromo(string $html): string
    {
        if (strlen($html) > self::MAX_PROMO_HTML_BYTES) {
            throw new Exception('Encart trop long.');
        }
        $clean = HtmlSanitizer::sanitize($html);
        $text = HtmlSanitizer::plainText($clean);
        if (mb_strlen($text) > self::MAX_PROMO_TEXT_LENGTH) {
            throw new Exception('Encart trop long (' . self::MAX_PROMO_TEXT_LENGTH . ' caractères max).');
        }
        return $text === '' ? '' : $clean;
    }

    public static function savePromo(string $cleanHtml): void
    {
        Settings::set(self::KEY_PROMO_HTML, $cleanHtml);
    }

    public static function isStoredName(mixed $name): bool
    {
        return is_string($name) && preg_match('/^' . self::STORED_NAME_PATTERN . '\z/', $name) === 1;
    }

    /**
     * @return string l'extension retenue, déduite du contenu réel
     */
    private static function validateWallpaper(array $file): string
    {
        $label = '« ' . ($file['name'] ?? 'image') . ' »';
        if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            throw new Exception("Image {$label} non reçue (trop lourde pour le serveur ?).");
        }
        if (filesize($file['tmp_name']) > self::MAX_WALLPAPER_BYTES) {
            throw new Exception("Image {$label} trop lourde (8 Mo max).");
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset(self::WALLPAPER_TYPES[$mime])) {
            throw new Exception("Image {$label} refusée : JPEG, PNG ou WebP uniquement.");
        }

        $size = @getimagesize($file['tmp_name']);
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw new Exception("Image {$label} illisible.");
        }
        if ($size[0] * $size[1] > self::MAX_WALLPAPER_PIXELS) {
            throw new Exception("Image {$label} trop grande (40 mégapixels max).");
        }

        return self::WALLPAPER_TYPES[$mime];
    }

    private static function storeUpload(array $file, string $extension): string
    {
        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = self::dir() . '/' . $storedName;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException("Déplacement de l'image impossible vers {$destination}");
        }
        chmod($destination, 0640);
        return $storedName;
    }

    private static function deleteStored(string $storedName): void
    {
        if (!self::isStoredName($storedName)) {
            return;
        }
        $path = self::dir() . '/' . $storedName;
        if (is_file($path) && !unlink($path)) {
            error_log("Send: image de fond non supprimée : {$path}");
        }
    }

    private static function ensureDir(): void
    {
        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Création de {$dir} impossible");
        }
    }

    private static function dir(): string
    {
        return STORAGE_PATH . '/branding';
    }
}
