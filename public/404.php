<?php
/**
 * Send - Page 404
 */

require_once __DIR__ . '/../src/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Send</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL) ?>/public/assets/style.css">
</head>
<body class="public-page error-page">
    <div class="error-container">
        <h1>404</h1>
        <p>Ce lien n'existe pas ou a expiré.</p>
    </div>
</body>
</html>
