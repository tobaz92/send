<?php
/**
 * Send - Upload découpé : cycle de vie d'un upload et de ses morceaux
 *
 * Toute transition d'état d'un upload (écriture d'un morceau, annulation,
 * finalisation, purge) se fait sous le verrou exclusif de son fichier
 * partiel : un morceau en cours d'écriture n'est jamais perdu ni lu à moitié.
 */

declare(strict_types=1);

class ChunkUpload
{
    private const EXPIRY_SECONDS = 86400;
    private const MAX_OPEN_UPLOADS = 100;
    private const DISK_MARGIN_BYTES = 100 * 1024 * 1024;
    private const ID_PATTERN = '/^[a-f0-9]{32}$/';
    private const MAX_INDEX_DIGITS = 9;

    /**
     * @return array{upload_id: string, chunk_size: int, total_chunks: int}
     */
    public static function start(string $originalName, int $size): array
    {
        self::purgeExpired();

        try {
            $name = FileValidator::checkName($originalName)['name'];
        } catch (Exception $e) {
            throw new UploadException($e->getMessage(), 422);
        }
        self::assertAcceptableSize($size);

        $chunkSize = UploadStorage::chunkSize();
        $totalChunks = intdiv($size + $chunkSize - 1, $chunkSize);
        $id = bin2hex(random_bytes(16));

        Database::insert('uploads', [
            'id' => $id,
            'original_name' => $name,
            'size' => $size,
            'chunk_size' => $chunkSize,
            'total_chunks' => $totalChunks,
        ]);

        try {
            UploadStorage::createPart($id);
        } catch (Throwable $e) {
            Database::delete('uploads', 'id = ?', [$id]);
            throw $e;
        }

        return ['upload_id' => $id, 'chunk_size' => $chunkSize, 'total_chunks' => $totalChunks];
    }

    public static function writeChunk(string $id, string $rawIndex, array $file): void
    {
        $upload = self::find($id);
        $index = self::parseIndex($rawIndex, (int) $upload['total_chunks']);
        $expected = self::expectedChunkBytes($upload, $index);
        self::assertUploadedChunk($file, $expected);

        $handle = UploadStorage::lock($id);
        try {
            self::assertOpen($id);
            UploadStorage::copyAt($file['tmp_name'], $handle, $index * (int) $upload['chunk_size'], $expected);
            Database::query(
                'INSERT OR IGNORE INTO upload_chunks (upload_id, chunk_index) VALUES (?, ?)',
                [$id, $index]
            );
            Database::query('UPDATE uploads SET updated_at = CURRENT_TIMESTAMP WHERE id = ?', [$id]);
        } finally {
            UploadStorage::unlock($handle);
        }
    }

    public static function cancel(string $id): void
    {
        self::find($id);
        $handle = UploadStorage::lock($id);
        try {
            self::assertOpen($id);
        } catch (Throwable $e) {
            UploadStorage::unlock($handle);
            throw $e;
        }
        self::discard($id, $handle);
    }

    /**
     * Réserve un upload complet pour la finalisation. Le verrou reste tenu :
     * l'appelant le rend via release() ou discard().
     *
     * @return array{row: array, handle: resource}
     */
    public static function claim(string $id): array
    {
        $upload = self::find($id);
        $claimed = Database::update('uploads', ['status' => 'assembling'], "id = ? AND status = 'open'", [$id]);
        if ($claimed === 0) {
            throw new UploadException("Fichier « {$upload['original_name']} » déjà en cours de finalisation.", 409);
        }

        // Attend la fin d'une écriture de morceau démarrée avant la réservation
        $handle = UploadStorage::lock($id);
        try {
            self::assertComplete($upload);
        } catch (Throwable $e) {
            self::release($id, $handle);
            throw $e;
        }

        return ['row' => $upload, 'handle' => $handle];
    }

    /** @param resource $handle */
    public static function release(string $id, $handle): void
    {
        Database::update('uploads', ['status' => 'open'], 'id = ?', [$id]);
        UploadStorage::unlock($handle);
    }

    /** @param resource $handle */
    public static function discard(string $id, $handle): void
    {
        Database::delete('uploads', 'id = ?', [$id]);
        UploadStorage::deletePart($id);
        UploadStorage::unlock($handle);
    }

