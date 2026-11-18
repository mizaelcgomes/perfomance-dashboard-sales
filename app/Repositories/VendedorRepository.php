<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Closers (tabela vendedores) e as equipes deles. Só as leituras dos relatórios (fase 2). */
final class VendedorRepository
{
    public function __construct(private Database $banco)
    {
    }

    /**
     * Todos os closers (inclusive os desligados e os suspensos) com os dados da equipe e o nome do líder, por ordem alfabética.
     * O nome do líder sai de subconsulta (e não de JOIN) para não brigar com o ONLY_FULL_GROUP_BY, e só vale se ele ainda for
     * closer ativo daquela equipe.
     * @return array<int, array<string, mixed>>
     */
    public function paraRelatorio(): array
    {
        return $this->banco->consultar(
            'SELECT v.id, v.nome, v.metas, v.meta_mensal, v.cor, v.img_url, v.equipe_id, v.suspended_at, v.deleted_at,
                    e.nome AS equipe_nome, e.cor AS equipe_cor, e.lider_id,
                    (SELECT li.nome FROM vendedores li
                      WHERE li.id = e.lider_id AND li.equipe_id = e.id AND li.deleted_at IS NULL) AS equipe_lider
             FROM vendedores v LEFT JOIN equipes e ON e.id = v.equipe_id
             WHERE ' . self::sqlEquipeVisivel('v.equipe_id') . '
             ORDER BY v.nome'
        );
    }

    /** Closers da "Visão do Time — Mês": só os ativos, ou todos (inclusive desligados) quando $incluirDesligados. @return array<int, array<string, mixed>> */
    public function paraResumoMensal(bool $incluirDesligados): array
    {
        $colunas = 'id, nome, metas, meta_mensal, cor, img_url, img_pravatar, deleted_at';
        return $incluirDesligados
            ? $this->banco->consultar("SELECT $colunas FROM vendedores WHERE " . self::sqlEquipeVisivel('equipe_id') . " ORDER BY nome")
            : $this->banco->consultar("SELECT $colunas FROM vendedores WHERE deleted_at IS NULL AND " . self::sqlEquipeVisivel('equipe_id') . " ORDER BY nome");
    }

    /**
     * Closers que aparecem no ranking da TV: ativos (não desligados nem suspensos/de férias), com a equipe, menos os de equipe oculta da TV (equipes.oculta_tv). A ordem é a do banco
     * (a TV ordena depois). @return array<int, array<string, mixed>>
     */
    public function ativosParaRanking(): array
    {
        return array_values(array_filter($this->ativosParaTv(), static fn(array $v): bool => !(int) $v['equipe_oculta']));
    }

    /**
     * Todos os closers ativos (não desligados nem suspensos/de férias), com a equipe e equipe_oculta (equipes.oculta_tv). Inclui os de equipe
     * oculta da TV: só o ranking da TV os usa, para somar os contratos deles na equipe. @return array<int, array<string, mixed>>
     */
    public function ativosParaTv(): array
    {
        return $this->banco->consultar(
            'SELECT v.id, v.nome, v.metas, v.meta_mensal, v.meta_diaria, v.cor, v.img_url, v.img_pravatar, v.equipe_id,
                    e.nome AS equipe_nome, e.cor AS equipe_cor, COALESCE(e.oculta_tv, 0) AS equipe_oculta
             FROM vendedores v
             LEFT JOIN equipes e ON e.id = v.equipe_id
             WHERE v.deleted_at IS NULL AND v.suspended_at IS NULL'
        );
    }

    /** Todos os closers que não estão suspensos (desligados inclusive), por nome: o filtro de ranking por período. @return array<int, array<string, mixed>> */
    public function naoSuspensosPorNome(): array
    {
        return $this->banco->consultar('SELECT id, nome, metas, meta_mensal, equipe_id, deleted_at FROM vendedores WHERE suspended_at IS NULL AND ' . self::sqlEquipeVisivel('equipe_id') . ' ORDER BY nome');
    }

