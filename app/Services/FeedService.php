<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Relogio;
use App\Repositories\LancamentoRepository;

/**
 * O feed de vendas de um dia (aba Contratos → Registro e Feed): um item por lançamento (hora, closer, benefício, quantidade), o total do dia
 * e a soma por benefício. Ao abrir um dia, o feed é reconciliado com os totais da matriz (lançamentos antigos viram "legado", sem hora).
 * Porte fiel do get_feed do api.php.
 */
final class FeedService
{
    public function __construct(
        private Database $banco,
        private ContratoService $contratos,
        private LancamentoRepository $lancamentos,
        private TiposBeneficioService $tipos
    ) {
    }

    /**
     * @param mixed $dataPedida a data do dia (AAAA-MM-DD); inválida ou ausente vale hoje
     * @return array<string, mixed> o corpo do get_feed, sem o "success"
     */
    public function dia($dataPedida): array
    {
        $hoje = Relogio::hoje();
        $data = ContratoService::dataValida($dataPedida) ? (string) $dataPedida : $hoje;
        $this->banco->transacao(function () use ($data): void {
            $this->contratos->sincronizarFeed($data, null, true);
        });

        $nomes = $this->tipos->ativos();
        $cores = $this->tipos->cores();
        $itens = [];
        $porTipo = [];
        $totalFeed = 0;
        foreach ($this->lancamentos->lancamentosDoDia($data) as $r) {
            $rotulo = $nomes[$r['tipo']] ?? TiposBeneficioService::rotuloDeInativo((string) $r['tipo']);   // tipo desativado/removido
            $cor = $cores[$r['tipo']] ?? TiposBeneficioService::cor((string) $r['tipo'], $rotulo);
            $q = (int) $r['quantidade'];
            $totalFeed += $q;
            if (!isset($porTipo[$r['tipo']])) {
                $porTipo[$r['tipo']] = ['tipo' => $r['tipo'], 'label' => $rotulo, 'cor' => $cor, 'qtd' => 0];
            }
            $porTipo[$r['tipo']]['qtd'] += $q;
            $itens[] = [
                'id' => (int) $r['id'], 'vendedor_id' => (int) $r['vendedor_id'], 'closer' => $r['closer'] ?? 'Closer removido', 'arquivado' => $r['deleted_at'] !== null,
                'equipe' => $r['equipe'], 'tipo' => $r['tipo'], 'label' => $rotulo, 'cor' => $cor, 'desc' => $r['tipo_descricao'],
                'qtd' => $q, 'origem' => $r['origem'], 'hora' => $r['origem'] === 'legado' ? null : substr((string) $r['criado_em'], 11, 5),
            ];
        }
        $totalDia = $this->lancamentos->totalDoHistoricoNaData($data);
        usort($porTipo, static fn($a, $b) => TiposBeneficioService::ehOutros($a['tipo']) <=> TiposBeneficioService::ehOutros($b['tipo']) ?: ($b['qtd'] <=> $a['qtd']) ?: strcmp($a['label'], $b['label']));
        return [
            'data' => $data, 'hoje' => $hoje, 'items' => $itens, 'total_dia' => $totalDia, 'total_feed' => $totalFeed,
            'sem_tipo' => max(0, $totalDia - $totalFeed), 'por_tipo' => array_values($porTipo),
        ];
    }
}
