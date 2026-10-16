<?php
/**
 * Send - Model File (fichier)
 */

declare(strict_types=1);

class File
{
    /**
     * Upload un fichier
     *
     * @throws Exception en cas d'erreur
     */
    public static function upload(array $file, int $shareId): int
    {
        if ($file["error"] !== UPLOAD_ERR_OK) {
            throw new Exception(self::getUploadErrorMessage($file["error"]));
        }

        // Vérifier que c'est bien un fichier uploadé (sécurité)
        if (!is_uploaded_file($file["tmp_name"])) {
            throw new Exception("Fichier invalide.");
        }

        $checked = FileValidator::validate($file["tmp_name"], $file["name"]);
        $storedName = self::generateStoredName($checked["extension"]);
        $destination = FILES_PATH . "/" . $storedName;

        if (!move_uploaded_file($file["tmp_name"], $destination)) {
            throw new Exception("Erreur lors de l'enregistrement du fichier.");
        }

        return self::register($checked, $storedName, $destination, $shareId);
    }

    /**
     * Enregistre un fichier assemblé sur disque (sous STORAGE_PATH)
     *
     * @throws InvalidArgumentException si le fichier est hors de STORAGE_PATH
     * @throws Exception si la validation ou le déplacement échoue
     */
    public static function storeAssembled(
        string $path,
        string $originalName,
        int $shareId,
    ): int {
        $realPath = realpath($path);
        $storageRoot = realpath(STORAGE_PATH);

        if (
            $realPath === false ||
            $storageRoot === false ||
            !str_starts_with($realPath, $storageRoot . DIRECTORY_SEPARATOR)
        ) {
            throw new InvalidArgumentException(
                "Le fichier assemblé doit se trouver sous STORAGE_PATH.",
            );
        }

        $checked = FileValidator::validate($realPath, $originalName);
        $storedName = self::generateStoredName($checked["extension"]);
        $destination = FILES_PATH . "/" . $storedName;

        if (!rename($realPath, $destination)) {
            throw new Exception("Erreur lors de l'enregistrement du fichier.");
        }

        return self::register($checked, $storedName, $destination, $shareId);
    }

    private static function register(
        array $checked,
        string $storedName,
        string $destination,
        int $shareId,
    ): int {
        chmod($destination, 0640);

        return Database::insert("files", [
            "share_id" => $shareId,
            "original_name" => $checked["name"],
            "stored_name" => $storedName,
            "size" => filesize($destination),
            "mime_type" => $checked["mime"],
        ]);
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
