<?php
/**
 * Endereços da API para o JavaScript: window.API_URL (admin e TV, ação por ?action=) e window.API_CLOSER_URL (portal do closer e do líder).
 * @var \App\Core\View $v
 */
?>
<script>window.API_URL=<?= json_encode($v->url('/api'), JSON_UNESCAPED_SLASHES) ?>;window.API_CLOSER_URL=<?= json_encode($v->url('/api/closer'), JSON_UNESCAPED_SLASHES) ?>;</script>
