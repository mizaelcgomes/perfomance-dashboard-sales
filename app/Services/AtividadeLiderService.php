<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Repositories\AtividadeLiderRepository;
use App\Repositories\ContaCloserRepository;
use App\Repositories\EquipeRepository;
use App\Repositories\VendedorRepository;
use DateTimeImmutable;

/**
 * "Atividades dos líderes" (admin, aba Produtividade): o que cada líder registrou no período e quando foi a última vez.
 *  - atividade = registro CRIADO pelo líder, na equipe dele (editar não conta);
 *  - o período é o mesmo da Produtividade (Hoje, Semana, Mês, Personalizado), calculado pelo mesmo serviço;
 *  - os registros próprios do líder como closer (ligações, follow-ups, leads, contratos) vêm da própria Produtividade, só como referência;
 *  - alerta quando a última atividade tem mais de LIMITE_DIAS dias (ou nunca houve).
 * Só leitura. Equipe sem líder aparece com zeros.
 */
final class AtividadeLiderService
{
    /** Dias corridos sem atividade a partir dos quais o admin é alertado (decisão 9 do plano). */
    public const LIMITE_DIAS = 3;

    public function __construct(
        private ProdutividadeService $produtividade,
        private EquipeRepository $equipes,
        private AtividadeLiderRepository $atividades,
        private ContaCloserRepository $contas,
        private VendedorRepository $vendedores
    ) {
    }

    /**
     * Uma linha por equipe (ou só a pedida), com os sem atividade primeiro.
     * @return array{periodo: array<string, mixed>, limite_dias: int, equipes: array<int, array<string, mixed>>}
     */
    public function resumo(string $periodo, string $from = '', string $to = '', int $equipeId = 0): array
    {
        $range = $this->produtividade->periodo($periodo, $from, $to);
        $de = $range['start'] . ' 00:00:00';
        $ate = ($range['vazio'] ? $range['start'] : ($range['eff_end'] ?? $range['end'])) . ' 23:59:59';
        $comConta = $this->contas->vendedoresComContaAtiva();
        $linhas = [];
        foreach ($this->equipes->comLideres() as $e) {
            if ($equipeId > 0 && (int) $e['id'] !== $equipeId) {
                continue;
            }
            $linhas[] = $this->linha($e, $periodo, $from, $to, $de, $ate, (bool) $range['vazio'], $comConta);
        }
        usort($linhas, static function (array $a, array $b): int {
            // sem líder por último; sem atividade no período primeiro; depois mais atividade primeiro; empate por equipe
            $grupo = static fn(array $l): int => $l['lider_nome'] === null ? 2 : ($l['total'] === 0 ? 0 : 1);
            return ($grupo($a) <=> $grupo($b)) ?: ($b['total'] <=> $a['total']) ?: strcmp((string) $a['equipe_nome'], (string) $b['equipe_nome']);
        });
        return ['periodo' => $range, 'limite_dias' => self::LIMITE_DIAS, 'equipes' => $linhas];
    }

    /**
     * Os últimos registros de liderança de UMA equipe (o líder atual dela). null se a equipe não existe.
     * @return array<string, mixed>|null
     */
    public function detalhe(int $equipeId, int $limite = 20): ?array
    {
        $e = $this->equipes->comLider($equipeId);
        if ($e === null) {
            return null;
        }
        $liderId = $e['lider_id'] === null || $e['lider_nome'] === null ? 0 : (int) $e['lider_id'];
        $registros = [];
        if ($liderId > 0) {
            $linhas = $this->atividades->ultimos($equipeId, $liderId, $limite);
            $nomes = $this->vendedores->nomes(array_filter(array_map(static fn(array $r): int => (int) ($r['closer_vendedor_id'] ?? 0), $linhas)));
            foreach ($linhas as $r) {
                $registros[] = [
                    'tipo' => (string) $r['tipo'],
                    'id' => (int) $r['id'],
                    'quando' => (string) $r['quando'],
                    'closer_nome' => $r['closer_vendedor_id'] === null ? null : ($nomes[(int) $r['closer_vendedor_id']] ?? null),
                    'descricao' => (string) $r['descricao'],
                    'status' => $r['status'] === null ? null : (string) $r['status'],
                ];
            }
        }
        return [
            'equipe' => ['id' => (int) $e['id'], 'nome' => (string) $e['nome'], 'cor' => (string) $e['cor']],
            'lider_nome' => $liderId > 0 ? (string) $e['lider_nome'] : null,
            'registros' => $registros,
        ];
    }

