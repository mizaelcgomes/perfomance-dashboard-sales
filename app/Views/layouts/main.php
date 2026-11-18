<?php /** @var \App\Core\View $v */ ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $v->e($titulo ?? 'TOP CLOSERS') ?></title>
<link rel="stylesheet" href="<?= $v->e($v->asset('css/base.css')) ?>">
</head>
<body>
<?= $conteudo ?>
<?= $v->parcial('partials/rodape') ?>
</body>
</html>
