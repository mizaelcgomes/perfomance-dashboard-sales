<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Move um arquivo enviado pelo formulário para o destino. Existe separado só para os testes poderem trocar o
 * move_uploaded_file (que só aceita arquivos de um envio HTTP de verdade) por uma cópia comum.
 */
class EnvioDeArquivo
{
    public function mover(string $de, string $para): bool
    {
        return move_uploaded_file($de, $para);
    }
}
