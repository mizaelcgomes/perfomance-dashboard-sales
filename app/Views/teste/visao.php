<?php /** @var \App\Core\View $v */ ?>
<main>
  <h1>Olá, <?= $v->e($nome) ?></h1>
  <p id="titulo-escapado"><?= $v->e($titulo) ?></p>
</main>
