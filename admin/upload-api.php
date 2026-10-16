<?php
/**
 * Send - API de l'upload découpé (JSON uniquement)
 *
 * POST /admin/api/upload/start   name, size              → upload_id, chunk_size, total_chunks
 * POST /admin/api/upload/chunk   upload_id, index, chunk → ok
 * POST /admin/api/upload/finish  upload_ids[], options   → url, slug, files_count, errors
 * POST /admin/api/upload/cancel  upload_id               → ok
 *
 * Jeton CSRF dédié (Auth::generateUploadToken) dans l'en-tête X-CSRF-Token.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

const UPLOAD_API_MAX_NAME_BYTES = 1024;
const UPLOAD_API_MAX_TITLE_BYTES = 255;
const UPLOAD_API_MAX_SIZE_DIGITS = 15;
const UPLOAD_API_SLUG_TYPES = ['random', 'short', 'custom'];

function uploadApiRespond(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function uploadApiString(string $key, int $maxBytes): string
{
    $value = $_POST[$key] ?? null;
    if (!is_string($value) || strlen($value) > $maxBytes) {
        throw new UploadException("Paramètre « {$key} » invalide.", 400);
    }
    return $value;
}

function uploadApiSize(): int
{
    $raw = uploadApiString('size', UPLOAD_API_MAX_SIZE_DIGITS);
    if (!ctype_digit($raw)) {
        throw new UploadException('Taille de fichier invalide.', 400);
    }
    return (int) $raw;
}

/** @return string[] */
function uploadApiIds(): array
{
    $ids = $_POST['upload_ids'] ?? null;
    $valid = is_array($ids)
        && array_is_list($ids)
        && count($ids) >= 1
        && count($ids) <= UploadFinisher::MAX_FILES_PER_SHARE
        && count(array_filter($ids, 'is_string')) === count($ids)
        && count(array_unique($ids)) === count($ids);
    if (!$valid) {
        throw new UploadException('Liste de fichiers invalide.', 400);
    }
    return $ids;
}

function uploadApiShareData(): array
{
    $slugType = uploadApiString('slug_type', 16);
    if (!in_array($slugType, UPLOAD_API_SLUG_TYPES, true)) {
        throw new UploadException('Type de lien invalide.', 400);
    }

    $shareData = [
        'title' => trim(uploadApiString('title', UPLOAD_API_MAX_TITLE_BYTES)),
        'password' => uploadApiString('password', 4096),
        'slug_type' => $slugType,
    ];

    $customSlug = trim(uploadApiString('custom_slug', 64));
    if ($slugType === 'custom' && $customSlug !== '') {
        if (!preg_match('/^[a-zA-Z0-9_-]{3,50}$/', $customSlug)) {
            throw new UploadException('Slug invalide (3-50 caractères alphanumériques, - et _ autorisés).', 422);
        }
        if (Share::findBySlug($customSlug)) {
            throw new UploadException('Ce slug est déjà utilisé.', 409);
        }
        $shareData['slug'] = $customSlug;
    }
    return $shareData;
}

function uploadApiChunkFile(): array
{
    $file = $_FILES['chunk'] ?? null;
    if (!is_array($file) || !is_int($file['error'] ?? null)) {
        throw new UploadException('Morceau manquant.', 400);
    }
    return $file;
}

/**
 * Corps au-delà de post_max_size : PHP vide $_POST et $_FILES sans erreur
 */
function uploadApiAssertBodySize(): void
{
    $postMax = UploadStorage::postMaxBytes();
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($postMax > 0 && $length > $postMax) {
        throw new UploadException('Requête trop volumineuse pour le serveur.', 413);
    }
}

function uploadApiChunk(): array
{
    ChunkUpload::writeChunk(uploadApiString('upload_id', 64), uploadApiString('index', 16), uploadApiChunkFile());
    return [];
}

function uploadApiCancel(): array
{
    ChunkUpload::cancel(uploadApiString('upload_id', 64));
    return [];
}

function uploadApiDispatch(string $action): array
{
    return match ($action) {
        'start' => ChunkUpload::start(uploadApiString('name', UPLOAD_API_MAX_NAME_BYTES), uploadApiSize()),
        'chunk' => uploadApiChunk(),
        'finish' => UploadFinisher::finish(uploadApiIds(), uploadApiShareData()),
        'cancel' => uploadApiCancel(),
        default => throw new UploadException('Action inconnue.', 404),
    };
}

$action = $GLOBALS['url_params'][0] ?? '';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new UploadException('Méthode non autorisée.', 405);
    }
    if (!Auth::check()) {
        throw new UploadException('Session expirée, reconnectez-vous.', 401);
    }
    uploadApiAssertBodySize();
    if (!Auth::verifyUploadToken((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        throw new UploadException('Jeton de sécurité invalide, rechargez la page.', 403);
    }
    // Libère le verrou de session : sinon chaque morceau bloque les autres onglets admin
    session_write_close();

    uploadApiRespond(200, ['success' => true] + uploadApiDispatch($action));
} catch (UploadException $e) {
    uploadApiRespond($e->httpStatus(), ['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log(sprintf('Send upload/%s : %s: %s', $action, get_class($e), $e->getMessage()));
    uploadApiRespond(500, ['success' => false, 'error' => 'Erreur interne du serveur.']);
}
