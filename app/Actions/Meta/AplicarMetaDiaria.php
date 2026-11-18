<?php
declare(strict_types=1);

namespace App\Actions\Meta;

use App\Core\Relogio;
use App\Core\Resultado;
use App\Repositories\ConfiguracaoRepository;
use App\Repositories\MetaRepository;
use App\Services\MetaDiaria;
use App\Services\MetasService;

/**
 * Caso de uso: aplicar um template de meta diária (crescente, uniforme ou personalizado) a TODOS os closers do mês. Substitui as metas diárias
 * de cada um, conta quantas exceções (séries que fugiam do template anterior ou da distribuição mais comum) foram substituídas e guarda qual
 * template foi aplicado (com a meta do mês de cada closer na época). Tudo numa transação.
 */
final class AplicarMetaDiaria
{
    public function __construct(private MetasService $metasService, private MetaRepository $metas, private ConfiguracaoRepository $config)
    {
    }

    /** @param mixed $ano @param mixed $mes @param mixed $tipo @param mixed $segsJson os intervalos do template personalizado, em JSON */
    public function executar($ano, $mes, $tipo, $segsJson): Resultado
    {
        $ano = (int) $ano;
        $mes = (int) $mes;
        $tipo = is_scalar($tipo) ? (string) $tipo : '';
        if ($ano < 2000 || $mes < 1 || $mes > 12 || !in_array($tipo, MetaDiaria::TIPOS, true)) {
            return Resultado::falha('Dados inválidos');
        }
        $dias = MetaDiaria::diasDoMes($ano, $mes);
        $segs = [];
        if ($tipo === 'personalizado') {
            $segs = json_decode(is_string($segsJson) ? $segsJson : '[]', true);
            $erro = MetaDiaria::validarIntervalos($segs, $dias);
            if ($erro !== '') {
                return Resultado::falha($erro);
            }
            $segs = array_map(static fn($s) => ['de' => (int) $s['de'], 'ate' => (int) $s['ate'], 'valor' => (int) $s['valor']], $segs);
        }
        $est = $this->metasService->estado($ano, $mes);
        $antes = $this->metasService->diariaAplicada($ano, $mes);
        $grav = $this->metas->diariasPorCloserNoMes($ano, $mes);
        $base = [];
        $substituidas = 0;
        [$comumAntes] = $antes ? [null] : MetaDiaria::comum($grav, array_column($est['closers'], 'id'));
        $this->metas->emTransacao(function () use ($est, $antes, $grav, $comumAntes, $dias, $tipo, $segs, $ano, $mes, &$base, &$substituidas): void {
            foreach ($est['closers'] as $c) {
                $id = $c['id'];
                $m = (int) $c['efetivas'][0];
                $base[(string) $id] = $m;
                $esp = MetaDiaria::esperada($antes, $id, $m, $dias);
                $ex = $antes
                    ? !MetaDiaria::iguais($grav[$id] ?? [], $esp ?? [])
                    : (!empty($grav[$id]) && ($comumAntes === null || !MetaDiaria::iguais($grav[$id], $comumAntes)));
                if ($ex) {
                    $substituidas++;
                }
                $this->metas->substituirDiarias($id, $ano, $mes, MetaDiaria::gerar($m, $dias, $tipo, $segs));
            }
            $this->config->definir(
                MetaDiaria::chave($ano, $mes),
                json_encode(['tipo' => $tipo, 'segs' => $segs, 'base' => $base, 'aplicado_em' => Relogio::agora()->format('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE)
            );
        });
        return Resultado::ok(
            ['aplicados' => count($est['closers']), 'excecoes_substituidas' => $substituidas],
            'Template aplicado a ' . count($est['closers']) . ' closers'
        );
    }
}
