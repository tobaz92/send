<?php
/**
 * Send - Image de fond et encart des pages publiques (inclus dans <body>)
 */

declare(strict_types=1);

$brandingWallpaperUrl = Branding::wallpaperUrl();
$brandingPromo = Branding::current();
?>
<?php if ($brandingWallpaperUrl !== null): ?>
    <img src="<?= htmlspecialchars($brandingWallpaperUrl) ?>" alt="" class="page-wallpaper" aria-hidden="true">
<?php endif; ?>
<?php if ($brandingPromo['promo_text'] !== ''): ?>
    <aside class="promo-card" aria-label="Annonce">
        <?php if ($brandingPromo['promo_url'] !== ''): ?>
            <a href="<?= htmlspecialchars($brandingPromo['promo_url']) ?>" target="_blank" rel="noopener noreferrer sponsored">
                <?= htmlspecialchars($brandingPromo['promo_text']) ?>
                <span class="promo-host"><?= htmlspecialchars((string) parse_url($brandingPromo['promo_url'], PHP_URL_HOST)) ?> &rarr;</span>
            </a>
        <?php else: ?>
            <p><?= htmlspecialchars($brandingPromo['promo_text']) ?></p>
        <?php endif; ?>
    </aside>
<?php endif; ?>
