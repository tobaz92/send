<?php
/**
 * Send - Model File (fichier)
 */

declare(strict_types=1);

class File
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

        "php",
        "php3",
        "php4",
        "php5",
        "php7",
        "php8",
        "phtml",
        "phar",
        "phps",
        "pht",
        "phpt",
        "pgif",
        "phtm",
        "inc",
        "module",

        // ASP / ASP.NET
        "asp",
        "aspx",
        "asa",
        "asax",
        "ascx",
        "ashx",
        "asmx",
        "cer",
        "cdx",
        "config",
        "axd",

        // Java / JSP
        "jsp",
        "jspx",
        "jsw",
        "jsv",
        "jspf",
        "java",
        "jar",
        "war",
        "class",

        // ColdFusion
        "cfm",
        "cfml",
        "cfc",
        "dbm",

        // Scripts serveur
        "cgi",
        "pl",
        "pm",
        "py",
        "pyc",
        "pyo",
        "pyw",
        "rb",
        "rhtml",

        // SSI (Server Side Includes)
        "shtml",
        "shtm",
        "stm",

        // Shell / système
        "sh",
        "bash",
        "zsh",
        "ksh",
        "csh",
        "bat",
        "cmd",
        "ps1",
        "psm1",
        "psd1",

        // Windows executables & scripts
        "exe",
        "com",
        "msi",
        "msp",
        "scr",
        "pif",
        "gadget",
        "hta",
        "cpl",
        "msc",
        "inf",
        "reg",
        "vbs",
        "vbe",
        "vb",
        "wsf",
        "wsh",
        "ws",
        "sct",
        "js",
        "jse",
        "lnk",
        "scf",
        "url",

        // Apache / Nginx config
        "htaccess",
        "htpasswd",
        "htgroup",
        "htdigest",
        "user.ini",
        "php.ini",
        "nginx.conf",

        // Fichiers de config sensibles
        "ini",
        "env",
        "yml",
        "yaml",
        "toml",
        "json",
        "xml",
        "sql",
        "sqlite",
        "db",
        "mdb",
        "accdb",
        "log",
        "bak",
        "old",
        "backup",
        "swp",
        "tmp",

        // Templates avec exécution potentielle
        "tpl",
        "twig",
        "blade",
        "smarty",
        "mustache",
        "hbs",

        // Flash / Silverlight (XSS, obsolètes mais dangereux)
        "swf",
        "xap",

        // Autres potentiellement dangereux
        "svg",
        "svgz",
        "xsl",
        "xslt",
    ];

    /**
     * Upload un fichier
     *
     * @throws Exception en cas d'erreur
     */
    public static function upload(array $file, int $shareId): int
    {
        // Vérifier les erreurs d'upload
        if ($file["error"] !== UPLOAD_ERR_OK) {
            throw new Exception(self::getUploadErrorMessage($file["error"]));
        }

        // Vérifier que c'est bien un fichier uploadé (sécurité)
        if (!is_uploaded_file($file["tmp_name"])) {
            throw new Exception("Fichier invalide.");
        }

        // Nettoyer le nom de fichier
        $originalName = self::sanitizeFilename($file["name"]);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // SÉCURITÉ: Vérifier les double extensions (ex: file.php.jpg)
        $nameParts = explode(".", strtolower($originalName));
        foreach ($nameParts as $part) {
            if (in_array($part, self::DANGEROUS_EXTENSIONS)) {
                throw new Exception("Extension dangereuse détectée.");
            }
        }

        // Vérifier l'extension finale
        if (!in_array($extension, ALLOWED_EXTENSIONS)) {
            throw new Exception("Extension '{$extension}' non autorisée.");
        }

        // Vérifier le MIME type réel
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file["tmp_name"]);

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

        // Vérifier la taille
        if (MAX_FILE_SIZE > 0 && $file["size"] > MAX_FILE_SIZE) {
            throw new Exception("Fichier trop volumineux.");
        }

        // Générer un nom unique (UUID, pas d'extension originale pour plus de sécurité)
        $storedName = self::generateStoredName($extension);

        // Déplacer le fichier
        $destination = FILES_PATH . "/" . $storedName;

        if (!move_uploaded_file($file["tmp_name"], $destination)) {
            throw new Exception("Erreur lors de l'enregistrement du fichier.");
        }

        // Sécuriser les permissions
        chmod($destination, 0640);

        // Enregistrer en base
        return Database::insert("files", [
            "share_id" => $shareId,
            "original_name" => $originalName,
            "stored_name" => $storedName,
            "size" => $file["size"],
            "mime_type" => $mimeType,
        ]);
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

        // Limiter la longueur
        if (strlen($filename) > 200) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $filename = substr($name, 0, 190) . "." . $ext;
        }

        return $filename ?: "fichier";
    }

    /**
     * Génère un nom de stockage unique
     */
    private static function generateStoredName(string $extension): string
    {
        return sprintf(
            "%s-%s.%s",
            bin2hex(random_bytes(8)),
            time(),
            $extension,
        );
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

    /**
     * Récupère les fichiers d'un partage
     */
    public static function getByShareId(int $shareId): array
    {
        return Database::fetchAll(
            "SELECT * FROM files WHERE share_id = ? ORDER BY original_name",
            [$shareId],
        );
    }

    /**
     * Trouve un fichier par ID
     */
    public static function findById(int $id): ?array
    {
        return Database::fetchOne("SELECT * FROM files WHERE id = ?", [$id]);
    }

    /**
     * Supprime un fichier physique
     */
    public static function deletePhysical(string $storedName): bool
    {
        $path = FILES_PATH . "/" . $storedName;

        if (file_exists($path)) {
            return unlink($path);
        }

        return false;
    }

    /**
     * Supprime un fichier (BDD + physique)
     */
    public static function delete(int $id): bool
    {
        $file = self::findById($id);

        if (!$file) {
            return false;
        }

        self::deletePhysical($file["stored_name"]);

        return Database::delete("files", "id = ?", [$id]) > 0;
    }

    /**
     * Récupère le chemin complet d'un fichier
     * Valide storedName contre les attaques de path traversal
     */
    public static function getPath(string $storedName): string
    {
        // Empêcher le path traversal via basename et validation du format
        $safeName = basename($storedName);

        // Valider le format attendu : hex-timestamp.ext
        if (!preg_match('/^[a-f0-9]+-\d+\.[a-z0-9]+$/i', $safeName)) {
            throw new \InvalidArgumentException(
                "Format de nom de fichier stocké invalide",
            );
        }

        return FILES_PATH . "/" . $safeName;
    }

    /**
     * Formate une taille en octets
     */
    public static function formatSize(int $bytes): string
    {
        $units = ["o", "Ko", "Mo", "Go", "To"];
        $i = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 2) . " " . $units[$i];
    }

    /**
     * Message d'erreur d'upload
     */
    private static function getUploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE => "Le fichier dépasse la limite du serveur.",
            UPLOAD_ERR_FORM_SIZE
                => "Le fichier dépasse la limite du formulaire.",
            UPLOAD_ERR_PARTIAL
                => 'Le fichier n\'a été que partiellement uploadé.',
            UPLOAD_ERR_NO_FILE => 'Aucun fichier n\'a été uploadé.',
            UPLOAD_ERR_NO_TMP_DIR
                => "Erreur serveur : dossier temporaire manquant.",
            UPLOAD_ERR_CANT_WRITE
                => 'Erreur serveur : impossible d\'écrire sur le disque.',
            UPLOAD_ERR_EXTENSION => "Upload bloqué par une extension PHP.",
            default => 'Erreur inconnue lors de l\'upload.',
        };
    }

    /**
     * Calcule la taille totale des fichiers d'un partage
     */
    public static function getTotalSize(int $shareId): int
    {
        $result = Database::fetchOne(
            "SELECT SUM(size) as total FROM files WHERE share_id = ?",
            [$shareId],
        );

        return (int) ($result["total"] ?? 0);
    }
}
