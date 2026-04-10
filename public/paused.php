<?php
/**
 * Send - Page partage en pause
 */

require_once __DIR__ . '/../src/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Indisponible - Send</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL) ?>/public/assets/style.css">
</head>
<body class="public-page error-page">
    <div class="error-container">
        <h1>Indisponible</h1>
        <p>Ce partage est temporairement indisponible.</p>
    </div>
</body>
</html>
