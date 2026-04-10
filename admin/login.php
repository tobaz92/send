<?php
/**
 * Send - Page de connexion admin
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$error = '';
$blocked = false;
$blockTime = 0;

// Si déjà connecté, rediriger
if (Auth::check()) {
    header('Location: ' . BASE_URL . '/admin');
    exit;
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';

    // Vérifier CSRF
    if (!Auth::verifyCsrfToken($csrfToken)) {
        $error = 'Session expirée, veuillez réessayer.';
    } elseif (empty($username) || empty($password)) {
        $error = 'Veuillez remplir tous les champs.';
    } else {
        $ip = Auth::getClientIp();

        if (Auth::isBlocked($ip)) {
            $blocked = true;
            $blockTime = Auth::getBlockTimeRemaining($ip);
        } elseif (Auth::attempt($username, $password)) {
            header('Location: ' . BASE_URL . '/admin');
            exit;
        } else {
            // Vérifier si maintenant bloqué
            if (Auth::isBlocked($ip)) {
                $blocked = true;
                $blockTime = Auth::getBlockTimeRemaining($ip);
            } else {
                $error = 'Identifiants incorrects.';
            }
        }
    }
}

// Générer nouveau token CSRF
$csrfToken = Auth::generateCsrfToken();

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - Send</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL) ?>/public/assets/style.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-box">
            <h1>Send</h1>
            <p class="subtitle">Connexion administration</p>

            <?php if ($blocked): ?>
                <div class="alert alert-error">
                    Trop de tentatives. Réessayez dans <span id="countdown"><?= $blockTime ?></span> secondes.
                </div>
            <?php elseif ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="" <?= $blocked ? 'style="display:none"' : '' ?>>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                <div class="form-group">
                    <label for="username">Identifiant</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        required
                        autocomplete="username"
                        autofocus
                    >
                </div>

                <div class="form-group">
                    <label for="password">Mot de passe</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        required
                        autocomplete="current-password"
                    >
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    Se connecter
                </button>
            </form>
        </div>
    </div>

    <?php if ($blocked): ?>
    <script>
        let countdown = <?= (int)$blockTime ?>;
        const el = document.getElementById('countdown');
        const interval = setInterval(() => {
            countdown--;
            el.textContent = countdown;
            if (countdown <= 0) {
                clearInterval(interval);
                location.reload();
            }
        }, 1000);
    </script>
    <?php endif; ?>
</body>
</html>
