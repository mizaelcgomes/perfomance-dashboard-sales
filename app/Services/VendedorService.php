<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Repositories\ContratoRepository;
use App\Repositories\VendedorRepository;

/** Leituras da tela Time do admin: closers em férias, arquivados e os dados de apoio (último fechamento e férias marcadas). */
final class VendedorService
{
    public function __construct(private VendedorRepository $vendedores, private ContratoRepository $contratos, private ImagemService $imagens)
    {
    }

    /** Closers em férias (suspensos), com a foto pronta para a tela. @return array<int, array<string, mixed>> */
    public function suspensos(): array
    {
        return array_map(function (array $r): array {
            $r['imageUrl'] = $this->imagens->url($r['img_url']);
            $r['equipe_id'] = $r['equipe_id'] !== null ? (int) $r['equipe_id'] : null;
            return $r;
        }, $this->vendedores->suspensos());
    }

    /**
     * Closers ativos para o admin (cadastro, equipes e seletor de contratos): TODOS, inclusive os de equipe oculta da TV, que a TV pública não recebe.
     * Só a identificação (sem contratos). @return array<int, array<string, mixed>>
     */
    public function elenco(): array
    {
        return array_map(fn(array $r): array => [
            'id' => (int) $r['id'], 'name' => (string) $r['nome'], 'equipe_id' => $r['equipe_id'] !== null ? (int) $r['equipe_id'] : null,
            'equipe_nome' => $r['equipe_nome'], 'color' => $r['cor'], 'imageUrl' => $this->imagens->url($r['img_url']),
        ], $this->vendedores->ativosParaTv());
    }

    /** Closers arquivados, com a foto pronta para a tela. @return array<int, array<string, mixed>> */
    public function arquivados(): array
    {
        return array_map(function (array $r): array {
            $r['imageUrl'] = $this->imagens->url($r['img_url']);
            return $r;
        }, $this->vendedores->arquivados());
    }

    /**
     * Apoio da tela "Time": o último dia com fechamento de cada closer (alerta de "sem fechamentos há N dias") e as férias agendadas ou em
     * curso (inclui as ainda não iniciadas).
     * @return array{today: string, last_sale: array<int, string>, ferias: array<int, array{inicio: ?string, fim: ?string}>}
     */
    public function visaoDoTime(): array
    {
        $hoje = Relogio::hoje();
        return ['today' => $hoje, 'last_sale' => $this->contratos->ultimoDiaComVenda($hoje), 'ferias' => $this->vendedores->feriasMarcadas()];
    }
}
