<?php
declare(strict_types=1);

namespace App\Actions\Vendedor;

use App\Core\Resultado;
use App\Repositories\EquipeRepository;
use App\Repositories\VendedorRepository;

/**
 * Caso de uso: mover um ou vários closers (ids separados por vírgula) para outra equipe; equipe vazia ou 0 = sem equipe. Usado pelo
 * arrastar-e-soltar, pelo menu "Mover para…" e pela ação em lote. Quem era líder de outra equipe perde a liderança ao sair dela.
 */
final class MoverVendedor
{
    public function __construct(private VendedorRepository $vendedores, private EquipeRepository $equipes)
    {
    }

    /** @param mixed $ids @param mixed $equipeId */
    public function executar($ids, $equipeId): Resultado
    {
        $lista = self::ids($ids);
        $destino = ($equipeId === '' || $equipeId === null || (int) $equipeId === 0) ? null : (int) $equipeId;
        if (!$lista) {
            return Resultado::falha('Nenhum closer informado');
        }
        if ($destino !== null && !$this->equipes->existe($destino)) {
            return Resultado::falha('Equipe não encontrada');
        }
        $this->equipes->tirarLiderancaAoMover($lista, $destino);
        $n = $this->vendedores->moverParaEquipe($lista, $destino);
        return Resultado::ok(['moved' => $n], $n === 1 ? 'Closer transferido' : $n . ' closers transferidos');
    }

    /** "3,5,5,x" vira [3, 5]: inteiros diferentes de zero, sem repetir. @param mixed $ids @return array<int, int> */
    public static function ids($ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', explode(',', is_scalar($ids) ? (string) $ids : '')))));
    }
}
