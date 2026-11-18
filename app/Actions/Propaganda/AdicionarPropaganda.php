<?php
declare(strict_types=1);

namespace App\Actions\Propaganda;

use App\Core\Resultado;
use App\Repositories\PropagandaRepository;

/** Caso de uso: cadastrar uma propaganda (página HTML) na rotação da TV. */
final class AdicionarPropaganda
{
    public function __construct(private PropagandaRepository $propagandas)
    {
    }

    public function executar(string $titulo, string $html, int $ativo): Resultado
    {
        if (!$html) {
            return Resultado::falha('Conteúdo HTML obrigatório');
        }
        $id = $this->propagandas->inserir(trim($titulo), $html, $ativo);
        return Resultado::ok(['id' => $id], 'Propaganda adicionada');
    }
}
