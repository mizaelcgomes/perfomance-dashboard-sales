<?php
declare(strict_types=1);

namespace App\Actions\Meta;

use App\Core\Resultado;
use App\Repositories\MetaRepository;
use App\Services\MetasService;

/** Caso de uso: remover a exceção de um closer (ele volta a seguir o padrão do mês). O padrão é calculado SEM contar o próprio closer. */
final class RemoverMetaExcecao
{
    public function __construct(private MetasService $metasService, private MetaRepository $metas)
    {
    }

    /** @param mixed $ano @param mixed $mes @param mixed $closerId */
    public function executar($ano, $mes, $closerId): Resultado
    {
        $ano = (int) $ano;
        $mes = (int) $mes;
        $cid = (int) $closerId;
        if ($ano < 2000 || $mes < 1 || $mes > 12 || !$cid) {
            return Resultado::falha('Dados inválidos');
        }
        $est = $this->metasService->estado($ano, $mes, $cid);
        if (!isset($est['_bases'][$cid])) {
            return Resultado::falha('Closer não encontrado');
        }
        $this->metas->emTransacao(function () use ($est, $cid, $ano, $mes): void {
            foreach ($est['padrao'] as $c => $p) {
                if ($p === null) {
                    $this->metas->apagarPeriodo('closer', $cid, $ano, $mes, (int) $c);
                } else {
                    $this->metasService->gravarCelula($cid, $ano, $mes, (int) $c, (int) $p, $est['_bases'][$cid]);
                }
            }
        });
        return Resultado::ok($this->metasService->estadoPublico($ano, $mes), 'Exceção removida');
    }
}
