<?php
declare(strict_types=1);

namespace App\Actions\Meta;

use App\Core\Resultado;
use App\Repositories\MetaRepository;
use App\Services\MetasService;

/**
 * Caso de uso: definir a meta fixa de UMA equipe (alvo = id), de TODAS as equipes (alvo = 'todos') ou do Comercial (escopo 'geral') nas
 * células informadas. metas = {"1":200,"2":null,"0":800}: número = meta fixa; null ou '' = volta ao automático (soma dos closers). Valor igual à
 * soma automática não grava linha (fica automático), como nas metas dos closers. Na equipe, a meta fixa antiga do cadastro (vale em todos os
 * meses) vira meta fixa deste mês e deixa de valer nos outros. Tudo numa transação.
 */
final class SalvarMetaExcecaoGrupo
{
    public function __construct(private MetasService $metasService, private MetaRepository $metas)
    {
    }

    /** @param mixed $escopo @param mixed $ano @param mixed $mes @param mixed $alvo @param mixed $metasJson */
    public function executar($escopo, $ano, $mes, $alvo, $metasJson): Resultado
    {
        $esc = is_scalar($escopo) ? (string) $escopo : '';
        $ano = (int) $ano;
        $mes = (int) $mes;
        $alvoS = is_scalar($alvo) ? (string) $alvo : '';
        $in = json_decode(is_string($metasJson) ? $metasJson : '{}', true);
        if (!in_array($esc, ['equipe', 'geral'], true) || $ano < 2000 || $mes < 1 || $mes > 12 || !is_array($in)) {
            return Resultado::falha('Dados inválidos');
        }
        $est = $this->metasService->estadoGrupo($esc, $ano, $mes);
        $validas = array_merge(array_column($est['semanas'], 'num'), [0]);
        $cel = [];
        foreach ($in as $k => $v) {
            if (!in_array((int) $k, $validas, true)) {
                return Resultado::falha('Semana inexistente neste mês: S' . (int) $k);
            }
            if ($v === null || $v === '') {
                $cel[(int) $k] = null;
                continue;
            }
            if (!is_numeric($v) || (int) $v < 1 || (int) $v > 99999) {
                return Resultado::falha('Use números inteiros maiores que zero nas metas.');
            }
            $cel[(int) $k] = (int) $v;
        }
        if (!$cel) {
            return Resultado::falha('Altere ao menos um valor.');
        }
        // soma automática de cada equipe (ou do comercial): valor igual a ela não vira linha
        $ids = [];
        $autos = [];
        foreach ($est['itens'] as $it) {
            $ids[] = (int) $it['id'];
            $autos[(int) $it['id']] = (array) $it['auto'];
        }
        if ($esc === 'geral') {
            $refs = [0];
        } elseif ($alvoS === 'todos') {
            $refs = $ids;
        } elseif (ctype_digit($alvoS) && in_array((int) $alvoS, $ids, true)) {
            $refs = [(int) $alvoS];
        } else {
            return Resultado::falha('Equipe não encontrada');
        }
        $this->metas->emTransacao(function () use ($refs, $autos, $cel, $validas, $esc, $ano, $mes): void {
            foreach ($refs as $ref) {
                $auto = $autos[$ref];
                if ($esc === 'equipe') {
                    $lg = $this->metas->metaAntigaDaEquipe($ref);
                    if ($lg && ((int) $lg['meta_semanal'] > 0 || (int) $lg['meta_mensal'] > 0)) {
                        $ja = $this->metas->semanasComMetaFixa($esc, $ref, $ano, $mes);
                        foreach ($validas as $c) {
                            $v = $c === 0 ? (int) $lg['meta_mensal'] : (int) $lg['meta_semanal'];
                            if ($v > 0 && $v !== $auto[$c] && !in_array($c, $ja, true)) {
                                $this->metas->salvarPeriodo($esc, $ref, $ano, $mes, $c, $v);
                            }
                        }
                        $this->metas->zerarMetaAntigaDaEquipe($ref);
                    }
                }
                foreach ($cel as $c => $val) {
                    if ($val === null || $val === $auto[$c]) {
                        $this->metas->apagarPeriodo($esc, $ref, $ano, $mes, $c);
                    } else {
                        $this->metas->salvarPeriodo($esc, $ref, $ano, $mes, $c, $val);
                    }
                }
            }
        });
        return Resultado::ok($this->metasService->estadoGrupo($esc, $ano, $mes), 'Exceção salva');
    }
}
