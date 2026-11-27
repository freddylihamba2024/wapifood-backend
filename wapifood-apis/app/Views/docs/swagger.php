<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>WapiFood API — Documentation</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui.css">
    <style>body { margin: 0; background: #fafafa; }</style>
</head>
<body>
<div id="swagger-ui"></div>
<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui-bundle.js" crossorigin="anonymous"></script>
<script>
    window.addEventListener('load', function () {
        window.ui = SwaggerUIBundle({
            url: <?= json_encode($specUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>,
            dom_id: '#swagger-ui',
            deepLinking: true,
            persistAuthorization: false,
        });
    });
</script>
</body>
</html>
