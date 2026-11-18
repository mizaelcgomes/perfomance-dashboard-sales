<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Tipos de benefício criados pelo admin (tabela tipos_beneficio_custom). Os cinco fixos ficam no TiposBeneficioService. */
final class TipoBeneficioRepository
{
    public function __construct(private Database $banco)
    {
    }

    /** @return array<int, array<string, mixed>> id, chave, label, ativo, cor, icone, na ordem escolhida (sort_order, id) */
    public function personalizados(): array
    {
        return $this->banco->consultar('SELECT id, chave, label, ativo, cor, icone FROM tipos_beneficio_custom ORDER BY sort_order, id');
    }

    // ── Fase 5: gravações dos tipos de benefício personalizados (aba Metas & Regras → Tipos de Benefício) ────

    /** A lista crua para a tela (id, chave, label, ativo, sort_order, cor, icone). @return array<int, array<string, mixed>> */
    public function todos(): array
    {
        return $this->banco->consultar('SELECT id, chave, label, ativo, sort_order, cor, icone FROM tipos_beneficio_custom ORDER BY sort_order, id');
    }

    /** A chave do tipo, ou null se o id não existe. */
    public function chaveDoId(int $id): ?string
    {
        $v = $this->banco->valor('SELECT chave FROM tipos_beneficio_custom WHERE id=?', [$id]);
        return $v === null ? null : (string) $v;
    }

    /** @param array<string, mixed> $extras coluna => valor (cor, icone), só as que vieram no envio */
    public function atualizar(int $id, string $label, int $ativo, array $extras): void
    {
        $sets = '';
        foreach (array_keys($extras) as $coluna) {
            $sets .= ', ' . $coluna . '=?';
        }
        $this->banco->executar('UPDATE tipos_beneficio_custom SET label=?, ativo=?' . $sets . ' WHERE id=?', array_merge([$label, $ativo], array_values($extras), [$id]));
    }

    /**
     * Cria o tipo no fim da ordem. Lança PDOException (código 23000) se a chave já existe.
     * @param array<string, mixed> $extras coluna => valor (cor, icone)
     */
    public function inserir(string $chave, string $label, int $ativo, array $extras): void
    {
        $colunas = 'chave,label,ativo,sort_order';
        $marcas = '?,?,?,COALESCE((SELECT MAX(sort_order)+1 FROM tipos_beneficio_custom t2),0)';
        foreach (array_keys($extras) as $coluna) {
            $colunas .= ',' . $coluna;
            $marcas .= ',?';
        }
        $this->banco->executar("INSERT INTO tipos_beneficio_custom ($colunas) VALUES ($marcas)", array_merge([$chave, $label, $ativo], array_values($extras)));
    }

    public function excluir(int $id): void
    {
        $this->banco->executar('DELETE FROM tipos_beneficio_custom WHERE id=?', [$id]);
    }
}
