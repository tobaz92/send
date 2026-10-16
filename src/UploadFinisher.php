<?php
/**
 * Send - Finalisation d'un upload découpé : validation des fichiers
 * assemblés et création du partage
 *
 * La transaction ne couvre que les écritures en base. En cas d'échec
 * technique, les fichiers déplacés reviennent en .part et les uploads sont
 * rendus : le client peut relancer la finalisation sans tout renvoyer.
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
        [$accepted, $errors] = self::validateAll($claimed);

        if ($accepted === []) {
            self::discardAll($claimed);
            throw new UploadException('Aucun fichier n\'a pu être enregistré. ' . implode(' ', $errors), 422);
        }

        $moved = [];
        try {
            foreach ($accepted as $id => $checked) {
                $moved[$id] = File::promoteAssembled(UploadStorage::partPath($id), $checked['extension']);
            }
            $result = self::persist($moved, $accepted, $shareData);
        } catch (Throwable $e) {
            self::restoreParts($moved);
            foreach ($claimed as $id => $upload) {
                ChunkUpload::release($id, $upload['handle']);
            }
            throw $e;
        }

        self::discardAll($claimed);
        return $result + ['errors' => $errors];
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

    /**
     * @return array{0: array<string, array>, 1: string[]} fichiers acceptés par id, messages de refus
     */
    private static function validateAll(array $claimed): array
    {
        $accepted = [];
        $errors = [];
        foreach ($claimed as $id => $upload) {
            $name = $upload['row']['original_name'];
            try {
                $accepted[$id] = FileValidator::validate(UploadStorage::partPath($id), $name);
            } catch (Exception $e) {
                $errors[] = $name . ': ' . $e->getMessage();
            }
        }
        return [$accepted, $errors];
    }

    /**
     * @param array<string, string> $moved nom stocké par id d'upload
     * @return array{slug: string, url: string, files_count: int}
     */
    private static function persist(array $moved, array $accepted, array $shareData): array
    {
        $db = Database::getInstance();
        // IMMEDIATE : un BEGIN différé échoue en SQLITE_BUSY sans attendre lors du passage lecture → écriture
        $db->exec('BEGIN IMMEDIATE');
        try {
            $shareId = self::createShare($shareData);
            foreach ($moved as $id => $storedName) {
                File::record($shareId, $accepted[$id], $storedName);
            }
            Database::insert('audit_logs', [
                'action' => 'upload',
                'ip' => Auth::getClientIp(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'details' => json_encode(['share_id' => $shareId, 'files_count' => count($moved)]),
            ]);
            $slug = Share::findById($shareId)['slug'];
            $db->exec('COMMIT');
        } catch (Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }

        return ['slug' => $slug, 'url' => Share::getPublicUrl($slug), 'files_count' => count($moved)];
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
     * Chemin d'erreur : l'exception d'origine est relancée par l'appelant,
     * un retour raté est journalisé (l'upload restera incomplet).
     *
     * @param array<string, string> $moved
     */
    private static function restoreParts(array $moved): void
    {
        foreach ($moved as $id => $storedName) {
            try {
                File::demote($storedName, UploadStorage::partPath($id));
            } catch (Throwable $e) {
                error_log("Send: fichier {$storedName} non restauré pour l'upload {$id} : " . $e->getMessage());
            }
        }
    }

    private static function discardAll(array $claimed): void
    {
        foreach ($claimed as $id => $upload) {
            ChunkUpload::discard($id, $upload['handle']);
        }
    }
}
