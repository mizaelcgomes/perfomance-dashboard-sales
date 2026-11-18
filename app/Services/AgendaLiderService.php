<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Core\Resultado;
use App\Repositories\EventoNotaRepository;
use App\Repositories\ReuniaoRepository;
use App\Repositories\TreinamentoRepository;
use App\Repositories\VendedorRepository;

/**
 * A Agenda do portal do líder: as reuniões da equipe e os treinamentos (da equipe e os gerais) num mês ou nos próximos dias, com participantes
 * e notas. As notas são só acréscimo (não se edita nem se apaga). Cada evento traz o "item" normalizado que as telas de registro usam para
 * editar, cancelar ou marcar presença, e a equipe vem sempre do middleware: um evento de outra equipe nunca aparece nem recebe nota.
 */
final class AgendaLiderService
{
    /** Os eventos que têm data e hora marcadas (os "próximos" só falam deles); as demais atividades são registros do dia. */
    private const COM_HORA = ['reuniao', 'treinamento'];

    public function __construct(
        private ReuniaoService $reunioes,
        private TreinamentoService $treinamentos,
        private ReuniaoRepository $reunioesRepo,
        private TreinamentoRepository $treinamentosRepo,
        private EventoNotaRepository $notas,
        private VendedorRepository $vendedores,
        private FeedbackService $feedbacks,
        private CobrancaCadastroService $cobrancas,
        private RevisaoAtendimentoService $revisoes
    ) {
    }

    /** Os eventos de um mês. Mês ou ano fora da faixa viram o mês atual. @return array<int, array<string, mixed>> */
    public function mes(int $equipeId, int $ano, int $mes): array
    {
        $agora = Relogio::agora();
        if ($ano < 2020 || $ano > 2100 || $mes < 1 || $mes > 12) {
            $ano = (int) $agora->format('Y');
            $mes = (int) $agora->format('n');
        }
        $primeiro = sprintf('%04d-%02d-01', $ano, $mes);
        $ultimo = (new \DateTimeImmutable($primeiro))->modify('last day of this month')->format('Y-m-d');
        return $this->eventos($equipeId, $primeiro . ' 00:00:00', $ultimo . ' 23:59:59');
    }

    /** As reuniões e os treinamentos de hoje até daqui a $dias dias, sem os cancelados. @return array<int, array<string, mixed>> */
    public function proximos(int $equipeId, int $dias = 7): array
    {
        $dias = max(1, min(60, $dias));
        $hoje = Relogio::agora();
        $eventos = $this->eventos($equipeId, $hoje->format('Y-m-d') . ' 00:00:00', $hoje->modify("+$dias days")->format('Y-m-d') . ' 23:59:59');
        return array_values(array_filter($eventos, static fn(array $e): bool => $e['status'] !== 'cancelada' && in_array($e['tipo'], self::COM_HORA, true)));
    }

    /**
     * Acrescenta uma nota a um evento. O evento tem que ser da equipe (ou treinamento geral, que todas as equipes veem).
     * @param mixed $texto
     */
    public function nota(int $equipeId, int $liderVendedorId, string $tipo, int $eventoId, $texto): Resultado
    {
        if ($tipo === 'reuniao') {
            $existe = $this->reunioesRepo->buscar($eventoId, $equipeId) !== null;
        } elseif ($tipo === 'treinamento') {
            $existe = $this->treinamentosRepo->buscar($eventoId, $equipeId) !== null;
        } else {
            return Resultado::falha('Evento não encontrado.');
        }
        if (!$existe) {
            return Resultado::falha('Evento não encontrado.');
        }
        $t = RegistroValidador::texto($texto, 500, true);
        if ($t === null || $t === '') {
            $msg = $t === null ? 'A nota pode ter até 500 caracteres.' : 'Escreva a nota.';
            return Resultado::falha($msg, ['texto' => $msg]);
        }
        $agora = Relogio::agora()->format('Y-m-d H:i:s');
        $this->notas->inserir($tipo, $eventoId, $equipeId, $t, $liderVendedorId, $agora);
        $nomes = $this->vendedores->nomes([$liderVendedorId]);
        return Resultado::ok(['nota' => ['texto' => $t, 'quando' => $agora, 'autor' => $nomes[$liderVendedorId] ?? '']], 'Nota adicionada.');
    }

