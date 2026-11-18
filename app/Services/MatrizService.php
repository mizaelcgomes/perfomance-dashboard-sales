<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Repositories\LancamentoRepository;
use DateTime;

/**
 * A matriz de auditoria (aba Contratos): benefício × dia, de um closer ou de todos (consolidado), num período de até 14 dias. Mostra também os
 * benefícios desativados que ainda têm contratos no período e os contratos lançados direto, sem benefício. Porte fiel do get_matriz do api.php.
 */
final class MatrizService
{
    public function __construct(private LancamentoRepository $lancamentos, private TiposBeneficioService $tipos)
    {
    }

    /**
     * @param mixed $ini @param mixed $fim
     * @return array<string, mixed> o corpo do get_matriz (com "success" true) ou ['success' => false, 'message' => ...]
     */
    public function matriz($ini, $fim, int $vendedorId): array
    {
        if (!ContratoService::dataValida($ini) || !ContratoService::dataValida($fim) || $fim < $ini) {
            return ['success' => false, 'message' => 'Período inválido'];
        }
        $datas = [];
        $d = new DateTime($ini);
        $limite = new DateTime($fim);
        while ($d <= $limite && count($datas) <= 14) {
            $datas[] = $d->format('Y-m-d');
            $d->modify('+1 day');
        }
        if (count($datas) > 14) {
            return ['success' => false, 'message' => 'Período muito longo (máx. 14 dias)'];
        }

        $celulas = [];
        $usados = [];
        foreach ($this->lancamentos->celulasDoPeriodo($ini, $fim, $vendedorId) as $r) {
            $celulas[$r['d']][$r['tipo']] = (int) $r['q'];
            $usados[$r['tipo']] = true;
        }
        $ativos = $this->tipos->ativos();
        $cores = $this->tipos->cores();
        $tipos = [];
        foreach ($ativos as $k => $rotulo) {
            $tipos[] = ['k' => $k, 'label' => $rotulo, 'cor' => $cores[$k], 'inativo' => false];
        }
        foreach ($usados as $k => $_) {
            if (!isset($ativos[$k])) {                                    // benefício desativado que ainda tem contratos no período
                $rotulo = TiposBeneficioService::rotuloDeInativo((string) $k);
                $tipos[] = ['k' => $k, 'label' => $rotulo, 'cor' => TiposBeneficioService::cor((string) $k, $rotulo), 'inativo' => true];
            }
        }
        usort($tipos, static fn($a, $b) => TiposBeneficioService::ehOutros((string) $a['k']) <=> TiposBeneficioService::ehOutros((string) $b['k']));   // "Outros" por último (ordenação estável mantém o resto)
        $descricoes = [];
        if ($vendedorId) {
            foreach ($this->lancamentos->descricoesDeOutros($vendedorId, $ini, $fim) as $r) {
                $descricoes[$r['d']] = $r['tipo_descricao'];
            }
        }
        $semTipo = [];
        foreach ($this->lancamentos->totaisDoHistoricoPorDia($ini, $fim, $vendedorId) as $r) {
            $dosTipos = array_sum($celulas[$r['d']] ?? []);
            if ((int) $r['q'] > $dosTipos) {
                $semTipo[$r['d']] = (int) $r['q'] - $dosTipos;                // contratos lançados direto, sem benefício
            }
        }
        return [
            'success' => true, 'hoje' => Relogio::hoje(), 'dates' => $datas, 'tipos' => $tipos,
            'cells' => (object) $celulas, 'desc' => (object) $descricoes, 'sem_tipo' => (object) $semTipo,
        ];
    }
}
