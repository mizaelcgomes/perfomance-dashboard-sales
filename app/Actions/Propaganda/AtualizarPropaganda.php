<?php
declare(strict_types=1);

namespace App\Actions\Propaganda;

use App\Core\Resultado;
use App\Repositories\PropagandaRepository;

/** Caso de uso: trocar título, conteúdo e situação (ativa/inativa) de uma propaganda. */
final class AtualizarPropaganda
{
    public function __construct(private PropagandaRepository $propagandas)
    {
    }

    public function executar(int $id, string $titulo, string $html, int $ativo): Resultado
    {
        if (!$id || !$html) {
            return Resultado::falha('Dados inválidos');
        }
        $this->propagandas->atualizar($id, trim($titulo), $html, $ativo);
        return Resultado::ok([], 'Propaganda atualizada');
    }
}
