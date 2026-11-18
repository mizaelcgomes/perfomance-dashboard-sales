<?php
declare(strict_types=1);

namespace App\Actions\Equipe;

use App\Core\Resultado;
use App\Repositories\EquipeRepository;
use App\Services\ImagemService;

/** Caso de uso: excluir uma equipe. A foto sai do disco e os closers dela ficam sem equipe (não são apagados). */
final class ExcluirEquipe
{
    public function __construct(private EquipeRepository $equipes, private ImagemService $imagens)
    {
    }

    /** @param mixed $id */
    public function executar($id): Resultado
    {
        $id = (int) (is_scalar($id) ? $id : 0);
        $atual = $this->equipes->paraEdicao($id);
        if ($atual) {
            $this->imagens->apagar($atual['img_url']);
        }
        $this->equipes->excluir($id);
        return Resultado::ok([], 'Equipe excluída');
    }
}
