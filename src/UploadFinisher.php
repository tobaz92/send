<?php
/**
 * Send - Finalisation d'un upload découpé : validation des fichiers
 * assemblés et création du partage
 */

declare(strict_types=1);

class UploadFinisher
{
    public const MAX_FILES_PER_SHARE = 100;
    private const SQLITE_CONSTRAINT = '23000';

    /**
     * @param string[] $uploadIds
     * @return array{slug: string, url: string, files_count: int, errors: string[]}
     */
    public static function finish(array $uploadIds, array $shareData): array
    {
        $claimed = self::claimAll($uploadIds);

        try {
            $result = self::storeAll($claimed, $shareData);
        } finally {
            // Succès ou échec, ces uploads sont terminés : un fichier refusé ne redeviendra pas valide
            foreach ($claimed as $id => $upload) {
                ChunkUpload::discard($id, $upload['handle']);
            }
        }

        Database::insert('audit_logs', [
            'action' => 'upload',
            'ip' => Auth::getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'details' => json_encode(['share_id' => $result['share_id'], 'files_count' => $result['files_count']]),
        ]);
        unset($result['share_id']);

        return $result;
    }

    /**
     * Tout ou rien : si un upload n'est pas réservable, les autres sont rendus
     *
     * @return array<string, array{row: array, handle: resource}>
     */
    private static function claimAll(array $uploadIds): array
    {
        $claimed = [];
        try {
            foreach ($uploadIds as $id) {
                $claimed[$id] = ChunkUpload::claim($id);
            }
        } catch (Throwable $e) {
            foreach ($claimed as $id => $upload) {
                ChunkUpload::release($id, $upload['handle']);
            }
            throw $e;
        }
        return $claimed;
    }

    private static function storeAll(array $claimed, array $shareData): array
    {
        $db = Database::getInstance();
        // IMMEDIATE : un BEGIN différé échoue en SQLITE_BUSY sans attendre lors du passage lecture → écriture
        $db->exec('BEGIN IMMEDIATE');
        $fileIds = [];
        $errors = [];

        try {
            $shareId = self::createShare($shareData);
            foreach ($claimed as $id => $upload) {
                $name = $upload['row']['original_name'];
                try {
                    $fileIds[] = File::storeAssembled(UploadStorage::partPath($id), $name, $shareId);
                } catch (PDOException $e) {
                    throw $e;
                } catch (Exception $e) {
                    $errors[] = $name . ': ' . $e->getMessage();
                }
            }

            if ($fileIds === []) {
                throw new UploadException('Aucun fichier n\'a pu être enregistré. ' . implode(' ', $errors), 422);
            }
            $db->exec('COMMIT');
        } catch (Throwable $e) {
            self::rollback($db, $fileIds);
            throw $e;
        }

        $share = Share::findById($shareId);
        return [
            'share_id' => $shareId,
            'slug' => $share['slug'],
            'url' => Share::getPublicUrl($share['slug']),
            'files_count' => count($fileIds),
            'errors' => $errors,
        ];
    }

    private static function createShare(array $shareData): int
    {
        try {
            return Share::create($shareData);
        } catch (PDOException $e) {
            if ($e->getCode() === self::SQLITE_CONSTRAINT) {
                throw new UploadException('Ce slug est déjà utilisé.', 409);
            }
            throw $e;
        }
    }

    /**
     * Annule la transaction et retire les fichiers déjà déplacés dans storage/files
     */
    private static function rollback(PDO $db, array $fileIds): void
    {
        $storedNames = [];
        foreach ($fileIds as $fileId) {
            $storedNames[] = File::findById($fileId)['stored_name'];
        }
        $db->exec('ROLLBACK');

        foreach ($storedNames as $storedName) {
            File::deletePhysical($storedName);
        }
    }
}
