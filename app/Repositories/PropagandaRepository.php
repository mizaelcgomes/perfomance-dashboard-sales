<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Propagandas da rotação da TV (tabela propagandas: páginas HTML exibidas em tela cheia). */
final class PropagandaRepository
{
    public function __construct(private Database $banco)
    {
    }

    /** Todas (a mais nova primeiro) ou só as ativas. Todas as colunas, como o api.php sempre devolveu. @return array<int, array<string, mixed>> */
    public function listar(bool $soAtivas): array
    {
        return $this->banco->consultar($soAtivas ? 'SELECT * FROM propagandas WHERE ativo=1 ORDER BY id DESC' : 'SELECT * FROM propagandas ORDER BY id DESC');
    }

    /** O que o painel da TV recebe junto com os rankings (get_data). @return array<int, array<string, mixed>> */
    public function paraPainel(): array
    {
        return $this->banco->consultar('SELECT id,titulo,conteudo_html,ativo FROM propagandas ORDER BY id');
    }

    public function inserir(string $titulo, string $html, int $ativo): int
    {
        $this->banco->executar('INSERT INTO propagandas (titulo,conteudo_html,ativo) VALUES (?,?,?)', [$titulo, $html, $ativo]);
        return (int) $this->banco->idInserido();
    }

    public function atualizar(int $id, string $titulo, string $html, int $ativo): void
    {
        $this->banco->executar('UPDATE propagandas SET titulo=?,conteudo_html=?,ativo=? WHERE id=?', [$titulo, $html, $ativo, $id]);
    }

    public function excluir(int $id): void
    {
        $this->banco->executar('DELETE FROM propagandas WHERE id=?', [$id]);
    }

    /** Inverte ativo/inativo e devolve o estado novo (false também quando o id não existe, como sempre foi). */
    public function alternar(int $id): bool
    {
        $this->banco->executar('UPDATE propagandas SET ativo = 1 - ativo WHERE id=?', [$id]);
        $linha = $this->banco->linha('SELECT ativo FROM propagandas WHERE id=?', [$id]);
        return $linha !== null && (bool) $linha['ativo'];
    }
}
