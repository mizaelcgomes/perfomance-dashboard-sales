<?php
declare(strict_types=1);

namespace App\Actions\Meta;

use App\Core\Resultado;
use App\Repositories\MetaRepository;
use App\Services\MetasService;

/**
 * Caso de uso: definir as metas de UM closer (alvo = id) ou de TODOS (alvo = 'todos') para as células informadas (S1…Sn e o mês).
 * metas = {"1":40,"2":40,"0":160} (0 = meta do mês). Valor igual ao que o closer já teria sem exceção limpa a linha. Tudo numa transação.
 */
final class SalvarMetaExcecao
{
    public function __construct(private MetasService $metasService, private MetaRepository $metas)
    {
    }

    /** @param mixed $ano @param mixed $mes @param mixed $alvo @param mixed $metasJson */
    public function executar($ano, $mes, $alvo, $metasJson): Resultado
    {
        $ano = (int) $ano;
        $mes = (int) $mes;
        $alvo = is_scalar($alvo) ? (string) $alvo : '';
        $in = json_decode(is_string($metasJson) ? $metasJson : '{}', true);
        if ($ano < 2000 || $mes < 1 || $mes > 12 || !is_array($in)) {
            return Resultado::falha('Dados inválidos');
        }
        $est = $this->metasService->estado($ano, $mes);
        $validas = array_merge(array_column($est['semanas'], 'num'), [0]);
        $cel = [];
        foreach ($in as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            if (!in_array((int) $k, $validas, true)) {
                return Resultado::falha('Semana inexistente neste mês: S' . (int) $k);
            }
            if (!is_numeric($v) || (int) $v < 1 || (int) $v > 99999) {
                return Resultado::falha('Use números inteiros maiores que zero nas metas.');
            }
            $cel[(int) $k] = (int) $v;
        }
        if (!$cel) {
            return Resultado::falha('Preencha ao menos uma meta.');
        }
        $ids = array_column($est['closers'], 'id');
        if ($alvo === 'todos') {
            $alvoIds = $ids;
        } elseif (ctype_digit($alvo) && in_array((int) $alvo, $ids, true)) {
            $alvoIds = [(int) $alvo];
        } else {
            return Resultado::falha('Closer não encontrado');
        }
        $this->metas->emTransacao(function () use ($alvoIds, $cel, $ano, $mes, $est): void {
            foreach ($alvoIds as $cid) {
                foreach ($cel as $c => $val) {
                    $this->metasService->gravarCelula($cid, $ano, $mes, $c, $val, $est['_bases'][$cid]);
                }
            }
        });
        return Resultado::ok(
            ['afetados' => count($alvoIds)] + $this->metasService->estadoPublico($ano, $mes),
            $alvo === 'todos' ? 'Meta aplicada a todos os closers' : 'Exceção salva'
        );
    }
}
