<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Repositories\ContaCloserRepository;
use App\Repositories\InteracaoCloserRepository;
use App\Repositories\VendedorRepository;
use Throwable;

/**
 * "Minhas Interações" do portal do closer: o que o líder registrou PARA ele (feedbacks, cobranças de cadastramento e revisões de atendimento,
 * que ele pode ler e responder) e o que é da EQUIPE dele (reuniões e treinamentos, só leitura). O closer é identificado pela conta da sessão
 * (nunca por um id da requisição). Feedbacks, cobranças e revisões têm o aviso "novo" (ainda não visto), que some ao abrir o item; reuniões e
 * treinamentos não têm: não são de um closer específico. A resposta do closer é um texto curto (até 500 letras), guardado à parte.
 */
final class InteracoesCloserService
{
    /** Tipos de interação, na ordem das sub-abas. */
    public const TIPOS = ['feedback', 'cobranca', 'revisao', 'reuniao', 'treinamento'];
    /** Os que o closer pode responder. */
    private const RESPONDE = ['feedback', 'cobranca', 'revisao'];
    public const MAX_RESPOSTA = 500;

    public function __construct(
        private ContaCloserRepository $contas,
        private InteracaoCloserRepository $interacoes,
        private VendedorRepository $vendedores
    ) {
    }

    /**
     * Quantas interações novas (não vistas) há, por tipo, e o total (a bolinha do menu e do sino). null se a conta não é de um closer ativo.
     * @return array{feedback: int, cobranca: int, revisao: int, total: int}|null
     */
    public function contagens(int $contaId): ?array
    {
        $closerId = $this->contas->vendedorDaConta($contaId);
        if ($closerId === null) {
            return null;
        }
        $n = $this->interacoes->naoVistos($closerId);
        return $n + ['total' => array_sum($n)];
    }

    /**
     * Os itens de um tipo, do mais recente para o mais antigo. null se a conta não é de um closer ativo; ['erro' => ...] se o tipo é inválido.
     * @return array<string, mixed>|null
     */
    public function listar(int $contaId, string $tipo): ?array
    {
        $closerId = $this->contas->vendedorDaConta($contaId);
        if ($closerId === null) {
            return null;
        }
        if ($tipo === 'recentes') {
            return ['tipo' => 'recentes', 'itens' => $this->recentes($contaId)];
        }
        if (!in_array($tipo, self::TIPOS, true)) {
            return ['erro' => 'Tipo de interação inválido.'];
        }
        $info = $this->interacoes->equipeDoCloser($closerId);
        $equipeId = (int) ($info['equipe_id'] ?? 0);
        $linhas = match ($tipo) {
            'feedback' => $this->interacoes->feedbacks($closerId),
            'cobranca' => $this->interacoes->cobrancas($closerId),
            'revisao' => $this->interacoes->revisoes($closerId),
            'reuniao' => $equipeId ? $this->interacoes->reunioes($equipeId) : [],
            default => $equipeId ? $this->interacoes->treinamentos($equipeId, $closerId) : [],
        };
        $quem = [];
        foreach ($linhas as $r) {
            $quem[] = (int) ($r['lider_vendedor_id'] ?? $r['criado_por'] ?? 0);
        }
        $nomes = $this->vendedores->nomes($quem);
        $respostas = in_array($tipo, self::RESPONDE, true) ? $this->respostasSeguras($tipo, array_map(static fn(array $r): int => (int) $r['id'], $linhas), $closerId) : null;

        $itens = [];
        foreach ($linhas as $r) {
            $itens[] = $this->item($tipo, $r, $nomes, $respostas);
        }
        return ['tipo' => $tipo, 'itens' => $itens, 'pode_responder' => $respostas !== null, 'maximo' => self::MAX_RESPOSTA];
    }

    /** As 3 interações mais recentes do líder para o closer (feedbacks, cobranças e revisões juntos): o cartão do Meu dia. @return array<int, array<string, mixed>> */
    private function recentes(int $contaId): array
    {
        $todos = [];
        foreach (self::RESPONDE as $tipo) {
            foreach ($this->listar($contaId, $tipo)['itens'] ?? [] as $i) {
                $todos[] = $i;
            }
        }
        usort($todos, static fn(array $a, array $b): int => strcmp($b['data'], $a['data']) ?: ($b['id'] <=> $a['id']));
        return array_slice($todos, 0, 3);
    }

    /** O closer abriu um item: marca como visto. Devolve o que mudou, ou null se a conta não é de closer. @return array{marcados: int}|null */
    public function marcarVisto(int $contaId, string $tipo, int $id): ?array
    {
        $closerId = $this->contas->vendedorDaConta($contaId);
        if ($closerId === null) {
            return null;
        }
        if (!in_array($tipo, self::RESPONDE, true)) {
            return ['marcados' => 0];
        }
        return ['marcados' => $this->interacoes->marcarVisto($tipo, $id, $closerId, Relogio::agora()->format('Y-m-d H:i:s'))];
    }

