<?php
/**
 * Send - Fichiers partiels des uploads découpés (storage/tmp/<id>.part)
 */

declare(strict_types=1);

class UploadStorage
{
    // Le proxy OVH coupe à 128 Mio : rester largement en dessous
    private const MAX_CHUNK_BYTES = 32 * 1024 * 1024;
    private const POST_OVERHEAD_BYTES = 64 * 1024;

    public static function partPath(string $id): string
    {
        return self::tmpDir() . '/' . $id . '.part';
    }

    /**
     * Taille de morceau compatible avec la config PHP réelle du serveur
     */
    public static function chunkSize(): int
    {
        $limits = [self::MAX_CHUNK_BYTES];
        $uploadMax = self::iniBytes('upload_max_filesize');
        $postMax = self::iniBytes('post_max_size');
        if ($uploadMax > 0) {
            $limits[] = $uploadMax;
        }
        if ($postMax > 0) {
            $limits[] = $postMax - self::POST_OVERHEAD_BYTES;
        }

        $size = min($limits);
        if ($size <= 0) {
            throw new RuntimeException('post_max_size trop petit pour l\'upload découpé.');
        }
        return $size;
    }

    public static function postMaxBytes(): int
    {
        return self::iniBytes('post_max_size');
    }

    public static function createPart(string $id): void
    {
        $dir = self::tmpDir();
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Création de {$dir} impossible");
        }
        $handle = fopen(self::partPath($id), 'x');
        if ($handle === false) {
            throw new RuntimeException("Création du fichier partiel {$id} impossible");
        }
        fclose($handle);
        chmod(self::partPath($id), 0640);
    }

    /** @return resource */
    public static function lock(string $id)
    {
        // Fichier absent = upload purgé ou annulé entre-temps : cas prévu, pas un warning
        $handle = @fopen(self::partPath($id), 'r+');
        if ($handle === false) {
            throw new UploadException('Upload introuvable ou expiré.', 404);
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException("Verrou impossible sur l'upload {$id}");
        }
        return $handle;
    }

    /**
     * @return resource|null null si le fichier est absent ou verrouillé ailleurs
     */
    public static function tryLock(string $id)
    {
        $handle = @fopen(self::partPath($id), 'r+');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    /** @param resource $handle */
    public static function unlock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public static function deletePart(string $id): void
    {
        $path = self::partPath($id);
        if (file_exists($path) && !unlink($path)) {
            error_log("Send: suppression impossible de {$path}");
        }
    }

    /**
     * Écrit le contenu de $sourcePath à l'offset donné, en flux
     *
     * @param resource $target
     */
    public static function copyAt(string $sourcePath, $target, int $offset, int $expected): void
    {
        if (fseek($target, $offset) !== 0) {
            throw new RuntimeException("fseek impossible à l'offset {$offset}");
        }
        $source = fopen($sourcePath, 'rb');
        if ($source === false) {
            throw new RuntimeException("Lecture du morceau impossible : {$sourcePath}");
        }
        $copied = stream_copy_to_stream($source, $target);
        fclose($source);

        if ($copied !== $expected || !fflush($target)) {
            throw new UploadException('Écriture du morceau incomplète (espace disque ?).', 507);
        }
    }

    public static function partSize(string $id): int|false
    {
        $path = self::partPath($id);
        clearstatcache(true, $path);
        return file_exists($path) ? filesize($path) : false;
    }

    /**
     * Ids des fichiers partiels non modifiés depuis $maxAgeSeconds
     *
     * @return string[]
     */
    public static function staleIds(int $maxAgeSeconds): array
    {
        $ids = [];
        foreach (glob(self::tmpDir() . '/*.part') ?: [] as $path) {
            $modifiedAt = @filemtime($path);
            if ($modifiedAt !== false && $modifiedAt < time() - $maxAgeSeconds) {
                $ids[] = basename($path, '.part');
            }
        }
        return $ids;
    }

    private static function tmpDir(): string
    {
        return STORAGE_PATH . '/tmp';
    }

    private static function iniBytes(string $key): int
    {
        $value = trim((string) ini_get($key));
        $number = (int) $value;
        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
