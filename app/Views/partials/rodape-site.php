<?php
/**
 * Rodapé das páginas do site (o mesmo HTML do _footer.php do sistema atual; o estilo vem do _footer.css, na raiz do site).
 * @var \App\Core\View $v
 */
$anoRodape = date('Y');
?>
<footer class="site-footer">
  <div class="sf-inner">
    <span class="sf-brand">TOP CLOSERS</span>
    <span class="sf-dot">◆</span>
    <span>Desenvolvido por <strong>Mizael Gomes</strong></span>
    <span class="sf-dot">◆</span>
    <span>&copy; <?= $v->e($anoRodape) ?> &mdash; Todos os direitos reservados</span>
  </div>
</footer>