    /** Closers do Painel do admin: todos os não suspensos (a ordem final é feita depois). @return array<int, array<string, mixed>> */
    public function naoSuspensosParaPainel(): array
    {
        return $this->banco->consultar('SELECT id, nome, metas, meta_mensal, cor, img_url, equipe_id, deleted_at FROM vendedores WHERE suspended_at IS NULL AND ' . self::sqlEquipeVisivel('equipe_id'));
    }

    /** Closers da "Visão do Time — Semana", por nome; $incluirDesligados traz também os desligados. @return array<int, array<string, mixed>> */
    public function paraResumoSemanal(bool $incluirDesligados): array
    {
        $onde = ($incluirDesligados ? '1' : 'deleted_at IS NULL') . ' AND ' . self::sqlEquipeVisivel('equipe_id');
        return $this->banco->consultar("SELECT id,nome,metas,meta_mensal,cor,img_url,img_pravatar,equipe_id,deleted_at FROM vendedores WHERE $onde ORDER BY nome");
    }

    /**
     * Closers (não arquivados) no escopo da Produtividade: todos, só de uma equipe ou só um closer (0 = sem filtro).
     * Férias (suspended_at) entram: a Produtividade os mostra marcados. @return array<int, array<string, mixed>>
     */
    public function paraProdutividade(int $equipeId = 0, int $closerId = 0): array
    {
        $onde = ['v.deleted_at IS NULL', self::sqlEquipeVisivel('v.equipe_id')];
        $parametros = [];
        if ($equipeId > 0) {
            $onde[] = 'v.equipe_id = ?';
            $parametros[] = $equipeId;
        }
        if ($closerId > 0) {
            $onde[] = 'v.id = ?';
            $parametros[] = $closerId;
        }
        return $this->banco->consultar(
            'SELECT v.id, v.nome, v.equipe_id, v.cor, v.img_url, v.suspended_at, e.nome AS equipe_nome, e.cor AS equipe_cor
               FROM vendedores v LEFT JOIN equipes e ON e.id = v.equipe_id
              WHERE ' . implode(' AND ', $onde) . ' ORDER BY v.nome',
            $parametros
        );
    }

    /** Todos os closers (id, nome, cor, deleted_at), por nome: os nomes dos gráficos. @return array<int, array<string, mixed>> */
    public function todosParaGrafico(): array
    {
        return $this->banco->consultar('SELECT id,nome,cor,deleted_at FROM vendedores WHERE ' . self::sqlEquipeVisivel('equipe_id') . ' ORDER BY nome');
    }

    /** Soma das metas semanais (mínimo 1 por closer) dos membros ATIVOS da equipe. */
    public function somaMetasDosMembros(int $equipeId): int
    {
        $soma = 0;
        foreach ($this->banco->consultar('SELECT metas FROM vendedores WHERE equipe_id=? AND deleted_at IS NULL AND suspended_at IS NULL', [$equipeId]) as $r) {
            $soma += max(1, (int) $r['metas']);
        }
        return $soma;
    }

    /**
     * Liga/desliga a suspensão pelas férias agendadas: quem tem férias que terminaram antes de $hoje volta (e as datas são limpas);
     * quem tem férias que começaram até $hoje e ainda não foi suspenso passa a ficar suspenso. Férias sem datas não são tocadas.
     */
    public function sincronizarFerias(string $hoje): void
    {
        $this->banco->executar('UPDATE vendedores SET suspended_at=NULL, ferias_inicio=NULL, ferias_fim=NULL WHERE deleted_at IS NULL AND ferias_fim IS NOT NULL AND ferias_fim < ?', [$hoje]);
        $this->banco->executar('UPDATE vendedores SET suspended_at=NOW() WHERE deleted_at IS NULL AND suspended_at IS NULL AND ferias_inicio IS NOT NULL AND ferias_inicio <= ?', [$hoje]);
    }

