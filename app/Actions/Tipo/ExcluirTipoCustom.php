<?php
declare(strict_types=1);

namespace App\Actions\Tipo;

use App\Core\Resultado;
use App\Repositories\TipoBeneficioRepository;
use App\Services\TiposBeneficioService;

/** Caso de uso: remover um benefício personalizado e tirá-lo da ordem salva. Os contratos já lançados com ele continuam nos totais. */
final class ExcluirTipoCustom
{
    public function __construct(private TipoBeneficioRepository $tipos, private TiposBeneficioService $servico)
    {
    }

    /** @param mixed $id */
    public function executar($id): Resultado
    {
        $id = (int) (is_scalar($id) ? $id : 0);
        if (!$id) {
            return Resultado::falha('ID inválido');
        }
        $chave = $this->tipos->chaveDoId($id);
        $this->tipos->excluir($id);
        if ($chave) {
            $this->servico->removerDaOrdem($chave);
        }
        return Resultado::ok([], 'Tipo removido');
    }
}
