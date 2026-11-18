<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Repositories\CobrancaCadastroRepository;
use App\Repositories\ContaCloserRepository;
use App\Repositories\FeedbackRepository;
use App\Repositories\VendedorRepository;

/**
 * "Meus feedbacks" no portal do closer: os feedbacks e as cobranças de cadastramento que o líder registrou PARA ELE, só leitura, com o aviso
 * "novo" (ainda não visto). O closer é identificado pela conta da sessão (nunca por um id vindo da requisição), então ninguém lê o que
 * outro closer recebeu. O closer vê tudo o que recebeu (sem campo de visibilidade, decisão do cliente).
 */
final class MeusFeedbacksService
{
    private const LIMITE_LISTA = 100;

    public function __construct(
        private ContaCloserRepository $contas,
        private FeedbackRepository $feedbacks,
        private CobrancaCadastroRepository $cobrancas,
        private VendedorRepository $vendedores
    ) {
    }

    /**
     * Lista do closer da conta (mais recentes primeiro), com os novos contados. null se a conta não é de um closer ativo.
     * @return array{itens: array<int, array<string, mixed>>, novos: int}|null
     */
    public function listar(int $contaId): ?array
    {
        $vendedorId = $this->contas->vendedorDaConta($contaId);
        if ($vendedorId === null) {
            return null;
        }
        $fb = $this->feedbacks->listarDoCloser($vendedorId);
        $cb = $this->cobrancas->listarDoCloser($vendedorId);
        $nomes = $this->vendedores->nomes(array_merge(
            array_map(static fn(array $r): int => (int) $r['lider_vendedor_id'], $fb),
            array_map(static fn(array $r): int => (int) $r['lider_vendedor_id'], $cb)
        ));
        $itens = [];
        foreach ($fb as $r) {
            $itens[] = [
                'tipo' => 'feedback',
                'id' => (int) $r['id'],
                'titulo' => (string) $r['assunto'],
                'texto' => (string) $r['texto'],
                'data' => (string) $r['data'],
                'lider_nome' => $nomes[(int) $r['lider_vendedor_id']] ?? '',
                'novo' => $r['visto_em'] === null,
                'atualizado' => $r['atualizado_em'] !== null,
                'ordem' => (string) ($r['atualizado_em'] ?? $r['criado_em']),
            ];
        }
        foreach ($cb as $r) {
            $itens[] = [
                'tipo' => 'cobranca',
                'id' => (int) $r['id'],
                'titulo' => 'Cobrança de cadastramento',
                'texto' => (string) ($r['observacao'] ?? ''),
                'data' => (string) $r['data'],
                'lider_nome' => $nomes[(int) $r['lider_vendedor_id']] ?? '',
                'novo' => $r['visto_em'] === null,
                'atualizado' => $r['atualizado_em'] !== null,
                'ordem' => (string) ($r['atualizado_em'] ?? $r['criado_em']),
            ];
        }
        usort($itens, static fn(array $a, array $b): int => strcmp($b['ordem'], $a['ordem']) ?: ($b['id'] <=> $a['id']));
        $itens = array_slice($itens, 0, self::LIMITE_LISTA);
        $novos = $this->feedbacks->contarNaoVistosDoCloser($vendedorId) + $this->cobrancas->contarNaoVistosDoCloser($vendedorId);
        foreach ($itens as &$i) {
            unset($i['ordem']);
        }
        unset($i);
        return ['itens' => $itens, 'novos' => $novos];
    }

    /** Quantos o closer ainda não viu (para o contador no portal). */
    public function contarNovos(int $contaId): int
    {
        $vendedorId = $this->contas->vendedorDaConta($contaId);
        return $vendedorId === null ? 0 : $this->feedbacks->contarNaoVistosDoCloser($vendedorId) + $this->cobrancas->contarNaoVistosDoCloser($vendedorId);
    }

    /** O closer abriu a seção: tudo o que tinha vira visto. Devolve quantos mudaram. */
    public function marcarVistos(int $contaId): int
    {
        $vendedorId = $this->contas->vendedorDaConta($contaId);
        if ($vendedorId === null) {
            return 0;
        }
        $agora = Relogio::agora()->format('Y-m-d H:i:s');
        return $this->feedbacks->marcarVistosDoCloser($vendedorId, $agora) + $this->cobrancas->marcarVistosDoCloser($vendedorId, $agora);
    }
}
