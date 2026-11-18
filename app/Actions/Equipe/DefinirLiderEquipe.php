<?php
declare(strict_types=1);

namespace App\Actions\Equipe;

use App\Core\Resultado;
use App\Repositories\EquipeRepository;

/** Caso de uso: definir (ou remover, com 0) o líder de uma equipe. O líder precisa ser closer ativo da própria equipe. */
final class DefinirLiderEquipe
{
    public function __construct(private EquipeRepository $equipes)
    {
    }

    /** @param mixed $equipeId @param mixed $liderId */
    public function executar($equipeId, $liderId): Resultado
    {
        $equipe = (int) (is_scalar($equipeId) ? $equipeId : 0);
        $lider = (int) (is_scalar($liderId) ? $liderId : 0);
        if (!$this->equipes->existe($equipe)) {
            return Resultado::falha('Equipe não encontrada');
        }
        if ($lider > 0 && !$this->equipes->ehMembroAtivo($lider, $equipe)) {
            return Resultado::falha('O líder precisa ser um closer ativo desta equipe');
        }
        $this->equipes->definirLider($equipe, $lider > 0 ? $lider : null);
        return Resultado::ok([], $lider > 0 ? 'Líder definido' : 'Líder removido');
    }
}
