<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= htmlspecialchars($title ?? 'KISS-Web', ENT_QUOTES, 'UTF-8') ?></title>

    <!-- Global styles -->
    <link rel="stylesheet" href="css/base.css">
    <link rel="stylesheet" href="css/mainStyleSheet.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="..."
          crossorigin="anonymous">
    <!-- Optional page-specific CSS -->
    <?php if (!empty($pageCSS)): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($pageCSS, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    
</head>