    /** Quantos closers estão suspensos (férias ou suspensão), sem contar os desligados. */
    public function contarSuspensos(): int
    {
        return (int) $this->banco->valor('SELECT COUNT(*) FROM vendedores WHERE deleted_at IS NULL AND suspended_at IS NOT NULL');
    }

    /** Condição SQL para contratos (coluna = vendedor_id): deixa de fora os closers de equipe oculta da TV. */
    public static function sqlSemOcultos(string $coluna): string
    {
        return "$coluna NOT IN (SELECT ov.id FROM vendedores ov INNER JOIN equipes oe ON oe.id = ov.equipe_id WHERE oe.oculta_tv = 1)";
    }

    /** Condição SQL para closers (coluna = equipe_id): os sem equipe ficam; os de equipe oculta da TV saem. */
    public static function sqlEquipeVisivel(string $coluna): string
    {
        return "($coluna IS NULL OR $coluna NOT IN (SELECT oe.id FROM equipes oe WHERE oe.oculta_tv = 1))";
    }

    /** Closers desligados ou suspensos que ainda têm equipe: os contratos deles continuam somando na equipe. @return array<int, array<string, mixed>> */
    public function inativosComEquipe(): array
    {
        return $this->banco->consultar('SELECT id, equipe_id FROM vendedores WHERE (deleted_at IS NOT NULL OR suspended_at IS NOT NULL) AND equipe_id IS NOT NULL');
    }

    /**
     * Closers de UMA equipe que não foram desligados (os de férias entram), por nome: [id, nome]. Base dos menus "closer" das telas do líder e
     * da conferência de que um closer pertence à equipe antes de gravar um registro.
     * @return array<int, array{id: int, nome: string}>
     */
    public function doTime(int $equipeId): array
    {
        $saida = [];
        foreach ($this->banco->consultar('SELECT id, nome FROM vendedores WHERE equipe_id = ? AND deleted_at IS NULL ORDER BY nome', [$equipeId]) as $r) {
            $saida[] = ['id' => (int) $r['id'], 'nome' => (string) $r['nome']];
        }
        return $saida;
    }

    /** Nome de cada vendedor (inclusive desligado), para mostrar nos registros antigos. @param array<int, int> $ids @return array<int, string> id => nome */
    public function nomes(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $saida = [];
        foreach ($this->banco->consultar("SELECT id, nome FROM vendedores WHERE id IN ($marcas)", $ids) as $r) {
            $saida[(int) $r['id']] = (string) $r['nome'];
        }
        return $saida;
    }

    // ── Fase 5: cadastro de closers (tela Time/Closers do admin) ───────────────────────────────────

    /** Closers em férias (suspensos e não arquivados) com a equipe, por nome. @return array<int, array<string, mixed>> */
    public function suspensos(): array
    {
        return $this->banco->consultar(
            'SELECT v.id, v.nome, v.metas, v.meta_mensal, v.cor, v.img_url, v.img_pravatar, v.equipe_id,
                    v.suspended_at, v.ferias_inicio, v.ferias_fim, e.nome AS equipe_nome
               FROM vendedores v
               LEFT JOIN equipes e ON e.id = v.equipe_id
              WHERE v.suspended_at IS NOT NULL AND v.deleted_at IS NULL
              ORDER BY v.nome'
        );
    }

    /** Closers arquivados (o mais recente primeiro), com a equipe e o motivo do desligamento. @return array<int, array<string, mixed>> */
    public function arquivados(): array
    {
        return $this->banco->consultar(
            'SELECT v.id, v.nome, v.metas, v.meta_mensal, v.cor, v.img_url, v.img_pravatar,
                    v.equipe_id, v.deleted_at, v.desligamento_motivo, e.nome AS equipe_nome
               FROM vendedores v
               LEFT JOIN equipes e ON e.id = v.equipe_id
              WHERE v.deleted_at IS NOT NULL
              ORDER BY v.deleted_at DESC'
        );
    }

