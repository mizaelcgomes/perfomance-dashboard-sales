<?php
declare(strict_types=1);

namespace App\Actions\Meta;

use App\Core\Resultado;
use App\Repositories\MetaRepository;
use App\Services\MetasService;

/** Caso de uso: remover a exceção de uma equipe (ou do Comercial): ela volta à soma automática das metas dos closers. */
final class RemoverMetaExcecaoGrupo
{
    public function __construct(private MetasService $metasService, private MetaRepository $metas)
    {
    }

    /** @param mixed $escopo @param mixed $ano @param mixed $mes @param mixed $alvo */
    public function executar($escopo, $ano, $mes, $alvo): Resultado
    {
        $esc = is_scalar($escopo) ? (string) $escopo : '';
        $ano = (int) $ano;
        $mes = (int) $mes;
        $alvo = (int) (is_scalar($alvo) ? $alvo : 0);
        if (!in_array($esc, ['equipe', 'geral'], true) || $ano < 2000 || $mes < 1 || $mes > 12) {
            return Resultado::falha('Dados inválidos');
        }
        $ref = 0;
        if ($esc === 'equipe') {
            if (!$alvo || !$this->metas->equipeExiste($alvo)) {
                return Resultado::falha('Equipe não encontrada');
            }
            $ref = $alvo;
        }
        $this->metas->emTransacao(function () use ($esc, $ref, $ano, $mes): void {
            $this->metas->apagarPeriodoDoRef($esc, $ref, $ano, $mes);
            if ($esc === 'equipe') {
                $this->metas->zerarMetaAntigaDaEquipe($ref);
            }
        });
        return Resultado::ok($this->metasService->estadoGrupo($esc, $ano, $mes), 'Exceção removida');
    }
}
