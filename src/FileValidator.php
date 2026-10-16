<?php
/**
 * Send - Validation d'un fichier (nom, extension, MIME, taille)
 */

declare(strict_types=1);

class FileValidator
{
    /**
     * Types MIME autorisés par extension
     */
    private const MIME_MAP = [
        "jpg" => ["image/jpeg"],
        "jpeg" => ["image/jpeg"],
        "png" => ["image/png"],
        "gif" => ["image/gif"],
        "pdf" => ["application/pdf"],
        "doc" => ["application/msword"],
        "docx" => [
            "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
        ],
        "xls" => ["application/vnd.ms-excel"],
        "xlsx" => [
            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        ],
        "ppt" => ["application/vnd.ms-powerpoint"],
        "pptx" => [
            "application/vnd.openxmlformats-officedocument.presentationml.presentation",
        ],
        "zip" => ["application/zip", "application/x-zip-compressed"],
        "txt" => ["text/plain"],
        "mp4" => ["video/mp4"],
        "mov" => ["video/quicktime"],
        "mp3" => ["audio/mpeg"],
        "csv" => ["text/csv", "text/plain"],
    ];

    /**
     * Extensions dangereuses à bloquer absolument
     * Liste exhaustive basée sur OWASP et CVE connus
     */
    private const DANGEROUS_EXTENSIONS = [
        // PHP et variantes
        "php", "php3", "php4", "php5", "php7", "php8", "phtml", "phar",
        "phps", "pht", "phpt", "pgif", "phtm", "inc", "module",
        // ASP / ASP.NET
        "asp", "aspx", "asa", "asax", "ascx", "ashx", "asmx", "cer", "cdx",
        "config", "axd",
        // Java / JSP
        "jsp", "jspx", "jsw", "jsv", "jspf", "java", "jar", "war", "class",
        // ColdFusion
        "cfm", "cfml", "cfc", "dbm",
        // Scripts serveur
        "cgi", "pl", "pm", "py", "pyc", "pyo", "pyw", "rb", "rhtml",
        // SSI (Server Side Includes)
        "shtml", "shtm", "stm",
        // Shell / système
        "sh", "bash", "zsh", "ksh", "csh", "bat", "cmd", "ps1", "psm1",
        "psd1",
        // Windows executables & scripts
        "exe", "com", "msi", "msp", "scr", "pif", "gadget", "hta", "cpl",
        "msc", "inf", "reg", "vbs", "vbe", "vb", "wsf", "wsh", "ws", "sct",
        "js", "jse", "lnk", "scf", "url",
        // Apache / Nginx config
        "htaccess", "htpasswd", "htgroup", "htdigest", "user.ini", "php.ini",
        "nginx.conf",
        // Fichiers de config sensibles
        "ini", "env", "yml", "yaml", "toml", "json", "xml", "sql", "sqlite",
        "db", "mdb", "accdb", "log", "bak", "old", "backup", "swp", "tmp",
        // Templates avec exécution potentielle
        "tpl", "twig", "blade", "smarty", "mustache", "hbs",
        // Flash / Silverlight (XSS, obsolètes mais dangereux)
        "swf", "xap",
        // Autres potentiellement dangereux
        "svg", "svgz", "xsl", "xslt",
    ];

    /**
     * Valide un fichier présent sur disque.
     *
     * @return array{name: string, extension: string, mime: string}
     * @throws Exception si un contrôle échoue
     */
    public static function validate(string $path, string $originalName): array
    {
        ["name" => $name, "extension" => $extension] = self::checkName($originalName);
        $mime = self::detectMime($path, $extension);

        $size = filesize($path);
        if ($size === false) {
            throw new Exception("Taille du fichier illisible.");
        }
        if (MAX_FILE_SIZE > 0 && $size > MAX_FILE_SIZE) {
            throw new Exception("Fichier trop volumineux.");
        }

        return ["name" => $name, "extension" => $extension, "mime" => $mime];
    }

    /**
     * Nettoie le nom et vérifie ses extensions, sans lire le contenu.
     *
     * @return array{name: string, extension: string}
     * @throws Exception si une extension est refusée
     */
    public static function checkName(string $originalName): array
    {
        $name = self::sanitizeFilename($originalName);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        self::assertExtension($name, $extension);

        return ["name" => $name, "extension" => $extension];
    }

    /**
     * Nettoie un nom de fichier
     */
    public static function sanitizeFilename(string $filename): string
    {
        // Supprimer les null bytes
        $filename = str_replace("\0", "", $filename);

        // Garder seulement le nom de base
        $filename = basename($filename);

        // Supprimer les caractères dangereux
        $filename = preg_replace("/[^\p{L}\p{N}\s\-_\.]/u", "", $filename);
        if ($filename === null) {
            throw new Exception("Nom de fichier invalide (encodage).");
        }

        // Limiter la longueur
        if (strlen($filename) > 200) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $filename = mb_substr($name, 0, 190) . "." . $ext;
        }

        return $filename ?: "fichier";
    }

    private static function assertExtension(string $name, string $extension): void
    {
        // SÉCURITÉ: Vérifier les double extensions (ex: file.php.jpg).
        // Le nom de base est exclu : « config.pdf » ou « old.txt » sont légitimes.
        $extensions = array_slice(explode(".", strtolower($name)), 1);
        foreach ($extensions as $part) {
            if (in_array(trim($part), self::DANGEROUS_EXTENSIONS)) {
                throw new Exception("Extension dangereuse détectée.");
            }
        }

        if (!in_array($extension, ALLOWED_EXTENSIONS)) {
            throw new Exception("Extension '{$extension}' non autorisée.");
        }
    }

    private static function detectMime(string $path, string $extension): string
    {
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($mimeType === false) {
            throw new Exception("Type de fichier illisible.");
        }

        // SÉCURITÉ: Bloquer les fichiers PHP déguisés
        if (
            str_contains($mimeType, "php") ||
            str_contains($mimeType, "x-httpd")
        ) {
            throw new Exception("Type de fichier non autorisé.");
        }

        if (!self::validateMimeType($extension, $mimeType)) {
            // Ne pas révéler le type MIME en production (fuite d'information)
            if (defined("DEBUG") && DEBUG) {
                throw new Exception(
                    "Type de fichier invalide (MIME: {$mimeType}).",
                );
            }
            throw new Exception("Type de fichier invalide.");
        }

        return $mimeType;
    }

    /**
     * Valide le MIME type par rapport à l'extension
     */
    private static function validateMimeType(
        string $extension,
        string $mimeType,
    ): bool {
        if (!isset(self::MIME_MAP[$extension])) {
            // Extension non mappée, accepter si dans la liste autorisée
            return in_array($extension, ALLOWED_EXTENSIONS);
        }

        return in_array($mimeType, self::MIME_MAP[$extension]);
    }
}
