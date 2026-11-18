<?php
declare(strict_types=1);

namespace App\Actions\Meta;

use App\Core\Resultado;
use App\Repositories\MetaRepository;
use App\Services\MetaDiaria;
use App\Services\MetasService;

/**
 * Caso de uso: remover a exceção de meta diária de um closer. Com template aplicado, ele volta à distribuição do template; sem template,
 * volta à distribuição mais comum entre os closers (ou fica sem metas diárias, se não houver uma).
 */
final class RemoverMetaDiariaExcecao
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
        $est = $this->metasService->estado($ano, $mes);
        $achou = null;
        foreach ($est['closers'] as $c) {
            if ($c['id'] === $cid) {
                $achou = $c;
            }
        }
        if (!$achou) {
            return Resultado::falha('Closer não encontrado');
        }
        $dias = MetaDiaria::diasDoMes($ano, $mes);
        $apl = $this->metasService->diariaAplicada($ano, $mes);
        $esp = $apl
            ? MetaDiaria::esperada($apl, $cid, (int) $achou['efetivas'][0], $dias)
            : MetaDiaria::comum($this->metas->diariasPorCloserNoMes($ano, $mes), array_column($est['closers'], 'id'))[0];
        $this->metas->substituirDiarias($cid, $ano, $mes, $esp ?? []);
        return Resultado::ok([], 'Exceção removida');
    }
}
