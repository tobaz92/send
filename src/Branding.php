<?php
/**
 * Send - Personnalisation des pages de téléchargement : image de fond et
 * encart promotionnel (texte + lien), communs à tous les partages
 *
 * L'image vit dans storage/branding (fermé au web) et n'est servie que par
 * la route /branding/wallpaper, avec son type MIME vérifié.
 */

declare(strict_types=1);

class Branding
{
    public const MAX_WALLPAPER_BYTES = 8 * 1024 * 1024;
    public const MAX_PROMO_TEXT_LENGTH = 200;
    public const MAX_PROMO_URL_LENGTH = 500;

    // SVG exclu : il peut porter du script
    private const WALLPAPER_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    private const MAX_WALLPAPER_PIXELS = 40_000_000;
    private const STORED_NAME_PATTERN = '/^[a-f0-9]{32}\.(jpg|png|webp)\z/';

    private const KEY_WALLPAPER = 'branding_wallpaper';
    private const KEY_PROMO_TEXT = 'branding_promo_text';
    private const KEY_PROMO_URL = 'branding_promo_url';

    /**
     * @return array{wallpaper: ?string, promo_text: string, promo_url: string}
     */
    public static function current(): array
    {
        return [
            'wallpaper' => Settings::get(self::KEY_WALLPAPER),
            'promo_text' => Settings::get(self::KEY_PROMO_TEXT) ?? '',
            'promo_url' => Settings::get(self::KEY_PROMO_URL) ?? '',
        ];
    }

    /**
     * URL publique de l'image de fond, versionnée par son nom (change à chaque envoi)
     */
    public static function wallpaperUrl(): ?string
    {
        $storedName = Settings::get(self::KEY_WALLPAPER);
        return $storedName === null ? null : BASE_URL . '/branding/wallpaper?v=' . substr($storedName, 0, 12);
    }

    /**
     * @return array{path: string, mime: string}|null
     */
    public static function wallpaperFile(): ?array
    {
        $storedName = Settings::get(self::KEY_WALLPAPER);
        if ($storedName === null || !preg_match(self::STORED_NAME_PATTERN, $storedName)) {
            return null;
        }
        $path = self::dir() . '/' . $storedName;
        if (!is_file($path)) {
            return null;
        }
        $extension = pathinfo($storedName, PATHINFO_EXTENSION);
        return ['path' => $path, 'mime' => array_search($extension, self::WALLPAPER_TYPES, true)];
    }

    /**
     * @throws Exception message destiné à l'admin si le fichier est refusé
     */
    public static function replaceWallpaper(array $file): void
    {
        $extension = self::validateWallpaper($file);
        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;

        self::ensureDir();
        $destination = self::dir() . '/' . $storedName;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException("Déplacement de l'image impossible vers {$destination}");
        }
        chmod($destination, 0640);

        $previous = Settings::get(self::KEY_WALLPAPER);
        Settings::set(self::KEY_WALLPAPER, $storedName);
        if ($previous !== null) {
            self::deleteStored($previous);
        }
    }

    public static function removeWallpaper(): void
    {
        $previous = Settings::get(self::KEY_WALLPAPER);
        Settings::delete(self::KEY_WALLPAPER);
        if ($previous !== null) {
            self::deleteStored($previous);
        }
    }

    /**
     * Texte et lien vides = encart masqué
     *
     * @return array{text: string, url: string}
     * @throws Exception si le texte ou l'URL sont invalides
     */
    public static function normalizePromo(string $text, string $url): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        $url = trim($url);

        if (mb_strlen($text) > self::MAX_PROMO_TEXT_LENGTH) {
            throw new Exception('Texte trop long (' . self::MAX_PROMO_TEXT_LENGTH . ' caractères max).');
        }
        if ($url !== '' && !self::isSafeUrl($url)) {
            throw new Exception('Lien invalide : adresse complète en http:// ou https:// attendue.');
        }
        if ($url !== '' && $text === '') {
            throw new Exception('Un lien sans texte ne s\'afficherait pas : ajoutez un texte.');
        }
        return ['text' => $text, 'url' => $url];
    }

    /** @param array{text: string, url: string} $promo issu de normalizePromo */
    public static function savePromo(array $promo): void
    {
        Settings::set(self::KEY_PROMO_TEXT, $promo['text']);
        Settings::set(self::KEY_PROMO_URL, $promo['url']);
    }

    private static function isSafeUrl(string $url): bool
    {
        if (strlen($url) > self::MAX_PROMO_URL_LENGTH || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) && parse_url($url, PHP_URL_HOST) !== null;
    }

    /**
     * @return string l'extension retenue, déduite du contenu réel
     */
    private static function validateWallpaper(array $file): string
    {
        if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            throw new Exception('Image non reçue (trop lourde pour le serveur ?).');
        }
        if (filesize($file['tmp_name']) > self::MAX_WALLPAPER_BYTES) {
            throw new Exception('Image trop lourde (8 Mo max).');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset(self::WALLPAPER_TYPES[$mime])) {
            throw new Exception('Format refusé : JPEG, PNG ou WebP uniquement.');
        }

        $size = @getimagesize($file['tmp_name']);
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw new Exception('Image illisible.');
        }
        if ($size[0] * $size[1] > self::MAX_WALLPAPER_PIXELS) {
            throw new Exception('Image trop grande (40 mégapixels max).');
        }

        return self::WALLPAPER_TYPES[$mime];
    }

    private static function deleteStored(string $storedName): void
    {
        if (!preg_match(self::STORED_NAME_PATTERN, $storedName)) {
            return;
        }
        $path = self::dir() . '/' . $storedName;
        if (is_file($path) && !unlink($path)) {
            error_log("Send: ancienne image de fond non supprimée : {$path}");
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
