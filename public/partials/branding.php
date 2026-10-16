<?php
/**
 * Send - Images de fond et encart des pages publiques (inclus dans <body>)
 */

declare(strict_types=1);

// Partiel inclus par une page publique, jamais appelé seul par URL
if (!defined('SEND_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

$brandingWallpapers = Branding::wallpaperUrls();
// HTML déjà nettoyé par liste blanche à l'enregistrement (Branding::normalizePromo)
$brandingPromoHtml = Branding::promoHtml();
$brandingPromoAlign = Branding::promoAlign();
?>
<?php if ($brandingWallpapers !== []): ?>
    <div class="page-wallpapers" aria-hidden="true"
         data-wallpapers="<?= htmlspecialchars(json_encode($brandingWallpapers, JSON_UNESCAPED_SLASHES)) ?>">
        <img src="<?= htmlspecialchars($brandingWallpapers[array_rand($brandingWallpapers)]) ?>" alt="" class="page-wallpaper is-visible">
    </div>
    <?php if (count($brandingWallpapers) > 1): ?>
        <script src="<?= htmlspecialchars(BASE_URL) ?>/public/assets/wallpaper-rotator.js?v=<?= filemtime(ROOT_PATH . '/public/assets/wallpaper-rotator.js') ?>" defer></script>
    <?php endif; ?>
<?php endif; ?>
<?php if ($brandingPromoHtml !== ''): ?>
    <aside class="promo-card promo-align-<?= $brandingPromoAlign ?>" aria-label="Annonce"><?= $brandingPromoHtml ?></aside>
<?php endif; ?>
