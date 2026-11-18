<?php
// Shared layout for Terms of Service / Privacy Policy. Caller sets $legal_title, $legal_updated and $legal_sections [[heading, [paragraphs]]].
$cur = basename($_SERVER['SCRIPT_NAME']);
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($legal_title) ?> | MBUS</title>
    <link rel="icon" href="Assets/images/mbus_logo.png">
    <link rel="stylesheet" href="Assets/css/legal.css?v=<?= (int)@filemtime(__DIR__ . '/../Assets/css/legal.css') ?>">
</head>
<body>
<main class="legal">
    <a class="legal-back" href="javascript:history.length>1?history.back():location.href='register.php'" aria-label="Go back">&larr; Back</a>
    <nav class="legal-tabs" aria-label="Legal pages">
        <a href="terms.php"<?= $cur === 'terms.php' ? ' class="active" aria-current="page"' : '' ?>>Terms of Service</a>
        <a href="privacy.php"<?= $cur === 'privacy.php' ? ' class="active" aria-current="page"' : '' ?>>Privacy Policy</a>
    </nav>
    <h1><?= htmlspecialchars($legal_title) ?></h1>
    <p class="legal-updated">Last updated <?= htmlspecialchars($legal_updated) ?></p>
    <?php foreach ($legal_sections as $i => $s): ?>
        <section>
            <h2><?= ($i + 1) ?>. <?= htmlspecialchars($s[0]) ?></h2>
            <?php foreach ($s[1] as $para): ?><p><?= htmlspecialchars($para) ?></p><?php endforeach; ?>
        </section>
    <?php endforeach; ?>
</main>
</body>
</html>