    public static function purgeExpired(): void
    {
        $expired = Database::fetchAll(
            "SELECT id FROM uploads WHERE updated_at < datetime('now', ?)",
            ['-' . self::EXPIRY_SECONDS . ' seconds']
        );
        foreach ($expired as $row) {
            $handle = UploadStorage::tryLock($row['id']);
            if ($handle !== null) {
                self::discard($row['id'], $handle);
            } elseif (UploadStorage::partSize($row['id']) === false) {
                Database::delete('uploads', 'id = ?', [$row['id']]);
            }
        }

        foreach (UploadStorage::staleIds(self::EXPIRY_SECONDS) as $id) {
            if (!Database::fetchOne('SELECT 1 FROM uploads WHERE id = ?', [$id])) {
                UploadStorage::deletePart($id);
            }
        }
    }

    private static function find(string $id): array
    {
        $upload = preg_match(self::ID_PATTERN, $id)
            ? Database::fetchOne('SELECT * FROM uploads WHERE id = ?', [$id])
            : null;
        if ($upload === null) {
            throw new UploadException('Upload introuvable ou expiré.', 404);
        }
        return $upload;
    }

    private static function assertOpen(string $id): void
    {
        $upload = Database::fetchOne('SELECT status FROM uploads WHERE id = ?', [$id]);
        if ($upload === null) {
            throw new UploadException('Upload introuvable ou expiré.', 404);
        }
        if ($upload['status'] !== 'open') {
            throw new UploadException('Upload en cours de finalisation.', 409);
        }
    }

    private static function assertComplete(array $upload): void
    {
        $received = (int) Database::fetchOne(
            'SELECT COUNT(*) AS n FROM upload_chunks WHERE upload_id = ?',
            [$upload['id']]
        )['n'];
        $diskSize = UploadStorage::partSize($upload['id']);

        if ($received !== (int) $upload['total_chunks'] || $diskSize !== (int) $upload['size']) {
            throw new UploadException(
                "Fichier « {$upload['original_name']} » incomplet ({$received}/{$upload['total_chunks']} morceaux).",
                409
            );
        }
    }

    private static function assertAcceptableSize(int $size): void
    {
        if ($size < 1) {
            throw new UploadException('Fichier vide.', 422);
        }
        if (MAX_FILE_SIZE > 0 && $size > MAX_FILE_SIZE) {
            throw new UploadException('Fichier trop volumineux.', 422);
        }

        $open = Database::fetchOne('SELECT COUNT(*) AS n, COALESCE(SUM(size), 0) AS bytes FROM uploads');
        if ((int) $open['n'] >= self::MAX_OPEN_UPLOADS) {
            throw new UploadException('Trop d\'uploads en cours, réessayez plus tard.', 429);
        }

        // disk_free_space est parfois désactivée en mutualisé : l'écriture contrôlée prend alors le relais
        $free = function_exists('disk_free_space') ? disk_free_space(STORAGE_PATH) : false;
        if ($free !== false && (int) $open['bytes'] + $size + self::DISK_MARGIN_BYTES > $free) {
            throw new UploadException('Espace disque insuffisant sur le serveur.', 507);
        }
    }

    private static function parseIndex(string $raw, int $totalChunks): int
    {
        $valid = ctype_digit($raw) && strlen($raw) <= self::MAX_INDEX_DIGITS && (int) $raw < $totalChunks;
        if (!$valid) {
            throw new UploadException('Numéro de morceau invalide.', 400);
        }
        return (int) $raw;
    }

    private static function expectedChunkBytes(array $upload, int $index): int
    {
        $chunkSize = (int) $upload['chunk_size'];
        $isLast = $index === (int) $upload['total_chunks'] - 1;
        return $isLast ? (int) $upload['size'] - $index * $chunkSize : $chunkSize;
    }

    private static function assertUploadedChunk(array $file, int $expected): void
    {
        $status = match ($file['error'] ?? null) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 413,
            UPLOAD_ERR_CANT_WRITE => 507,
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_EXTENSION => 500,
            default => 400,
        };
        if ($status !== null) {
            throw new UploadException('Morceau non reçu par le serveur.', $status);
        }
        if (!is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            throw new UploadException('Morceau invalide.', 400);
        }
        if (filesize($file['tmp_name']) !== $expected) {
            throw new UploadException('Taille de morceau incorrecte.', 400);
        }
    }
}
