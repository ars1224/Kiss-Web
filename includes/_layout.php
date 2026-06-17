<?php
// Default title if none set
if (!isset($title)) {
    $title = $pageTitle ?? 'KISS-Web';
}

// Every page MUST set $content to a valid PHP file path
if (!isset($content) || !is_file($content)) {
    http_response_code(500);
    exit('Layout requires a valid $content file.');
}
?>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= htmlspecialchars($title ?? 'KISS-Web', ENT_QUOTES, 'UTF-8') ?></title>

    <!-- Global styles -->
    <link rel="stylesheet" href="css/base.css?v=<?= filemtime(__DIR__ . '/../css/base.css') ?>">
    <link rel="stylesheet" href="css/mainStyleSheet.css?v=<?= filemtime(__DIR__ . '/../css/mainStyleSheet.css') ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="..."
          crossorigin="anonymous">
    <!-- Optional page-specific CSS -->
    <?php if (!empty($pageCSS)): ?>
        <?php
        $pageCssPath = __DIR__ . '/../' . ltrim($pageCSS, '/');
        $pageCssVersion = is_file($pageCssPath) ? '?v=' . filemtime($pageCssPath) : '';
        ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($pageCSS . $pageCssVersion, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>

    <link rel="stylesheet" href="css/mobile.css?v=<?= filemtime(__DIR__ . '/../css/mobile.css') ?>">
    
</head>


<body class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <?php include $content; ?>
    </main>

    <?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