    /** Férias marcadas (em curso ou agendadas) dos closers ativos: [id => [inicio, fim]]. @return array<int, array{inicio: ?string, fim: ?string}> */
    public function feriasMarcadas(): array
    {
        $saida = [];
        foreach ($this->banco->consultar('SELECT id, ferias_inicio, ferias_fim FROM vendedores WHERE deleted_at IS NULL AND ferias_inicio IS NOT NULL') as $r) {
            $saida[(int) $r['id']] = ['inicio' => $r['ferias_inicio'], 'fim' => $r['ferias_fim']];
        }
        return $saida;
    }

    /** Foto, avatar e metas de um closer ativo, ou null (arquivado ou inexistente). @return array<string, mixed>|null */
    public function ativoParaEdicao(int $id): ?array
    {
        return $this->banco->linha('SELECT img_url,img_pravatar,metas,meta_mensal FROM vendedores WHERE id=? AND deleted_at IS NULL', [$id]);
    }

    /** Só a meta semanal de um closer ativo, ou null. @return array<string, mixed>|null */
    public function metasDoAtivo(int $id): ?array
    {
        return $this->banco->linha('SELECT metas FROM vendedores WHERE id=? AND deleted_at IS NULL', [$id]);
    }

    /** As metas base (semanal e mensal) de um closer, arquivado ou não, ou null. @return array<string, mixed>|null */
    public function metasDoCloser(int $id): ?array
    {
        return $this->banco->linha('SELECT metas, meta_mensal FROM vendedores WHERE id=?', [$id]);
    }

    public function ativoExiste(int $id): bool
    {
        return $this->banco->linha('SELECT 1 FROM vendedores WHERE id=? AND deleted_at IS NULL', [$id]) !== null;
    }

    /** A linha do closer (arquivado ou não) com o que importa para validar um lançamento (deleted_at), ou null. @return array<string, mixed>|null */
    public function situacao(int $id): ?array
    {
        return $this->banco->linha('SELECT deleted_at FROM vendedores WHERE id=?', [$id]);
    }

    /** Já existe outro closer ativo com este nome (sem diferenciar maiúsculas)? $exceto = o próprio closer, numa edição. */
    public function nomeEmUso(string $nome, int $exceto = 0): bool
    {
        return $exceto > 0
            ? $this->banco->linha('SELECT id FROM vendedores WHERE LOWER(nome)=LOWER(?) AND id<>? AND deleted_at IS NULL', [$nome, $exceto]) !== null
            : $this->banco->linha('SELECT id FROM vendedores WHERE LOWER(nome)=LOWER(?) AND deleted_at IS NULL', [$nome]) !== null;
    }

    /** Cria o closer (sem vendas, faltando a meta inteira, score 0) e devolve o id. */
    public function criar(?int $equipeId, string $nome, int $metas, int $metaMensal, string $cor, ?string $imagem, ?int $avatar): int
    {
        $this->banco->executar(
            'INSERT INTO vendedores (equipe_id,nome,metas,meta_mensal,vendas,faltam,score,cor,img_url,img_pravatar) VALUES (?,?,?,?,0,?,0,?,?,?)',
            [$equipeId, $nome, $metas, $metaMensal, $metas, $cor, $imagem, $avatar]
        );
        return (int) $this->banco->idInserido();
    }

    /** Linhas zeradas de contratos (uma por dia da semana) do closer novo. */
    public function criarDiasDaSemana(int $id, int $quantos): void
    {
        for ($i = 0; $i < $quantos; $i++) {
            $this->banco->executar('INSERT INTO contratos (vendedor_id,dia_semana,quantidade) VALUES (?,?,0)', [$id, $i]);
        }
    }