    /**
     * Grava a resposta do closer a um feedback, cobrança ou revisão DELE. Devolve ['success' => true, 'resposta' => ...] ou ['success' => false,
     * 'message' => ...]; null se a conta não é de um closer ativo.
     * @return array<string, mixed>|null
     */
    public function responder(int $contaId, string $tipo, int $id, string $texto): ?array
    {
        $closerId = $this->contas->vendedorDaConta($contaId);
        if ($closerId === null) {
            return null;
        }
        if (!in_array($tipo, self::RESPONDE, true)) {
            return ['success' => false, 'message' => 'Só dá para responder feedbacks, cobranças e revisões.'];
        }
        $texto = Texto::limpar($texto, self::MAX_RESPOSTA);
        if ($texto === '') {
            return ['success' => false, 'message' => 'Escreva a resposta antes de enviar.'];
        }
        if (!$this->interacoes->pertence($tipo, $id, $closerId)) {
            return ['success' => false, 'message' => 'Interação não encontrada.'];
        }
        $agora = Relogio::agora()->format('Y-m-d H:i:s');
        $rid = $this->interacoes->inserirResposta($tipo, $id, $closerId, $texto, $agora);
        $this->interacoes->marcarVisto($tipo, $id, $closerId, $agora);        // quem responde, leu
        return ['success' => true, 'message' => 'Resposta enviada.', 'resposta' => ['id' => $rid, 'texto' => $texto, 'criado_em' => $agora]];
    }

    /** As respostas de um tipo, ou null se a tabela ainda não existe (migração do líder não rodada): a tela só esconde o campo de resposta. */
    private function respostasSeguras(string $tipo, array $ids, int $closerId): ?array
    {
        try {
            return $this->interacoes->respostas($tipo, $ids, $closerId);
        } catch (Throwable $erro) {
            error_log('[portal-closer] respostas: ' . $erro->getMessage());
            return null;
        }
    }

    /**
     * @param array<string, mixed> $r
     * @param array<int, string> $nomes
     * @param array<int, array<int, array<string, mixed>>>|null $respostas
     * @return array<string, mixed>
     */
    private function item(string $tipo, array $r, array $nomes, ?array $respostas): array
    {
        $id = (int) $r['id'];
        $quem = (int) ($r['lider_vendedor_id'] ?? $r['criado_por'] ?? 0);
        $base = ['id' => $id, 'tipo' => $tipo, 'lider_nome' => $nomes[$quem] ?? '', 'novo' => false, 'atualizado' => false, 'respostas' => $respostas[$id] ?? []];
        $hoje = Relogio::hoje();
        switch ($tipo) {
            case 'feedback':
                return array_merge($base, ['titulo' => (string) $r['assunto'], 'texto' => (string) $r['texto'], 'data' => (string) $r['data'], 'novo' => $r['visto_em'] === null, 'atualizado' => $r['atualizado_em'] !== null]);
            case 'cobranca':
                return array_merge($base, ['titulo' => 'Cobrança de cadastramento', 'texto' => (string) ($r['observacao'] ?? ''), 'data' => (string) $r['data'], 'novo' => $r['visto_em'] === null, 'atualizado' => $r['atualizado_em'] !== null]);
            case 'revisao':
                return array_merge($base, [
                    'titulo' => 'Revisão de atendimento', 'texto' => (string) ($r['observacao'] ?? ''), 'data' => (string) $r['data'],
                    'cliente' => Telefone::mascarar(Telefone::digitos($r['telefone_cliente'])), 'novo' => $r['visto_em'] === null, 'atualizado' => $r['atualizado_em'] !== null,
                ]);
            case 'reuniao':
                return array_merge($base, [
                    'titulo' => (string) $r['titulo'], 'texto' => '', 'data' => substr((string) $r['data_hora_marcada'], 0, 10), 'quando' => (string) $r['data_hora_marcada'],
                    'status' => (string) $r['status'], 'realizada_em' => $r['data_hora_realizada'],
                ]);
            default:
                $passou = substr((string) $r['data_hora'], 0, 10) < $hoje;
                $status = $r['cancelado_em'] !== null ? 'cancelado' : ($passou ? 'realizado' : 'agendado');
                return array_merge($base, [
                    'titulo' => (string) $r['assunto'], 'texto' => '', 'data' => substr((string) $r['data_hora'], 0, 10), 'quando' => (string) $r['data_hora'],
                    'status' => $status, 'presente' => (int) $r['presente'] > 0,
                ]);
        }
    }
}