    /**
     * @param array<string, mixed> $e linha de EquipeRepository::comLideres()
     * @param array<int, true> $comConta
     * @return array<string, mixed>
     */
    private function linha(array $e, string $periodo, string $from, string $to, string $de, string $ate, bool $vazio, array $comConta): array
    {
        $id = (int) $e['id'];
        $liderId = $e['lider_id'] === null || $e['lider_nome'] === null ? 0 : (int) $e['lider_id'];
        $base = [
            'equipe_id' => $id, 'equipe_nome' => (string) $e['nome'], 'equipe_cor' => (string) $e['cor'],
            'lider_vendedor_id' => $liderId > 0 ? $liderId : null, 'lider_nome' => $liderId > 0 ? (string) $e['lider_nome'] : null,
            'lider_acesso' => $liderId > 0 ? isset($comConta[$liderId]) : null,
        ];
        if ($liderId === 0) {
            return $base + [
                'reunioes' => ['marcadas' => 0, 'realizadas' => 0, 'canceladas' => 0], 'feedbacks' => 0, 'cobrancas' => 0, 'revisoes' => 0,
                'treinamentos' => ['dados' => 0, 'presencas' => 0], 'total' => 0, 'proprios' => null,
                'ultima_atividade' => null, 'dias_sem_atividade' => null, 'alerta' => null,
            ];
        }
        // período que ainda não começou: nada a contar (mesma regra da Produtividade)
        $conta = static fn(callable $f, $zero) => $vazio ? $zero : $f();
        $reunioes = $conta(fn() => $this->atividades->reunioes($id, $liderId, $de, $ate), ['marcadas' => 0, 'realizadas' => 0, 'canceladas' => 0]);
        $feedbacks = $conta(fn() => $this->atividades->registrosDoLider('feedbacks', $id, $liderId, $de, $ate), 0);
        $cobrancas = $conta(fn() => $this->atividades->registrosDoLider('cobrancas_cadastro', $id, $liderId, $de, $ate), 0);
        $revisoes = $conta(fn() => $this->atividades->registrosDoLider('revisoes_atendimento', $id, $liderId, $de, $ate), 0);
        $treinos = $conta(fn() => $this->atividades->treinamentos($id, $liderId, $de, $ate), ['dados' => 0, 'presencas' => 0]);

        $proprios = null;
        $prod = $this->produtividade->dados($periodo, $from, $to, $id, $liderId);
        foreach ($prod['ranking'] as $r) {
            if ((int) $r['id'] === $liderId) {
                $proprios = ['ligacoes' => (int) $r['ligacoes'], 'followups' => (int) $r['followups'], 'leads_quentes' => (int) $r['leads_quentes'], 'contratos' => (int) $r['contratos']];
            }
        }
        $ultima = $this->atividades->ultimaAtividade($id, $liderId);
        $dias = $ultima === null ? null : $this->diasDesde($ultima);
        $alerta = null;
        if ($ultima === null) {
            $alerta = ['k' => 'nunca', 't' => 'Nunca registrou atividade de liderança'];
        } elseif ($dias !== null && $dias > self::LIMITE_DIAS) {
            $alerta = ['k' => 'parado', 't' => "Sem atividade de liderança há $dias dias"];
        }
        return $base + [
            'reunioes' => $reunioes, 'feedbacks' => $feedbacks, 'cobrancas' => $cobrancas, 'revisoes' => $revisoes, 'treinamentos' => $treinos,
            'total' => $reunioes['marcadas'] + $feedbacks + $cobrancas + $revisoes + $treinos['dados'],
            'proprios' => $proprios, 'ultima_atividade' => $ultima, 'dias_sem_atividade' => $dias, 'alerta' => $alerta,
        ];
    }

    /** Dias corridos entre a data de $quando e hoje (0 = hoje). */
    private function diasDesde(string $quando): int
    {
        $hoje = new DateTimeImmutable(Relogio::hoje(), Relogio::fuso());
        $dia = new DateTimeImmutable(substr($quando, 0, 10), Relogio::fuso());
        return max(0, (int) $dia->diff($hoje)->format('%r%a'));
    }
}