    public function atualizar(int $id, ?int $equipeId, string $nome, int $metas, int $metaMensal, string $cor, ?string $imagem, ?int $avatar): void
    {
        $this->banco->executar(
            'UPDATE vendedores SET equipe_id=?,nome=?,metas=?,meta_mensal=?,cor=?,img_url=?,img_pravatar=? WHERE id=?',
            [$equipeId, $nome, $metas, $metaMensal, $cor, $imagem, $avatar, $id]
        );
    }

    /** Arquiva (exclusão lógica): guarda o motivo e limpa qualquer férias, para o closer voltar ativo ao ser reativado. */
    public function arquivar(int $id, ?string $motivo): void
    {
        $this->banco->executar(
            'UPDATE vendedores SET deleted_at=NOW(), desligamento_motivo=?, suspended_at=NULL, ferias_inicio=NULL, ferias_fim=NULL WHERE id=? AND deleted_at IS NULL',
            [$motivo, $id]
        );
    }

    /** Reativa um closer arquivado; $trocarEquipe = também muda a equipe (null = sem equipe). */
    public function restaurar(int $id, bool $trocarEquipe, ?int $equipeId): void
    {
        if ($trocarEquipe) {
            $this->banco->executar('UPDATE vendedores SET deleted_at=NULL, desligamento_motivo=NULL, equipe_id=? WHERE id=?', [$equipeId, $id]);
        } else {
            $this->banco->executar('UPDATE vendedores SET deleted_at=NULL, desligamento_motivo=NULL WHERE id=?', [$id]);
        }
    }

    public function suspender(int $id): void
    {
        $this->banco->executar('UPDATE vendedores SET suspended_at=NOW() WHERE id=? AND deleted_at IS NULL', [$id]);
    }

    /** Volta das férias e cancela as agendadas. */
    public function reativarSuspenso(int $id): void
    {
        $this->banco->executar('UPDATE vendedores SET suspended_at=NULL, ferias_inicio=NULL, ferias_fim=NULL WHERE id=? AND deleted_at IS NULL', [$id]);
    }

    /** Grava o período de férias; $comecouJa = entra em férias agora (suspended_at), senão fica agendado. */
    public function definirFerias(int $id, string $inicio, ?string $fim, bool $comecouJa): void
    {
        $this->banco->executar(
            'UPDATE vendedores SET ferias_inicio=?, ferias_fim=?, suspended_at=IF(?=1, COALESCE(suspended_at, NOW()), NULL) WHERE id=? AND deleted_at IS NULL',
            [$inicio, $fim, $comecouJa ? 1 : 0, $id]
        );
    }

    /**
     * Move closers ativos para outra equipe (null = sem equipe) e devolve quantos mudaram. $ids vem de inteiros já tratados.
     * @param array<int, int> $ids
     */
    public function moverParaEquipe(array $ids, ?int $equipeId): int
    {
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        return $this->banco->executar("UPDATE vendedores SET equipe_id=? WHERE id IN ($marcas) AND deleted_at IS NULL", array_merge([$equipeId], $ids));
    }

    /**
     * Aplica metas (colunas => valor) a vários closers ativos e devolve quantos mudaram. As colunas são fixas do código (metas, meta_mensal).
     * @param array<int, int> $ids
     * @param array<string, int> $colunas
     */
    public function atualizarMetas(array $ids, array $colunas): int
    {
        $sets = [];
        foreach (array_keys($colunas) as $coluna) {
            $sets[] = $coluna . '=?';
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        return $this->banco->executar('UPDATE vendedores SET ' . implode(',', $sets) . " WHERE id IN ($marcas) AND deleted_at IS NULL", array_merge(array_values($colunas), $ids));
    }

    /** Totais da semana corrente do closer (vendas, faltam e score), que o ranking e o painel leem. */
    public function gravarVendas(int $id, int $vendas, int $faltam, int $score): void
    {
        $this->banco->executar('UPDATE vendedores SET vendas=?,faltam=?,score=? WHERE id=?', [$vendas, $faltam, $score, $id]);
    }
}
