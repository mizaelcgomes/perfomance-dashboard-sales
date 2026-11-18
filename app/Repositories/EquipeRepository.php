<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Equipes (tabela equipes). Só as leituras do ranking da TV e da Retrospectiva. */
final class EquipeRepository
{
    public function __construct(private Database $banco)
    {
    }

    /** Todas as equipes com o que o ranking precisa (cor, logo e metas padrão). @return array<int, array<string, mixed>> */
    public function todasParaRanking(): array
    {
        return $this->banco->consultar('SELECT id, nome, cor, img_url, meta_semanal, meta_mensal FROM equipes WHERE COALESCE(oculta_tv, 0) = 0');
    }

    /** As equipes que a TV pode mostrar (não ocultas), com o que o ranking precisa. @return array<int, array<string, mixed>> */
    public function todasParaTv(): array
    {
        return $this->banco->consultar('SELECT id, nome, cor, img_url, meta_semanal, meta_mensal FROM equipes WHERE COALESCE(oculta_tv, 0) = 0');
    }

    /** Todas as equipes por nome, com o que o Painel do admin precisa. @return array<int, array<string, mixed>> */
    public function todasPorNome(): array
    {
        return $this->banco->consultar('SELECT id, nome, cor, img_url, meta_semanal, meta_mensal FROM equipes WHERE COALESCE(oculta_tv, 0) = 0 ORDER BY nome');
    }

    /**
     * Todas as equipes por nome, com o líder cadastrado (lider_id e o nome dele; o nome só vale se o líder não foi arquivado).
     * @return array<int, array<string, mixed>>
     */
    public function comLideres(): array
    {
        return $this->banco->consultar(
            'SELECT e.id, e.nome, e.cor, e.lider_id, v.nome AS lider_nome
               FROM equipes e LEFT JOIN vendedores v ON v.id = e.lider_id AND v.deleted_at IS NULL
              ORDER BY e.nome'
        );
    }

    /** Uma equipe (id, nome, cor, lider_id e nome do líder), ou null. @return array<string, mixed>|null */
    public function comLider(int $equipeId): ?array
    {
        return $this->banco->linha(
            'SELECT e.id, e.nome, e.cor, e.lider_id, v.nome AS lider_nome
               FROM equipes e LEFT JOIN vendedores v ON v.id = e.lider_id AND v.deleted_at IS NULL
              WHERE e.id = ?',
            [$equipeId]
        );
    }

    /** @return array<int, string> [equipe_id] => nome */
    public function nomes(): array
    {
        $saida = [];
        foreach ($this->banco->consultar('SELECT id,nome FROM equipes') as $r) {
            $saida[(int) $r['id']] = $r['nome'];
        }
        return $saida;
    }

    /**
     * Logo e metas padrão das equipes dadas. $ids vem do próprio ranking (números inteiros), nunca da requisição.
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function logoEMetas(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $lista = implode(',', array_map('intval', $ids));
        return $this->banco->consultar("SELECT id, img_url, meta_semanal, meta_mensal FROM equipes WHERE id IN ($lista)");
    }

    // ── Fase 5: cadastro de equipes (tela Time/Equipes do admin) ───────────────────────────────────

    /**
     * Todas as equipes por nome, com contagem de closers ativos, vendas e metas somadas e o nome do líder. O nome do líder sai de subconsulta
     * (e não de JOIN) para não brigar com o ONLY_FULL_GROUP_BY, e já valida o vínculo: só vale se ele ainda for closer ativo desta equipe.
     * @return array<int, array<string, mixed>>
     */
    public function comTotais(): array
    {
        return $this->banco->consultar(
            'SELECT e.*, COUNT(v.id) AS total_closers,
                    COALESCE(SUM(CASE WHEN COALESCE(e.oculta_tv, 0) = 1 THEN 0 ELSE v.vendas END),0) AS total_vendas,
                    COALESCE(SUM(v.metas),0)  AS total_metas,
                    (SELECT li.nome FROM vendedores li
                      WHERE li.id = e.lider_id AND li.equipe_id = e.id AND li.deleted_at IS NULL) AS lider_nome
               FROM equipes e LEFT JOIN vendedores v ON v.equipe_id=e.id AND v.deleted_at IS NULL
              GROUP BY e.id ORDER BY e.nome'
        );
    }

    /** Foto e metas fixas antigas de uma equipe, ou null. @return array<string, mixed>|null */
    public function paraEdicao(int $id): ?array
    {
        return $this->banco->linha('SELECT img_url, meta_semanal, meta_mensal, oculta_tv FROM equipes WHERE id=?', [$id]);
    }

    public function existe(int $id): bool
    {
        return $this->banco->linha('SELECT 1 FROM equipes WHERE id=?', [$id]) !== null;
    }

    public function criar(string $nome, string $cor, ?string $imagem, int $metaSemanal, int $metaMensal, int $ocultaTv = 0): int
    {
        $this->banco->executar('INSERT INTO equipes (nome,cor,img_url,meta_semanal,meta_mensal,oculta_tv) VALUES (?,?,?,?,?,?)', [$nome, $cor, $imagem, $metaSemanal, $metaMensal, $ocultaTv]);
        return (int) $this->banco->idInserido();
    }

    public function atualizar(int $id, string $nome, string $cor, ?string $imagem, int $metaSemanal, int $metaMensal, ?int $liderId, int $ocultaTv): void
    {
        $this->banco->executar(
            'UPDATE equipes SET nome=?,cor=?,img_url=?,meta_semanal=?,meta_mensal=?,lider_id=?,oculta_tv=? WHERE id=?',
            [$nome, $cor, $imagem, $metaSemanal, $metaMensal, $liderId, $ocultaTv, $id]
        );
    }

    /** Apaga a equipe; os closers dela ficam sem equipe (não são apagados). */
    public function excluir(int $id): void
    {
        $this->banco->executar('UPDATE vendedores SET equipe_id=NULL WHERE equipe_id=?', [$id]);
        $this->banco->executar('DELETE FROM equipes WHERE id=?', [$id]);
    }

    /** O closer é ativo e pertence a esta equipe (condição para ser o líder dela)? */
    public function ehMembroAtivo(int $closerId, int $equipeId): bool
    {
        return $this->banco->linha('SELECT 1 FROM vendedores WHERE id=? AND equipe_id=? AND deleted_at IS NULL', [$closerId, $equipeId]) !== null;
    }

    public function definirLider(int $equipeId, ?int $liderId): void
    {
        $this->banco->executar('UPDATE equipes SET lider_id=? WHERE id=?', [$liderId, $equipeId]);
    }

    /** Tira a liderança de quem era líder de qualquer equipe (closer arquivado). */
    public function tirarLiderancaDe(int $closerId): void
    {
        $this->banco->executar('UPDATE equipes SET lider_id=NULL WHERE lider_id=?', [$closerId]);
    }

    /**
     * Quem muda de equipe perde a liderança da equipe de onde sai: sem destino ($destino null) perde de qualquer uma; com destino, só das outras.
     * @param array<int, int> $closerIds
     */
    public function tirarLiderancaAoMover(array $closerIds, ?int $destino): void
    {
        $marcas = implode(',', array_fill(0, count($closerIds), '?'));
        if ($destino === null) {
            $this->banco->executar("UPDATE equipes SET lider_id=NULL WHERE lider_id IN ($marcas)", $closerIds);
        } else {
            $this->banco->executar("UPDATE equipes SET lider_id=NULL WHERE lider_id IN ($marcas) AND id<>?", array_merge($closerIds, [$destino]));
        }
    }
}
