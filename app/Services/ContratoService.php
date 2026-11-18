<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Repositories\LancamentoRepository;
use App\Repositories\VendedorRepository;

/**
 * Os lançamentos de contratos (aba Contratos do admin). contratos_tipos (por benefício) e contratos_historico (total do dia) são a fonte única do
 * ranking e do painel; contratos_feed é o "diário" dos lançamentos (hora, closer, quantidade) e é reconciliado com esses totais quando a matriz
 * grava ou quando o feed de um dia é aberto. Porte fiel das funções cv* do api.php. Tudo que grava deve rodar dentro de uma transação aberta
 * por quem chama (as consultas FOR UPDATE dependem dela).
 */
final class ContratoService
{
    public function __construct(
        private LancamentoRepository $lancamentos,
        private VendedorRepository $vendedores,
        private Calendario $calendario,
        private TiposBeneficioService $tipos
    ) {
    }

    /** Data no formato AAAA-MM-DD que existe no calendário. @param mixed $data */
    public static function dataValida($data): bool
    {
        return is_string($data) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $data, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    public static function agora(): string
    {
        return Relogio::agora()->format('Y-m-d H:i:s');
    }

    /**
     * Grava o total do dia, espelha na tabela legada (dia_semana) e recalcula vendas/faltam/score da semana corrente. Devolve o total da semana.
     */
    public function sincronizarVendedor(int $vendedorId, string $data, int $totalDoDia): int
    {
        $this->lancamentos->gravarTotalDoDia($vendedorId, $data, $totalDoDia);
        $semana = $this->calendario->semanaAtual();
        $indice = array_search($data, $this->calendario->datas($semana['start'], $semana['end']));
        if ($indice !== false) {
            $this->lancamentos->gravarDiaDaSemana($vendedorId, (int) $indice, $totalDoDia);
        }
        $totalDaSemana = $this->lancamentos->somaDoPeriodo($vendedorId, $semana['start'], $semana['end']);
        $linha = $this->vendedores->metasDoAtivo($vendedorId);
        $metas = max(1, $linha ? (int) $linha['metas'] : 1);
        $this->vendedores->gravarVendas($vendedorId, $totalDaSemana, max(0, $metas - $totalDaSemana), min(100, (int) round($totalDaSemana / $metas * 100)));
        return $totalDaSemana;
    }

    /** Soma $delta (pode ser negativo) ao benefício de um closer num dia, sem mexer nos outros tipos. Devolve o total do dia. */
    public function aplicarDelta(int $vendedorId, string $data, string $tipo, string $descricao, int $delta): int
    {
        $linha = $this->lancamentos->linhaDoTipoTravada($vendedorId, $data, $tipo);
        if ($linha) {
            $novo = max(0, (int) $linha['quantidade'] + $delta);
            if ($novo === 0) {
                $this->lancamentos->apagarLinhaDeTipo((int) $linha['id']);
            } else {
                $d = (string) $linha['tipo_descricao'];
                if ($tipo === 'outros' && $descricao !== '' && $delta > 0 && stripos($d, $descricao) === false) {
                    $d = Texto::cortar($d === '' ? $descricao : $d . ' / ' . $descricao, 0, 100);
                }
                $this->lancamentos->atualizarLinhaDeTipo((int) $linha['id'], $novo, $d);
            }
        } elseif ($delta > 0) {
            $this->lancamentos->inserirTipo($vendedorId, $data, $tipo, $tipo === 'outros' ? $descricao : '', $delta);
        }
        $total = max(0, $this->lancamentos->totalDoDia($vendedorId, $data, true) + $delta);
        $this->sincronizarVendedor($vendedorId, $data, $total);
        return $total;
    }

    /** Deixa o feed de (closer, dia, benefício) com exatamente $quantidade contratos. Sem eventos antes = lançamento antigo ("legado", sem hora). */
    private function reconciliarFeed(int $vendedorId, string $data, string $tipo, string $descricao, int $quantidade, bool $leitura): void
    {
        $soma = $this->lancamentos->somaDoFeed($vendedorId, $data, $tipo);
        if ($soma === $quantidade) {
            return;
        }
        $this->lancamentos->apagarFeedDoTipo($vendedorId, $data, $tipo);
        if ($quantidade <= 0) {
            return;
        }
        $legado = $leitura && $soma === 0;
        $this->lancamentos->inserirNoFeed($vendedorId, $data, $tipo, $descricao, $quantidade, $legado ? 'legado' : 'matriz', $legado ? $data . ' 00:00:00' : self::agora());
    }

    /** Reconcilia o feed de um dia (todos os closers, ou só $vendedorId) com contratos_tipos. $leitura = chamado ao abrir o feed. */
    public function sincronizarFeed(string $data, ?int $vendedorId, bool $leitura): void
    {
        $agregado = $this->lancamentos->agregadoDeTipos($data, $vendedorId);
        $feed = $this->lancamentos->agregadoDoFeed($data, $vendedorId);
        foreach (array_unique(array_merge(array_keys($agregado), array_keys($feed))) as $chave) {
            $q = $agregado[$chave]['q'] ?? 0;
            if ($q === ($feed[$chave] ?? 0)) {
                continue;
            }
            [$v, $t] = explode('|', (string) $chave, 2);
            $this->reconciliarFeed((int) $v, $data, $t, $agregado[$chave]['d'] ?? '', $q, $leitura);
        }
    }

    /** Valida os campos de uma venda rápida. Devolve a mensagem de erro ('' = ok). */
    public function validarVenda(int $vendedorId, string $data, string $tipo, int $quantidade, bool $exigeAtivo): string
    {
        $v = $this->vendedores->situacao($vendedorId);
        if (!$v) {
            return 'Closer não encontrado.';
        }
        if ($exigeAtivo && $v['deleted_at'] !== null) {
            return 'Este closer está arquivado.';
        }
        if (!self::dataValida($data)) {
            return 'Data inválida.';
        }
        if ($data > Relogio::hoje()) {
            return 'Não é possível registrar vendas em data futura.';
        }
        if (!array_key_exists($tipo, $this->tipos->ativos())) {
            return 'Tipo de benefício inválido.';
        }
        if ($quantidade < 1 || $quantidade > 99) {
            return 'A quantidade deve ser de 1 a 99.';
        }
        return '';
    }
}