    /**
     * @return array<int, array<string, mixed>> ordenados por data e hora
     */
    private function eventos(int $equipeId, string $de, string $ate): array
    {
        $agora = Relogio::agora()->format('Y-m-d H:i:s');
        $reunioes = $this->reunioes->entre($equipeId, $de, $ate);
        $treinos = $this->treinamentos->entre($equipeId, $de, $ate);
        $notasR = $this->notas->listarDe('reuniao', array_column($reunioes, 'id'), $equipeId);
        $notasT = $this->notas->listarDe('treinamento', array_column($treinos, 'id'), $equipeId);
        $ids = [];
        foreach ($reunioes as $r) {
            $ids = array_merge($ids, $r['participantes']);
        }
        foreach ($treinos as $t) {
            $ids = array_merge($ids, $t['presentes']);
        }
        foreach ([$notasR, $notasT] as $grupo) {
            foreach ($grupo as $lista) {
                foreach ($lista as $n) {
                    $ids[] = (int) $n['criado_por'];
                }
            }
        }
        $nomes = $this->vendedores->nomes($ids);
        $pessoas = static fn(array $lista): array => array_map(static fn(int $id): array => ['id' => $id, 'nome' => $nomes[$id] ?? ''], $lista);
        $formatarNotas = static fn(array $lista): array => array_map(static fn(array $n): array => ['texto' => (string) $n['texto'], 'quando' => (string) $n['criado_em'], 'autor' => $nomes[(int) $n['criado_por']] ?? ''], array_slice($lista, 0, 5));

        $saida = [];
        foreach ($reunioes as $r) {
            $saida[] = [
                'tipo' => 'reuniao', 'id' => $r['id'], 'titulo' => $r['titulo'], 'quando' => $r['data_hora_marcada'], 'status' => $r['status'],
                'participantes' => $pessoas($r['participantes']), 'notas' => $formatarNotas($notasR[$r['id']] ?? []),
                'geral' => false, 'editavel' => $r['status'] !== 'cancelada', 'cancelavel' => $r['status'] === 'agendada', 'item' => $r,
            ];
        }
        foreach ($treinos as $t) {
            $status = $t['cancelado'] ? 'cancelada' : ($t['data_hora'] <= $agora ? 'realizado' : 'agendado');
            $saida[] = [
                'tipo' => 'treinamento', 'id' => $t['id'], 'titulo' => $t['assunto'], 'quando' => $t['data_hora'], 'status' => $status,
                'participantes' => $pessoas($t['presentes']), 'notas' => $formatarNotas($notasT[$t['id']] ?? []),
                'geral' => $t['geral'], 'editavel' => $t['editavel'], 'cancelavel' => $t['editavel'], 'item' => $t,
            ];
        }
        $saida = array_merge($saida, $this->registrosDoDia($equipeId, substr($de, 0, 10), substr($ate, 0, 10)));
        usort($saida, static fn(array $a, array $b): int => strcmp($a['quando'], $b['quando']) ?: strcmp($a['tipo'], $b['tipo']) ?: ($a['id'] <=> $b['id']));
        return $saida;
    }

    /**
     * Feedbacks, cobranças de cadastramento e revisões de atendimento do período: têm só o DIA (sem horário), então entram às 00:00 e a tela
     * mostra apenas a data. Vêm das mesmas listas das telas de registro (as últimas 200 de cada tipo, as mais recentes primeiro).
     * @return array<int, array<string, mixed>>
     */
    private function registrosDoDia(int $equipeId, string $de, string $ate): array
    {
        $saida = [];
        $fontes = [
            ['feedback', $this->feedbacks->listar($equipeId)],
            ['cobranca', $this->cobrancas->listar($equipeId)],
            ['revisao', $this->revisoes->listar($equipeId)],
        ];
        foreach ($fontes as [$tipo, $itens]) {
            foreach ($itens as $it) {
                if ($it['data'] < $de || $it['data'] > $ate) {
                    continue;
                }
                $titulo = $tipo === 'feedback' ? $it['assunto'] : ($tipo === 'cobranca' ? 'Cobrança de cadastramento' : 'Revisão de atendimento');
                $detalhe = $tipo === 'feedback' ? $it['texto'] : $it['observacao'];
                if ($tipo === 'revisao') {
                    $detalhe = trim($it['telefone_fmt'] . ($detalhe !== '' ? ' — ' . $detalhe : ''));
                }
                $saida[] = [
                    'tipo' => $tipo, 'id' => $it['id'], 'titulo' => $titulo, 'quando' => $it['data'] . ' 00:00:00', 'sem_hora' => true, 'status' => 'registrada',
                    'closer_nome' => $it['closer_nome'], 'detalhe' => $detalhe, 'visto' => $tipo !== 'revisao' ? $it['visto'] : null,
                    'participantes' => [], 'notas' => [], 'geral' => false, 'editavel' => true, 'cancelavel' => false, 'item' => $it,
                ];
            }
        }
        return $saida;
    }
}
